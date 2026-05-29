### Decouple log-shaping logic from Laravel globals (config/env/request once, not per-record)

**Criticality:** Medium (architectural; unblocks testability + fixes B9/B7 cleanly)

**TAGS:**
- tech-debt

**Description:**
Across the package, framework globals are called inline inside hot paths:
`config()`/`env()` in `LogManager::appendContext` & `ExtraProcessor::__invoke` (per record),
`env()` in `SqlLogServiceProvider`/`QueueHeartBeat`, `request()` in `BaseContext`,
`app()->basePath()` in `TraitFileTrace`. This is the root cause behind several findings, not a
style nit.

**Problem:**
1. **Per-record recomputation** contradicts spec §4.2 ("`extra` is stable per process"):
   `ExtraProcessor` rebuilds app/env/release/host on *every* log line.
2. **`env()` at runtime is fragile** (card `0002` B9): returns null after `php artisan
   config:cache` for any var not backed by a real OS env var.
3. **Not unit-testable in isolation** → the suite needs a full Laravel bootstrap (the reason
   PHP/`phpunit` can only run via docker, never as plain unit tests). The pure log-shaping
   logic (`appendContext`, `contextTypeCorrector`, `traceToString`, `trimLog`) is entangled
   with `config()`/`request()`/`$_SERVER`.
4. **`appendContext` has a dual contract** — it mutates `$context` by reference *and* returns
   the trimmed message. Ambiguous and hard to test.

**Impact:**
Silent field loss in prod (B9), cross-job correlation leak (B7), and a test suite that can't
exercise the core logic cheaply — so regressions like B5 slip through.

**Recommendation — incremental, lowest-risk first (this is a Laravel integration package, so
the goal is testable seams, NOT a framework-agnostic rewrite):**

1. **Config-once DTO.** Build an immutable `LogConfig` value object from `config('logger.*')`
   at service-provider boot; inject into `LogManager` & `ExtraProcessor` ctors. Removes
   `config()`/`env()` from hot paths. *Fixes B9 and §4.2 recomputation with minimal blast radius
   — do this step first, it's high value / low risk.*
2. **Compute process-stable `extra` once** in `ExtraProcessor::__construct` (from the DTO);
   `__invoke` only appends the precomputed array.
3. **Thin seams for request/IO** — a `RequestContext` (IP, request_id) abstraction instead of
   `request()` + raw `$_SERVER`/`putenv`. Natural home to fix **B7** (reset request_id per
   entrypoint) and to wire **T5** (card `0001`).
4. **Split `appendContext`'s dual role** — pure `enrichContext(Level,message,context): array`
   (no by-ref mutation) + separate message trimming. Pure → unit-testable without booting HTTP.
5. **Keep Laravel facades only at the edges** (service-provider wiring). Domain logic
   (enrich/typeCorrect/trace/trim/redact) becomes pure functions of inputs.

**Acceptance Criteria:**
- `ExtraProcessor` computes stable fields once; no `env()` in any per-record path.
- Core log-shaping logic has unit tests that run **without** a full app bootstrap.
- B9 closed (config-derived values survive `config:cache`); B7 closed via the request seam.
- `make test` green; no `phpstan` (level 8) / `cs-check` regressions.

**Open questions:**
- Scope/sequencing: ship step 1 (config DTO) as a standalone PR first, or the whole refactor
  together? (Recommend: step 1 alone first — it closes B9 fast.)
- Do we want a published `config/logger.php` stub (artisan `vendor:publish`), or keep config
  conventions doc-only in `Readme.md`?
- Absorb B7/B9 from card `0002` into this card, or fix them tactically there and refactor later?
