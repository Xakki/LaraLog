-- ===========================================================================
-- fluent-bit Lua hooks: чистка, расплющивание для GELF и нормализация level
-- ===========================================================================

-- ===========================================================================
-- 0) Обогащение tail-input записей: у файлов нет container_id/labels от
--    docker fluentd-driver, добавляем docker_service/docker_tier/log_format/
--    docker_project/docker_container/log_source вручную, чтобы в Graylog
--    структура совпадала с docker-input. host/hostname/docker_profile ставит
--    глобальный [FILTER] modify Add (одинаково для docker и файлов) — тут не дублируем.
-- ===========================================================================

local _ENV_PROJECT = os.getenv("COMPOSE_PROJECT_NAME") or "?"

local function _fill_common(record, service, tier, log_format, cn_suffix)
    record["docker_service"]   = service
    record["docker_tier"]      = tier
    record["log_format"]       = log_format
    record["docker_project"]   = _ENV_PROJECT
    record["docker_container"] = _ENV_PROJECT .. cn_suffix
    record["log_source"]       = "file"
end

-- MariaDB slowlog tail — единственный системный файл, который не уходит в stderr
-- (mysqld не умеет писать slowlog в stdout). Один источник — хардкод полей.
function enrich_mariadb_tail(tag, ts, record)
    _fill_common(record, "mariadb", "db", "mariadb-slowlog", "-mariadb")
    return 1, ts, record
end

-- Универсальный JSON-tail. Tag формата "service.jsonfile.<replaced-path>",
-- basename файла берём из Path_Key = "file" (полный путь). service = имя файла
-- без расширения .ndjson. tier — из самой JSON-записи (если приложение его
-- кладёт), иначе fallback "app". Если файл вне /var/log/json (нет Path_Key)
-- — используем хвост tag после "service.jsonfile.".
function enrich_jsonfile(tag, ts, record)
    local path = record["file"]
    local basename
    if type(path) == "string" then
        basename = path:match("([^/]+)$") or path
        basename = basename:gsub("%.ndjson$", "")
        record["file"] = nil
    else
        basename = tag:gsub("^service%.jsonfile%.?", "")
        if basename == "" then basename = "unknown" end
    end
    local service    = record["service"]    or basename
    local tier       = record["tier"]       or "app"
    local log_format = record["log_format"] or service
    record["service"] = nil
    record["tier"]    = nil
    _fill_common(record, service, tier, log_format, "-" .. basename)
    return 1, ts, record
end

-- 1) Docker fluentd-driver кладёт имя контейнера как "/matrix_dev-php-1" (с лидирующим
--    слэшем — наследие старого Docker namespace). Поле уже переименовано в docker_container
--    (см. [FILTER] modify). Срезаем слэш, чтобы в Graylog было чистое "matrix_dev-php-1".
function strip_container_name(tag, ts, record)
    local cn = record["docker_container"]
    if type(cn) == "string" and cn:sub(1, 1) == "/" then
        record["docker_container"] = cn:sub(2)
        return 1, ts, record
    end
    return 0, ts, record
end

-- ===========================================================================
-- Парсинг "хвоста" nginx error message — request-context полей.
-- ===========================================================================
-- Nginx после собственно описания ошибки добавляет (при ошибке в контексте запроса):
--   ", client: <ip>, server: <name>, request: \"<METHOD URI HTTP/x.y>\","
--   " upstream: \"...\", host: \"...\", referrer: \"...\""
-- Эти поля идут в фиксированном порядке, но любые могут отсутствовать.
-- Regex c кучей опциональных групп через парсер fluent-bit нестабилен (Onigmo
-- backtracking), поэтому раскручиваем построчно в Lua. Извлечённые ключи
-- (client/server/request_method/request_uri/request_proto/request_host/upstream/referrer)
-- кладём в корень записи. Из message срезаем ", client: ..." хвост, оставляя
-- только описание ошибки (open() failed / connect() / SSL_do_handshake / ...).
function parse_nginx_error_context(tag, ts, record)
    local msg = record["message"]
    if type(msg) ~= "string" then return 0, ts, record end

    local cut = string.find(msg, ", client: ", 1, true)
    if not cut then return 0, ts, record end

    local tail = string.sub(msg, cut + 2) -- срезаем ", "
    record["message"] = string.sub(msg, 1, cut - 1)

    -- Сначала забираем request: "..." как единое целое (содержит пробелы),
    -- чтобы не путать парсер запятых внутри URI.
    local req = string.match(tail, 'request: "([^"]*)"')
    if req then
        local m, u, p = string.match(req, "^(%S+)%s+(%S+)%s+(HTTP/[%d%.]+)$")
        if m then
            record["request_method"] = m
            record["request_uri"]    = u
            record["request_proto"]  = p
        else
            record["request"] = req
        end
    end

    -- host: "..." → request_host (host уже занят source-полем GELF).
    local host = string.match(tail, 'host: "([^"]*)"')
    if host then record["request_host"] = host end

    local referrer = string.match(tail, 'referrer: "([^"]*)"')
    if referrer then record["referrer"] = referrer end

    local upstream = string.match(tail, 'upstream: "([^"]*)"')
    if upstream then record["upstream"] = upstream end

    -- client / server — без кавычек, до следующей запятой или конца строки.
    local client = string.match(tail, "client: ([^,]+)")
    if client then record["client"] = client end

    local server = string.match(tail, "server: ([^,]+)")
    if server then record["server"] = server end

    return 1, ts, record
end

-- ===========================================================================
-- Классификация нативного (не-JSON) вывода контейнеров.
-- ===========================================================================
-- Приложение (Monolog JsonFormatter, PhpErrorCatcher → StreamStorage и т.п.)
-- пишет логи строкой NDJSON, её разворачивает [FILTER] parser json_default
-- и удаляет ключ "log". Если "log" после json_default остался — строка НЕ
-- JSON: это сырой stderr (PHP Fatal/Stack trace, аварийный вывод до
-- инициализации err.php, supervisor warnings, отладочный вывод сторонних либ).
-- Помечаем log_kind=native, чтобы такие записи было видно/фильтровать в Graylog
-- отдельно от структурированных. Имя не пересекается с context_log_type
-- приложения и log_source ("stdout"/"stderr"/"file") — старые запросы не ломаются.
function tag_native_stderr(tag, ts, record)
    if record["log"] == nil then return 0, ts, record end
    record["log_kind"] = "native"
    return 1, ts, record
end

-- ===========================================================================
-- Нормализация уровня логирования (Monolog-совместимая шкала)
-- ===========================================================================
-- Возвращает level_name (DEBUG..EMERGENCY) и level_php (100..600) для всех записей.
-- Источник уровня:
--   1. Laravel/Monolog JSON уже кладёт level + level_name в корень — оставляем.
--   2. Парсер nginx_error / mariadb_record извлёк level_str ("warn", "Note", ...) — мапим.
--   3. Парсер keydb извлёк level_char ("#"|"*"|"-"|".") — мапим.
--   4. Nginx access JSON содержит status (HTTP-код) — деривация по статусу.
--   5. По умолчанию — INFO/200.

local LEVEL_BY_NAME = {
    DEBUG     = 100,
    INFO      = 200,
    NOTICE    = 250,
    WARNING   = 300,
    ERROR     = 400,
    CRITICAL  = 500,
    ALERT     = 550,
    EMERGENCY = 600,
}

-- Маппинг Monolog → syslog (0..7) для GELF.level (см. Gelf_Level_Key в OUTPUT).
-- Без него fluent-bit пишет в stderr "level is N, but should be in 0..7".
local SYSLOG_BY_NAME = {
    DEBUG     = 7,
    INFO      = 6,
    NOTICE    = 5,
    WARNING   = 4,
    ERROR     = 3,
    CRITICAL  = 2,
    ALERT     = 1,
    EMERGENCY = 0,
}

-- nginx error severities (lowercase, как пишет nginx).
local NGINX_LEVEL = {
    debug  = "DEBUG",
    info   = "INFO",
    notice = "NOTICE",
    warn   = "WARNING",
    error  = "ERROR",
    crit   = "CRITICAL",
    alert  = "ALERT",
    emerg  = "EMERGENCY",
}

-- KeyDB: # warning, * notice, - verbose (~info), . debug.
local KEYDB_LEVEL = {
    ["#"] = "WARNING",
    ["*"] = "NOTICE",
    ["-"] = "INFO",
    ["."] = "DEBUG",
}

-- MariaDB: [Note] / [Warning] / [ERROR]; редкий [Info] для отдельных плагинов.
local MARIADB_LEVEL = {
    Note    = "NOTICE",
    Info    = "INFO",
    Warning = "WARNING",
    ERROR   = "ERROR",
}

local function set_level(record, name)
    record["level_name"] = name
    record["level_php"] = LEVEL_BY_NAME[name]
    record["syslog_severity"] = SYSLOG_BY_NAME[name]
end

function infer_level(tag, ts, record)
    -- 1) Уже выставлено (Laravel/Monolog JSON). Monolog кладёт числовой level
    -- (Monolog-шкала 100..600) — переносим в level_php, освобождая GELF-зарезервированный
    -- "level" (туда пойдёт только syslog_severity через Gelf_Level_Key в OUTPUT).
    if record["level_name"] then
        local name = string.upper(tostring(record["level_name"]))
        record["level_php"] = record["level"] or LEVEL_BY_NAME[name]
        record["level"] = nil
        record["syslog_severity"] = SYSLOG_BY_NAME[name] or 6
        return 1, ts, record
    end

    -- 2) Извлечено nginx_error или mariadb_record парсером.
    -- Переименовываем level_str → level_raw, чтобы оригинальная строка-уровень
    -- ("warn", "Note", ...) сохранялась в Graylog для отладки источника.
    local lvl_str = record["level_str"]
    if lvl_str then
        local name = NGINX_LEVEL[lvl_str] or MARIADB_LEVEL[lvl_str]
        if name then
            set_level(record, name)
            record["level_raw"] = lvl_str
            record["level_str"] = nil
            return 1, ts, record
        end
    end

    -- 3) Извлечено keydb парсером.
    local lvl_char = record["level_char"]
    if lvl_char then
        local name = KEYDB_LEVEL[lvl_char]
        if name then
            set_level(record, name)
            record["level_raw"] = lvl_char
            record["level_char"] = nil
            return 1, ts, record
        end
    end

    -- 4) Nginx access JSON: status — корень.
    local status = record["status"]
    if status then
        local s = tonumber(status) or 0
        if s >= 500 then
            set_level(record, "ERROR")
        elseif s >= 400 then
            set_level(record, "WARNING")
        else
            set_level(record, "INFO")
        end
        return 1, ts, record
    end

    -- 5) Дефолт — INFO.
    set_level(record, "INFO")
    return 1, ts, record
end

-- ===========================================================================
-- 2) GELF не поддерживает вложенные объекты/массивы — поле должно быть скаляром.
--    Laravel/Monolog кладёт context = {user = {id, email}, exception = {class, trace = [..]}},
--    extra = {request_id = ...}. Аналогично у php-legacy могут быть ctx/req/trace.
--    Эта функция рекурсивно расплющивает map/array в плоские ключи parent_child:
--      context.user.id      -> context_user_id
--      context.exception.trace[0] -> context_exception_trace_0
--    Скаляры (string/number/bool) оставляем как есть.
local MAX_DEPTH  = 6
local SEP        = "_"
-- Ключи, которые НЕ трогаем (служебные, плоские, либо уже строки):
local SKIP = {
    container_id = true, docker_container = true, log_source = true,
    short_message = true, message = true, log = true,
    docker_service = true, docker_project = true, docker_image = true,
    docker_tier = true, docker_profile = true,
    log_format = true, log_kind = true,
    level_php = true, level_name = true, syslog_severity = true,
    channel = true, datetime = true, ["@timestamp"] = true,
}

local function is_array(t)
    if type(t) ~= "table" then return false end
    local n = 0
    for k, _ in pairs(t) do
        if type(k) ~= "number" then return false end
        n = n + 1
    end
    return n > 0
end

local function flatten(prefix, value, out, depth)
    if depth > MAX_DEPTH then
        out[prefix] = tostring(value)
        return
    end
    local t = type(value)
    if t == "table" then
        local has_keys = false
        if is_array(value) then
            for i, v in ipairs(value) do
                flatten(prefix .. SEP .. tostring(i - 1), v, out, depth + 1)
                has_keys = true
            end
        else
            for k, v in pairs(value) do
                local sk = tostring(k):gsub("[^%w]", "_")
                flatten(prefix .. SEP .. sk, v, out, depth + 1)
                has_keys = true
            end
        end
        if not has_keys then
            out[prefix] = ""
        end
    elseif t == "nil" then
        -- skip
    else
        out[prefix] = value
    end
end

function flatten_nested(tag, ts, record)
    local changed = false
    local additions = {}
    local removals  = {}
    for k, v in pairs(record) do
        if not SKIP[k] and type(v) == "table" then
            flatten(k, v, additions, 0)
            removals[#removals + 1] = k
            changed = true
        end
    end
    if not changed then
        return 0, ts, record
    end
    for _, k in ipairs(removals) do record[k] = nil end
    for k, v in pairs(additions)  do record[k] = v end
    return 1, ts, record
end

-- ===========================================================================
-- 3) Универсальная нормализация event timestamp + защита GELF mandatory полей.
-- ===========================================================================
-- Graylog GELF-decoder валит запись если:
--   * "short_message" пустой/отсутствует ("empty mandatory short_message field")
--   * "timestamp" пришёл строкой вместо number ("invalid timestamp (type: STRING)")
--
-- Источник event ts — поле record.datetime (Laravel/Monolog/PhpErrorCatcher,
-- ISO 8601 с TZ) или record.time_local (nginx access JSON,
-- "04/May/2026:22:42:37 +0300"). Если ничего не распарсилось — оставляем
-- входной ts (время приёма fluent-bit'ом).
--
-- Дополнительно срываем top-level "timestamp" → "timestamp_raw": сторонние
-- PHP-логгеры иногда кладут unix-time строкой; если оставить под именем
-- "timestamp", Graylog трактует как GELF-mandatory и валит запись.
-- Сохранение под "_raw" суффиксом — чтобы исходное значение видно в Graylog.

local MONTH_NAME = {Jan=1,Feb=2,Mar=3,Apr=4,May=5,Jun=6,
                    Jul=7,Aug=8,Sep=9,Oct=10,Nov=11,Dec=12}

local function _is_leap(y)
    return (y % 4 == 0 and y % 100 ~= 0) or y % 400 == 0
end

-- Конвертирует Y-Mo-D h:m:s, трактуя их как UTC, в unix epoch (number, sec).
local function _ymdhms_to_utc_epoch(Y, Mo, D, h, m, s)
    local md = {31,28,31,30,31,30,31,31,30,31,30,31}
    local days = 0
    for y = 1970, Y - 1 do
        days = days + (_is_leap(y) and 366 or 365)
    end
    for mo = 1, Mo - 1 do
        local d = md[mo]
        if mo == 2 and _is_leap(Y) then d = 29 end
        days = days + d
    end
    days = days + (D - 1)
    return days * 86400 + h * 3600 + m * 60 + s
end

-- Парсит TZ-суффикс ("Z" | "+03:00" | "-0500" | "") в offset (sec).
-- Возвращает nil для непустой непохожей на TZ строки (caller трактует как fail).
local function _parse_tz(tz)
    if not tz or tz == "" or tz == "Z" or tz == "z" then return 0 end
    local sign, hh, mm = tz:match("^([+%-])(%d%d):?(%d%d)$")
    if not sign then return nil end
    local off = (tonumber(hh) or 0) * 3600 + (tonumber(mm) or 0) * 60
    if sign == "+" then return off else return -off end
end

local function parse_iso8601(s)
    if type(s) ~= "string" or #s < 19 then return nil end
    -- "2026-05-04T22:42:37" + (".microsec")? + ("Z"|"+03:00"|"+0300"|"")
    local Y, Mo, D, h, m, sec, frac, tz =
        s:match("^(%d%d%d%d)-(%d%d)-(%d%d)T(%d%d):(%d%d):(%d%d)(%.?%d*)([Zz+%-][%d:]*)$")
    if not Y then
        Y, Mo, D, h, m, sec, frac =
            s:match("^(%d%d%d%d)-(%d%d)-(%d%d)T(%d%d):(%d%d):(%d%d)(%.?%d*)$")
        tz = ""
    end
    if not Y then return nil end
    local off = _parse_tz(tz)
    if off == nil then return nil end
    local epoch = _ymdhms_to_utc_epoch(tonumber(Y), tonumber(Mo), tonumber(D),
                                        tonumber(h), tonumber(m), tonumber(sec))
    local fr = (frac and frac ~= "" and tonumber(frac)) or 0
    return epoch + fr - off
end

local function parse_nginx_time(s)
    -- "04/May/2026:22:42:37 +0300"
    if type(s) ~= "string" then return nil end
    local D, Mname, Y, h, m, sec, sign, oh, om =
        s:match("^(%d%d)/(%a+)/(%d%d%d%d):(%d%d):(%d%d):(%d%d) ([+%-])(%d%d)(%d%d)$")
    if not D then return nil end
    local Mo = MONTH_NAME[Mname]
    if not Mo then return nil end
    local epoch = _ymdhms_to_utc_epoch(tonumber(Y), Mo, tonumber(D),
                                        tonumber(h), tonumber(m), tonumber(sec))
    local off = (tonumber(oh) * 3600 + tonumber(om) * 60)
    if sign == "-" then off = -off end
    return epoch - off
end

-- Каскад заполнения short_message. Возвращает true если запись изменилась.
-- Источники в порядке приоритета (от наиболее каноничного к fallback):
--   1. message      — Laravel/Monolog, php-legacy StreamStorage, наши probes
--   2. msg          — некоторые JSON-логгеры пишут через "msg"
--   3. log          — сырые тексты от Docker fluentd-driver (KeyDB, Memcached,
--                     nginx error, php-fpm NOTICE, MySQL slowlog) и парсеры,
--                     которые не извлекли структурное `message`
--   4. request_uri  — nginx access JSON: uri идёт в short_message, чтобы он
--                     стал основным сообщением записи в Graylog
--   5. "-"          — фолбэк, чтобы GELF-decoder не валил на пустом коротком
local function _coalesce_short_message(record)
    local sm = record["short_message"]
    if sm ~= nil and sm ~= "" then return false end
    for _, src in ipairs({"message", "msg", "log", "request_uri"}) do
        local v = record[src]
        if v ~= nil and v ~= "" then
            record["short_message"] = v
            record[src] = nil
            return true
        end
    end
    record["short_message"] = "-"
    return true
end

function normalize_event_time(tag, ts, record)
    local dt = record["datetime"]
    local iso = parse_iso8601(dt)
    local new_ts = iso or parse_nginx_time(record["time_local"])

    local changed = false

    -- Не-ISO "datetime" (nginx "2026/05/30 ..", keydb "30 Apr 2026 ..", mariadb
    -- "2026-04-30 12:34:56") под именем "datetime" ломает OpenSearch date-mapping
    -- → Graylog показывает "Invalid date" (и роняет bulk-вставку при первом mapping).
    -- Кладём такой формат в keyword-safe "datetime_raw". ISO8601 (php/Monolog)
    -- оставляем как есть — валидная date, event-ts из неё уже снят выше.
    if dt ~= nil and iso == nil then
        record["datetime_raw"] = dt
        record["datetime"] = nil
        changed = true
    end

    -- Стороннее top-level "timestamp" → "timestamp_raw" (чтобы Graylog не
    -- трактовал как GELF-mandatory и не валил запись типом string).
    local stray_ts = record["timestamp"]
    if stray_ts ~= nil then
        record["timestamp_raw"] = stray_ts
        record["timestamp"] = nil
        changed = true
    end

    -- Каскад наполнения short_message — заменяет 5 modify-фильтров,
    -- которые раньше делали Conditional Rename. Преимущество: отсекает
    -- empty-string значения (Condition Key_does_not_exist такое пропускает,
    -- и Graylog GELF-decoder валил с "empty mandatory short_message field").
    if _coalesce_short_message(record) then
        changed = true
    end

    if new_ts then
        return 2, new_ts, record
    elseif changed then
        return 1, ts, record
    end
    return 0, ts, record
end
