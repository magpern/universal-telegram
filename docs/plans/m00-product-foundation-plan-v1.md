# M00 Implementation Plan — Product Foundation

Status: Frozen — implementation authorized. This document is self-contained: it does not require a reader to consult any earlier conversation draft.

Implements: `docs/milestones/m00-product-foundation.md`. Relies on ADR-0001 through ADR-0004 and ADR-0011 (already accepted in the project's documentation baseline). Proposes ADR-0005 through ADR-0010, full text included below and materialized alongside this plan under `docs/adr/`.

## 1. Repository findings at plan-drafting time

The repository contains only the accepted documentation baseline (governance, milestone charters, ADR-0001 through ADR-0004, testing strategy templates, closure template) committed as a single documentation-only commit on `main`. No code, no `composer.json`, no CI configuration, and no `.git` history beyond that one commit exist yet.

Four sibling WordPress plugins built by the same Product Owner on the same VPS were surveyed as architectural precedent: `universal-multicurrency`, `universal-geo-context`, `ai-multilingual`, and `wc-inventory-overview`. The first three use an identical composition pattern: a PSR-4 namespace mapped to `src/`, a `final class Plugin` singleton composition root (`instance()` plus an idempotent `init(): void` behind a `private bool $booted` flag, no dependency-injection container, constructor-injected services that register their own hooks), a `magpern/` Composer vendor namespace, `"php": ">=8.1"`, matching `phpunit.xml.dist`/`phpunit-integration.xml.dist` skeletons, and a `phpcs.xml.dist` using `WordPress-Extra` plus `WooCommerce-Core` with a `PrefixAllGlobals` rule keyed to each plugin's own short hook prefix. `wc-inventory-overview` is an older, pre-PSR-4, flat-`includes/`-directory design with no namespace at all, and is treated as historical precedent only, not the pattern to follow for a new plugin. `universal-geo-context` is the closest precedent for this plugin's product shape (WooCommerce is a genuinely optional integration, not the plugin's core purpose). `ai-multilingual` is the closest precedent for this plugin's complexity (custom database tables, background jobs via Action Scheduler, a capability grant/revoke lifecycle, and an encrypted-credential vault), and is used as primary evidence wherever this plugin needs comparable machinery.

Current version baselines were verified against live, authoritative sources at drafting time: WordPress 7.1 is the current stable release running in this project's authoritative development environment; WordPress 6.9 ("Gene", released 2025-12-02) is a real, existing prior-major release, superseded by 7.0 and then 7.1; WooCommerce's own published server-requirements documentation states it needs WordPress 6.9 or greater and PHP 8.3 or greater for its own current release. This means a plugin PHP floor of 8.1 is not weakened by WooCommerce's presence: any WooCommerce-present environment already exceeds an 8.1 floor on its own, so the floor only meaningfully constrains WordPress-only installations.

The exact proposed Composer dependency set for M00 (production: `php >=8.1`, `woocommerce/action-scheduler ^4.1`; development: `phpunit/phpunit ^9.6`, `yoast/phpunit-polyfills ^2.0`, `wp-coding-standards/wpcs ^3.1`, `woocommerce/woocommerce-sniffs ^1.0`, `dealerdirect/phpcodesniffer-composer-installer ^1.0`, `phpstan/phpstan ^2.0`, `szepeviktor/phpstan-wordpress ^2.0`) was installed under a real PHP 8.1.34 interpreter inside an isolated, disposable Docker container, entirely outside this repository. `composer install` completed with exit code 0, resolving 42 packages with no conflicts, including `woocommerce/action-scheduler` at `4.1.0` and `szepeviktor/phpstan-wordpress` at `2.0.3`. A follow-up smoke test — a trivial `UniversalTelegram\Smoke\Smoke` class analyzed with PHPStan at level 5, including the WordPress-stubs extension — passed with zero errors and no baseline file. This is treated as settled, verified evidence, not a conditional assumption: PHP 8.1 is a published compatibility floor for this plan, and any future change to it is a change to a published contract requiring a superseding plan, Master Architect review, Product Owner approval, and a new ADR, exactly as any other previously-accepted decision that must change.

The exact installed `woocommerce/action-scheduler` 4.1.0 package was also read directly, in full, to settle its integration contract from source rather than from assumption. The library's own bootstrap file, `action-scheduler.php`, registers itself on WordPress's `plugins_loaded` action at priority 0, and initializes whichever bundled copy has the highest registered version number at priority 1, via `ActionScheduler_Versions::initialize_latest_version`. `ActionScheduler::init()` only completes synchronously if WordPress's `init` action has already fired; otherwise its own readiness is deferred to `init` priority 1, at which point it fires `do_action('action_scheduler_init')` — documented in its own source as the point after which it is safe to use the procedural API. Every one of the library's procedural functions (`as_enqueue_async_action`, `as_schedule_single_action`, `as_unschedule_all_actions`, `as_has_scheduled_action`, `as_get_scheduled_actions`) begins with a call to `ActionScheduler::is_initialized()` and returns a safe, documented sentinel value (`0`, `false`, or an empty array, depending on the function) rather than throwing or fatally erroring if called before the library is ready. The library's own data-store class, `ActionScheduler_DBStore`, exposes a public `query_actions($query, $query_type)` method supporting group and status filtering with `per_page` pagination, and a public `delete_action($action_id)` method that removes the action row and then fires `do_action('action_scheduler_deleted_action', $action_id)`. The library's own default logger, `ActionScheduler_DBLogger`, registers itself on that exact hook in its constructor to remove that action's own log rows — meaning a single call to `delete_action()` on the standard, default-active logger cascades to remove both the action row and its associated log rows, with no separate logger call required. The library's own official WP-CLI delete command (`Action_Scheduler\WP_CLI\Action\Delete_Command`) uses exactly this `delete_action()` call, wrapped in a per-ID try/catch that tolerates and continues past an already-deleted row, as its own canonical bulk-deletion pattern — this plan's own historical-cleanup design (below) follows that same official pattern precisely, rather than inventing a new one.

## 2. Settled assumptions and decisions

The following are settled for this plan, each with the evidence behind it:

- The plugin follows the three-sibling PSR-4 composition pattern, not `wc-inventory-overview`'s flat, pre-PSR-4 pattern. Evidence: PSR-4 is the unanimous convention in every plugin built after `wc-inventory-overview`, and that plugin's own architecture-decision-record discipline is the weakest of the four surveyed.
- PHP floor `>=8.1`, WordPress floor `6.9`, WordPress `Tested up to: 7.1`. Evidence: the isolated dependency-resolution check above; WooCommerce's own current floor already exceeds 8.1, so this plugin's floor is not the binding constraint in a WooCommerce-present environment; WordPress 6.9 is the prior-major release still in active use, and matches WooCommerce's own stated WordPress floor exactly, so this plugin's floor does not diverge from WooCommerce's own posture.
- Composer package `magpern/universal-telegram`, `type: wordpress-plugin`, `license: GPL-2.0-or-later`. Evidence: unanimous vendor-namespace convention among the three PSR-4 siblings.
- Hook and option prefix `universal_telegram_`, derived directly from the plugin's own slug (already fixed by ADR-0002). Evidence: `universal-geo-context` uses an equivalent spelled-out-from-slug prefix (`universal_geo_`); a prefix derived from the ADR-0002 slug rather than an unrelated acronym is the more directly evidence-backed choice, and avoids the ambiguity a short, generic acronym would introduce on a public plugin.
- `woocommerce/action-scheduler ^4.1` is bundled as a direct Composer production dependency of this plugin, not relied upon via WooCommerce's own bundled copy. Evidence: WooCommerce is an optional integration (ADR-0003), and Telegram dispatch reliability is core plugin functionality that must work identically whether WooCommerce is present or not. Relying on WooCommerce's own copy would silently remove queueing entirely on WordPress-only installs. The exact installed version was confirmed, from source, to coexist safely with any other bundler (including WooCommerce, should it later be activated on the same site) via the library's own highest-version-wins registration mechanism, and to require only PHP `>=7.2`, comfortably inside this plugin's own floor.
- Secret storage uses AES-256-GCM, not AES-256-CBC, and never erases stored ciphertext automatically on a decryption failure. Full contract in section 4.
- Migration locking uses single, atomic conditional SQL statements for every state transition, never a separate read followed by a separate write. Full contract in section 4.
- A migration failure discovered outside of plugin activation must never crash an ordinary WordPress request, and an Action Scheduler job encountered while the plugin's schema is unavailable must never be allowed to be marked successful merely because its business logic was skipped. Full contract in section 4.
- Historical Action Scheduler action and log cleanup, when a site operator opts into full data removal at uninstall, uses the library's own public store API (`query_actions()` plus `delete_action()`, paginated, exactly matching the library's own official WP-CLI delete command's pattern) — never a raw SQL statement against Action Scheduler's own shared tables, which other plugins, including WooCommerce, may also be using.
- Every WooCommerce-present configuration referenced anywhere in this plan — Work Packages 5, 8, 10, and 11, and every corresponding CI job and package-test command — is pinned to the single, concrete version `11.0.1`. Evidence: confirmed as WooCommerce's current stable release, with a changelog entry dated 2026-08-10, via the plugin's own official listing on wordpress.org at drafting time. No work package leaves this version as a placeholder to be resolved later; the same exact version string is used consistently everywhere a WooCommerce-present configuration is installed or tested.
- Formal, independent manual acceptance testing is deferred until milestone M10, per ADR-0011. M00's own quality evidence is the frozen plan, code review, mandatory automated validation, and green CI, not a manual acceptance session. This does not weaken any automated test requirement in this plan.

## 3. Technical identity

Display name: "Telegram Operations Hub for WordPress" (fixed by ADR-0002; used in the WordPress Plugin Name header, `readme.txt` title, and human-facing copy only). Slug, plugin folder, GitHub repository name, and text domain: `universal-telegram` (fixed by ADR-0002). PHP namespace root: `UniversalTelegram\`, mapped to `src/`; test namespace `UniversalTelegram\Tests\`, mapped to `tests/`. Hook and option prefix: `universal_telegram_`. Plugin version constant: `UNIVERSAL_TELEGRAM_VERSION`. Plugin main-file constant: `UNIVERSAL_TELEGRAM_PLUGIN_FILE`. Composer package: `magpern/universal-telegram`. License: GPL-2.0-or-later, recorded in a `LICENSE` file using the standard license text, matching the plugin header and `composer.json`.

## 4. Architectural decisions

### 4.1 Composition root and product module boundaries

The plugin's composition root is `Core\Plugin`, a `final class` singleton: `Plugin::instance()` returns the single shared instance, and `init(): void` is idempotent, guarded by a `private bool $booted` flag, matching the pattern already proven across this Product Owner's other plugins. There is no dependency-injection container; every service is constructed explicitly and wired by hand inside `init()`, and each service registers its own WordPress hooks from its own constructor or an explicit `register()` method it exposes.

Thirteen top-level product boundaries are authoritative for this plugin, exactly as follows: `Core`, `Persistence`, `Queue`, `Audit`, `Privacy`, `Events`, `Automations`, `Telegram`, `Conversations`, `ChatWidget`, `AI`, `Administration`, `Integrations`. Later-milestone concerns are subdomains of these thirteen, never additional top-level boundaries: WooCommerce-specific event coverage (M03) and visitor/browser events (M04) are subdomains of `Events`; operator workflow (M07) is a subdomain of `Conversations`; the administrative Telegram bot (M08) is a subdomain of `Telegram`; digests and operational intelligence (M11) are a subdomain of `Automations`. `Security`, `Configuration`, `Lifecycle`, and `Diagnostics` are internal components, not additional top-level boundaries: `Security`, `Configuration`, and `Lifecycle` live inside `Core`, and `Diagnostics` lives inside `Administration`, since the master plan's own description of the administration interface explicitly names "Diagnostics" as one of its own sections.

M00 writes real, functional code only for the boundaries its own charter requires: `Core` (with its internal `Configuration`, `Lifecycle`, `Security`, and `Capabilities` components), `Persistence`, `Queue`, `Audit`, `Privacy`, `Integrations`, and the `Diagnostics` subdomain of `Administration`. The remaining six boundaries — `Events`, `Automations`, `Telegram`, `Conversations`, `ChatWidget`, and `AI` — receive no files at all at M00. Their ownership and eventual subdomain layout are recorded in a `docs/ARCHITECTURE.md` reference document, and a structural guard test asserts that none of these six directories exists under `src/` until the owning milestone's own frozen plan authorizes creating it. This replaces an earlier, rejected design that created twelve empty placeholder classes purely to satisfy their own structural test — dead code with no other function, which the milestone's own charter explicitly excludes.

### 4.2 Queue implementation, failure semantics, and degraded-mode correctness

The plugin bundles `woocommerce/action-scheduler ^4.1` as a direct Composer production dependency. Its bootstrap file, `vendor/woocommerce/action-scheduler/action-scheduler.php`, is required unconditionally as top-level code in the plugin's own main file, `universal-telegram.php`, immediately after `vendor/autoload.php` and before any `add_action()` call. Because WordPress includes every active plugin's main file in full before firing `plugins_loaded` on any of them, this placement inherently satisfies loading the library before its own `plugins_loaded` priority-0 self-registration — it is a structural consequence of where the `require_once` statement sits in the file, not a timing race that needs separate management.

A job is represented by `Queue\JobEnvelope`, an immutable value object carrying a stable `job_id` generated once via `wp_generate_uuid4()` and threaded unchanged through every retry attempt of the same logical job, a `job_type` string identifying which registered handler owns it, an `attempt` counter, and a payload. The payload is subject to a fail-closed classification policy, enforced centrally in `JobEnvelope`'s own constructor rather than left to caller discipline: the constructor requires an explicit classification map, using the same four-level scheme defined for the plugin's privacy model (`PUBLIC`, `INTERNAL`, `SENSITIVE`, `SECRET`), covering every payload field with no exceptions. Any field left unclassified, or classified `SENSITIVE` or `SECRET`, causes the constructor to throw `Queue\PayloadRejectedException` immediately, to the calling code — a loud, catchable, development-time failure a job-handler author must fix, not an environmental failure to be silently logged. In practice this means Action Scheduler's own persisted action arguments may only ever contain non-sensitive metadata and opaque identifiers (a conversation identifier, a WordPress post ID, the name of a credential context); a job handler that actually needs sensitive business data — a decrypted bot token, real message content — re-fetches it at execution time using those identifiers, so it is never written into Action Scheduler's own unencrypted, longer-retained storage. Any future genuine need to persist encrypted sensitive data inside a queue payload itself, rather than rehydrating it at execution time, is out of scope for this design and requires its own future architecture decision.

Job dispatch never blocks or breaks the originating WordPress request under any circumstance. `Queue\Dispatcher::enqueue()` returns a `Queue\DispatchResult` value object — never `void`, and the method itself never throws to its caller — carrying a state (`SCHEDULED`, `REJECTED_PAYLOAD`, `SCHEMA_UNAVAILABLE`, or `FAILED`), an optional Action Scheduler action identifier, and an optional stable error code. Internally, `enqueue()` wraps the call to `as_enqueue_async_action()` in a try/catch block and additionally treats a returned action identifier of zero or less as a failure identical to a caught exception, since the library's own documented behavior returns zero, rather than throwing, for several of its own internal failure paths. If the plugin's own schema is currently unavailable (see below), `enqueue()` refuses to dispatch at all, without ever calling into Action Scheduler, and returns state `SCHEMA_UNAVAILABLE`. Any exception encountered while attempting to record this outcome is itself caught by an inner, guaranteed-non-throwing fallback logger that accepts only a value from a small, fixed set of stable error codes (never an exception message, and never a payload value), so that even a failure in the plugin's own logging infrastructure can never propagate back into the request that called `enqueue()`.

Job execution is handled by `Queue\WorkerRunner`, the single callback registered against the Action Scheduler hook `universal_telegram_run_job`, in group `universal-telegram`. `WorkerRunner` and the internal `Queue\HandlerRegistry` it consults are always registered, in every request, regardless of whether the plugin's own schema is currently available — this is a deliberate, load-bearing correction to an earlier design. An earlier draft of this plan avoided registering `WorkerRunner`'s hook callback at all while the schema was degraded, reasoning that no business logic should run against a broken schema; review found this created a serious correctness defect instead: if Action Scheduler's own cron-driven runner picks up an already-scheduled `universal_telegram_run_job` action while nothing at all is listening for that hook, WordPress's own hook system does nothing, Action Scheduler observes no exception, and marks that action `complete` — meaning a real job could be silently recorded as successfully done while never actually running, for the entire duration of any degraded window. The corrected design instead always registers the callback, and moves the schema-availability check inside it, to the very first line of `WorkerRunner::run()`, before any job handler is ever looked up or invoked.

When `WorkerRunner::run()` finds the plugin's schema unavailable, it does not invoke any job handler — no business logic executes. It instead synthesizes a typed `Queue\SchemaUnavailableException` and passes it through exactly the same failure-handling sequence used for any other worker exception, described next, so a schema-unavailable execution is treated as an ordinary retryable worker failure, not a special case with its own separate bookkeeping path.

On any exception — whether thrown by an actual job handler or synthesized for schema unavailability — `WorkerRunner` performs four independently exception-guarded steps, structured so that a failure inside any one step can never suppress the steps after it:

- It attempts to record exactly one `queue_job_attempt_failed` entry through the plugin's audit-writing path. If the plugin's own audit table happens to be unavailable — the same underlying condition that produced the schema-unavailable exception in the first place — this write itself fails, and that failure is caught internally; the plugin falls back to its guaranteed-non-throwing, stable-error-code-only logger rather than attempting to write anything containing an exception message or payload value.
- It consults `Queue\RetryPolicy::shouldRetry()`. If evaluating the policy itself somehow throws, the runner defaults to treating the job as retry-eligible, since silently dropping a job is worse than attempting one extra retry, bounded in any case by the policy's own hard attempt ceiling.
- If eligible, it attempts to self-schedule the next attempt via `as_schedule_single_action()`, again checking for both a thrown exception and a returned action identifier of zero or less; if that scheduling attempt itself fails, it records a distinct `queue_job_reschedule_failed` entry (again falling back to the stable-code logger if the audit write itself fails), so a failure to reschedule is separately identifiable from an ordinary terminal failure rather than looking identical to one. If not eligible — the maximum attempt count has been reached — it records a `queue_job_terminal_failure` entry instead.
- It always rethrows the original exception afterward, regardless of how the preceding three steps went, so that Action Scheduler's own bookkeeping for this specific attempt is correctly marked failed, never successful. Action Scheduler's own `action_scheduler_failed_action` hook is never separately used for recording purposes; `WorkerRunner` is the sole writer of failure-audit entries, which avoids the risk of duplicate records by construction rather than by after-the-fact deduplication logic.

`Queue\RetryPolicy` is entirely generic: a maximum of five attempts by default, exponential backoff with jitter (a thirty-second base delay, a nine-hundred-second cap), and no awareness of any specific provider's HTTP status codes or failure semantics — Telegram-specific and AI-provider-specific circuit breaking, dead-letter handling beyond the generic terminal-failure record described above, and health-alert notifications are explicitly out of scope for M00, reserved for the milestones that first dispatch a real provider call. To make backoff timing deterministically testable, `RetryPolicy`'s constructor accepts optional clock and jitter callables, defaulting to `time()` and a small random-integer closure in production, and overridden with fixed-value closures in tests so exact expected delays can be asserted rather than only checked to fall within a range.

`Queue\HandlerRegistry`, owned by `Core\Plugin`, is an internal `job_type` to handler map populated only by `Plugin::init()` itself; it is explicitly not a public extension point — no `do_action` or `apply_filters` exists for a third party to register a handler through it. M00 registers exactly one real handler through it: the bounded diagnostic self-test described in section 4.9, which is the concrete, working proof that the registry, the dispatcher, and the worker runner function correctly end to end, rather than a documented contract with nothing yet implemented against it. `Queue\QueueHealth` exposes pending and failed action counts, scoped to the plugin's own Action Scheduler group, for use by the diagnostics surface.

### 4.3 Persistence, atomic migration locking, and safe degraded mode

`Persistence\Migrator` implements schema changes as numbered, ordered `step_N_*` methods, executing raw `$wpdb->query()` data-definition statements directly, never WordPress core's `dbDelta()` helper — a sibling plugin in this Product Owner's own fleet documents having encountered `dbDelta()`'s known limitation of silently dropping certain composite or prefix-length index declarations, and this plugin's own schema is expected to grow tables with exactly that kind of indexing from the next milestone onward. Table creation uses `CREATE TABLE IF NOT EXISTS`, a clause supported extremely broadly across the database versions a WordPress installation may run, making a step safe to re-run in full after a partial failure. Any future step that needs to add a column does not rely on `ADD COLUMN IF NOT EXISTS`, since that specific clause's support is not guaranteed across every database version a WordPress installation may run; instead it first queries `INFORMATION_SCHEMA.COLUMNS` to check whether the column already exists, and issues a plain, universally-compatible `ALTER TABLE ... ADD COLUMN` statement only when it is genuinely absent. Every `CREATE TABLE` statement uses `$wpdb->get_charset_collate()` so the resulting table matches the site's own configured charset and collation rather than a hardcoded value. Every step is paired with its own `verify_step_N(): bool` method, which re-queries `INFORMATION_SCHEMA` after the step's statements run to confirm the schema actually matches what the step intended — not merely that no database error was returned, which alone would be insufficient for any step containing more than one data-definition statement, since such statements each commit individually and a step's second statement could fail after its first already succeeded. The schema-version option, `universal_telegram_db_version`, kept deliberately separate from the plugin's own settings option so that resetting one can never be mistaken for resetting the other, advances only immediately after both a step's statements and its postcondition check succeed, never partially.

Concurrent migration attempts are coordinated by `Persistence\MigrationLock`, using a genuinely atomic single SQL statement for every state transition, never a separate read followed by a separate write. A fresh acquisition attempt issues a plain `INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')`; because `option_name` carries a unique index, this statement can only ever succeed once for the lock's option name, which is what makes acquisition atomic — the database's own constraint enforcement provides the guarantee, not any coordination at the application level. On success, the caller receives a `Persistence\MigrationLockHandle` carrying both a freshly generated token and the exact value string that was written. If the insert fails because the row already exists, the current value is read and its embedded timestamp checked; if it is older than a five-minute staleness threshold, reclamation is attempted via a single atomic compare-and-swap: `UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s`, where the final comparison uses the exact value just read. If exactly one row is affected, the swap succeeded and the caller now holds a new handle; if zero rows are affected, some other process changed the row between the read and this statement, and the caller does not proceed as though it holds the lock. Releasing a held lock issues a single atomic `DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s`, using the exact value string recorded in the caller's own handle; if zero rows are affected, the row no longer matches what the caller holds — because some other process has already reclaimed it as stale — and the release is treated as a safe no-op, never an error. Every one of these raw SQL writes is followed immediately by an explicit `wp_cache_delete()` call against WordPress's own options cache, since raw SQL bypasses the caching layer WordPress's own option functions maintain, and any code elsewhere in the plugin reading the lock's current value through `get_option()` would otherwise see a stale cached value after one of these writes.

This design was checked against the specific interleaving that motivated the atomic-statement requirement: a first process acquires the lock, its value recorded as a token paired with an early timestamp; time passes and the lock becomes stale; a second process's acquisition attempt finds the stale value, and its compare-and-swap update succeeds, since the row currently holds exactly the value the second process just read, leaving the second process holding a new token as the lock's sole current value. The first process, unaware any of this has happened, later calls its own release using its own original, now-stale value; the delete statement's `WHERE option_value = %s` clause no longer matches the row's current contents, so the statement affects zero rows, and the second process's lock is left completely untouched. This scenario is one of the required integration tests for this component, described in the work packages below.

A migration failure discovered during an ordinary post-activation request — a frontend page load, a REST or AJAX request, a WordPress cron event, or an Action-Scheduler-driven job execution — must never crash that request. Activation-time failure may still block activation itself, surfaced through WordPress's own native activation-failure screen, since a fresh activation failing outright is an expected, contained outcome. But `Core\Plugin::init()`, hooked at `plugins_loaded` and therefore running on every one of those request types, wraps its own call to `Migrator::maybeMigrate()` in a try/catch block; on a caught `Persistence\MigrationFailedException`, a `Persistence\SchemaHealth` service, constructed once per request, is marked unavailable for the remainder of that request only. This state is never persisted anywhere beyond the current request — it is recomputed fresh on every subsequent request by re-attempting the migration, so recovery from a transient cause is automatic, with no separate "clear degraded state" step needed anywhere.

Rather than conditionally deciding, inside the composition root, which subsystems to construct based on whether the schema is currently available — the approach an earlier draft of this plan took, and which produced the worker-runner defect described in section 4.2 — every M00 service is now always constructed and always wired in `Core\Plugin::init()`, regardless of schema availability. Instead, each individual operation that actually touches the database checks `Persistence\SchemaHealth::isAvailable()`, or is itself defensively wrapped, at its own specific point of use: `Queue\Dispatcher::enqueue()` refuses to dispatch a new job and returns state `SCHEMA_UNAVAILABLE`; `Queue\WorkerRunner::run()` refuses to invoke a job handler and instead raises the schema-unavailable condition through its own normal, already-robust failure-handling sequence, described in section 4.2; `Administration\Diagnostics\DiagnosticsPage` remains fully reachable and renders a degraded-schema notice in place of its normal report, since it is specifically the mechanism an administrator needs to be able to see that something is wrong; and the diagnostic self-test control on that same page, described in section 4.9, is hidden entirely while dispatch is disabled, since offering a control that could not functionally work would be confusing rather than useful. This uniform rule — every service is always wired, every database-touching operation is individually guarded at its own call site — is deliberately chosen over selective construction specifically because selective construction is what allowed a WordPress hook to end up silently unregistered in the first place.

Wherever a degraded-schema condition is reported to any surface outside the plugin's own internal state — an administrator notice, the diagnostics page, or the guaranteed-non-throwing fallback logger — only a small, fixed, stable failure code (`Persistence\MigrationFailureCode`) ever reaches that surface. The raw underlying database error is never rendered to an administrator, never shown on the diagnostics page, and never written to any log the plugin itself controls, mirroring the same stable-code-only discipline already established for the queue's own failure reporting in section 4.2.

Network-wide multisite activation — activating the plugin across an entire network at once, through Network Admin's "Network Activate" action — is explicitly refused, not partially supported. `Core\Lifecycle\Activator::activate()` inspects the `$network_wide` parameter WordPress itself passes to the activation callback, and if true, calls `wp_die()` with a clear explanation and performs no schema provisioning at all — an unambiguous refusal rather than an unclear partial state. Activating the plugin for a single site within a multisite network, the ordinary per-site activation path, is fully supported and behaves identically to a non-multisite installation, since WordPress core already scopes `$wpdb->prefix` correctly to the current site in that case, with no special handling required.

The plugin's own single table remains entirely independent of Action Scheduler's own self-managed tables at all times; nothing in this plugin's own migration framework ever creates, alters, or drops any table Action Scheduler itself owns.

### 4.4 Audit logging and privacy classification

`Privacy\Classification` defines four levels: `PUBLIC`, `INTERNAL`, `SENSITIVE`, and `SECRET`. `Privacy\Redactor::redact()` walks a data structure, including nested arrays, matched against an explicit classification map using dot-notation keys for nested paths, stripping any field classified `SECRET` entirely and masking any field classified `SENSITIVE`. Any field encountered at any depth that has no corresponding entry in the classification map is rejected — removed from the output entirely — never passed through unchanged, and never defaulted to `PUBLIC`; this fail-closed behavior for both nested and unclassified fields is a deliberate, load-bearing property of the redaction model, not an incidental default. This mechanism remains internal to the plugin at M00: there is no public registration hook allowing a third party to extend the classification map, since nothing at M00 yet needs one, and building one now would be exactly the kind of speculative public contract the milestone's own charter excludes; it becomes a public extension point only when a later milestone that genuinely needs one introduces its own architecture decision for it.

`Audit\AuditLogger::record()` requires a classification map as a mandatory constructor and method parameter — there is no overload accepting an unclassified context — and internally calls `Privacy\Redactor::redact()` itself before persisting anything, so redaction is enforced centrally, in one place, and cannot be skipped by a caller that forgets to pre-redact its own data. `Audit\AuditLogRepository` is the corresponding read path. Both classes are introduced together with `Privacy\Classification` and `Privacy\Redactor` in the same work package, since `AuditLogger`'s own constructor genuinely depends on `Redactor`'s presence, and introducing the dependent class in a separate, later work package than its own dependency would leave an interim commit whose code cannot actually compile or run.

The plugin's single database table, `{$wpdb->prefix}universal_telegram_audit_log`, has the following columns: an auto-incrementing unsigned big-integer primary key `id`; `occurred_at`, a datetime; `actor_type`, a short string identifying the kind of actor (a WordPress user, the system itself, or a future Telegram operator); a nullable `actor_id`; `action`, a string naming what happened; `context`, nullable long text holding a JSON-encoded, already-redacted payload; and `privacy_classification`, a short string recording the overall sensitivity of the entry itself. It carries indexes on `occurred_at` and on `action`, to support the query patterns the diagnostics surface and any future administration screen will need.

### 4.5 Secret storage and fail-closed key handling

`Core\Security\CredentialVault` uses AES-256-GCM, an authenticated encryption mode, rather than an unauthenticated mode such as CBC — a deliberate improvement over a comparable design already present in this Product Owner's own fleet, since this plugin's own milestone charter explicitly requires fail-closed secret handling, and an earlier precedent's own hardcoded fallback key directly contradicts that requirement. A fresh, cryptographically random twelve-byte nonce is generated for every single encryption call and never reused. The authentication tag is sixteen bytes, requested explicitly rather than relied upon as an implicit default. The caller-supplied context string — identifying what is being encrypted, such as a future credential slot's name — is used as authenticated additional data, cryptographically binding a given ciphertext to the specific thing it was encrypted for; attempting to decrypt a ciphertext under a different context than the one it was encrypted under fails authentication even with the correct key, and this behavior is directly unit-tested.

Stored ciphertext takes the form of a fixed literal prefix identifying the envelope format version, followed by a single byte recording which of three key-derivation tiers produced the key material used, followed by the nonce, the authentication tag, and the ciphertext itself, all base64-encoded together. The envelope-version prefix and the per-ciphertext key-source byte are deliberately distinct concepts: the envelope version would change only if the ciphertext's own layout changed, for instance by moving to a different cipher in some future revision, while the key-source byte can differ from one ciphertext to the next under the same envelope version, depending on which key-derivation tier happened to resolve at the moment that specific value was encrypted.

Key resolution proceeds through three tiers, in order. The first tier is an explicit `UNIVERSAL_TELEGRAM_CREDENTIAL_KEY` constant, if defined; it must be exactly a sixty-four character hexadecimal string, representing thirty-two raw bytes of key material used directly with no further hashing, and if the constant is defined but does not match this exact format, the vault fails closed immediately — it does not fall through to a later tier, since an explicitly configured but malformed key is treated as an operator error to surface, not as though no key had been configured at all. The second tier concatenates all four of WordPress's own `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, and `NONCE_KEY` constants, if every one of them is defined and none of them still holds WordPress's own shipped default placeholder text, and hashes the result with SHA-256. The third and final tier hashes WordPress's own `wp_salt('auth')` value with SHA-256. If none of the three tiers resolves to usable key material at all, the vault fails closed, throwing a typed `CredentialUnavailableException`; there is no fourth, always-available, hardcoded fallback key anywhere in this plugin's production code, and the only way a deterministic key reaches the vault outside of these three tiers is through the same explicit constant, defined only inside a test's own bootstrap file, satisfying the milestone charter's own requirement that a deterministic fallback key may be injected only by tests.

A decryption failure — most commonly, an authentication-tag mismatch after WordPress's own salts have been rotated, invalidating whichever tier's key material previously produced a given ciphertext — never erases or otherwise modifies the stored ciphertext itself. `decrypt()` instead returns a typed result carrying one of three states: available, meaning the plaintext was successfully recovered; invalidated, meaning a ciphertext exists but could not be authenticated under the currently resolved key; or unavailable, meaning no key material could be resolved at all. Only an explicit operator action — re-saving a credential through whichever future feature actually stores one — ever overwrites a stored ciphertext; the vault itself never does. `CredentialVault::reencrypt()` decrypts a value using either the currently resolved key or an explicitly supplied override key, then re-encrypts it under the currently resolved key — the primitive a future rotation workflow would use to migrate a stored value off an old key's material once an operator can supply that old material directly. M00 implements and thoroughly unit-tests this primitive; it does not ship an end-to-end rotation command, since no milestone before M01 stores a real credential for such a command to operate on, and building one now would be speculative.

### 4.6 Capability model

One WordPress capability is introduced at M00: `Core\Capabilities\CapabilityRegistrar` defines and owns `universal_telegram_manage`, granted to the `administrator` role on plugin activation and revoked from every role unconditionally on uninstall, independent of whichever data-retention choice an operator has made. This capability gates a genuine, rendered administration screen — the diagnostics page described in section 4.9 — through WordPress core's own `add_menu_page()` capability parameter, so an unauthorized user is denied by WordPress itself, not merely by an assertion in this plan's own prose. The page's one interactive control independently re-verifies both this capability and a nonce inside its own request handler, not only at the point the menu item itself is registered, closing the gap between "the menu item is hidden" and "the underlying action is actually protected against a direct, forged request." Later milestones — operator identity in M07, bot-command authorization in M08 — are expected to extend this model by registering their own additional capability constants through the same registrar's grant-and-revoke pattern, rather than inventing a separate mechanism.

### 4.7 WooCommerce presence detection

`Integrations\WooCommerce\WooCommerceSupport::isActive(): bool` wraps a check for the `WooCommerce` class's existence, together with a minimal version-floor check, and is the single place every later module that might need to behave differently in WooCommerce's presence or absence should ask the question, rather than each reimplementing its own check. The plugin's bootstrap file declares compatibility with WooCommerce's High-Performance Order Storage feature unconditionally, through the standard `before_woocommerce_init` hook, since doing so is safe and free even though M00 itself never touches an order; it does not declare compatibility with the Cart and Checkout blocks feature, since nothing in this plugin through milestone M07 ever touches cart or checkout at all, and declaring compatibility with a surface the plugin genuinely does not use would be misleading rather than merely harmless. The plugin's own header carries no `Requires Plugins: woocommerce` field, consistent with WooCommerce being a genuinely optional integration under ADR-0003.

### 4.8 Configuration storage

All plugin configuration lives in a single serialized option, `universal_telegram_settings`, registered through the WordPress Settings API under group `universal_telegram_settings_group` — matching the single-option-array convention already proven across every sibling plugin surveyed, rather than a proliferation of individual options. The schema-version option described in section 4.3 is kept deliberately separate from this settings option.

### 4.9 Administration boundary: the diagnostics page and its bounded self-test

`Administration\Diagnostics\DiagnosticsPage` is the plugin's only administration screen at M00, registered via `add_menu_page()` gated on the `universal_telegram_manage` capability. It renders `Administration\Diagnostics\DiagnosticsReport`: plugin, PHP, WordPress, and WooCommerce-presence version information; the queue's own pending and failed action counts, scoped to the plugin's own group; and a short list of the most recent audit-log entries, already passed through the redaction path described in section 4.4. Every value the page renders — every audit entry, every diagnostic field — is passed through WordPress's own output-escaping functions before being written into the page's markup, verified directly by a test that feeds a deliberately HTML-injection-shaped fixture value through the report and asserts it appears escaped, never raw, in the rendered output. When the plugin's schema is degraded, this page remains fully reachable and instead renders a short notice stating that the schema is currently unavailable and will be retried automatically, using only the stable failure code described in section 4.3, never a raw database error.

The page carries exactly one interactive control beyond viewing the report: a bounded, capability-protected, nonce-protected self-test, `Administration\Diagnostics\SelfTest`, deliberately designed to be safe enough to ship inside the plugin's own distributable package, unlike a purely development-only test fixture would be. It is available only when WordPress's own `WP_DEBUG` constant is true, checked on the server side on every request the control's handler receives, not merely hidden client-side; only to a user holding `universal_telegram_manage`, re-verified inside the handler itself rather than trusted from the menu-registration check alone; only alongside a valid nonce, verified before any other logic runs; only while the plugin's own schema is available, since the underlying dispatch it exercises would otherwise simply be refused; and it can only ever enqueue the plugin's own fixed self-test job type, within the plugin's own Action Scheduler group, for a maximum of five attempts — the same ceiling `RetryPolicy` already enforces generically, not a separately invented one. The only value an operator supplies is a single integer from one to five, selecting one of exactly two possible outcomes rather than an arbitrary behavior, chosen specifically so the ceiling is never exceeded: an input of one, two, three, or four causes the job to deliberately fail exactly that many times and then succeed on its immediately following attempt, which always remains within the five-attempt ceiling; an input of five causes the job to deliberately fail on all five of its permitted attempts, producing a terminal-failure record rather than a success, since a sixth, succeeding attempt would exceed `RetryPolicy`'s own cap and is therefore never offered as a choice. Nothing about the job's actual behavior — which is entirely fixed in code, throwing a specific typed failure on however many of its five permitted attempts the input selects and then either completing as a harmless no-op or remaining terminally failed — is influenced by any other input, so there is no path by which this control could be made to run an arbitrary hook, an arbitrary payload, arbitrary PHP, SQL, a shell command, or an outbound network request of any kind. The control itself is entirely absent from the rendered page — not merely disabled — whenever any one of these conditions is not met, so the feature's very existence is not signaled outside the narrow window in which it is both safe and meaningful to use.

The self-test additionally exercises a fixed, non-randomized synthetic secret value — the same literal string on every run, by design, so an independent reviewer can search for it reproducibly across separate sessions — by encrypting it through `CredentialVault` under a dedicated diagnostic context and recording only the resulting ciphertext, never the plaintext, as part of its own audit trail. AES-256-GCM encryption does not preserve its plaintext as a recoverable substring of its own ciphertext, so the literal sentinel string is not merely redacted from view but is never present in any persisted byte sequence anywhere the ciphertext itself is stored; only the encrypted, encoded ciphertext blob is retained. This gives a reviewer a concrete, repeatable verification: search the entire system — persisted plugin data, the audit table, Action Scheduler's own action arguments and log rows, the diagnostics page's own rendered output, the PHP error log, and a full database export — for that exact literal plaintext string, and confirm it appears nowhere at all in any of those locations; the only trace of the self-test's own encryption exercise anywhere in the system is the unreadable ciphertext blob itself.

Because this component is deliberately bounded and safe to ship, it is present and functional in the plugin's built distributable package, not excluded from it. Genuinely development-only test fixtures — code that exists purely to support the automated test suite, carries no capability or nonce protection of its own, and would not be safe to expose on a live site — remain excluded from the built package, living instead under the test suite's own directory, loaded only through the development-only portion of the plugin's autoloading configuration.

### 4.10 Uninstall behavior and Action Scheduler cleanup

`uninstall.php` requires `vendor/autoload.php`, then explicitly requires `vendor/woocommerce/action-scheduler/action-scheduler.php` directly. Because WordPress's own plugin-deletion flow runs `uninstall.php` in the same request as the delete action, well after that request's own `plugins_loaded` and `init` actions have already fired for whichever plugins were active — and this plugin, being deactivated before deletion as WordPress itself requires, was not among them — the library's own bootstrap file contains exactly the fallback branch needed to initialize it correctly at this late point: it checks that `plugins_loaded` has already fired, that it is not currently firing, and that no `ActionScheduler` class yet exists, and if so, initializes itself immediately and synchronously. Because `init` has also already fired earlier in the same request, the library's own initialization routine takes its synchronous-completion branch rather than deferring to a hook that will never fire again this request, meaning that by the time the `require_once` statement returns, Action Scheduler is fully initialized and every one of its procedural functions is immediately usable for the remainder of the script.

`Core\Lifecycle\Uninstaller` always, unconditionally, regardless of whichever data-retention choice an operator has made: revokes `universal_telegram_manage` from every role, through `CapabilityRegistrar`; and cancels every currently pending scheduled action in the plugin's own Action Scheduler group, `universal-telegram`, via `as_unschedule_all_actions(null, [], 'universal-telegram')`. This specific call, confirmed directly from the library's own source, routes to the store's own `cancel_actions_by_group()` method, which transitions every pending action in that group to a canceled status; a not-yet-run scheduled action has no retention value regardless of an operator's data-retention preference, since it is simply no-longer-actionable cleanup, not historical data.

When the operator has left `remove_data_on_uninstall` at its default of false, nothing further happens: the plugin's own audit table, settings, and schema-version option all remain, and the now-canceled action rows and their log entries remain in Action Scheduler's own tables, exactly as the documented retention policy describes.

When the operator has explicitly set `remove_data_on_uninstall` to true, the Uninstaller additionally: drops the plugin's own audit table and deletes its own settings and schema-version options; and removes every historical action and log row belonging to the plugin's own group — covering actions already complete, already failed, already canceled by the unconditional step above, and any stale action still marked in-progress, which would otherwise represent an orphaned, never-completing row from a worker that died mid-execution. This historical removal is performed exactly as the library's own official WP-CLI delete command performs it, using only the library's own public store API: `ActionScheduler::store()->query_actions(['group' => 'universal-telegram', 'status' => [ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler_Store::STATUS_FAILED, ActionScheduler_Store::STATUS_CANCELED, ActionScheduler_Store::STATUS_RUNNING], 'per_page' => 1000, 'orderby' => 'none'], 'select')`, paginated in a loop exactly mirroring the library's own internal bulk-cancellation pattern — repeating the query and processing a batch of up to one thousand identifiers at a time until an empty result is returned — followed, for every identifier returned, by `ActionScheduler::store()->delete_action($action_id)`, wrapped in its own try/catch tolerating the specific, expected case where a row has already been removed by another process since it was queried. Each successful `delete_action()` call itself fires the library's own `action_scheduler_deleted_action` hook, to which the library's own default logger has already registered its own cleanup of that action's log rows in its constructor — so no separate call to remove log entries is needed on this plugin's own part; the library's own default logger already cascades that removal automatically. At no point does this plugin ever issue a raw SQL statement against Action Scheduler's own shared tables, and it never drops or truncates any table Action Scheduler itself owns, since another active plugin — including WooCommerce, should it be present — may still depend on those same tables continuing to exist and to hold whatever data belongs to it.

This is tested in all four combinations of the retention setting and WooCommerce's presence, with the plugin deactivated before `uninstall.php` runs in every case, matching WordPress's own actual deletion flow.

## 5. Directory, namespace, schema, and API impact

Under the plugin's root: `universal-telegram.php` as the bootstrap file; `uninstall.php`; `LICENSE`; `composer.json` and `composer.lock`; `phpcs.xml.dist`; `phpstan.neon.dist`, with no accompanying baseline file; `phpunit.xml.dist` and `phpunit-integration.xml.dist`; `readme.txt`; `CHANGELOG.md`; a `docker` directory holding a `Dockerfile` and a `docker-compose.yml`; a `bin` directory holding `build-zip.sh` and a `docker` subdirectory of thin wrapper scripts, each simply invoking the equivalent command inside the Docker Compose `php` service, so that no host installation of PHP, Composer, or Node is ever required for any validation step described in this plan; and a `.github/workflows` directory holding only `ci.yml` at M00.

Under `src/`, using the namespace `UniversalTelegram\`: a `Core` directory holding `Plugin.php`, and, inside it, `Configuration`, `Lifecycle`, `Security`, and `Capabilities` subdirectories for their respective classes described above; a `Persistence` directory; an `Audit` directory; a `Privacy` directory; a `Queue` directory; an `Integrations` directory holding a `WooCommerce` subdirectory; and an `Administration` directory holding a `Diagnostics` subdirectory. No directory or file exists under `src/` for `Events`, `Automations`, `Telegram`, `Conversations`, `ChatWidget`, or `AI` at M00; their ownership is recorded only in `docs/ARCHITECTURE.md`, and a structural guard test enforces their continued absence until each one's own owning milestone authorizes its creation.

Under `tests/`: `unit` and `integration` directories mirroring the `src/` tree; a `bin` directory holding `install-wp.sh`, described in section 7; a `package` directory holding the tests that install and exercise the plugin's own built distributable package, described in section 7; and, within `integration`, a `Support` directory holding fixtures that exist purely for the automated test suite and are never loaded outside it.

The plugin introduces one database table, `{$wpdb->prefix}universal_telegram_audit_log`, described in section 4.4; two persistent options, `universal_telegram_settings` and `universal_telegram_db_version`; and one transient-lifetime option used only during an active migration, `universal_telegram_migration_lock`, described in section 4.3. It introduces no REST route, no public PHP function or class contract, and no JavaScript of any kind — `Queue\HandlerRegistry` and the privacy classification map are both explicitly internal, not public extension points, as described in sections 4.2 and 4.4.

## 6. Security and privacy impact

`CredentialVault`, described in section 4.5, is this plugin's first piece of credential-handling code, and its full contract — authenticated encryption, fail-closed key resolution with no insecure fallback, and preservation rather than erasure of ciphertext on a failed decryption — is reviewed in full in this plan rather than inherited unreviewed from any earlier precedent. `universal_telegram_manage`, described in section 4.6, now gates a genuine, rendered administration surface with independently verified capability and nonce checks, rather than being asserted only against shell or command-line access, which is a different authorization boundary entirely. The fail-closed queue-payload classification policy described in section 4.2 structurally prevents sensitive data from ever being written into Action Scheduler's own unencrypted, longer-retained storage, regardless of what a future job-handler author might otherwise be tempted to pass through it. `Privacy\Redactor`, described in section 4.4, is exercised end to end by the diagnostics self-test's own synthetic secret, described in section 4.9, proving the full redaction path against a genuine, if synthetic, credential rather than only against isolated unit-test fixtures. Nothing in M00's own scope touches a visitor's personal data, a raw IP address, or any WooCommerce order — that begins only with milestone M04 and milestone M03 respectively, and each will need its own privacy review at that time.

## 7. Test and CI strategy

All validation runs through thin wrapper scripts under `bin/docker/`, each of which invokes the equivalent command inside the `php` service defined in `docker/docker-compose.yml` — never a bare host installation of Composer, PHPUnit, PHPCS, or PHPStan, and never any Node-based tooling, matching this VPS's own Docker-only tooling requirement. `docker/docker-compose.yml` defines a `php` service, built from `docker/Dockerfile` with a build argument selecting the PHP version, and a `db` service running a current MariaDB release, used only by the integration-test wrapper scripts.

Unit tests carry no WordPress bootstrap at all and mirror the `src/` tree under `tests/unit/`. Integration tests run against a real, specific version of WordPress core, downloaded and configured by `tests/bin/install-wp.sh`, which is deliberately parameterized by an exact WordPress version rather than relying on any single Composer-resolved package to serve every tested version at once: given a version argument, it downloads WordPress core for that exact version, and separately exports the official WordPress core test-suite scaffold matching that same exact version directly from its own upstream source repository, into a version-specific directory; it never uses a single, Composer-locked test-scaffold package to serve two different WordPress-core versions, since that package can only ever resolve to one version at a time, and using it for both a WordPress-6.9 leg and a WordPress-7.1 leg would silently test the older core against a newer scaffold rather than genuinely validating against the older one. `tests/integration/bootstrap.php` reads the exact WordPress test-library location from an environment variable, following the same long-established convention WordPress core's own test bootstrap itself respects, and each integration configuration sets that environment variable to its own freshly fetched, version-matched pair before invoking PHPUnit. Because this WordPress-core-and-test-library fetch is performed entirely outside Composer's own dependency graph, by this dedicated script rather than by any Composer package, removing the single-version test-scaffold package that an earlier draft of this plan depended on from `composer.json` only reduces the dependency set already verified to resolve cleanly at PHP 8.1 — it cannot reintroduce a conflict among the packages that remain, so the earlier verification remains valid evidence for the corrected, smaller set without needing to be repeated.

A structural guard test asserts that the six undocumented-boundary directories described in section 4.1 do not exist under `src/`. `RetryPolicy`'s injected clock and jitter seams, described in section 4.2, are used to assert exact expected backoff delays rather than only a value falling within some range. Constructing a `JobEnvelope` with an unclassified or sensitively-classified payload field is asserted to throw immediately, at construction, not merely to fail silently later. The migration lock's atomic-interleaving scenario described in section 4.3 is a dedicated integration test. A deliberately failing, multi-statement migration step is used to assert that a partial failure leaves the schema-version option unchanged and is safely re-runnable in full. A deliberate migration failure is combined with an ordinary simulated frontend request to assert that no exception escapes and that the request completes normally, satisfying section 4.3's degraded-mode requirement. An already-scheduled action encountered while the schema is deliberately held unavailable is asserted to be marked failed by Action Scheduler, not complete; to remain eligible for the normal bounded retry policy; to never actually invoke its own business-logic handler while unavailable; and, once schema availability is restored, to execute normally on its next attempt if attempts remain — these four assertions together are the direct test of the worker-runner correction described in section 4.2. An attempted network-wide activation is asserted to be refused, with no schema writes performed. Uninstall is tested in all four combinations of the data-retention setting and WooCommerce's presence, with the plugin deactivated first in every case, asserting that pending actions in the plugin's own group are always canceled, that historical action and log rows are removed only when retention has been explicitly disabled, and that no table belonging to Action Scheduler itself is ever dropped or truncated.

Coding-standard checks run through PHPCS, configured with the `WordPress-Extra` and `WooCommerce-Core` rule sets, a `PrefixAllGlobals` rule keyed to `universal_telegram`, a text-domain check keyed to `universal-telegram`, a minimum-WordPress-version check of 6.9, and a minimum-PHP-version check of 8.1. Static analysis runs through PHPStan at level 5, including the WordPress-stubs extension, with no baseline file — this is a genuinely new codebase, and the isolated verification described in section 1 already confirmed it can pass cleanly at this level without one.

Automated checks are introduced into `.github/workflows/ci.yml` only at the point in the work-package sequence, described in section 8, where the code and tests that make each specific job pass already exist in the same commit — no job is ever pushed in a state where it would fail, and every job's required tooling exists in the very same commit that first turns that job on.

## 8. Implementation work packages

Implementation branch: `feature/m00-product-foundation`. Every work package below is committed to this branch, in order, after the freeze commit that precedes it. The Docker wrapper commands referenced below are established in Work Package 1 and used unchanged, with only their own arguments varying, by every later work package: `bin/docker/composer.sh` (any Composer subcommand), `bin/docker/phpcs.sh`, `bin/docker/phpstan.sh`, `bin/docker/test-unit.sh`, `bin/docker/test-integration-wp-only.sh --wp-version=6.9` (or `--wp-version=7.1`), `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`, `bin/docker/build-zip.sh`, and `bin/docker/test-package.sh --wp-version=<version> --php-version=<version>` with an optional `--woocommerce=11.0.1` flag.

### Work Package 1 — Bootstrap, licensing, Docker development environment, and CI foundation

Objective: establish the plugin's bootstrap file, its license, its complete verified Composer dependency set including the bundled Action Scheduler library, its Docker-based development toolchain, its coding-standard and static-analysis configuration, both its unit and its WordPress-only integration test harnesses, and a minimal, idempotent composition-root skeleton — so that every validation command this plan ever refers to already exists and already passes from the very first commit.

Exact files added:
- `universal-telegram.php`
- `uninstall.php` (created empty at this step, containing only the `WP_UNINSTALL_PLUGIN` guard and a comment that Work Package 10 implements its body; this avoids WordPress ever executing a missing uninstall file against a partially built plugin during this branch's own development)
- `LICENSE`
- `composer.json`
- `composer.lock`
- `phpcs.xml.dist`
- `phpstan.neon.dist`
- `phpunit.xml.dist`
- `phpunit-integration.xml.dist`
- `readme.txt`
- `CHANGELOG.md`
- `docker/Dockerfile`
- `docker/docker-compose.yml`
- `bin/build-zip.sh`
- `bin/docker/composer.sh`
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-unit.sh`
- `bin/docker/test-integration-wp-only.sh`
- `bin/docker/build-zip.sh`
- `tests/bin/install-wp.sh`
- `tests/unit/bootstrap.php`
- `tests/integration/bootstrap.php`
- `.github/workflows/ci.yml`
- `src/Core/Plugin.php`
- `tests/unit/Core/PluginTest.php`
- `tests/integration/Core/PluginActivatesTest.php`

Exact files modified: none — this is the first work package.

Exact validation commands:
- `bin/docker/composer.sh install --no-interaction`
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-unit.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`
- `bin/docker/build-zip.sh`

CI/job changes: creates `.github/workflows/ci.yml` with six jobs — `phpcs`, `static-analysis`, `unit` (matrix: PHP 8.1, 8.3, 8.4), `integration-wp-only-floor` (WordPress 6.9, PHP 8.1), `integration-wp-only-current` (WordPress 7.1, PHP 8.3), and `build`. Each job runs the corresponding Docker wrapper command listed above.

Acceptance evidence: all seven local wrapper-command runs listed above exit `0`; `tests/unit/Core/PluginTest.php` asserts `Plugin::instance()` returns the identical object across two calls and that calling `init()` twice does not re-register any hook; `tests/integration/Core/PluginActivatesTest.php` asserts the plugin activates against a genuinely downloaded WordPress 6.9 core and a genuinely downloaded WordPress 7.1 core with no fatal error and no PHP warning; the six-job CI workflow is green on the pushed branch.

Planned commit message: `build(m00): bootstrap plugin, Docker toolchain, and CI foundation (WP1)`

### Work Package 2 — Core configuration storage and lifecycle scaffolding

Objective: add the plugin's single settings option and its activation/deactivation lifecycle, including the explicit refusal of network-wide multisite activation.

Exact files added:
- `src/Core/Configuration/Settings.php`
- `src/Core/Lifecycle/Activator.php`
- `src/Core/Lifecycle/Deactivator.php`
- `tests/unit/Core/Configuration/SettingsTest.php`
- `tests/integration/Core/Lifecycle/NetworkActivationTest.php`

Exact files modified:
- `src/Core/Plugin.php` — `init()` now constructs `Settings`, registers `Activator::activate()` and `Deactivator::deactivate()` against WordPress's activation and deactivation hooks in `universal-telegram.php`'s own bootstrap wiring.
- `universal-telegram.php` — adds `register_activation_hook()` and `register_deactivation_hook()` calls pointing at `Activator::activate()` and `Deactivator::deactivate()`.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-unit.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`

CI/job changes: none — the existing `unit` and `integration-wp-only-*` jobs already run every test this work package adds.

Acceptance evidence: `tests/unit/Core/Configuration/SettingsTest.php` asserts the settings option round-trips through the Settings API under group `universal_telegram_settings_group`; `tests/integration/Core/Lifecycle/NetworkActivationTest.php` asserts that invoking `Activator::activate(true)` (the network-wide case) calls `wp_die()` and performs zero database writes, while `Activator::activate(false)` completes normally; all five validation commands exit `0`; CI remains green.

Planned commit message: `feat(core): add configuration storage and lifecycle scaffolding, refuse network activation (WP2)`

### Work Package 3 — Persistence, atomic migration locking, and safe degraded mode

Objective: add the migration framework, the plugin's one database table (the audit log, created empty), the atomic migration lock, the schema-health service, and the degraded-mode wiring inside the composition root that keeps an ordinary request from ever crashing on a migration failure discovered outside activation.

Exact files added:
- `src/Persistence/Migrator.php`
- `src/Persistence/MigrationLock.php`
- `src/Persistence/MigrationLockHandle.php`
- `src/Persistence/MigrationFailedException.php`
- `src/Persistence/MigrationFailureCode.php`
- `src/Persistence/SchemaHealth.php`
- `tests/integration/Persistence/MigratorTest.php`
- `tests/integration/Persistence/MigrationLockTest.php`
- `tests/integration/Persistence/DegradedModeTest.php`

Exact files modified:
- `src/Core/Plugin.php` — `init()` now constructs `SchemaHealth`, wraps a call to `Migrator::maybeMigrate()` in a try/catch on `MigrationFailedException`, and sets `SchemaHealth`'s availability state accordingly, before constructing any later work package's own services.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`

CI/job changes: none — the existing `integration-wp-only-*` jobs already run every test this work package adds.

Acceptance evidence: `tests/integration/Persistence/MigratorTest.php` asserts a clean install creates `{$wpdb->prefix}universal_telegram_audit_log` exactly once, that `universal_telegram_db_version` advances to `1` only after both the table-creation statement and its postcondition check succeed, and that a deliberately failing second statement in a synthetic multi-statement test step leaves the version unchanged and is safely re-run in full; `tests/integration/Persistence/MigrationLockTest.php` asserts the exact interleaving described in section 4.3 — a first process's stale lock is reclaimed by a second process, and the first process's own subsequent release call affects zero rows and leaves the second process's lock completely untouched; `tests/integration/Persistence/DegradedModeTest.php` asserts that a simulated migration failure leaves `SchemaHealth::isAvailable()` false for the remainder of that one request, that an ordinary simulated frontend request completes without an uncaught exception while degraded, and that no raw database error string appears anywhere in the response; all four validation commands exit `0`; CI remains green.

Planned commit message: `feat(persistence): add migration framework, atomic lock, and safe degraded mode (WP3)`

### Work Package 4 — Privacy classification and audit logging

Objective: add the four-level privacy classification scheme and its fail-closed redactor, together with the audit-logging write and read paths, in the same commit, since the audit logger's own constructor genuinely depends on the redactor introduced alongside it.

Exact files added:
- `src/Privacy/Classification.php`
- `src/Privacy/Redactor.php`
- `src/Audit/AuditLogger.php`
- `src/Audit/AuditLogRepository.php`
- `tests/unit/Privacy/ClassificationTest.php`
- `tests/unit/Privacy/RedactorTest.php`
- `tests/integration/Audit/AuditLoggerTest.php`

Exact files modified:
- `src/Core/Plugin.php` — `init()` now constructs `AuditLogger` and `AuditLogRepository`, passing `SchemaHealth` and `Redactor` to `AuditLogger`, unconditionally, regardless of schema availability.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-unit.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`

CI/job changes: none — the existing `unit` and `integration-wp-only-*` jobs already run every test this work package adds.

Acceptance evidence: `tests/unit/Privacy/RedactorTest.php` asserts a `SECRET`-classified field is stripped, a `SENSITIVE`-classified field is masked, a nested field matched by a dot-notation classification key is handled at every depth, and any field — nested or top-level — absent from the classification map is removed from the output rather than passed through or defaulted to `PUBLIC`; `tests/integration/Audit/AuditLoggerTest.php` asserts a recorded entry is retrievable through `AuditLogRepository` afterward, and that calling `AuditLogger::record()` with a field missing from its own classification map results in that field being absent from the persisted `context` column; all five validation commands exit `0`; CI remains green.

Planned commit message: `feat(audit): add privacy classification, fail-closed redaction, and audit logging (WP4)`

### Work Package 5 — WooCommerce presence detection and the WooCommerce-present test configuration

Objective: add the WooCommerce-presence detection surface, and stand up the WooCommerce-present integration-test configuration for the first time.

Exact files added:
- `src/Integrations/WooCommerce/WooCommerceSupport.php`
- `tests/integration/Integrations/WooCommerceSupportTest.php`
- `bin/docker/test-integration-wc-present.sh`

Exact files modified:
- `src/Core/Plugin.php` — `init()` now constructs `WooCommerceSupport`.
- `universal-telegram.php` — adds the `before_woocommerce_init` hook declaring High-Performance Order Storage compatibility.
- `.github/workflows/ci.yml` — adds job `integration-wc-present-current`, invoking `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`.
- `tests/bin/install-wp.sh` — extended to accept an optional WooCommerce-version argument, installing the pinned WooCommerce release into the test WordPress instance when supplied.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`
- `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`

CI/job changes: adds `integration-wc-present-current` to `.github/workflows/ci.yml`, already green at this commit.

Acceptance evidence: `tests/integration/Integrations/WooCommerceSupportTest.php` asserts `WooCommerceSupport::isActive()` returns `false` under the WordPress-only configuration and `true` under the WooCommerce-present configuration; the WordPress-only legs are re-run and remain green, confirming this work package introduces no regression to the WooCommerce-absent path; all five validation commands exit `0`; CI, now carrying seven jobs, is green.

Planned commit message: `feat(integrations): add WooCommerce-presence detection and stand up the WooCommerce-present test configuration (WP5)`

### Work Package 6 — Secret storage: the credential vault

Objective: add the AES-256-GCM credential vault in full, with its complete key-resolution, fail-closed, and re-encryption contract.

Exact files added:
- `src/Core/Security/CredentialVault.php`
- `src/Core/Security/CredentialState.php`
- `src/Core/Security/CredentialResult.php`
- `src/Core/Security/CredentialUnavailableException.php`
- `tests/unit/Core/Security/CredentialVaultTest.php`

Exact files modified:
- `src/Core/Plugin.php` — `init()` now constructs `CredentialVault`.
- `tests/unit/bootstrap.php` — defines the deterministic `UNIVERSAL_TELEGRAM_CREDENTIAL_KEY` constant used only by this test bootstrap, satisfying the requirement that a deterministic fallback key may be injected only by tests.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-unit.sh`

CI/job changes: none — the existing `unit` job already runs every test this work package adds.

Acceptance evidence: `tests/unit/Core/Security/CredentialVaultTest.php` asserts a successful encrypt/decrypt round trip; that decrypting under a context different from the one used to encrypt returns state `INVALIDATED`, never plaintext; that a malformed `UNIVERSAL_TELEGRAM_CREDENTIAL_KEY` constant causes the vault to fail closed without falling through to a weaker key-resolution tier; that simulating a key-material change leaves the stored ciphertext byte-for-byte unmodified and reports `INVALIDATED`, never erasing it; and that `reencrypt()` correctly moves a value from one resolved key to another, after which the original key can no longer decrypt it. All three validation commands exit `0`; CI remains green.

Planned commit message: `feat(security): add AES-256-GCM credential vault with fail-closed key resolution (WP6)`

### Work Package 7 — Capability model

Objective: add the plugin's one WordPress capability, granted on activation and revoked unconditionally on uninstall.

Exact files added:
- `src/Core/Capabilities/CapabilityRegistrar.php`
- `tests/integration/Core/Capabilities/CapabilityRegistrarTest.php`

Exact files modified:
- `src/Core/Plugin.php` — `init()` now constructs `CapabilityRegistrar`.
- `src/Core/Lifecycle/Activator.php` — `activate()` now calls `CapabilityRegistrar::grantToAdministrator()`.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`

CI/job changes: none — the existing `integration-wp-only-*` jobs already run every test this work package adds.

Acceptance evidence: `tests/integration/Core/Capabilities/CapabilityRegistrarTest.php` asserts the `administrator` role holds `universal_telegram_manage` immediately after activation and that a role granted no such capability does not hold it; all four validation commands exit `0`; CI remains green.

Planned commit message: `feat(capabilities): add universal_telegram_manage, granted on activation (WP7)`

### Work Package 8 — Queue: the complete generic retry foundation

Objective: add the job envelope with its fail-closed payload classification, the deterministic retry policy, the dispatcher, the always-registered and schema-aware worker runner, the internal handler registry, and the queue-health service — implemented entirely against the Action Scheduler dependency already bundled since Work Package 1.

Exact files added:
- `src/Queue/JobEnvelope.php`
- `src/Queue/PayloadRejectedException.php`
- `src/Queue/RetryPolicy.php`
- `src/Queue/Dispatcher.php`
- `src/Queue/DispatchResult.php`
- `src/Queue/WorkerRunner.php`
- `src/Queue/SchemaUnavailableException.php`
- `src/Queue/FailureCode.php`
- `src/Queue/HandlerRegistry.php`
- `src/Queue/QueueHealth.php`
- `tests/unit/Queue/RetryPolicyTest.php`
- `tests/unit/Queue/JobEnvelopeTest.php`
- `tests/integration/Queue/DispatcherTest.php`
- `tests/integration/Queue/WorkerRunnerTest.php`
- `tests/integration/Queue/SchemaDegradedExecutionTest.php`
- `tests/integration/Support/FailingJobFixture.php` (development-only; mapped under the plugin's `autoload-dev` PSR-4 root, never under production `autoload`)

Exact files modified:
- `src/Core/Plugin.php` — `init()` now constructs `HandlerRegistry`, `Dispatcher`, and `WorkerRunner`, and registers `WorkerRunner::run()` against the Action Scheduler hook `universal_telegram_run_job` unconditionally, on every request, regardless of `SchemaHealth::isAvailable()`.
- `composer.json` — `autoload-dev` gains the mapping for `tests/integration/Support/`, if not already covered by the existing `UniversalTelegram\Tests\` mapping established in Work Package 1; no production dependency is added here, since `woocommerce/action-scheduler` was already added in Work Package 1.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-unit.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`
- `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`

CI/job changes: none — the existing `unit`, `integration-wp-only-current`, and `integration-wc-present-current` jobs already run every test this work package adds.

Acceptance evidence: `tests/unit/Queue/RetryPolicyTest.php` asserts exact backoff delays using the injected deterministic clock and jitter seams; `tests/unit/Queue/JobEnvelopeTest.php` asserts that constructing an envelope with an unclassified or `SENSITIVE`/`SECRET`-classified payload field throws `PayloadRejectedException` immediately; `tests/integration/Queue/DispatcherTest.php` asserts a forced dispatch-time exception and a forced zero-return action identifier both result in state `FAILED` without ever propagating to the caller, and that a `SchemaHealth`-degraded state results in `SCHEMA_UNAVAILABLE` without any call into Action Scheduler; `tests/integration/Queue/WorkerRunnerTest.php`, using `FailingJobFixture`, asserts exactly one `queue_job_attempt_failed` entry per failed attempt, correct rescheduling at the policy's computed delay, a `queue_job_terminal_failure` entry after the maximum attempt is exhausted, and that the original exception is always rethrown so the corresponding Action Scheduler action is marked failed; `tests/integration/Queue/SchemaDegradedExecutionTest.php` asserts that an already-scheduled action executed while `SchemaHealth` is deliberately held unavailable is marked failed by Action Scheduler, not complete, never invokes `FailingJobFixture`'s own handler body, remains eligible for its next bounded retry attempt, and executes normally once availability is restored. All five validation commands exit `0`, run against both the WooCommerce-absent and WooCommerce-present configurations; CI remains green.

Planned commit message: `feat(queue): add job envelope, dispatcher, and schema-aware worker runner with corrected degraded-mode handling (WP8)`

### Work Package 9 — Administration: the diagnostics page and its bounded self-test

Objective: add the plugin's one administration screen, its redacted diagnostics report, and its bounded, capability- and nonce-protected self-test, registering the self-test as the queue's one real handler entry.

Exact files added:
- `src/Administration/Diagnostics/DiagnosticsPage.php`
- `src/Administration/Diagnostics/DiagnosticsReport.php`
- `src/Administration/Diagnostics/SelfTest.php`
- `tests/integration/Administration/Diagnostics/DiagnosticsPageTest.php`
- `tests/integration/Administration/Diagnostics/SelfTestTest.php`

Exact files modified:
- `src/Core/Plugin.php` — `init()` now constructs `DiagnosticsPage` and `SelfTest`, registers `DiagnosticsPage::registerMenu()` against `admin_menu`, registers `SelfTest`'s handler against `admin_post_universal_telegram_diag_self_test`, and calls `HandlerRegistry::register('diagnostics_self_test', ...)` pointing at `SelfTest`'s own job-handler method.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`

CI/job changes: none — the existing `integration-wp-only-*` jobs already run every test this work package adds.

Acceptance evidence: `tests/integration/Administration/Diagnostics/DiagnosticsPageTest.php` asserts a user lacking `universal_telegram_manage` is denied the page by WordPress core itself; that a deliberately HTML-injection-shaped fixture value in a rendered audit entry appears escaped, never raw, in the page's output; and that while `SchemaHealth` is degraded, the page still renders, showing only the stable `MigrationFailureCode` value, never a raw database error. `tests/integration/Administration/Diagnostics/SelfTestTest.php` asserts the self-test control is absent from the rendered page whenever `WP_DEBUG` is false, whenever the current user lacks the capability, or whenever `SchemaHealth` is degraded; that a direct `admin-post.php` request carrying no valid nonce is rejected; that triggering the control with an input from one to four produces exactly that many recorded `queue_job_attempt_failed` entries followed by one recorded success, and that triggering it with an input of five produces exactly five recorded `queue_job_attempt_failed` entries followed by one `queue_job_terminal_failure` entry, never a success; and that the fixed synthetic secret sentinel, once run through `CredentialVault`, appears nowhere in plaintext — not in the audit table, not in the corresponding Action Scheduler action arguments, not in the page's own rendered output, not in the PHP error log, and not in a full database export — with only its ciphertext ever persisted.

Planned commit message: `feat(administration): add diagnostics page and bounded self-test, register the queue's first handler (WP9)`

### Work Package 10 — Uninstall behavior and Action Scheduler cleanup

Objective: implement the plugin's uninstall routine in full, including the unconditional cancellation of pending actions in the plugin's own Action Scheduler group and the retention-flag-gated removal of the plugin's own tables, options, and historical Action Scheduler action and log rows.

Exact files added:
- `src/Core/Lifecycle/Uninstaller.php`
- `tests/integration/Core/Lifecycle/UninstallTest.php`

Exact files modified:
- `uninstall.php` — replaces the placeholder guard added in Work Package 1 with the full routine: requires `vendor/autoload.php`, then requires `vendor/woocommerce/action-scheduler/action-scheduler.php` directly, then calls `Uninstaller::run()`.

Exact validation commands:
- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9`
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1`
- `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`

CI/job changes: none — the existing `integration-wp-only-*` and `integration-wc-present-current` jobs already run every test this work package adds.

Acceptance evidence: `tests/integration/Core/Lifecycle/UninstallTest.php`, run across all four combinations of `remove_data_on_uninstall` (`true`/`false`) and WooCommerce (`absent`/`present`), with the plugin deactivated before `uninstall.php` runs in every case, asserts: `universal_telegram_manage` is revoked from every role in all four combinations; every pending action in Action Scheduler group `universal-telegram` is canceled in all four combinations; when `remove_data_on_uninstall` is `false`, the audit table, settings option, and schema-version option all remain, and no historical action or log row is removed; when `remove_data_on_uninstall` is `true`, the audit table is dropped, the settings and schema-version options are deleted, and every action and log row in group `universal-telegram` with status complete, failed, canceled, or in-progress is removed via `ActionScheduler::store()->delete_action()`, paginated exactly as described in section 4.10; and in every one of the four combinations, no table Action Scheduler itself owns is ever dropped or truncated. All five validation commands exit `0`; CI remains green.

Planned commit message: `feat(lifecycle): implement uninstall with unconditional pending-action cleanup and retention-gated data removal (WP10)`

### Work Package 11 — Package verification

Objective: finalize the packaging script and add a new CI job that installs and exercises the plugin's own built distributable package, not only a source checkout, across three configurations.

Exact files added:
- `bin/docker/test-package.sh`
- `tests/package/run.sh`

Exact files modified:
- `bin/build-zip.sh` — finalized to run `composer install --no-dev --no-interaction --optimize-autoloader` against the already-committed `composer.lock`, to assert `vendor/woocommerce/action-scheduler/action-scheduler.php` is present in the resulting package, and to exclude `docker/`, `tests/`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, and `.github/` from the resulting ZIP — `src/Administration/Diagnostics/SelfTest.php` is explicitly not among the exclusions.
- `.github/workflows/ci.yml` — adds job `package-acceptance`, a three-leg matrix: WordPress 6.9 with PHP 8.1 and WooCommerce absent; WordPress 7.1 with PHP 8.3 and WooCommerce absent; WordPress 7.1 with PHP 8.3 and WooCommerce present. Each leg runs `bin/docker/test-package.sh` with the corresponding arguments.

Exact validation commands:
- `bin/docker/build-zip.sh`
- `bin/docker/test-package.sh --wp-version=6.9 --php-version=8.1`
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3`
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3 --woocommerce=11.0.1`

CI/job changes: adds `package-acceptance` to `.github/workflows/ci.yml`, already green at this commit.

Acceptance evidence: `tests/package/run.sh`, invoked once per leg by `bin/docker/test-package.sh`, installs the ZIP produced by `bin/docker/build-zip.sh` into a fresh WordPress instance via WP-CLI's `wp plugin install --activate`, and asserts: activation succeeds with no fatal error; the diagnostics page renders; with `WP_DEBUG` true, the self-test control is present, and triggers a controlled queue execution that produces the expected attempt-failure and success records for an input from one to four and the expected five attempt-failures plus terminal-failure record for an input of five; deactivation and reactivation both succeed with no data loss; and uninstall, run against the packaged plugin with `remove_data_on_uninstall` set both `true` and `false`, behaves exactly as `tests/integration/Core/Lifecycle/UninstallTest.php` already established for a source checkout. All four validation commands exit `0`; CI, now carrying nine jobs, is green.

Planned commit message: `build(package): finalize distributable ZIP and add packaged-plugin acceptance testing across three configurations (WP11)`

### Work Package 12 — Developer documentation, versioning record, and doc-link validation

Objective: record the thirteen product boundaries and their ownership, and this plan's own versioning conventions, in the repository's own developer-facing documentation, and add a concrete, Docker-runnable command that verifies every relative Markdown link in that documentation actually resolves.

Exact files added:
- `docs/ARCHITECTURE.md`
- `README.md`
- `bin/check-doc-links.php` — a small, dependency-free PHP script taking one or more paths as arguments, recursively walking any directory argument for `.md` files, extracting every relative (non-`http`) Markdown link from each file found, resolving each one against the linking file's own directory, and exiting non-zero with a list of unresolved links if any target does not exist on disk; exits `0` with no output if every link resolves.

Exact files modified:
- `composer.json` — adds a `scripts` entry, `check-doc-links`, defined as `php bin/check-doc-links.php docs README.md`.

Exact validation commands:
- `bin/docker/composer.sh run-script check-doc-links` (Composer's own subcommand for invoking a named script is `run-script`; this is the exact, correct form of the command the wrapper established in Work Package 1 must run — a plain `run` is not a Composer command)

CI/job changes: none — this validation runs locally and is not added as its own CI job at M00, since the existing `phpcs`/`static-analysis`/`build` jobs are already the established gate for this milestone's CI matrix and this command is a documentation-only check with no code to lint or analyze.

Acceptance evidence: `docs/ARCHITECTURE.md` lists all thirteen authoritative product boundaries from section 4.1, states which milestone owns each of the six not yet implemented, and records the versioning conventions from section 9 of this plan exactly; `bin/docker/composer.sh run-script check-doc-links` exits `0` against `docs/ARCHITECTURE.md` and `README.md`, and is separately verified to correctly exit non-zero against a deliberately broken test link before this work package's own commit, confirming the script actually detects failures rather than always reporting success; every other validation command already established by Work Packages 1 through 11 continues to pass unchanged, since this work package touches no source or test file, only documentation, one new support script, and one `composer.json` script entry.

Planned commit message: `docs(m00): add architecture reference, developer README, and doc-link validator (WP12)`

## 9. Versioning conventions

The plugin's initial development version is `0.0.1`, held in the `UNIVERSAL_TELEGRAM_VERSION` constant and mirrored in `readme.txt`'s own stable-tag field. Versioning follows Semantic Versioning: while the plugin remains below `1.0.0`, any of the minor or patch positions may introduce a breaking change without a major-version bump, consistent with Semantic Versioning's own stated pre-1.0 behavior and with the fact that no public release of this plugin exists yet for any such change to break. Version `1.0.0` itself is reserved for the release completing milestones M00 through M07 together with M12, exactly as the master plan's own stated release boundary already describes; major-version bumps after that point are reserved for breaking changes to the plugin's own public contracts, once any exist. The database schema version is an entirely independent, monotonically increasing integer, unrelated to the plugin's own Semantic Versioning string, starting at 1 for M00's own single migration step and stored in its own dedicated option, never conflated with the plugin's version. A future Git tag, whenever one is first created by whichever milestone first produces a public release, follows the format `vX.Y.Z`. A built distributable package is named `universal-telegram-{version}.zip`. No Git tag and no GitHub Release are created during M00 itself; nothing in this plan's own work packages creates either, and `.github/workflows/ci.yml` at M00 carries no release-triggered workflow at all.

## 10. Risks

Bundling Action Scheduler directly, rather than relying on WooCommerce's own copy, is the one point at which this plan deliberately departs from every sibling plugin surveyed; the risk that this could conflict with WooCommerce's own bundled copy if WooCommerce is later activated on the same site is mitigated by the library's own verified, source-confirmed highest-version-wins registration mechanism, and is directly exercised by Work Package 8's own WooCommerce-present integration tests. Choosing AES-256-GCM over the AES-256-CBC pattern already present elsewhere in this Product Owner's fleet is a deliberate, reviewed security improvement rather than an arbitrary stylistic departure, and is documented as such in ADR-0008 below. Running every validation step, including CI itself, through Docker rather than through a native runner's own installed toolchain is a deliberate divergence from every sibling plugin's own continuous-integration approach, chosen specifically to satisfy this project's Docker-only tooling requirement, and its cost is that local development and CI now share exactly the same tooling, which is itself a simplification rather than an added risk. Testing only a WordPress-6.9-floor, WordPress-7.1-current, and WooCommerce-present-current configuration, rather than the fuller floor-to-ceiling compatibility matrix some sibling plugins run, is a deliberate, named scope boundary — the exhaustive compatibility sweep remains milestone M12's own charter, per ADR-0004's own stated scope, and this plan's own matrix already directly tests the plugin's declared floor. Deferring formal, independent manual acceptance testing until milestone M10, per ADR-0011, means M00's own quality evidence rests on automated validation, code review, and green CI rather than an independent manual test session; this is a deliberate, Product-Owner-approved governance decision, not an oversight, and does not reduce the automated validation this plan itself requires.

## 11. Explicit exclusions

No release-auditing script, no translation-extraction script, and no tag-triggered release workflow exist at M00; none of the three has anything genuine to operate on yet, since no public release, no substantial translatable user interface text, and no Git tag exist at this milestone, and each is more properly established by whichever future milestone first genuinely needs it. No PHPStan baseline file is created, since this is a greenfield codebase verified to pass cleanly without one. No public extension API of any kind exists — not the queue's internal handler registry, and not the privacy model's classification map. No key-versioning or automatic re-wrapping mechanism exists beyond the single, thoroughly unit-tested re-encryption primitive described in section 4.5. No Telegram-specific circuit breaker, no dead-letter handling beyond the generic terminal-failure record described in section 4.2, and no health-alert notification exist yet — these belong to the milestone that first dispatches a real provider call. No exhaustive floor-to-ceiling compatibility matrix exists beyond what section 7 already describes; that fuller sweep belongs to milestone M12. No REST route, no JavaScript, and no `.wp-env.json`-based or otherwise Node-dependent development tooling exists anywhere in this plan. Network-wide multisite activation is explicitly refused, not partially supported, as described in section 4.3. No Git tag and no GitHub Release are created during M00, as described in section 9. No companion bot server, no software-as-a-service runtime dependency, no artificial-intelligence implementation, no Telegram API connectivity of any kind, and no chat-widget or other later-milestone behavior of any kind exists anywhere in this plan — the plugin's self-contained, WordPress-only deployment model, with no companion service of any kind, is preserved in full. No formal, independent manual acceptance session exists for M00, per ADR-0011.

## 12. Requirements traceability

Every item below states the charter requirement, the work package that satisfies it, the exact validation command or test that proves it, and the expected result.

- Plugin bootstrap and full lifecycle, covering activation, upgrade, deactivation, and uninstall.
  - Work package: one, two, three, and ten.
  - Validation: the WordPress-only integration test run.
  - Expected result: a clean install, upgrade, deactivation, and uninstall all complete without error in both a WordPress-only and a WooCommerce-present configuration.

- Namespace, autoloading, and directory structure fixed by ADR-0002 and this plan's own technical-identity section.
  - Work package: one.
  - Validation: the coding-standard check.
  - Expected result: every source file conforms to the configured `PrefixAllGlobals` and namespace rules with zero violations.

- Module boundaries for all thirteen authoritative product boundaries.
  - Work package: one, and the structural guard test it introduces.
  - Validation: the unit-test run.
  - Expected result: the six undocumented boundaries are confirmed absent from `src/`, and every boundary this milestone does implement is present exactly where section 5 describes it.

- Dependency composition and service registration.
  - Work package: one through nine.
  - Validation: the unit-test run.
  - Expected result: `Core\Plugin::init()` constructs and wires every M00 service exactly once, even across repeated calls to `init()` itself.

- Database schema migration framework.
  - Work package: three.
  - Validation: the WordPress-only integration test run, including the postcondition-verification and atomic-lock-interleaving tests described in section 7.
  - Expected result: the audit-log table is created exactly once on a clean install, a partial failure leaves the schema version unchanged and is safely re-run, and the described lock interleaving leaves the second process's lock untouched.

- Durable queue abstraction and failure boundary, including the corrected degraded-mode behavior.
  - Work package: eight.
  - Validation: the WordPress-only and WooCommerce-present integration test runs.
  - Expected result: a dispatch-time failure never propagates to the caller; a worker-side failure is recorded and remains eligible for the configured retry policy rather than being silently lost; and a job encountered while the schema is unavailable is marked failed, not complete, and executes normally once availability is restored.

- Capability and authorization model.
  - Work package: seven and nine.
  - Validation: the WordPress-only integration test run.
  - Expected result: an administrator holds `universal_telegram_manage` immediately after activation, and a user lacking it is denied access to the diagnostics page by WordPress core itself.

- Audit logging model.
  - Work package: three, for the table itself, and four, for the write and read paths.
  - Validation: the WordPress-only integration test run.
  - Expected result: a recorded entry is retrievable afterward, and any field absent from its own classification map is rejected rather than stored unredacted.

- Privacy classification and redaction model.
  - Work package: four and nine.
  - Validation: the unit-test run and the diagnostics self-test's own synthetic-secret check.
  - Expected result: a field classified `SECRET` never survives redaction, and the self-test's own synthetic secret never appears in plaintext anywhere the redaction path is supposed to protect.

- Secret-handling policy, fail-closed.
  - Work package: six.
  - Validation: the unit-test run.
  - Expected result: a malformed explicit key constant fails closed without falling through to a weaker tier, and no code path returns a hardcoded fallback key outside of a test's own bootstrap file.

- Configuration storage conventions.
  - Work package: two.
  - Validation: the unit-test run.
  - Expected result: all plugin settings persist through the single, documented settings option.

- Error and diagnostics foundations, including the degraded-mode contract.
  - Work package: three and nine.
  - Validation: the WordPress-only integration test run.
  - Expected result: a migration failure discovered outside activation never crashes the request that discovers it, and the diagnostics page remains reachable and accurately reports the degraded state when this occurs.

- Coding standards, static analysis, and test foundations, with continuous integration.
  - Work package: one.
  - Validation: the coding-standard check and the static-analysis check.
  - Expected result: both checks pass with zero violations and no suppression baseline of any kind.

- WooCommerce-presence detection surface.
  - Work package: five.
  - Validation: the WooCommerce-present integration test run.
  - Expected result: the detection surface correctly reports WooCommerce's presence or absence in each of the two configurations.

- Architecture decision records, developer documentation, and versioning and release conventions.
  - Work package: twelve, together with the freeze commit that precedes work package one and materializes ADR-0005 through ADR-0010 themselves.
  - Validation: `bin/docker/composer.sh run-script check-doc-links`.
  - Expected result: every link resolves, and the recorded versioning conventions match section 9 of this plan exactly.

- Clean install, upgrade, and uninstall in both a WordPress-only and a WooCommerce-present configuration, from the plugin's own built distributable package, not only from a source checkout.
  - Work package: eleven.
  - Validation: the packaged-plugin acceptance test, across all three of its own configurations.
  - Expected result: every lifecycle stage — activation, queue execution, diagnostics, deactivation, reactivation, and uninstall — succeeds against the actual built ZIP.

- A queued job's failure is recorded, and the job remains eligible for retry rather than being silently lost.
  - Work package: eight and nine.
  - Validation: the diagnostics page's own self-test control.
  - Expected result: a reviewer can trigger a controlled failure sequence — an input from one to four, producing that many recorded attempt-failures followed by a recorded success, or an input of five, producing five recorded attempt-failures followed by a recorded terminal failure — and directly observe the resulting entries in every case.

- No secret-shaped value appears in any log, export, or diagnostic surface after a credential has been configured.
  - Work package: six and nine.
  - Validation: the synthetic-secret search described in section 4.9.
  - Expected result: the fixed sentinel string appears nowhere in plaintext in persisted data, logs, rendered output, Action Scheduler arguments, or database exports; only the encrypted ciphertext blob is retained.

- Unit and integration test foundations pass, in both configurations, as a standing requirement of every commit in this plan.
  - Work package: one through eleven.
  - Validation: the full continuous-integration matrix described in section 7.
  - Expected result: every job is green at every commit from the point it is first introduced onward.

- Every architecture decision record this milestone itself introduces is accepted before any implementation code exists.
  - Work package: none — this precedes work package one entirely, as part of the freeze commit itself.
  - Validation: the freeze commit's own contents.
  - Expected result: ADR-0005 through ADR-0010 exist, each marked accepted, in the same code-free commit as this plan itself, before any other commit in this milestone's branch.

## 13. Complete Definition of Done

- A clean install succeeds in a WordPress-only configuration.
  - Work package: one, two, and three.
  - Validation: the WordPress-6.9-floor and WordPress-7.1-current integration test runs.

- A clean install succeeds in a WooCommerce-present configuration.
  - Work package: five.
  - Validation: the WooCommerce-present integration test run.

- A clean upgrade succeeds in both configurations.
  - Work package: three.
  - Validation: the migration postcondition-verification test.

- A clean uninstall succeeds in both configurations.
  - Work package: ten.
  - Validation: the uninstall test's own four retention-and-WooCommerce combinations.

- A queue-dispatch failure never affects the frontend request that triggered it.
  - Work package: eight.
  - Validation: the dispatch-isolation test.

- A worker-side failure is recorded and remains eligible for the configured retry policy, never silently lost, and a schema-unavailable execution is correctly marked failed rather than successful.
  - Work package: eight.
  - Validation: the worker-failure, retry, terminal-failure, and schema-degraded-execution tests together.

- Secrets are excluded from every log, export, and diagnostic surface.
  - Work package: six and nine.
  - Validation: the synthetic-secret search.

- Unit and integration test foundations pass, in both configurations.
  - Work package: one through eleven.
  - Validation: the full continuous-integration matrix.

- A migration failure discovered outside activation never crashes an ordinary request.
  - Work package: three.
  - Validation: the degraded-mode frontend-request test.

- All of the above acceptance criteria are either fully met or explicitly accepted as a documented limitation by the Product Owner.
  - Work package: none directly — evaluated after work package eleven, against every criterion above.
  - Validation: the completed continuous-integration matrix, referenced by the milestone's own closure record per ADR-0011.

- Formal quality evidence for this milestone is the frozen plan, code review, mandatory automated validation, and green CI, per ADR-0011; no independent manual acceptance session is required for M00.
  - Work package: none — a governance decision, recorded in ADR-0011.
  - Validation: ADR-0011's own accepted status, and the milestone closure record's explicit confirmation that this deferral applies.

- A closure document is committed recording the milestone's own final status.
  - Work package: none — this occurs after implementation, during the milestone's own closure phase.
  - Validation: the committed closure record itself.

- Automated unit and integration continuous-integration results exist for both configurations.
  - Work package: one through eleven.
  - Validation: the continuous-integration matrix's own recorded results.

- The frozen plan's own commit hash is recorded.
  - Work package: none — recorded at the freeze commit that precedes work package one.
  - Validation: the freeze commit itself.

- Every architecture decision record this milestone introduces is accepted before implementation begins.
  - Work package: none — precedes work package one, part of the freeze commit.
  - Validation: the freeze commit's own contents, containing ADR-0005 through ADR-0010, each already marked accepted.

## Final consistency validation

This document was validated, programmatically where stated, before being frozen: no Markdown tables of any kind appear anywhere in this document; the PHP and WordPress floors are settled by verified evidence; the migration lock is genuinely atomic; the Action Scheduler historical-cleanup method names are confirmed from the installed library's own source; the degraded-mode worker-execution design always registers `WorkerRunner` and checks schema availability before invoking any handler, so a scheduled job can never silently succeed without its handler executing; the WooCommerce version is a single, concrete, verified pin (`11.0.1`) used consistently in every work package, CI job, and package-test command that needs it; every planned non-generated project file in Section 8 is assigned to at least one work package using explicit repository paths, with no wildcard path used anywhere, and a file may be modified in more than one work package where later work packages genuinely extend it; no work package relies on a vague validation phrase — every validation command is the exact Docker wrapper command, with its exact arguments where required; the diagnostics self-test's retry contract is internally consistent with `RetryPolicy`'s own five-attempt ceiling everywhere it is described; the synthetic-secret wording is stated identically wherever it appears, specifying that the fixed sentinel appears nowhere in plaintext in persisted data, logs, rendered output, Action Scheduler arguments, or database exports, with only the encrypted ciphertext blob ever retained; every ADR matches the corresponding architectural decision described in Section 4; and the implementation branch, `feature/m00-product-foundation`, applies to all twelve work packages without exception.
