### Resolve TODO markers from commit 1bd602a

**Criticality:** Medium

**TAGS:**
- tech-debt
- feature

**Description:**
Commit `1bd602a` ("todo") introduced the language-agnostic spec `docs/LoggingRules.md`
(LaraLog is declared its *reference implementation*) and left 6 `TODO` markers in the
code. This card tracks closing those gaps and bringing the implementation in line with
its own spec. Bugs found during review (not author TODOs) live in card `0002`.

The markers:

| # | Location | Marker | Spec anchor |
|---|----------|--------|-------------|
| T1 | `src/TraitFileTrace.php:7` | `use config/logging.php` — `$excludedPartials` hardcoded | §3.7 |
| T2 | `docs/LoggingRules.md:179` | trace-depth numbers (5/10/20) should be configurable | §3.7 / §4.6 |
| T3 | `src/TraitFileTrace.php:110` | string trace args may leak credentials | §2 "What we DO NOT log" / §2.1 |
| T4 | `src/TraitFileTrace.php:113` | non-string (`var_export`) trace args may leak credentials | §2 / §2.1 |
| T5 | `src/LogManager.php:130` | detect *who* triggered logging (direct / `set_error_handler` / `set_exception_handler` / `register_shutdown_function`) | §4.3.1 `log_type`, §5 correlation |
| T6 | `src/LogManager.php:203` | `str2lower` + `snake_case` of context keys | §4.7 Field naming |

**Problem:**
The reference implementation does not yet satisfy parts of the spec it ships:
- §2/§2.1 — secrets in trace arguments are emitted verbatim (only length-capped to 128 chars).
- §3.7/§4.6 — trace depth and stripped-frame rules are hardcoded constants, not config.
- §4.7 — context keys are not normalized to `snake_case` (mixed-case keys break dashboards).
- §4.3.1/§5 — no `log_type` to distinguish direct calls from error/exception/shutdown handlers.

**Impact:**
- Security: credentials/PII can land in logs via stack-trace args (the spec's #1 rule).
- Ops: mixed-case keys fan out into duplicate Elasticsearch columns / Loki indexer issues.
- DX: trace depth & exclude-list cannot be tuned per project without editing the package.

**Recommendation (proposed plan, per marker):**

- **T1 + T2 (config-driven trace) — one unit.**
  Read `$excludedPartials`, trace-depth-by-level map, and the per-arg 128-char cap from
  `config('logger.*')` (the package already reads `logger.message_limit` / `logger.allow_memory`,
  so keep the `logger.` namespace, not `logging.php`). Ship sane defaults so behavior is
  unchanged when unset. Fix the buggy depth block while here — see card `0002` (the
  `Level::Warning` duplicate/enum-comparison bug); T2 and that bug touch the same lines.

- **T3 + T4 (credential redaction) — one unit. — DECIDED: safe built-in default + extendable.**
  Ship a built-in denylist (`password`, `passwd`, `token`, `secret`, `authorization`, `api_key`,
  `apikey`, `*_key`, `private_key`, `cookie`, `set-cookie`, `card`/PAN patterns…) that is **on by
  default**, and let projects **add** their own via `config('logger.redact', [])` (merged with the
  built-ins, not replacing them). Check against the *argument name where available* and the value;
  redact to `***` before the 128-char trim. Reuse the same denylist for `contextTypeCorrector` so
  context values are covered too.

- **T5 (log_type / caller classification).**
  Determine origin and stamp `context['log_type']` (per §4.3.1). Cheapest reliable signal:
  inspect `debug_backtrace()` for the framework's registered handler frames
  (`set_error_handler`/`set_exception_handler`/`register_shutdown_function`), or have the
  service provider register handlers that tag the record. Prefer the latter (explicit) over
  backtrace sniffing (fragile).

- **T6 (key normalization). — DECIDED: opt-in, default OFF.**
  In `contextTypeCorrector`, normalize keys to lowercase `snake_case`, gated behind
  `config('logger.snake_case', false)` (**default false** → no breaking change out of the box).
  Run normalization **before** the reserved-key `switch` so the `LOGGER_*` constants still match.
  Constant alignment (`LOGGER_MEMORY_PEAK`) already handled in card `0002` B8.

**Acceptance Criteria:**
- All 6 markers removed; behavior covered by spec sections above.
- New behavior gated behind `logger.*` config with backward-compatible defaults.
- Unit tests added for: redaction (T3/T4), key normalization (T6), config-driven depth (T1/T2),
  `log_type` stamping (T5).
- `make test` green (`cs-check` + `phpstan` level 8 + `phpunit`).
- `docs/LoggingRules.md` **and** `docs/LoggingRules.ru.md` §3.7 TODO lines removed, and the
  config keys documented in `Readme.md`.

**Resolved decisions (2026-05-29):**
1. **T6 snake_case** — opt-in, **default OFF** (`logger.snake_case=false`). No breaking change
   out of the box. *(was Q1)*
3. **T3/T4 redaction** — safe **built-in denylist on by default**, projects extend via
   `logger.redact` (merged, not replaced). *(was Q3)*
4. **Config namespace** — `logger.*` (existing package convention), not `config/logging.php`.

**Resolved 2026-05-29 — all open questions closed:**
2. **T5** — user chose **Option A** (chained handlers, opt-in `logger.capture_handlers`,
   default OFF) with field name **`log_type`** and spec values `logger|trigger|exception|fatal`.
5. **§4.7 SQL renames — DONE.** User chose conform + fix the config-doc bug:
   `table`→`db_table`, `millisecond`→`db_time_ms`, `bindings`→`db_bindings`; Readme slow-log
   keys corrected (`sqlSlowLogAll`/`sqlSlowLogForSelect`). ⚠ breaking for dashboards on old
   SQL field names. A DB-backed test for `SqlLogServiceProvider` (would also retro-cover B3)
   remains deferred — needs an sqlite `QueryExecuted` harness.

**Execution Log (2026-05-29) — implemented on branch `feat/resolve-todos-and-review-fixes`:**
- **T1/T2 ✅** `config/logger.php` `trace.{excluded_partials,depth,arg_limit}`; `TraitFileTrace`
  reads them (cached, `flushTraceConfig()` test seam); `LogManager::traceDepthForLevel()`.
- **T3/T4 ✅** `Redactor` (built-in denylist on + `logger.redact` merged); by-key masking in
  `contextTypeCorrector`, by-value (Bearer/JWT/`secret=…`) in trace args. Built-in list trimmed
  to high-signal needles to avoid false positives (`card`/`pin`/`auth`/`session` excluded).
- **T5 ✅** `LogType` + `appendContext` stamps `log_type`; handlers installed by
  `LaraLogServiceProvider` when `capture_handlers=true`. ⚠ `fatal` tagging best-effort
  (framework shutdown handler ordering) — documented in `LogType` + spec.
- **T6 ✅** `logger.snake_case` (default off) in `contextTypeCorrector`.
- Docs §3.7 TODO removed (en+ru); Readme config table added.
- ✅ **Verified in docker** (`xakki/laralog-php:8.3`): cs-check OK · phpstan L8 *No errors* ·
  phpunit OK (6 tests, 18 assertions). All 6 TODO markers removed from `src`.
- ⚠ **T5 Option A is NOT covered by the unit tests** (the suite doesn't trigger global PHP
  error/exception/shutdown handlers). It installs handlers chained to the framework's. Before
  enabling `capture_handlers` in any real app, exercise error / uncaught-exception / fatal
  paths and confirm Laravel's rendering + reporting (Sentry/Whoops) still fire.
- ⏳ For `done`: T5 manual verification + open question Q5 (`db_*` renames) + user sign-off.

---

## T5 design — open (caller / log-source classification)

Goal: stamp every record with *how* the log was triggered, so dashboards can split
direct app logs from PHP error/exception/shutdown paths (§4.3.1 `log_type`, §5 correlation).
Four origins to distinguish: **direct** call · **error handler** (`set_error_handler`) ·
**exception handler** (`set_exception_handler`) · **shutdown/fatal** (`register_shutdown_function`).

### Option A — explicit handlers registered by the package (service provider)
The package registers its own `set_error_handler` / `set_exception_handler` /
`register_shutdown_function` (chaining to any previously-registered ones), and each sets a
process-scoped flag (e.g. `self::$origin`) that `appendContext` reads and then clears.
- **(+)** Reliable and cheap — O(1), no backtrace scan per log.
- **(+)** Origin is *known*, not guessed; correct even when frames are stripped/inlined.
- **(+)** Natural home for B7 (reset `request_id` per entrypoint) and for converting PHP
  warnings/notices into structured logs.
- **(−)** Changes app bootstrap — the package now owns global handlers. Must chain to existing
  handlers (Laravel/Sentry/Whoops) or it breaks them. Order-of-registration sensitive.
- **(−)** Shutdown handler + opcache/fatal edge cases need care.
- **(−)** Slightly "magic" for a log *driver* to install global handlers — should be opt-in
  (`logger.capture_handlers=false` by default) so it never surprises a host app.

### Option B — backtrace sniffing inside `appendContext`
Inspect `debug_backtrace()` (already captured for `file`/`trace`) for marker frames whose
`function` is `{closure}` under Laravel's `HandleExceptions` / the registered handler.
- **(+)** Zero bootstrap change; nothing global installed; fully self-contained.
- **(+)** Reuses the backtrace already built — marginal extra cost.
- **(−)** Fragile: depends on Laravel internal frame names/structure → breaks across L10/11/12.
- **(−)** Heuristic — false negatives when frames are stripped by `$excludedPartials` (T1).
- **(−)** Hard to distinguish shutdown-fatal from a normal late call.

### Recommendation
**Hybrid, default-safe:** Option B as the always-on best-effort signal (no bootstrap change),
and Option A available behind `logger.capture_handlers=true` for apps that want authoritative
origin + structured PHP-error capture. Field name: keep `log_type` per §4.3.1 unless we adopt
OTel — then map to a documented value set (`direct|php_error|exception|shutdown`).
**Decision needed:** (a) ship B-only first, A later? or build the hybrid now? (b) confirm
`log_type` field name + allowed values.
