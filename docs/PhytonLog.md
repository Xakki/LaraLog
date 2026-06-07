# Python logging via fluent-bit

> How a non-PHP (Python stdlib `logging`) component ships into the same
> Graylog GELF pipeline that LaraLog feeds. The reference Python helper is
> `graylog_logging.py` from the avito-bidder repo; field names below match
> what that helper emits and what this transport's fluent-bit configs parse.
>
> *See also: [Graylog integration](./Graylog.md), [Logging Rules](./LoggingRules.md) ([RU](./LoggingRules.ru.md)).*

---

## 1. Overview — two ingestion paths

A Python service that uses `graylog_logging.py` ends up with **two** independent
log paths to Graylog. Both are active at the same time during migration.

| | Path A — direct GELF/HTTPS | Path B — stderr → fluent-bit |
|---|---|---|
| Producer | in-process `GELFHTTPSHandler` (graypy) | stderr text line |
| Transport | HTTPS POST to `…/gelf`, GELF dict built in-process | docker `fluentd` log-driver → fluent-bit → Graylog GELF |
| Parsing | none — graypy serialises the `LogRecord` into a GELF dict | fluent-bit `python_log` regex reconstructs fields from text |
| Event time | real (`record.created`) | fluent-bit receive time (text `datetime` has no TZ) |
| Failure mode | async, drop-on-full (never blocks the hot loop) | best-effort docker async driver |

**Path A** — graypy takes each `LogRecord`, builds a GELF JSON dict
in-process (level, host, timestamp, short_message, extra fields) and POSTs it
straight to the Graylog GELF/HTTPS input. No text parsing, no fluent-bit.

**Path B** — the same record is *also* written to stderr as a plain text line
by a `StreamHandler`. Docker's `fluentd` log-driver forwards that line to
fluent-bit, which has no idea it is Python: it tries the global JSON parser,
fails (the line is not JSON), tags it `log_kind=native`, then `python_log`
re-extracts `datetime` / `level_str` / `logger` / `message` from the text and
ships the reconstructed record on to Graylog as GELF.

**Migration intent.** Both paths run now → the same event lands in Graylog
**twice** (transitional double-ingestion). This is deliberate: Path B is being
validated against the proven Path A. After a green observation window, flip
`GRAYLOG_ENABLED=false` on the Python services → Path A goes dormant and
Path B becomes primary. Path A stays as a one-env-var fallback.

---

## 2. Python logging mechanism (`graylog_logging.py`)

One shared helper for every Python entrypoint. Each one calls
`setup_graylog_logging(component=...)` **once** at startup; the call is
idempotent per component.

**stderr text formatter (this is the format Path B parses).** A root
`StreamHandler` formats every record as:

```
[%(asctime)s] [%(levelname)s] %(name)s: %(message)s
```

with `datefmt="%Y-%m-%d %H:%M:%S"` (no milliseconds), e.g.

```
[2026-06-01 22:00:00] [INFO] avito_messenger_core: started
```

This handler is **synchronous** — even if Graylog is unreachable the line still
reaches stderr/journald, so logs are never lost locally.

**Async GELF over a non-blocking queue (Path A).** The GELF handler
(`GELFHTTPSHandler`, a TLS subclass of graypy's `GELFHTTPHandler`) is attached
to root *through* a `QueueListener`: records go onto a bounded
`queue.Queue(maxsize=20000)` via a `_DropQueueHandler` that does
`put_nowait()` and **drops on full** rather than blocking. A background
listener thread does the actual HTTPS delivery. So a slow/down Graylog never
stalls the hot loop (bidder, position-checker). `compress=False` — the GELF
HTTP input does not unzip request bodies (gzip → 202 but silently dropped),
so plain JSON is sent.

**Per-node tags → GELF extra fields.** A `_GelfTags` logging filter injects
four attributes onto every record. graypy auto-prefixes any non-underscore
record attribute with `_` (and skips ones that already start with `_`), so
Graylog receives them under the underscore-prefixed names:

| record attr | GELF field | value |
|---|---|---|
| `record.component` | `_component` | script id (`bidder`, `bidder-api`, `position-checker`, `messenger-bot`, …) |
| `record.env` | `_env` | deployment env (`prod` / `dev`) |
| `record.hostname` | `_hostname` | `socket.gethostname()` (separate from GELF `source`, which is statically `avito-bidder` for all nodes) |
| `record.external_ip` | `_external_ip` | node public IP, resolved via `ip.xakki.pro` |

---

## 3. fluent-bit parse path (Path B)

The text line travels through this transport's fluent-bit config like any
other native (non-JSON) container output:

1. **Route by label.** The container is labelled `log_format: python`
   (see `https://github.com/Xakki/fluent-log`). fluent-bit's generic
   `rewrite_tag` rule turns the tag into `gl.python` based on that label —
   routing is by `log_format`, never by container/service name.
2. **Global JSON parse fails.** `json_default` runs first and *fails* — the
   stderr text is not JSON.
3. **Tag as native.** Because the `log` key survived `json_default`,
   `tag_native_stderr` (cleanup.lua) sets `log_kind=native`, so these records
   are filterable in Graylog apart from structured JSON ones.
4. **Per-type parse.** `service.d/python.conf` runs the `python_log` regex
   parser (`Key_Name log`, `Reserve_Data On`) and extracts
   `datetime` / `level_str` / `logger` / `message`:

   ```
   ^\[(?<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(?<level_str>[A-Z]+)\] (?<logger>[^:]+): (?<message>.*)$
   ```

5. **Map the level.** `infer_level` (cleanup.lua) looks up the UPPERCASE
   `level_str` via the `PYTHON_LEVEL` table → sets `level_name` / `level_php`
   / `syslog_severity`, and renames the original string to `level_raw`.
6. **Normalise time + message.** `normalize_event_time`: the text `datetime`
   has no timezone, so it is **not** used as the event timestamp — it is moved
   to `datetime_raw` (keeps OpenSearch date-mapping happy) and the event time
   stays at fluent-bit receive time. `short_message` is filled from the
   coalesce cascade (`message` → `msg` → `log` → `request_uri` → `"-"`).

`logger` passes through flat as a plain field — Python logger names may contain
dots but not a colon, so the `[^:]+` capture is safe.

---

## 4. Concrete examples (both paths side by side)

Raw stderr line:

```
[2026-06-01 22:00:00] [INFO] avito_messenger_core: started
```

### Path B (fluent-bit) — reconstructed fields

```jsonc
{
  "short_message":   "started",
  "logger":          "avito_messenger_core",
  "level_name":      "INFO",
  "level_php":       200,
  "syslog_severity": 6,            // → GELF level = 6
  "level_raw":       "INFO",
  "datetime_raw":    "2026-06-01 22:00:00", // no `datetime`; event-ts = fluent-bit receive time
  "log_kind":        "native",
  "log_format":      "python"
  // + docker_* enrichment (docker_service / docker_container / docker_project / …)
}
```

### Path A (direct GELF) — graypy-built dict

```jsonc
{
  "short_message": "started",      // raw message only — the GELF handler has NO formatter,
                                   // graypy serialises record.getMessage()
  "level":         6,              // GELF syslog severity
  "facility":      "avito_messenger_core", // = the logger name
  "timestamp":     <record.created>,       // real event time
  "_component":    "messenger-bot",
  "_env":          "prod",
  "_hostname":     "...",
  "_external_ip":  "...",
  // GELF host / source set by the handler (source = "avito-bidder")
  // NOTE: no level_name / level_php on this path
}
```

### Severity mapping (other levels)

| Raw line | `level_name` | `level_php` | `syslog_severity` → GELF `level` |
|---|---|---|---|
| `… [INFO] svc: started` | INFO | 200 | 6 |
| `… [WARNING] svc: retrying` | WARNING | 300 | 4 |
| `… [ERROR] svc: failed` | ERROR | 400 | 3 |

(`PYTHON_LEVEL` also maps `DEBUG`→7, `CRITICAL`/`FATAL`→2, and the aliases
`WARN`→WARNING, `FATAL`→CRITICAL.)

---

## 5. Integration notes / gotchas

- **`component` label divergence.** The avito-bidder source compose adds a
  `component` label to each Python service so GELF gets `_component` on
  Path B too. This LaraLog compose is a **template** and intentionally omits
  it. To get `_component` on Path B here, add a `component` label to your
  service *and* extend the driver's `labels:` list in the `x-logging` anchor
  so docker forwards it.
- **Multiline tracebacks.** `logger.exception()` emits a traceback whose
  continuation lines do **not** match `python_log`. On Path B each line
  arrives as a separate `log_kind=native` INFO event (same behaviour as nginx
  / mariadb continuations). A multiline parser is a possible future addition.
- **Double-ingestion during migration.** While both paths are on, every event
  lands in Graylog twice. Disable Path A with `GRAYLOG_ENABLED=false` once
  Path B is trusted; Path B then carries the traffic and Path A is a
  one-env-var fallback.

---

## 6. Compliance vs the logging spec

Measured against [LoggingRules.ru.md](./LoggingRules.ru.md). The Python helper
predates this spec, so this is a gap analysis, not a claim of conformance.

### Conformances

- **Levels + GELF severity.** Full 0–7 syslog severity set is emitted
  (`Gelf_Level_Key syslog_severity` in the fluent-bit OUTPUT) — §3.
- **`short_message` always populated.** The coalesce cascade guarantees a
  non-empty short message, so the GELF decoder never rejects on an empty
  mandatory field — §4.
- **Source / host present.** GELF `source` + `host`, plus per-node
  `_hostname` and `_external_ip` — §4.2 / §5.2.
- **Pipeline is best-effort, never drops the request.** Path A is async with
  drop-on-full; the synchronous stderr handler stays as the local safety net —
  §1.6 / §8.
- **TLS on both paths.** Path A is HTTPS; the fluent-bit → Graylog hop is the
  same TLS GELF input as the PHP transport — §2.1.

### Deviations (strongest first)

1. **[SECURITY] No PII/secret redaction** in `graylog_logging.py` — there is
   no equivalent of LaraLog's `Redactor.php`. Anything a Python service logs
   (exception messages with user input, args) goes out raw — §2.
2. **[SECURITY] Not JSON on the wire + `\n`/`\r` not escaped** on the stderr
   line → log-injection / log-forging surface, since Path B reconstructs from
   text — §2.1.
3. **`level_name` UPPERCASE** (the spec wants lowercase) and **absent on
   Path A** entirely — §4.1.
4. **`datetime` not RFC-3339** (no `T`, no microseconds, no timezone) → real
   event time is lost on Path B (falls back to receive time) — §4.1.
5. **Top-level Monolog `level` int (100..600) absent on Path A** — §4.1.
6. **Missing contract fields**: `channel`, `log_type`, `app_ver`, `log_ver`,
   `tier` — §4.x.
7. **GELF extras diverge from canonical names**: `_env` / `_component` instead
   of `app_env` / `app_name` (and from the OTel `deployment.environment` /
   `service.name`) — §9.3.1.
8. **Tracebacks not coalesced** into a single `trace` field — they fan out as
   separate native events — §3.7 / §6.1.

> Remediation of these deviations is tracked in the avito-bidder kanban card
> **K-078**; this document only records the findings.
