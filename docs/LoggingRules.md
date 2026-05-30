# Logging Rules

> Pragmatic rules for production logging. Plain language, no fluff.
> LaraLog is the reference implementation for PHP/Laravel; code pointers appear as `LaraLog: src/...`.
>
> Status: **draft v1**, iterating in the open.
>
> *Russian version: [LoggingRules.ru.md](./LoggingRules.ru.md)*

---

## 0. Audience and goals

For engineers and tech leads who want logs that:

- **Let you find the cause of a problem** — a developer or oncall engineer should be able to understand context and root cause from logs quickly;
- **Are cheap and reliable** — logging infrastructure, collection costs, the balance between delivery guarantees, freshness, and resources, pipelines, indexes, and retention are real money;
- **Are secure** — no PII, secrets, or sessions in long-term storage;
- **Tie into traces and metrics** — one transaction, one story, the ability to reconstruct the chain of events.

The principles below are language-agnostic. PHP specifics live in callout blocks using this library as an example.

---

## 1. Core principles

1. **A log is an API.** It is consumed by oncall, ops, and you in six months. Breaking a field name hurts as much as breaking a REST endpoint. See §9.3 for the field catalog and rename process.
2. **Structure beats prose.** A log record is a JSON object with stable field names. `message` is for humans. Everything you search by goes in `context` and `extra`.
3. **Mind cardinality.** Anything that changes every request (`request_id`, `user_id`, `order_id`) is gold as a field, poison as an index label. See §5.5.
4. **Log once, at the boundary.** A failure is logged once, where the full context is available. Catch-log-rethrow at every level multiplies noise without new information.
5. **Stability is a sprint tax.** Logging is part of tech debt and needs ongoing time: CI ratchet, scheduled review, removal of what nobody reads, tuning alerts and dashboards.
6. **Logging must not break the request.** If Graylog is down or Redis is full, the request still succeeds. Logging is best-effort.

---

## 2. What we DO NOT log

Non-negotiable. A violation is a security incident, not a style question.

| Never                                  | Examples                                       | Why                          |
|----------------------------------------|------------------------------------------------|------------------------------|
| Passwords, hashes, recovery tokens     | `password`, `password_hash`, `reset_token`     | Trivial credential theft     |
| API keys, bearer/refresh tokens        | `Authorization: Bearer …`, `X-Api-Key`         | Same                         |
| Session IDs                            | `PHPSESSID`, `JSESSIONID`, `Cookie: session=…` | Session hijacking            |
| Full payment data                      | PAN, CVV, expiry                               | PCI-DSS                      |
| Government IDs                         | SSN, national IDs, passport numbers            | GDPR/HIPAA                   |
| Medical data                           | Diagnoses, prescriptions, biometrics           | HIPAA                        |
| Full PII without need                  | Email + phone + address together               | GDPR data minimization       |
| Raw endpoint bodies with any of above  | `POST /login`, `POST /payment`                 | All of the above             |

**Redact at the process boundary, not in storage.** If a value is sensitive, it should not be in the stream. Safe placeholders: `***`, `[redacted]`, `sha256:<first-8-chars>` if you need to trace without disclosure.

Watch out for HTTP dumps, traces with arguments, ORM bindings, and exception messages with user input substituted in. LaraLog already truncates `bindings` strings >512 chars and shows them only when `APP_DEBUG=true` — never enable that in prod.

### 2.1 Pipeline security

Redacting values at the source is only half the story. The log pipeline itself is the same attack surface as any service.

**Log injection / log forging.** If a field carries user input with `\n`, `\r`, or ANSI escapes, an attacker can forge log lines: insert a fake record to hide their tracks or blame another user. Defense:

- At the source: JSON serialization (it escapes control chars). Never assemble logs by string concatenation.
- For plain-text transports (syslog line format): escape `\n`/`\r` explicitly before writing.
- At the sink (Graylog/Loki): validate schema, drop lines missing required top-level fields.

**Transport.** UDP plain text is acceptable **only inside a trusted network** (private VPC, k8s pod-to-pod). Across a segment boundary or the internet — syslog over TLS (RFC 5425), RELP with TLS, or HTTPS to the collector. The audit channel is always TLS, regardless of segment.

**Encryption at rest.** Operational: at the storage provider's discretion (server-side encryption on S3/disk is usually enough). Audit and any storage with PII: explicit requirement, customer-managed keys where the regulator demands them.

**Reading operational logs is itself an audit event.** Not just the audit channel (§6.5.7). Engineer queries against prod logs through a UI (Graylog, Kibana, Loki Explore) get written as audit:

```jsonc
{"action":"logs.operational.read","actor":{"user_id":..., "role":"engineer"},
 "target":{"stream":"prod-api","query":"...","time_range":"..."},
 "result":"success"}
```

Especially important for windows that might have contained PII (even if redaction should have caught it). Insider protection and regulator-friendly.

**Environment isolation.** Don't dump dev/staging logs into the same index/stream as prod — cross-contamination of search and alerts. One pipeline, separate streams.

---

## 3. Levels

8 PSR-3 / RFC 5424 levels. In practice we actively use 6: `debug`, `info`, `notice`, `warning`, `error`, `critical`. `emergency` is a special case of critical (everything is down). `alert` usually collapses into `critical`.

### 3.1 Decision tree

```
Is the service serving requests?
├─ No, everything is down                                       → EMERGENCY
└─ Yes
   ├─ Data corrupted or lost?                                   → CRITICAL  (immediate alert)
   ├─ Operation failed, no recovery, surfaced to user?          → ERROR     (alert above 1% of log volume)
   ├─ Failure swallowed, but SOMETHING needs to be done?        → WARNING   (alert above 5%)
   ├─ Strange / suspicious / auto-corrected?                    → NOTICE    (alert above 10%)
   ├─ Normal business event?                                    → INFO
   └─ Useful only when reproducing a bug?                       → DEBUG     (off by default in prod, enable conditionally only)
```

### 3.2 What NOTICE is

Notice means "pay attention if it spikes". It doesn't alert on a single event, it alerts on a trend.

It covers:

- **Type and validation errors** the system handled: a `string` arrived instead of `int` and was coerced, an empty field was defaulted, an unknown enum case fell into `default`.
- **Suspicious user actions**: 3 failed logins per minute from one IP, attempt to modify someone else's resource (got 403, but the fact is worth recording), request with suspicious headers.
- **Failures that "should be" Warning, but**:
  - data is auto-corrected and work continues;
  - execution flow is not affected;
  - there's no concrete developer action.
- **Deprecated paths**: an old API was called, but it worked.
- **First retry attempt** after an upstream failure (see §6.4).

A Notice spike above baseline is a signal that something changed. A steady Notice background is normal.

### 3.3 WARN ↔ NOTICE: demotion rule

**If a Warning has no concrete action, it's a Notice.**

Test: "If a developer sees this warning, what should they do?" If the answer is "well, sometimes it happens" — demote to notice. Alerting on a warning with no actionability guarantees warning fatigue.

### 3.4 WARN vs ERROR (the most-broken rule)

- **WARN = the system absorbed the failure, but something went wrong.** A fallback fired, we filled from cache, we hit the legacy path. The user's request still succeeded.
- **ERROR = the failure escaped.** All retries exhausted, the operation didn't complete, the user got a 5xx or a wrong result.

Consequence for retry loops: intermediate attempts → `warning`, final exhaustion → `error`. See §6.4.

### 3.5 Alerts per level

Numbers are starting points, fine-tune per service traffic.

| Level                | Threshold              | Condition                                                        |
|----------------------|------------------------|------------------------------------------------------------------|
| Critical / Emergency | **Immediate**          | any single event                                                 |
| Error                | **> 1% of log volume** | `rate(level=error)[5m] / rate(*)[5m] > 0.01` AND `count >= 5`    |
| Warning              | **> 5%**               | `rate(level=warning)[5m] / rate(*)[5m] > 0.05` AND `count >= 20` |
| Notice               | **> 10%**              | `rate(level=notice)[5m] / rate(*)[5m] > 0.10` AND `count >= 50`  |
| Info / Debug         | no alerts              | -                                                                |

**Denominator `rate(*)`** = total log volume of the service. Universal, but biased toward info-heavy services: if you emit a lot of `info` (e.g. per §6.4.1 — one for every successful external call), the denominator grows and the `error` rate as a percentage drops. Not catastrophic — the floor count covers it — but cross-service comparisons need adjusting for verbosity.

**Floor count** (`>= N`) protects against false positives on low traffic: 1 event out of 5 is 20%, but there's nothing to wake oncall for.

**5-minute window** is a compromise: long enough that the average doesn't bounce on spikes, short enough to catch an active incident. The burst case (50 errors in 30s then silence) is additionally caught by `increase(level=error)[1m] >= 20` running alongside the rate threshold.

**Slice axes — at least two:**

| Axis        | Catches                                            | Example                                                                 |
|-------------|----------------------------------------------------|-------------------------------------------------------------------------|
| global      | system failures                                    | `rate(level=error)[5m] / rate(*)[5m]`                                   |
| per `tag`   | failure of one domain/feature                      | `rate(level=error{tag="billing"})[5m] / rate(*{tag="billing"})[5m]`     |
| per route   | failure of one endpoint                            | same with `request_url` (not as a Loki label, see §5.5)                 |
| per `tier`  | front vs worker vs cron distinction                | `rate(level=error{tier="worker"})[5m] / rate(*{tier="worker"})[5m]`     |

Global without per-tag gives frequent false negatives: 0.5% errors globally can be 30% for a specific `billing` tag, which is already an incident.

### 3.6 Production default

- Prod log level: `info`.
- Hot paths with >10 INFO/req — that's `debug`.
- DEBUG is enabled locally, per-instance, by special request, for a specific incident; never globally.

### 3.7 Trace and call site

Traces help you understand where a log or exception was raised. But they're bulky.
For every log it's useful to know where it was emitted (for fast debugging).
At `Warning` and above a stack trace is added automatically. Trace depth scales with severity — the more serious, the more trace. Same goes for log context (in critical logs, context can be decisive):

| Level                        | Trace depth (frames) |
|------------------------------|----------------------|
| Warning                      | 5                    |
| Error                        | 10                   |
| Critical / Alert / Emergency | 20                   |

`src/LogManager.php` accepts all 8 PSR-3 methods.
Vendor/Monolog/Illuminate frames are stripped (`TraitFileTrace`). Depth, the strip list, and the per-argument limit are configurable via `config/logger.php` (`trace.depth`, `trace.excluded_partials`, `trace.arg_limit`).

---

## 4. Log shape

### 4.1 Top-level fields (contract)

A fixed set, nothing custom goes here — this is the public contract of the log / Monolog-compatible.

| Field        | Type     | Notes                                                                          |
|--------------|----------|--------------------------------------------------------------------------------|
| `datetime`   | RFC 3339 | `Y-m-d\TH:i:s.uP` `2026-05-28T14:23:45.123456+00:00`                           |
| `level`      | int      | severity_number per RFC 5424 / PSR-3                                           |
| `level_name` | string   | level name in lowercase                                                        |
| `channel`    | string   | PSR-3/Monolog channel name (`app`, `sql`, `audit`, ...)                        |
| `message`    | string   | short sentence, no value interpolation                                         |
| `context`    | object   | values **per event** (request, user, exception, resources)                     |
| `extra`      | object   | values **stable per process** (env, release, host, library version)            |

`level` — 100=debug, 200=info, 250=notice, 300=warning, 400=error, 500=critical, 550=alert, 600=emergency. Convenient for numeric filters and sorting (`level >= 300`).
`level_name` — `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. For humans and runbooks.

### 4.2 extra — stable per process

Auto-populated by processors at process start. The value does not change between calls inside one request/script.

| Field             | Type   | Source                                                          |
|-------------------|--------|-----------------------------------------------------------------|
| `app_name`        | string | env                                                             |
| `app_env`         | string | env (`prod`, `staging`, `dev`)                                  |
| `app_ver`         | string | release version                                                 |
| `log_ver`         | string | logging library version                                         |
| `host_ip`         | string | IP of the **host/node**, injected externally (env, k8s downward API) |
| `host_name`       | string | host hostname (or container id if no external injection)        |
| `container_name`  | string | docker/k8s                                                      |
| `tier`            | string | `web`/`api`/`worker`/`cron`                                     |
| `release_tag`     | string | git tag or CI build id                                          |
| `release_time`    | string | deploy time, RFC 3339                                           |
| `console_argv`    | array  | argv for CLI scripts                                            |
| `load_avg_1m`     | float  | system load 1 min (`LoadAverageProcessor`)                      |
| `load_avg_5m`     | float  | system load 5 min                                               |
| `load_avg_15m`    | float  | system load 15 min                                              |

> **LaraLog implementation:** `ExtraProcessor` (`src/Processor/ExtraProcessor.php`) + `BaseContext` (`src/BaseContext.php`). `host_ip` is taken from env (e.g. k8s downward API `status.hostIP`), not via `gethostbyname()` — so the value is actually the node IP, not the container's. Currently `request_id` goes into `extra` — this is legacy; migration into `context` is planned (see §4.3).

### 4.3 context — per-event

Populated by application code or auto-injected via MDC/scope. Changes per request/event.

| Field                        | Type           | When                                                                                       |
|------------------------------|----------------|--------------------------------------------------------------------------------------------|
| `log_type`                   | string         | who initiated the log; see §4.3.1 (lives in `context`, not top-level, for Monolog compat)  |
| `request_id`                 | UUID v4        | from `X-Request-ID` or generated; carried across queues and outgoing calls                 |
| `request_url`                | string         | path (without query string or with redacted query)                                         |
| `request_method`             | string         | `GET`/`POST`/...                                                                           |
| `request_host`               | string         | `Host` header                                                                              |
| `request_user_agent`         | string         | `User-Agent`                                                                               |
| `trace_id`, `span_id`        | hex            | with OTel; `request_id` is a degenerate form                                               |
| `user_id`                    | int            | actor of the operation                                                                     |
| `session_id`                 | string (hash)  | HTTP session / queue worker session                                                        |
| `file`, `line`               | string         | `path:int` auto from the stack (`TraitFileTrace`), don't pass manually                     |
| `trace`                      | string         | stack trace, truncated per level (see §3.7)                                                |
| `exception`                  | class FQN      | fully-qualified exception class name                                                       |
| `exception_message`          | string         | exception message                                                                          |
| `exception_code`             | int            | exception code                                                                             |
| `exception_prev`, `_prev_*`  | object         | previous-cause chain                                                                       |
| `memory_usage`               | int (bytes)    | `memory_get_usage(true)`                                                                   |
| `memory_peak`                | int (bytes)    | `memory_get_peak_usage(true)`                                                              |
| `tag`                        | string         | routing label (`sql`, `queue`, `auth`, ...); whitelist see §9.3                            |
| business IDs                 | int / string   | `order_id`, `payment_id`, anything domain-specific                                         |

> **LaraLog implementation:** `LogManager::appendContext()` collects exception fields; `LogManager::contextTypeCorrector()` normalizes types.

**`request_id` grey area:** the value is stable **within one request** but changes between requests — hence `context`, not `extra`. The rule "stable per process" = "one value from process start to process exit".

#### 4.3.1 log_type

Helps separate "what was written deliberately from code" from "what crashed on its own" in one log pile. Without this field, a fatal crash is lost among regular errors in search — alerting on "only real crashes" becomes impossible.

| Value       | Source                                          | Example (PHP)                                                                            |
|-------------|-------------------------------------------------|------------------------------------------------------------------------------------------|
| `logger`    | explicit call in code                           | `Log::info(...)`, `$this->logger->error(...)`                                            |
| `trigger`   | initiated by the language runtime               | `set_error_handler` on `E_NOTICE`/`E_WARNING`, `trigger_error()`, deprecation warnings   |
| `exception` | exception — uncaught or explicitly logged       | `set_exception_handler`, explicit `Log::error('...', ['exception' => $e])` on caught     |
| `fatal`     | script crash by the runtime                     | Caught in `register_shutdown_function`, parse-error, OOM, max_execution_time exceeded    |

> **LaraLog implementation:** `log_type` is set automatically by the source site (exception-handler → `exception`, shutdown → `fatal`, error-handler → `trigger`, everything else → `logger`). Candidate for v1.x.

Default — `logger`. A typical alert condition: `log_type IN (exception, fatal)` separates real failures from "the developer felt like emitting a warning".

### 4.4 Business identifiers — in context, not in message

```jsonc
// bad
{"level":"info","message":"order 12345 paid by user 67890 for 49.99 EUR"}

// good
{"level":"info","message":"order paid",
 "context":{"order_id":12345,"user_id":67890,"amount_cents":4999,"currency":"EUR","tag":"billing"}}
```

The good form is searchable (`order_id:12345`, `amount_cents>10000`). The bad one isn't.

**Anti-example with PII** (formally structured, still wrong):

```jsonc
// bad — even in context
{"level":"info","message":"login attempt",
 "context":{"email":"user@example.com","password":"hunter2","ip":"1.2.3.4"}}

// good
{"level":"info","message":"login attempt",
 "context":{"user_id":67890,"ip":"1.2.3.4","auth_method":"password","tag":"auth"}}
```

Structure doesn't override the redaction policy of §2. Password — always in the ban list; email — drop it, keep `user_id`; raw IP — evaluate under GDPR (usually required for login, debatable on every request).

### 4.5 Type discipline

The same field arriving sometimes as `"123"` and sometimes as `123` produces two columns in Elasticsearch, an indexer crash in Loki, and confusion in Grafana.

- `*_id`, `*Id`, `*_cnt`, `*_count`, `*Size` → integer
- `is_*`, `has_*`, `flag*` → boolean
- Money → integer in minor units (`amount_cents`), never float
- Durations → integer milliseconds
- Timestamps in context → one of: RFC 3339 string OR Unix epoch int. Pick per project, don't mix.

> **LaraLog implementation:** `LogManager::contextTypeCorrector()` does this automatically. Reserved keys in `src/const.php`.

### 4.6 Size limits

| Limit               | Default                                          | Why                                                  |
|---------------------|--------------------------------------------------|------------------------------------------------------|
| `message`           | ~3 KB                                            | long message = payload dumped, belongs in context    |
| Whole line          | ~1.4 KB (Graylog UDP), ~64 KB (Loki, but <16 KB) | UDP fragmentation, indexer limits                    |
| Stack trace         | 5 / 10 / 20 frames by level                      | see §3.7                                             |
| Trace argument      | 128 chars per argument                           | otherwise explodes on data-heavy methods             |

**Truncation strategy** (`CustomFormatter::trimLog`): first `message`, then string values in `context`/`extra`. Numeric/boolean fields are not touched. Truncation marker — `…`, so the consumer knows.

### 4.7 Field naming

- `snake_case` everywhere. Mixing camelCase and snake_case in one payload breaks dashboards.
- Prefixes for related fields: `db_query`, `db_time_ms`, `db_table`.
- Reserved prefixes: `app_*`, `http_*`, `db_*`, `queue_*`. Project keys go under their own prefix.

---

## 5. Context and correlation

### 5.1 Three IDs

| ID            | Scope                              | Lifetime                              | Source            |
|---------------|------------------------------------|---------------------------------------|-------------------|
| `trace_id`    | the whole distributed transaction  | until the operation ends, via queues  | OTel              |
| `span_id`     | a unit of work inside a trace      | duration of the span                  | OTel              |
| `request_id`  | one HTTP request                   | one service, one request              | `X-Request-ID`    |

With OTel — use `trace_id`/`span_id`. Without — `request_id` is the MVP substitute.

### 5.2 Business IDs

`user_id`, `order_id`, `session_id`. They outlive a single trace — the only way to answer "what happened to order 12345 in the last 24h".

Inject into the logging scope once at the entrypoint (the MDC pattern). Don't drag them through every call by hand.

### 5.3 Entrypoints

Every entrypoint (HTTP, queue worker, scheduled job, RPC) emits a pair:

```jsonc
{"level":"info","message":"entry","context":{"tag":"entrypoint","entrypoint":"POST /orders","payload_size":1234}}
…work…
{"level":"info","message":"exit","context":{"tag":"entrypoint","entrypoint":"POST /orders","success":true,"duration_ms":83,"status_code":200}}
```

Without exit, you can't tell whether it finished. Without entry, there's no anchor for request_id.

### 5.4 Crossing boundaries

- Outgoing HTTP — propagate `X-Request-ID` (or W3C `traceparent` with OTel).
- Outgoing queue job — serialize `request_id`/`trace_id` into the payload, restore in the worker.
- DB — not propagated; slow-query logs carry their own `request_id`, injected via a session variable or SQL comment.

### 5.5 Cardinality budget

The most common reason log pipelines go down.

> **Rule:** anything that changes per-request goes into a **field** (queryable, not indexed). Stable across thousands of requests can be a **label** (indexed).

| OK as label (low cardinality)     | NOT OK as label                |
|-----------------------------------|--------------------------------|
| `app_env` (dev/staging/prod)      | `request_id`                   |
| `tier` (web/api/worker)           | `user_id`, `order_id`, `email` |
| `tag` (auth/billing/sql)          | full URL with query string     |
| `level`                           | datetime                       |

Loki blows up chunk count, Elastic — mapping, Graylog — stream index. Differently, but equally painfully.

> [Grafana — Best practices for logging](https://grafana.com/blog/2022/05/16/all-things-logs-best-practices-for-logging-and-grafana-loki/).

---

## 6. Special scenarios

### 6.1 Exceptions

Log a failure **once** — at the top level of handling, where business context exists. Catch-log-rethrow at every level — no.

Required fields:

- `exception` — fully-qualified class name (not the message)
- `exception_code` — integer
- `file:line` of the throw point
- `trace` — truncated per level (5/10/20), framework frames stripped
- `exception_prev`, `exception_prev_*` — full chain via `getPrevious()`

**The exception message is suspect.** `$e->getMessage()` may have user input substituted in (`"User john'; DROP TABLE -- not found"`). That's a **source of PII and log injection** at the same time. Options:

- Store class FQN + code, message — only when `app_env != prod` or in a sanitized form.
- Or apply the same redactor as for the request body (§2).

**Fan-out / parallel work.** If the top level launches `Promise.all` / parallel jobs and some fail — log **each failed branch separately**, with a shared `trace_id` and an individual `branch_id` or index. One summary log hides causes; individual ones are reconstructable by `trace_id`.

**Enrichment without logging.** If a lower level has context that the top level doesn't (e.g. `db_query` inside a Repository, invisible to the Controller) — **don't log there**, add it to MDC/scope (`Log::withContext(['db_query' => ...])`). The top-level log will pick it up.

> **LaraLog implementation:** `LogManager::appendContext()`. `TraitFileTrace` strips `Monolog`, `Illuminate/Log/`, `vendor/laravel`.

### 6.2 Slow SQL queries

Separate channel, `tag: sql`. Required fields: `db_query` (truncated), `db_table`, `db_time_ms`, `sql_type`. **Never** log raw `bindings` in prod.

Two thresholds:

```
SQL_SLOW_LOG_FOR_SELECT=200ms
SQL_SLOW_LOG_ALL=500ms
```

Writes are expected to be slower, so their threshold is higher.

**N+1 detection.** A separate class of slow-query: many fast queries inside one request_id summing up. Count `count(distinct db_query_normalized)` within one request_id; threshold — >50 identical queries = N+1, `tag: sql_n_plus_1`, `level: warning`. The choice of normalization (substitute literals, AST-fingerprint) is a tradeoff between accuracy and overhead.

> **LaraLog implementation:** `src/SqlLogServiceProvider.php`. Bindings — only when `APP_DEBUG=true` and ≤ 20 bindings, strings >512 chars are replaced with `string(N)`. Telescope queries are excluded. N+1 detection — candidate for v1.x.

### 6.3 Queues and background workers

**Heartbeat — via Prometheus where possible.** A worker exposes a metric:

```
queue_worker_heartbeat_total{worker="default"} (counter, increment every N seconds)
queue_worker_last_active_timestamp{worker="default"} (gauge, last activity time)
```

The alert fires when the metric stops updating for X seconds. Cheaper, more accurate, and doesn't pollute the log pipeline.

Heartbeat as a log line is kept where Prometheus isn't available (e.g. a one-shot scheduled job without a long-lived process to scrape).

The rest:
- Job start/finish — entrypoint pair, as in §5.3, `entrypoint: queue:<job-class>`.
- On `WorkerStopping` emit `info` with status — to see whether it exited cleanly or was killed.

> **LaraLog implementation:** `src/QueueHeartBeat.php` emits heartbeat to the log (legacy approach). Migration to a Prometheus metric — candidate for v2.

### 6.4 External calls and retries

#### 6.4.1 Successful call

**Emit an `info` log on every successful external call.** Without payload, just metrics and identifiers.

```jsonc
{"level":"info","level_name":"info","message":"http call ok",
 "context":{"tag":"upstream","target":"payments-api","method":"POST","path":"/charge",
            "response_code":200,"latency_ms":143,"response_size":512,"request_id":"..."}}
```

Why we don't optimize "silence = normal":

- p50/p95/p99 latency is computed directly from logs (or converted to a Prometheus histogram — see §8.2).
- baseline error rate (error/total per `target`) needs a denominator.
- "silence" is indistinguishable from "the request never went out" — history is lost.

Required fields:

| Field            | Type   | Notes                                          |
|------------------|--------|------------------------------------------------|
| `target`         | string | upstream name (low cardinality, not URL)       |
| `method`, `path` | string | for HTTP; path without query string            |
| `response_code`  | int    | HTTP/gRPC code                                 |
| `latency_ms`     | int    | end-to-end                                     |
| `response_size`  | int    | in bytes                                       |
| `attempt`        | int    | 1 on the first successful attempt              |

**What we don't put in:** request/response body, headers with credentials, query string with possible PII. If you need body shape — `content_type`, `response_size`.

#### 6.4.2 Failure level progression

"First attempt" in the table below = **the first failure on a call** (i.e. the first retry, since retries only start after the original attempt failed).

| Event                                       | Level     |
|---------------------------------------------|-----------|
| First failure on a call (retry starts)      | `notice`  |
| All subsequent retry attempts               | `warning` |
| `max_attempts` exhausted                    | `error`   |
| Circuit breaker opens                       | `warning` |
| Circuit breaker stays open > N minutes      | `error`   |

Logic: a first upstream failure is normal for distributed systems, expected. A steady retry rate is a signal that upstream is degrading (Warning 5% threshold). All attempts burned out — the client got rejected (Error).

Required fields on a failure (in addition to §6.4.1):

- `attempt` — attempt number, starting at 1
- `max_attempts`
- `backoff_ms` — how long we waited before this attempt
- `error_reason` — short string (`timeout`, `connection_refused`, `5xx`, `4xx`), not a sentence
- `exception` (if any) — class, not the message

Idempotency keys go in context. We don't log response body for retries — only its shape (`response_size`, `content_type`).

### 6.5 Audit logs

#### 6.5.1 What it is and why it's separate

An audit log records "who, what, on what, when, with what outcome did something". It's a different contract from the operational log:

|                | Operational log                | Audit log                                       |
|----------------|--------------------------------|-------------------------------------------------|
| Goal           | Debug, monitoring              | Compliance, investigations, legal evidence      |
| Storage        | Nice to have, not critical     | Mandatory durable + append-only                 |
| Retention      | Days to weeks                  | Years (1-7 depending on regulator)              |
| Read access    | Any engineer                   | Separate role, access is itself audited         |
| Sampling       | Allowed                        | Never                                           |
| Transport      | Best-effort (UDP allowed)      | Synchronous, at-least-once                      |

**Don't mix the streams.** Separate sink, separate index/table/bucket, separate retention.

#### 6.5.2 When it's required

Mandatory if any of:
- Payment data → PCI-DSS
- EU personal data → GDPR Art.30 (Records of Processing)
- Medical data → HIPAA
- Financial reporting → SOX
- ISO 27001 / SOC 2 certification
- B2B with DPAs (Data Processing Agreements) — almost always

#### 6.5.3 Events always audited

| Category         | Examples                                                                              |
|------------------|---------------------------------------------------------------------------------------|
| Authentication   | login/logout, failed login, password change, MFA added/removed, password reset        |
| Authorization    | role grant/revoke, permission change, impersonation                                   |
| Data access      | read/export of PII or financial data                                                  |
| Data mutation    | CRUD on critical entities (users, orders, billing, configs)                           |
| Security         | key rotation, security setting change, blocked IP                                     |
| Administration   | account creation/deletion, system config change                                       |
| API keys / tokens| creation, revocation, rotation                                                        |

#### 6.5.4 Required fields

5W: who, what, where, when, what changed.

```jsonc
{
  "datetime": "2026-05-28T14:23:45.123456+00:00",
  "actor": {
    "user_id": 67890,
    "role": "admin",
    "session_id": "sha256:a1b2c3d4",
    "ip": "1.2.3.4",
    "user_agent": "...",
    "impersonated_by": null
  },
  "action": "user.role.grant",
  "target": {
    "type": "user",
    "id": 12345,
    "owner_id": 12345
  },
  "result": "success",
  "before": {"role": "viewer"},
  "after": {"role": "admin"},
  "request_id": "uuid-...",
  "reason": "ticket #1234",
  "audit_ver": "1"
}
```

- **Who** (`actor`) — id, role, IP, session, whether impersonation
- **What** (`action`) — verb in `domain.entity.verb` form: `user.role.grant`, `order.delete`, `payment.refund`
- **Where** (`target`) — resource type and id, plus `owner_id` if actor ≠ owner
- **When** (`datetime`) — server time with microseconds
- **What changed** (`before`/`after` for mutations) + `result` (success/failure)
- `request_id` — correlation with operational logs
- `reason` — ticket, comment, justification (if the business process requires)
- `audit_ver` — audit schema version, so schema migrations survive

#### 6.5.5 Immutability

- **Append-only**: the writer has only INSERT. No UPDATE/DELETE at the application level.
- At the DB level — a TRIGGER blocking UPDATE/DELETE on that table, or a role with INSERT only.
- WORM storage if the regulator demands: S3 Object Lock, GCS Bucket Lock, Azure Immutable Storage.
- Hash chain for high-compliance (optional): each record stores `prev_hash` = sha256 of the previous. Forging the middle of the history becomes mathematically expensive.

#### 6.5.6 Retention (typical durations)

| Regulator   | Minimum                                                          |
|-------------|------------------------------------------------------------------|
| PCI-DSS     | 1 year, 3 months of which hot-access                             |
| GDPR        | longer of business need and other regulation                     |
| HIPAA       | 6 years                                                          |
| SOX         | 7 years                                                          |
| ISO 27001   | per organizational policy, typically 3-7 years                   |

Check with legal for your specific product. Numbers above are starting points.

#### 6.5.7 Read access to audit logs

- Separate role `audit-reader`, no overlap with operational admin.
- Reading the audit log is itself an audit event ("audit-of-audit"):
  - `action: audit.log.read`
  - `actor: audit-reader`
  - `target: { type: "audit_record_range", filter: "..." }`
- Admin UI queries against audit data are logged with the user and the filter.

#### 6.5.8 What does NOT go into audit

- Passwords, hashes, raw tokens — even on action `password.change`. Store metadata: `changed_at`, `complexity_ok`, `via: web/api/sso`.
- Full payment data — store `pan_last4`, `card_brand`.
- Token contents — store `token_id`, `token_hash`.

Sensitive values in mutations (`email`, `phone`, `name`):
- If the regulator requires before/after — store.
- If not — store only the fact of change: `field_changed: email`, no values.

#### 6.5.9 Audit and GDPR Right to Erasure

When a data subject requests deletion:
- Operational logs get deleted/redacted.
- Audit logs are **not deleted** — that contradicts compliance requirements.
- Instead — **pseudonymization**: PII in audit is replaced with a stable identifier with no reversible link to the subject.
- The procedure is documented in the DPA.

#### 6.5.10 Implementation

- Separate logger / channel: `Log::channel('audit')` or a dedicated service.
- **Do not** use the general PSR-3 logger — audit has a different contract: write guarantee, not fire-and-forget.
- Transport — durable: sync to DB, or queue with at-least-once delivery. No UDP.
- Sampling — never.

> **LaraLog implementation:** v1 deliberately does not include an audit channel. Audit needs durable storage, which is outside operational logging. Recommended: a separate service/table or specialized SaaS (e.g. via outbox pattern + worker writing to a dedicated DB).

#### 6.5.11 Edge cases

**System actor.** When an action is initiated not by a human but by a background job/system, `actor` is still required:

```jsonc
"actor": {"type": "system", "name": "billing.daily-rollup", "trigger": "cron:0 3 * * *", "trace_id": "..."}
```

No `actor: null` — audit must answer what exactly triggered it.

**Cascade and bulk operations.** One command mutates N records (bulk update, import, migration). Two options:

- **Per-record audit** — one event per record. Expensive for bulk but gives full traceability. Recommended for small-mid bulk (≤1000 records).
- **Bulk envelope** — one event with `target: {type: "bulk", count: 50000, filter: "...", sample_ids: [...]}`. For migrations / large operations. `sample_ids` is a random sample for quick checks.

The choice depends on the regulator. PCI-DSS usually demands per-record for payment mutations.

**Testing audit.** Audit logs are part of the compliance contract — regressions cost fines. Minimum:

- For every security-sensitive action in code — a test "after the call there's a record with expected fields in the audit table".
- Snapshot tests on the audit event schema (`audit_ver`).
- A test "audit channel unavailable → the operation fails" (not silent skip). See §6.5.10 — audit is not fire-and-forget.

**GDPR-erasure vs regulator conflict.** §6.5.9 says "pseudonymization instead of deletion", but in some jurisdictions/categories (minors, consent withdrawn for a specific category) the regulator may require actual deletion. Solution:

- Document in the DPA which subject categories trigger full deletion and for which actions.
- For others — pseudonymization (stable hash without reversible link).
- Any deletion from audit is itself logged in a meta-audit (`action: audit.record.erase`) with the legal basis.

**Mass export.** An operator downloading a large audit range is itself an audit event (§6.5.7), plus: rate-limit on export size + two-step authorization for dumps >N records. Insider exfiltration protection.

**Hash chain vs horizontal scaling.** A linear hash chain (§6.5.5) requires a single writer — a bottleneck at high throughput. Alternatives:

- Partitioned Merkle tree — each partition has its own chain, roots are joined periodically.
- External notarization — periodic signing of audit-batch hashes in an external service (KMS signature, blockchain).

The choice depends on threat model — if you're defending against an external attacker, Merkle is enough. Against a DBA — external is needed.

---

## 7. Anti-patterns

| Anti-pattern                                                                  | Why it hurts                                       | How to fix                                                                                |
|-------------------------------------------------------------------------------|----------------------------------------------------|-------------------------------------------------------------------------------------------|
| `log->info("user $userId logged in")`                                         | not searchable, breaks aggregation                 | `info("login success", ["user_id" => $userId, "tag" => "auth"])`                          |
| Logging an exception at every catch level                                     | 5× noise on one failure                            | catch once at the top, rethrow below                                                      |
| `throw new SomeException()` without `previous` when re-throwing               | cause chain is lost, debugging is blind            | `throw new SomeException(msg, code, $prevException)`                                      |
| `log->error("something happened")`                                            | useless in search                                  | message: noun + verb; context: values                                                     |
| `log->warning(...)` on a path that always fires                               | warning fatigue                                    | demote to notice or remove                                                                |
| `log->info(...)` in a tight loop                                              | floods the pipeline                                | sampling, per-batch aggregation, or debug                                                 |
| `request_id` / `user_id` as Loki labels                                       | index explosion                                    | as fields, not labels                                                                     |
| Logging full request/response body "for debugging"                            | PII + size blowup                                  | shape (`size`, `content_type`), not contents                                              |
| Catch and swallow without log                                                 | invisible failures                                 | minimum notice/warning; better — propagate                                                |
| `print_r($obj)` / `var_export($obj)` in message                               | unbounded size, leakage                            | explicit fields, type coerce, truncate                                                    |
| Sync log writes on the request path                                           | one slow Graylog = p99 outside                     | UDP / async / Redis buffer (LaraLog: `RedisLogger`, capped)                               |
| Mixing audit and operational streams                                          | compliance and cost both lose                      | two sinks, two retentions                                                                 |
| Warning without actionability                                                 | warning fatigue                                    | demote to notice                                                                          |

---

## 8. Transport and pipeline

[The Twelve-Factor App § XI — Logs](https://12factor.net/logs):

> "A twelve-factor app never concerns itself with routing or storage of its output stream. The app's environment is responsible."

In practice:

1. **App writes JSON lines to stdout/stderr.**
2. **Sidecar / agent** (Vector, Fluent Bit, Filebeat, syslog-ng) tails the stream.
3. **Pipeline** (Graylog, Loki, Elastic, Datadog) parses, routes, retains.
4. **Backpressure** lives on the agent, not the app.

For low-volume / low-criticality — fire-and-forget is OK (syslog UDP, capped Redis buffer). For audit and compliance — durable transport (sync, queue with persistence).

> **LaraLog recommended stack:** `['stderr']`

### 8.1 Per-environment configs

- **Dev**: human-readable formatter, `debug`, no sampling.
- **CI**: JSON, `info`, no sampling, retained per-run as a build artifact. **Redact env-dumps** — CI often emits `printenv` or config dumps; apply the same key ban list here.
- **Staging**: identical to prod, including sampling — so you can reproduce volume issues.
- **Production**: JSON, `info`, sampling where applicable, per-source cap.

### 8.2 Sampling

Sampling is a cost-control tool when the info volume outgrows the budget. It applies **only to info/debug** — notice/warning/error/critical are always kept in full (otherwise §3.5 alerts break).

**Head sampling.** The keep/drop decision is made **at the source**, before sending. Cheapest, but context is lost if a failure happens later:

```
if (level <= INFO && hash(request_id) % 100 < sample_rate_percent) keep
else if (level >= NOTICE) keep_always
```

**Sticky-per-trace.** The decision is made **once at trace start** and propagated to every log of that trace. Guarantees that if a request stays in the sample, its full history is there — not "5 logs out of 12":

```
sampling_decision = hash(trace_id ?? request_id) % 100 < rate
// injected into MDC, propagated across queues
```

This is the de-facto standard for distributed tracing — do the same for logs.

**Tail sampling.** The decision is made **after the trace completes**, when the outcome is known. More expensive (needs a buffer) but more accurate: keep 100% of traces with errors / high latency, drop "boring ok-200 in 50ms". Implemented in the agent (OTel Collector) or in the pipeline — not in the app.

**Adaptive sampling.** The rate adapts to load: quiet — higher rate, peak — lower. Budget stays constant, sampling is denser during interesting periods.

**Metrics from logs are separate.** If metrics are computed from logs (error rate, p95 latency via histogram_quantile, business KPI), sampling distorts metrics. Solutions:

- Compute metrics **before** sampling (an intermediate aggregator that sees everything).
- Store `sampled_rate` in every log — upscale on compute: `actual_count = sampled_count * (100 / sampled_rate)`.
- Emit critical metrics to Prometheus separately (not via logs).

**Checklist before turning sampling on:**

1. Error/warning alerts are preserved (sampling doesn't touch them).
2. Sticky-per-trace is enabled (otherwise reconstruction is lost).
3. Metrics computed from logs either account for sampling or are computed before it.
4. The sampling rate is visible in the log (`sampled_rate` field or channel name).
5. Sampling is never applied to audit.

---

## 9. Lifecycle

A spec without a CI ratchet rots. Minimum:

1. **Linter** (or custom static check): forbids interpolation in `message`, `print_r`/`var_export` in logs, ban list of keys (`password`, `token`, …).
2. **Quarterly review**:
   - Notice/Warning/Error ratios by day and week.
   - Top-20 log lines by volume — are any meaningless?
   - Top-20 by zero-query-hits — are any unused? Delete them.
3. **Document versioning.** Services bump the `app_log_spec` field (e.g. `"v1"`) — postmortems know which rules were in effect.
4. **Onboarding.** New engineers read this doc on day 1. New log lines in PRs are reviewed against §7 by a peer.

### 9.1 Metrics to watch

- **MTTR** — the main one. If MTTR isn't dropping as logs grow, logs are wrong.
- **Notice/Warning/Error rates** — should be stable. A spike in any one is a signal.
- **Log pipeline cost** ÷ **traffic** — flat or declining. Growing = logging what's not being read.
- **Signal/noise** in Warning: % of warnings with actionable follow-ups. Below 50% — time to clean up (demote to notice or remove).
- **Agent drop rate** (Vector/Fluent Bit metrics) — should be near zero. Growing = backpressure, scale up or drop deliberately.
- **Schema violation rate** — % of logs missing required top-level fields. Should be 0 after migration; a spike = a regression in a new service.

### 9.2 Testing logs

The linter (§9 item 1) is reactive. Tests are proactive. Minimum:

**Unit tests:** assert that code emits a log of the expected level with the expected fields.

```php
// PHP / Monolog
use Monolog\Handler\TestHandler;

$handler = new TestHandler();
$logger = new Logger('test', [$handler]);
$service = new BillingService($logger);

$service->chargeFailed($order);

$this->assertTrue($handler->hasErrorRecords());
$record = $handler->getRecords()[0];
$this->assertSame('charge failed', $record['message']);
$this->assertSame('billing', $record['context']['tag']);
$this->assertSame($order->id, $record['context']['order_id']);
```

**PII regression tests:** for every point where a sensitive value might slip through, an explicit test:

```php
public function test_login_failure_log_does_not_contain_password(): void
{
    $this->postJson('/login', ['email' => 'a@b.c', 'password' => 'secret123']);
    $logs = $this->handler->getRecords();
    foreach ($logs as $log) {
        $serialized = json_encode($log);
        $this->assertStringNotContainsString('secret123', $serialized);
    }
}
```

Worth keeping a generic test that for a **list of known secrets** asserts they never appear in logs in smoke scenarios. Cheap; catches regressions in redactors.

**Pipeline integration test** (not on every PR, but on infra changes):

- Bring up docker-compose with app + agent + Graylog/Loki.
- Generate N logs of different levels.
- Assert N records arrive at the backend with the correct schema.
- Assert one of them with PII was **redacted at the agent stage** (if redaction lives there).

**Schema snapshot.** A snapshot of a real log object with masked datetimes. A PR changing the schema breaks the snapshot and forces an explicit contract review.

### 9.3 Field catalog and schema lifecycle

A doc without a catalog is a recommendation without enforcement. The catalog makes fields **discoverable** and **safely mutable**.

**Field catalog.** One file (`docs/log-fields.yml` or similar) — the source of truth: which field exists, in which layer (top-level/extra/context), type, description, who uses it, deprecation status.

```yaml
- name: order_id
  layer: context
  type: int
  description: Order identifier in the billing domain
  owner: team-billing
  added_in: v1
- name: payment_method_old
  layer: context
  type: string
  status: deprecated
  deprecated_in: v3
  remove_in: v5
  replaces_with: payment_method  # the new field name
```

The catalog is part of PR review: a new field = a new catalog entry.

**Whitelist for `tag`.** `tag` is an indexed label (see §5.5); unchecked growth = cardinality explosion. Whitelist in the catalog:

```yaml
tags_allowed:
  - auth
  - billing
  - sql
  - queue
  - upstream
  - entrypoint
  - audit
  - cron
```

The linter asserts that `context.tag` in every log comes from the whitelist. A new tag = PR review + explicit addition.

**Field lifecycle.**

| Stage          | What we do                                                                                  |
|----------------|---------------------------------------------------------------------------------------------|
| `proposed`     | field in the catalog with status `proposed`, not yet emitted by code                        |
| `active`       | emitted and used in dashboards/alerts                                                       |
| `deprecated`   | emitted alongside the replacement (dual-write window ≥ 2 releases)                          |
| `read-only`    | new records omit it, old records remain for historical search                               |
| `removed`      | field removed from the catalog; runbooks and dashboards must be migrated beforehand         |

**Rename = dual-write.** Direct rename breaks search, dashboards, alerts. Correct workflow:

1. Catalog: add the new field as `active`, mark the old as `deprecated`.
2. Code: write both fields.
3. Migrate dashboards/alerts to the new field.
4. After N releases: catalog — old as `read-only`, code — stop emitting.
5. After N more releases: `removed`.

**Contract versioning.** The top-level field `app_log_spec: "v3"` is bumped on breaking schema changes. Postmortems on old incidents know which contract was in effect. Each bump — an entry in the document's changelog.

#### 9.3.1 OpenTelemetry semantic conventions

Structurally compatible: `extra` corresponds to OTel `Resource` (per-service stable), `context` — OTel `Attributes` (per-event), `message` — `Body`, `datetime` — `Timestamp`, `level`/`level_name` — `SeverityNumber`/`SeverityText`.

Field names diverge from [OTel Semantic Conventions](https://opentelemetry.io/docs/specs/semconv/) deliberately — in favor of brevity and Loki/Graylog query ergonomics (dots in OTel names break parsers). When integrating with the OTel stack (Tempo, Honeycomb, Datadog) — convert via OTel Collector `transform` processor or Vector `remap`.

| LaraLog                | OTel sem conv                       | Note                              |
|------------------------|-------------------------------------|-----------------------------------|
| `datetime`             | `Timestamp`                         |                                   |
| `level`                | `SeverityNumber`                    |                                   |
| `level_name`           | `SeverityText`                      |                                   |
| `message`              | `Body`                              |                                   |
| `channel`              | -                                   | no direct equivalent              |
| `app_name`             | `service.name`                      |                                   |
| `app_ver`              | `service.version`                   |                                   |
| `app_env`              | `deployment.environment`            |                                   |
| `tier`                 | `service.namespace`                 | approximate                       |
| `host_name`            | `host.name`                         |                                   |
| `host_ip`              | `host.ip`                           |                                   |
| `container_name`       | `container.name`                    |                                   |
| `trace_id`, `span_id`  | `trace_id`, `span_id`               | compatible (snake_case)           |
| `request_method`       | `http.request.method`               |                                   |
| `request_url`          | `url.path`                          | without query string              |
| `request_host`         | `server.address`                    |                                   |
| `request_user_agent`   | `user_agent.original`               |                                   |
| `status_code`          | `http.response.status_code`         | for HTTP                          |
| `latency_ms`           | `http.client.request.duration`      | OTel in seconds, we use ms        |
| `target`               | `server.address`                    | for upstream calls                |
| `db_query`             | `db.query.text`                     |                                   |
| `db_table`             | `db.collection.name`                |                                   |
| `db_time_ms`           | `db.client.operation.duration`      | OTel in seconds, we use ms        |
| `exception`            | `exception.type`                    | FQN of the class                  |
| `exception_message`    | `exception.message`                 |                                   |
| `trace`                | `exception.stacktrace`              | for exception logs                |
| `user_id`              | `enduser.id`                        |                                   |

For new fields — when adding to the §9.3 catalog, check whether an OTel equivalent exists; if it does, either adopt the OTel name or explicitly record the divergence in the catalog.

---

## 10. Quickstart (LaraLog)

With LaraLog you already get:

- Auto-injection of `request_id`, `app_ver`, `host`, memory metrics, release info (`ExtraProcessor`).
- Size caps for message and the whole line with smart truncation (`LogManager::message_limit`, `CustomFormatter`).
- Exception unwrap with previous chain (`appendContext`).
- Type coercion in context (`contextTypeCorrector`).
- File:line of the call site, framework frames stripped (`TraitFileTrace`).
- Auto stack trace at `Warning+`, depth scaled by level (5/10/20).
- Slow SQL channel with safe binding redaction (`SqlLogServiceProvider`).
- Queue heartbeat as a log line (legacy, migrating to Prometheus).
- Channels: stderr, syslog-UDP → Graylog, JSON file, Redis (capped), Telegram.

Recommended composition:

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['stderr'],
],
```

What's still on you:

- The correct **level** at the call site (§3).
- **Stable field names** + business IDs in context (§4.4).
- Following the **redaction list** (§2).
- Keeping **cardinality** under control in your label schema (§5.5).
- A **separate audit channel** (§6.5).

### 10.1 Migration from Monolog default

If you currently run Monolog without LaraLog (or Laravel's default `LineFormatter`):

1. **Switch to JSON in prod** — replace `LineFormatter` with `JsonFormatter` for `stderr`/`syslog` channels. The minimally invasive step.
2. **Add `ExtraProcessor`** (LaraLog): pulls in `app_*`, `host`, `release_*` without app code changes.
3. **Wire `LogManager` via `Providers/AppServiceProvider`** — that's how you get trace, exception_prev chain, type coercion automatically.
4. **Convert log sites to structure** (`['key' => $val]` instead of `"... $val ..."`) — enforce via linter in CI, do it incrementally by domain.
5. **Add `tag`** to the catalog whitelist (§9.3) and inject into every log.
6. **Enable the slow SQL channel** (`SqlLogServiceProvider`) last — by then the pipeline is settled.

In parallel with migration, add both old and new field names to the catalog; dual-write while dashboards move over.

---
