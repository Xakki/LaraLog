### Fix bugs found in architecture/code review of commit 1bd602a

**Criticality:** High

**TAGS:**
- bug-fix

**Description:**
Issues surfaced by the architectural + code review of `1bd602a`. These are *not* author
`TODO` markers (those are card `0001`) — they are defects with a clear, agreed fix.
Confidence labels: **[confirmed by reading]** vs **[verify at runtime]** (PHP could not be
run in the review env — only docker `make` targets exist).

**Problem / findings:**

- **B1 — `src/Tap/NoContext.php` PSR-4 namespace mismatch. [confirmed]**
  File is in `src/Tap/` but declares `namespace Xakki\LaraLog\Processor;`. Autoload maps
  `Xakki\LaraLog\` → `src/`, so the class `...\Processor\NoContext` is expected at
  `src/Processor/`. As written the tap cannot be referenced by a correct FQCN.
  → Fix: `namespace Xakki\LaraLog\Tap;` (and update any references).

- **B2 — `src/LogManager.php:159-165` dead `elseif` + enum comparison.**
  ```php
  if ($level->value === Level::Warning) { $limit = 5; }       // int === enum → always false
  elseif ($level->value === Level::Warning) { $limit = 10; }  // duplicate of the if → dead branch
  else { $limit = 20; }
  ```
  **[confirmed]** the `elseif` duplicates the `if` (copy-paste; intent per §3.7 table is
  `Level::Error` → 10). **[confirmed]** `$level->value` is an `int`, `Level::Warning` is an
  enum instance → `===` is always false → `$limit` is always 20.
  Line 158 `$level->value >= Level::Warning` has the same int-vs-enum shape; whether it
  evaluates "always false" at runtime is **[verify at runtime]** (depends on PHP's
  object-vs-int comparison), but the **fix is correct regardless**.
  → Fix: use `$level->value >= Level::Warning->value` (or Monolog's `Level::includes()` /
  `isHigherThan()`); set the three depths to 5/10/20 by level (ties into T1/T2 in card `0001`).

- **B3 — `src/SqlLogServiceProvider.php:49` dead truncation. [confirmed]**
  The loop builds a truncated `$bind[$k]=$v` (512-char guard), then
  `$bind = json_encode($query->bindings)` **overwrites it with the original untruncated
  bindings**. The guard never takes effect; raw long values are logged.
  → Fix: `$bind = json_encode($bind);`. (Also see §2.1 — bindings may contain secrets;
  consider redaction from card `0001` T3/T4.)

- **B4 — test namespace mismatch. [confirmed]**
  `tests/Unit/LogManagerTest.php` declares `namespace AppTests\Unit;` but `autoload-dev`
  maps `Xakki\LaraLogTests\` → `tests/`. Inconsistent with `AbstractTestCase`
  (`Xakki\LaraLogTests`). → Fix: `namespace Xakki\LaraLogTests\Unit;`.

- **B5 — `testInit` likely fails against current `appendContext`. [verify at runtime]**
  Expected context omits the always-added `request_id` (`appendContext` line 174) and the
  trace branch. Either the assertion is stale or B2 masks the trace. → Run `make phpunit`,
  then correct the test or the code so they agree.

- **B6 — CI does not run the test suite. [confirmed]**
  `.github/workflows/autotest.yml` runs only `phpstan` + `cs-check`; `composer phpunit`
  (in `make test`) is never executed in CI. So B5-class regressions ship unnoticed.
  → Fix: add a `phpunit` step to the workflow.

- **B7 — `request_id` leaks across queue jobs. [confirmed by reading; ties to §5.1]**
  `getOrCreateRequestId()` caches into `$_SERVER['HTTP_REQUEST_ID']` + `putenv` and is never
  reset. A long-running worker stamps every job with the *first* job's id, defeating the
  §5.1 correlation goal. → Fix: reset per job (hook queue events, cf. `QueueHeartBeat`), or
  don't persist for CLI/queue context. Coordinate with card `0001` T5.

- **B8 — `LOGGER_MEMORY_PEAK` constant bypassed + camelCase. [confirmed]**
  `src/const.php` defines `LOGGER_MEMORY_PEAK='memoryPeak'` / `LOGGER_MEMORY='memory'`
  (camelCase, violates §4.7), but `appendContext` writes literal `memory_peak` / `memory_usage`
  — the constants are dead. → Fix: write via the constants and set them to snake_case
  (`memory_peak` / `memory_usage`), aligning with card `0001` T6.

- **B9 — `env()` at log-time → fields vanish under `config:cache`. [confirmed pattern; verify per-var]**
  `ExtraProcessor::__invoke` runs per record and pulls `env('TIER'|'RELEASE_TAG'|'RELEASE_TIME'|
  'CONTAINER_NAME'|'HOST_IP'|'HOST_NAME')` + `config('app.version')`. Same pattern in
  `SqlLogServiceProvider` (`env('APP_DEBUG')`), `QueueHeartBeat` (`env('TICK_LOG')`),
  `LogManager` (`env('HTTP_REQUEST_ID')`). Two problems, both vs §4.2 ("extra is stable per process"):
  - **Correctness:** Laravel's `env()` returns **null** after `php artisan config:cache` (prod-standard)
    for any var not backed by a real OS env var. The host/release vars are passed as docker
    `environment:` in `transports/fluent-logging-example.yml` (real OS env → survive), but
    `env('APP_DEBUG')` in `SqlLogServiceProvider` and `config('app.version')` (not in default
    `config/app.php`) are the at-risk ones → SQL-binding capture silently disabled, `app_ver`
    null in prod. Verify each var's source; move config-derived values into a published config file.
  - **Architectural:** recomputing per-record contradicts §4.2 (compute once at boot, cache on the
    processor instance).

**Impact:**
- B1/B4: broken/inconsistent autoloading; B3/B7: incorrect or leaking log data (B3 also a
  secrets-leak path); B2: trace depth permanently wrong; B5/B6: regressions ship silently;
  B8: documented constants are a lie; B9: release/host/debug fields silently null in
  `config:cache`'d production — the most material correctness risk in the diff.

**Recommendation:**
Fix B1–B4, B8 (mechanical), wire B6 (CI phpunit step), then use green CI to resolve B5.
B7 coordinate with card `0001` T5 (caller/correlation work).

**Acceptance Criteria:**
- B1–B9 addressed; `make test` green locally **and** in CI (B6).
- B2 regression test: `testTraceDepthIsConfigurable` (depth 1 → `***` truncation marker). ✅
- B3: covered by **code inspection only** (one-token `$query->bindings`→`$bind` fix). A unit
  test needs a DB-backed `QueryExecuted` event (`DB::listen`), deferred — *AC amended* (was
  "regression test covering B3").
- No new `phpstan` (level 8) or `cs-check` violations. ✅

**Follow-ups noted during review (non-blocking):**
- `messageLen` stays camelCase even with `snake_case=true` — it's injected *after*
  `contextTypeCorrector`, so it bypasses normalization (minor §4.7 inconsistency). Same for
  the other package-added keys (`request_id`, `file`, `trace`, exception_*). Decide whether to
  snake the internal keys too (would be a field rename).
- **B7 is a leak fix, not full §5.1.** Each job gets a fresh `request_id` (no more reuse of the
  first job's id), but cross-queue *propagation* (dispatcher's id → job) is still TODO.

**Execution Log:**
- 2026-05-29 — quick mechanical fixes (committed on `feat/resolve-todos-and-review-fixes`):
  - **B1 ✅** `src/Tap/NoContext.php` → `namespace Xakki\LaraLog\Tap;` (verified no external refs).
  - **B3 ✅** `SqlLogServiceProvider.php:49` → `json_encode($bind)` (512-char truncation now effective).
  - **B4 ✅** `tests/Unit/LogManagerTest.php` → `namespace Xakki\LaraLogTests\Unit;`.
  - **B8 ✅** `const.php` `LOGGER_MEMORY='memory_usage'`, `LOGGER_MEMORY_PEAK='memory_peak'`;
    `LogManager` now emits via the constants. Output keys unchanged → **not breaking** for log
    consumers. Verified no external refs to the constants before changing their values.
  - ⏳ Not run: `make test` (no PHP in this env, docker-only) — **must run before commit**.
- 2026-05-29 — remaining fixes applied on branch `feat/resolve-todos-and-review-fixes`:
  - **B6 ✅** phpunit step added to `.github/workflows/autotest.yml`.
  - **B2 ✅** `LogManager::traceDepthForLevel()` replaces the broken int-vs-enum block
    (also removed the now-stale `@phpstan-ignore-next-line` — confirms the original comparison
    was knowingly suppressed). Depths 5/10/20, config-overridable.
  - **B7 ✅** `LogManager::resetRequestId()` + `JobProcessing` listener in `LaraLogServiceProvider`.
  - **B9 ✅** `ExtraProcessor` reads stable fields from `config('logger.*')` (config:cache-safe)
    once in the constructor; new `config/logger.php` maps them from env() at config-load time.
  - **B5 ✅** `testInit` rewritten to assert stable substrings; new tests added.
- ✅ **Verified in docker** (`xakki/laralog-php:8.3`): `cs-check` OK · `phpstan` L8 *No errors* ·
  `phpunit` OK (6 tests, 18 assertions). All B1–B9 addressed and green locally.
- ⏳ For `done`: confirm green **in CI** (push the branch — B6 added the phpunit step) + user sign-off.
- Note: B9's *correctness* half depends on the published `config/logger.php` (env captured by
  `config:cache`). If an app doesn't publish it, host/release fields fall back to null — documented.
