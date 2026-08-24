# M02 — Normalized Events and Notifications: Implementation Plan (v1, revision 2)

## 0. Document status

This is the definitive implementation plan for M02 of `universal-telegram`, prepared for Master
Architect review and Product Owner (Vlad Stormhaven) approval. Per governance, this document and
every ADR it depends on must be committed as one code-free freeze package before any
implementation begins. No repository files, branches, commits, dependencies, or code exist yet as
a result of drafting this plan. Once frozen it targets `docs/plans/m02-normalized-events-and-notifications-plan-v1.md`.

Acceptance model for this milestone: per ADR-0011, M02 falls in the M00–M09 band, where closure
evidence is the frozen plan, code review, mandatory automated validation (unit, integration,
coding-standard, static analysis, packaged-plugin acceptance), and green CI — not an independent
manual acceptance session. The M02 charter's inherited template still names an independent test
focus and an acceptance report; per ADR-0011's own "Affected Documents/Milestones" section, both
are read as satisfied by this automated-evidence-plus-closure-record standard instead, and no
separate manual acceptance role is required before M10. Milestone closure status is decided by the
Product Owner, Vlad Stormhaven, informed by the Master Architect's recommendation and the
automated evidence this plan produces.

**Revision 2 note:** this revision corrects seven architectural gaps identified in Master
Architect review of v1 revision 1: event identity/idempotency (§5.1, §5.5), dispatch-log state
honesty (§7.5), emission-hook safety (§5.3), fatal-error capture boundaries and marker lifecycle
(§8.6), notification feedback-loop exclusions (§8.7), PUBLIC-only durable history (§5.4, §5.2),
and consistency corrections throughout (§16). No section outside those listed, and their
downstream work packages/ADRs/traceability rows, changed in substance.

## 1. Executive summary

M02 replaces M01's absence of any generic event/notification model with a self-contained,
WordPress-only normalized event system and an administrator-configurable notification rule
engine that dispatches through M01's existing Telegram transport unchanged. Administrators gain
the ability to define, per rule: which normalized event triggers it, what conditions (AND-only)
must hold, which bot and destination receive it, and what templated message is sent — with
deterministic evaluation, no duplicate rule-engine handoff across retries, and a simulation tool
that explains matched and rejected rules without any live Telegram call.

**Version target: `0.1.0` → `0.2.0`** (minor bump; M02 is the first new functional-capability
class after M01, mirroring the M00→M01 rationale already recorded in `docs/ARCHITECTURE.md`).
`universal_telegram_db_version` advances `7` → `10` (three new migration steps, four new tables —
see §5.4 and §5.5). No unresolved version decision remains.

M02 introduces two new top-level module boundaries pre-authorized by `docs/ARCHITECTURE.md`:
`Events` and `Automations`. It adds no new Composer dependency, no new queue job type, and no new
outbound-send code path — it renders a message and calls M01's existing
`Telegram\Outbound\MessageDispatcher::send()` unchanged, which already implements the ADR-0012
opaque-queue-payload pattern this plan is required to preserve.

## 2. Verified baseline

Repository: `/opt/biopentra/dev/universal-telegram`. `git rev-parse HEAD` and
`git rev-parse origin/main` both resolve to **`780ed9edd3411fb3d32399ff8fbce7fa47701d6e`**
(working tree clean), matching the M01 final SHA stated in the authorizing task. This is the
commit that recorded M01's technical closure and PASS evidence. M00 and M01 both show status
**Closed (PASS)** in `docs/milestones/README.md`.

One item to confirm before M02's entry criteria are formally satisfied: the M01 closure record
(`docs/closure/m01-telegram-connectivity-closure.md`) recorded Product Owner acceptance as
**Pending** at the time of that commit. M02's entry criteria require M00 and M01 both closed PASS
"acceptable to the Product Owner." This plan proceeds on the assumption that Product Owner
(Vlad Stormhaven) acceptance of M01 is confirmed no later than this plan's own freeze commit; if
it is not, the M02 freeze commit must not occur until it is.

### M01 contracts reused unchanged (not modified by this plan)

- `Queue\JobEnvelope` / `Queue\Dispatcher` / `Queue\HandlerRegistry` / `Queue\WorkerRunner` /
  `Queue\RetryPolicy` — the fail-closed classification queue contract (ADR-0006).
- `Telegram\Configuration\BotProfileRepository`, `BotProfile` — bot profiles, referenced by
  integer `bot_id`.
- `Telegram\Configuration\DestinationRepository`, `Destination` — destinations, referenced by
  integer `destination_id`.
- `Telegram\Outbound\OutboundMessageRepository`, `Telegram\Outbound\MessageDispatcher::send()` —
  the sole path by which M02 causes a Telegram message to be sent. `send()` already writes the
  rendered text into the `CredentialVault`-encrypted `universal_telegram_outbound_messages` table
  and enqueues a `JobEnvelope` carrying only an opaque `message_uuid` plus `INTERNAL`-classified
  `bot_id`/`destination_id` (ADR-0012), and returns a typed, never-throwing `DispatchResult`. M02
  never constructs a `JobEnvelope` of its own and never introduces a new queue `job_type`.
- `Telegram\Reliability\CircuitBreaker`, `RateLimiter`, dead-letter handling on
  `outbound_messages.status`, and the `possible_duplicate_delivery` diagnostic flag (ADR-0014) —
  untouched; M02's own dedup is a distinct, additional, honestly-scoped layer above this (§7.5).
- `Persistence\Migrator` (steps 1–7), `Persistence\MigrationLock`, `Persistence\SchemaHealth` —
  extended with new steps 8–10, existing steps untouched.
- `Core\Security\CredentialVault` — not used for new secrets (M02 introduces none), but its
  `Classification`/`Redactor` machinery from the `Privacy` boundary is extended (§5.2, §6.1).
- `Core\Capabilities\CapabilityRegistrar` — extended with new capability constant(s) following
  its existing grant-on-activate/revoke-on-uninstall pattern (ADR-0010); `manage_options` is never
  substituted.
- `Administration\Diagnostics\DiagnosticsReport` / `DiagnosticsPage` — extended in place with new
  read-only health fields; no new Diagnostics class is created.

No M01 behavior is modified. No M01 file listed above is edited by any M02 work package except by
strict, additive extension (new migration steps appended to `Migrator`; new capability constant
appended to `CapabilityRegistrar`; new fields appended to `DiagnosticsReport::generate()` and a new
section in `DiagnosticsPage::render()`).

## 3. Scope

**In scope (from the M02 charter):** event model and registry; core WordPress event coverage;
rule engine with AND-only condition groups; a Telegram notification action; message templates;
deduplication and cooldown; event history; rule simulation tooling.

**Explicitly out of scope, and not touched opportunistically by any work package:**

- WooCommerce-specific events (M03) — no WooCommerce hook is registered by this plan.
- Visitor/browser/page-navigation/consent/cart/checkout events (M04) — no client-side or
  session-tracking code is added.
- Nested OR condition groups — `docs/future-scope.md` requires Master Architect review, Product
  Owner approval, and a charter/future-scope update before this can enter any milestone's scope;
  this plan does not implement it, does not stub it, and does not shape the schema to make it a
  trivial follow-up beyond the ordinary cost of any future schema migration.
- A generic webhook rule action — same future-scope gate; only a Telegram send action exists.
- Conversation storage, chat widget, Telegram commands, operator workflow, AI, or any SaaS/
  companion-service component.
- Attachments, unbounded custom scripting, or any executable condition/template language beyond
  the fixed allowlisted-field interpolation defined in §7.4.
- Any modification of M01's multi-bot, encryption, queue, retry, delivery, or webhook contracts.
- A background "resume stuck claim" recovery job for the narrow mid-request-termination case
  described in §7.5 — deliberately deferred, documented as an accepted, diagnosable limitation
  rather than built.

## 4. Architecture boundaries and dependency direction

Per `docs/ARCHITECTURE.md`'s pre-authorized boundary table, M02 is the owning milestone for two of
the plugin's thirteen fixed top-level boundaries, both currently guarded absent by
`tests/unit/Core/StructuralBoundariesTest.php`:

- **`Events`** (`src/Events/`) — event identity, envelope, registry, safe emission façade,
  ingestion, redacted history projection. WooCommerce events (M03) and visitor/browser events
  (M04) will later be subdomains of this same boundary, not new boundaries.
- **`Automations`** (`src/Automations/`) — rule model, condition evaluation, template rendering,
  dedup/cooldown, dispatch wiring. Digests (M11) will later be a subdomain of this boundary.

Dependency direction is one-way: `Automations` depends on `Events` (reads normalized events and
the registry) and on `Telegram` (calls `MessageDispatcher::send()`); `Events` depends on `Privacy`
(classification/redaction) and `Persistence`; neither `Events` nor `Automations` is depended upon
by `Telegram`, `Core`, `Persistence`, `Privacy`, `Queue`, or `Audit` — those five remain exactly as
M00/M01 left them. `Administration` gains new subpages under its existing `Diagnostics` pattern
and a new `Automations` admin subdomain (rule builder, simulation, event history), consistent with
how M01 added a `Telegram` subdomain to `Administration` rather than a new top-level boundary.

## 5. Event contract and catalog

### 5.1 Event identity and the event envelope

**Event identity is a first-class, mandatory contract**, not an incidental UUID generated at
dispatch time. Every emission must supply a **source-supplied idempotency key** — a string the
emitting code chooses to represent "this exact logical occurrence," stable across any legitimate
re-invocation of the same underlying business event and distinct for any genuinely new
occurrence. `Events\EventIdentity::derive( string $event_type, int $schema_version, string
$idempotency_key ): string` computes `event_id` deterministically as the lower-case hex SHA-256
digest of `$event_type . "\x1f" . $schema_version . "\x1f" . $idempotency_key` (64 hex
characters; the `\x1f` unit-separator byte prevents concatenation collisions between differently-
split inputs). **Re-emitting the same event type, schema version, and idempotency key always
produces the same `event_id`** — this is the entire mechanism by which replay is recognized
anywhere downstream (event history, §5.4; rule dispatch log, §7.5).

`Events\EventEnvelope` (final, immutable value object, constructed transiently — never itself
serialized whole into any queue payload or database row):

```php
final class EventEnvelope {
    public function __construct(
        string $event_type,          // e.g. "wordpress.user_registered"
        int $schema_version,
        string $idempotency_key,     // source-supplied, mandatory, never generated internally
        string $event_id,            // = EventIdentity::derive($event_type, $schema_version, $idempotency_key)
        \DateTimeImmutable $occurred_at, // UTC
        string $source,              // fixed EventSource enum value
        array $actor,                // e.g. ['user_id' => 42]
        array $subject,              // e.g. ['post_id' => 17]
        array $context,              // e.g. ['ip_hash' => '...']
        array $payload                // event-type-specific structured fields
    )
}
```

`event_type` must match `^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$` (namespaced, dot-separated,
lower-case — `wordpress.login_failed`, `wordpress.user_registered`). `$idempotency_key` must be a
non-empty string, 1–255 bytes; construction throws `Events\InvalidIdempotencyKeyException`
otherwise. Construction validates `event_type` against `Events\Registry` and throws
`Events\UnregisteredEventTypeException` if unknown; this is caught by `Events\EventEmitter`
(§5.3), never by the emitting caller.

Every field present anywhere in `actor`, `subject`, `context`, or `payload` must have a
classification (`Privacy\Classification::PUBLIC` or `::INTERNAL`; `SENSITIVE`/`SECRET` fields are
never accepted into an event at all, see §6.1) supplied at registration time. A field with no
classification-map entry causes `EventEnvelope` construction to throw
`Events\UnclassifiedFieldException` immediately — fail-closed, mirroring the existing pattern in
`Queue\JobEnvelope` and `Privacy\Redactor`.

`EventSource` is a fixed enum (`Events\EventSource`): `WORDPRESS_CORE` (the only value M02 itself
emits), reserved future values `WOOCOMMERCE`, `VISITOR`, `CUSTOM` are declared now (as enum cases)
so M03/M04/third-party code has a stable value to target without M02 needing to build their
emitters — declaring the enum case is not the same as building the emitter, and is not scope
creep since it costs nothing and prevents M03/M04 from needing their own enum-extension ADR later.

### 5.2 Registry and schema versioning

`Events\Registry` (composition-root-owned, one instance per request, rebuilt fresh every request —
consistent with ADR-0007's "always constructed, checked at point of use" pattern, never cached
across requests):

```php
final class Registry {
    public function register(
        string $event_type,
        int $schema_version,
        array $field_classification_map,   // path => Classification, every allowed field
        array $allowed_variable_fields,     // subset of the map's paths; PUBLIC or INTERNAL; usable in conditions/templates only, never persisted
        array $history_projection_fields    // subset of the map's paths; MUST be classified PUBLIC; persisted to durable event history
    ): void;                                 // throws EventTypeAlreadyRegisteredException, UnclassifiedFieldException, NonPublicHistoryFieldException
    public function is_registered( string $event_type ): bool;
    public function schema_version_for( string $event_type ): ?int;
    public function classification_map_for( string $event_type ): array;
    public function allowed_variable_fields_for( string $event_type ): array;
    public function history_projection_fields_for( string $event_type ): array;
    public function all(): array;            // for the admin event-catalog browser
}
```

`register()` is fail-closed on three independent checks: (1) `allowed_variable_fields` and
`history_projection_fields` must each be a subset of `field_classification_map`'s keys, or
registration throws `UnclassifiedFieldException`; (2) **every entry in
`history_projection_fields` must be classified exactly `Classification::PUBLIC`** in the same
map — an `INTERNAL`-classified field listed in `history_projection_fields` throws
`Events\NonPublicHistoryFieldException` — `INTERNAL` fields may be used transiently for rule
conditions and message templates (§7.2, §7.4) but can never reach the durable history projection
(§5.4); (3) duplicate `(event_type, schema_version)` registration throws
`EventTypeAlreadyRegisteredException`. There is no unregistration; the registry is rebuilt from
scratch every request from the single registration hook below, so a deactivated extension's event
types simply stop appearing.

### 5.3 Public extension surface: registration hook and the emission façade

Exactly **two** public extension surfaces — one hook, one function — both genuinely required by
the master plan and by ADR-0009's own forward pointer to "the milestone that first introduces this
plugin's own broader event-registration and rule-engine capability." No other hook or function is
added speculatively, and — correcting the prior revision — **event emission is never exposed as a
public `do_action()` hook at all**, for the reason below.

1. **`do_action( 'universal_telegram_register_event_types', Events\Registry $registry )`** — fired
   once, during the composition root's `init()`, at WordPress `init` priority 20 (after
   `Integrations\WooCommerce` presence detection, before any admin-menu registration). Core
   WordPress event types (§8) are registered on this same hook at priority 10; third-party code
   (and M03/M04 in later milestones) registers at any priority via the passed `$registry`
   instance. WordPress core does not catch action-callback exceptions, so a badly-behaved
   third-party registration can fatal that one request — accepted, as it did for
   `Queue\HandlerRegistry::register()` in M00/M01, since the failure is local to whichever
   request first loads the misbehaving extension and surfaces loudly during that extension's own
   development. Compatibility commitment: this hook's signature is stable for the lifetime of the
   `Events` boundary; a breaking change requires a superseding ADR.

2. **`universal_telegram_emit_event( string $event_type, array $data, string $idempotency_key ):
   void`** — a plain, stable, public PHP function (declared in the plugin's root namespace-safe
   procedural include, the plugin's established convention for the one or two functions meant for
   direct third-party PHP use rather than WordPress hook wiring). It delegates, unconditionally
   and without any additional public surface, to the composition root's singleton
   `Events\EventEmitter::emit()` (§5.3.1). `$data` uses the three sub-array shape (`actor`,
   `subject`, `context` — `payload` is derived from whichever of these the caller supplies under
   a `payload` key, or defaults to `[]`); a missing key defaults to `[]`.

**Why emission is a function, not a hook (design correction from revision 1):** the previous
revision fired `do_action('universal_telegram_event', ...)` as the emission point and hung
`EventDispatcher::on_event()` off it as the "sole subscriber," but a `do_action()` call site is,
by WordPress's own execution model, inherently multi-subscriber and inherently unable to protect
its caller from a listener's exception unless every individual listener call is separately wrapped
— which `do_action()` itself does not do. Exposing emission as a hook would have left two
unpalatable choices: claim a safety guarantee `do_action()` cannot structurally provide, or
explicitly carve out "third-party listener exceptions are not covered" as a documented gap. This
revision removes the gap entirely by making emission a **direct function call into a single,
internally-owned service** (§5.3.1) that wraps its own internal work in one `try/catch`, with no
listener registration surface at all — there is nothing for a third party to attach a
misbehaving subscriber to. `universal_telegram_emit_event()` is the only way, for M02's own
emitters and for any third party, to record an event; there is no parallel `do_action()`-based
path, public or internal, for emission.

#### 5.3.1 `Events\EventEmitter` — the safety façade

```php
final class EventEmitter {
    public function emit( string $event_type, array $data, string $idempotency_key ): void {
        try {
            $envelope = $this->build_envelope( $event_type, $data, $idempotency_key ); // may throw
            $this->dispatcher->handle( $envelope ); // internal orchestration, §5.5
        } catch ( \Throwable $e ) {
            $this->audit->log_fixed_failure_code( 'events.emission_failed', /* no message text */ );
        }
    }
}
```

Every failure mode — an unregistered event type, an unclassified field, an invalid idempotency
key, a downstream history-write or rule-evaluation exception — is caught here and reduced to one
fixed, non-message-carrying diagnostic code recorded via the existing `Audit` boundary. **This is
the entire safety guarantee, and it covers the whole call graph from `universal_telegram_emit_event()`
down through history projection and rule evaluation, because that whole call graph is now internal
method calls inside `emit()`'s own `try/catch`, not a chain of independently-firing hooks.**
`EventEmitter` is constructed once at composition-root `init()`, unconditionally, and is the sole
implementation backing `universal_telegram_emit_event()` — there is no way to reach
`Events\EventDispatcher::handle()` except through it.

### 5.4 Durable event history: a PUBLIC-only redacted projection

New table `universal_telegram_event_history` (migration step 8): `id BIGINT UNSIGNED AUTO_INCREMENT
PRIMARY KEY`, `event_id CHAR(64) NOT NULL UNIQUE` (the deterministic identity from §5.1),
`event_type VARCHAR(190) NOT NULL`, `schema_version SMALLINT UNSIGNED NOT NULL`,
`occurred_at DATETIME NOT NULL`, `source VARCHAR(32) NOT NULL`,
`projected_fields_json TEXT NOT NULL`, `created_at DATETIME NOT NULL`. Index on
`(event_type, occurred_at)` for the admin history browser and retention cleanup.

`projected_fields_json` contains **only** the fields listed in
`history_projection_fields_for($event_type)` — and, per §5.2's registration-time enforcement,
every one of those fields is guaranteed classified `PUBLIC`. `EventHistoryRepository::record()`
still calls `Privacy\Redactor::redact()` with the full classification map as defense in depth (so
a future change to `Redactor`'s own logic, or to the registration-time check, cannot silently
regress this guarantee without a second, independent test catching it — see WP2's test list), but
by construction **no `INTERNAL`, `SENSITIVE`, or `SECRET` field can ever reach this table**.
`INTERNAL` fields exist only inside the transient, in-memory `EventEnvelope` used for rule
condition evaluation and template rendering (§7.2, §7.4) and are never written here.

Insertion uses an idempotent `INSERT IGNORE` keyed on the `event_id` unique constraint — a replayed
event (same `event_type` + `schema_version` + `idempotency_key`, hence same `event_id`) produces
no second history row; the first write is authoritative. `EventHistoryRepository::record()` checks
`SchemaHealth::is_available()` first and is a no-op (logged, not thrown) if unavailable, per
ADR-0007's degraded-mode convention.

### 5.5 Internal ingestion orchestration

`Events\EventDispatcher::handle( EventEnvelope $event ): void` — called only by `EventEmitter`
(§5.3.1), never directly by any emitter or any hook subscriber. Performs, in order: (1) write the
history projection (§5.4); (2) invoke `Automations\RuleEvaluator::evaluate($event)` (§7.3). Both
steps run synchronously, in-process, for the duration of the originating request or job — this is
a deliberate design choice that avoids ever needing to durably store a full, unredacted event for
later asynchronous re-hydration; the only asynchronous step in the entire pipeline remains the
Telegram HTTP call itself, already handled by M01's existing queue via `MessageDispatcher::send()`.

**What is never persisted anywhere, by construction:** raw unredacted event payloads; any
`INTERNAL` field outside a rule condition/template's transient in-memory use; any field classified
`SENSITIVE` or `SECRET` (rejected at `EventEnvelope` construction, §5.1 — such a field cannot exist
inside an event at all); secrets or credential-like values; full HTTP request bodies; PHP stack
traces; passwords or password hashes (the `wordpress.password_reset` emitter, §8, never reads
`$new_pass` at all); arbitrary free-text PHP error/exception messages (the fatal-error mechanism,
§8.6, captures only a fixed error-type constant and a file/line hash, never message text). The
fully rendered Telegram message text is durable **only** in M01's existing
`CredentialVault`-encrypted `universal_telegram_outbound_messages` table — M02 introduces no
second place where rendered notification content is stored.

**Retention and cleanup:** a new recurring Action Scheduler action,
`universal_telegram_events_retention_cleanup`, registered in the same queue group as M01's
existing recurring jobs, running daily. It deletes `event_history` rows older than
`universal_telegram_event_retention_days` (new `Settings` field, default 90),
`notification_dispatch_log` rows (§7.5) older than
`universal_telegram_dispatch_log_retention_days` (default 90), and `fatal_error_markers` rows per
the lifecycle-specific rules in §8.6 — all added to the existing `Core\Configuration\Settings`
allowlist-based sanitizer alongside M01's existing retention settings. Cleanup runs as a bounded
`DELETE ... WHERE occurred_at < %s LIMIT 500` loop to avoid long table locks.

**Degraded-schema behavior:** every `Events`/`Automations` repository is always constructed at
composition-root time (never conditionally, per ADR-0007) and checks `SchemaHealth::is_available()`
at its own point of use. If unavailable: `EventHistoryRepository::record()` no-ops; rule evaluation
(§7.3) treats "no rules loadable" identically to "zero enabled rules" (no dispatch occurs, no
error surfaces to the WordPress request that emitted the event); the admin rule-builder and event-
history screens render the same fixed `SchemaHealth::failure_code()` string the Diagnostics page
already uses, never a raw DB error.

**Uninstall:** **four** new `DROP TABLE IF EXISTS` lines (guarded by the existing
`remove_data_on_uninstall` setting) added to `Core\Lifecycle\Uninstaller` — `event_history`,
`fatal_error_markers`, `notification_rules`, and `notification_dispatch_log` — plus the new
capability constant(s) added to `CapabilityRegistrar::revoke_from_all_roles()`'s existing
unconditional revoke list, and the new retention `Settings` fields deleted along with the rest of
the plugin's options row, exactly as M00/M01's existing settings already are.

## 6. Security, privacy, and audit

### 6.1 Privacy classification as a registered extension point

M00's `Privacy\Redactor` and `Privacy\Classification` remain exactly as built — M02 does not
change their fail-closed behavior. What M02 adds is the mechanism by which a classification map is
supplied for event data (`Events\Registry::register()`'s `$field_classification_map`), and a
stricter, registration-time-enforced rule specific to M02's own history projection: any field
routed to durable history must be classified `PUBLIC`, never merely "classified" (§5.2). This is
the "genuine public extension point... once a later milestone that actually needs one" that
ADR-0009 anticipated M02 would be, combined with M02's own additional privacy commitment that goes
beyond what ADR-0009 itself required.

### 6.2 Audit trail: event → rule → outbound message

`notification_dispatch_log` (§7.5) is the traceability record connecting a normalized event to
the rule that matched it and to the M01 outbound message it produced, satisfying the "delivery-
log/audit traceability from event to rule to M01 outbound message" requirement. Every rule
evaluated against every event produces exactly one row (§7.5), reached through the existing
`Audit\AuditLogger`, whose `log()` call already requires a classification map argument and
performs redaction internally — M02 supplies the map built in §6.1, so no raw event or rule
content reaches the audit log unredacted.

### 6.3 Access control

New capability constant `CapabilityRegistrar::MANAGE_AUTOMATIONS =
'universal_telegram_manage_automations'`, distinct from M01's existing `MANAGE` constant, granted
to `administrator` on activation and unconditionally revoked from every role on uninstall,
following ADR-0010's exact pattern. Rationale: rule/event configuration is a genuinely distinct
authorization need from bot/destination configuration, satisfying ADR-0010's own bar for adding a
new constant rather than reusing the existing one. Every admin write handler independently
re-verifies both this capability and a nonce inside its own request handler, mirroring
`DiagnosticsPage`'s existing pattern exactly.

### 6.4 Threat summary

- **Unregistered/malformed event type, unclassified field, or invalid idempotency key at
  emission**: fails closed inside `EventEmitter::emit()`'s own `try/catch`, logged with a fixed
  code, never fatals the emitting request (§5.3.1).
- **Third-party emission-path exceptions**: structurally impossible to leak past `EventEmitter`,
  since there is no hook-based emission surface for a third party to attach a throwing listener to
  in the first place (§5.3) — the entire call graph from `universal_telegram_emit_event()` through
  history write and rule evaluation is inside one function's `try/catch`.
- **Sensitive/internal data leakage into durable history**: structurally impossible — registration
  rejects any non-`PUBLIC` field from `history_projection_fields` (§5.2); a mis-registered event
  type declaring a field `PUBLIC` that shouldn't be is a registration-time defect in reviewed code,
  mitigated by requiring PHPCS/PHPStan-clean, reviewed code for every new emitter this plan adds.
- **Template injection / arbitrary code execution via message templates**: structurally
  impossible — the interpolator (§7.4) is a fixed-grammar `{{ field.path }}` token replacer
  against an explicit per-event-type allowlist, never `eval()`.
- **Condition-model injection**: conditions are stored as a fixed JSON clause shape validated
  against the same per-event-type field allowlist and a fixed operator enum at write time (§7.2).
- **Duplicate rule-engine handoff on replay**: prevented by the deterministic `event_id` (§5.1)
  combined with the `(rule_id, event_id)` unique constraint in §7.5 — see §7.5 for the precise,
  honestly-scoped guarantee this provides and does not provide.
- **Notification feedback loops**: the plugin's own queue-group failures and the plugin's own REST
  namespace's failed requests are explicitly excluded from event emission (§8.7) so a broken
  Telegram transport, or a malicious/malformed actor targeting the webhook route, can never cause
  the plugin to attempt to notify about itself via the very channel that is failing or being
  attacked.
- **Diagnostics/log leakage**: event history stores only the PUBLIC-only redacted projection
  (§5.4); audit log entries are redacted via the same classification map (§6.2); the admin
  rule-simulation tool (§9.2) may display `INTERNAL` fields transiently to a capability-gated
  admin for diagnostic purposes but never writes them anywhere (§5.2, §9.2).

## 7. Rule engine

### 7.1 Rule model and storage

New table `universal_telegram_notification_rules` (migration step 9): `id`, `name VARCHAR(190)`,
`event_type VARCHAR(190) NOT NULL`, `schema_version_min SMALLINT UNSIGNED NOT NULL`,
`conditions_json TEXT NOT NULL` (a flat JSON array of clauses, §7.2 — empty array `[]` means
"always matches, no condition"), `bot_id BIGINT UNSIGNED NOT NULL`,
`destination_id BIGINT UNSIGNED NOT NULL`, `template TEXT NOT NULL`, `enabled TINYINT(1) NOT NULL
DEFAULT 1`, `priority INT NOT NULL DEFAULT 100`, `cooldown_seconds INT UNSIGNED NOT NULL DEFAULT
0`, `created_at DATETIME NOT NULL`, `updated_at DATETIME NOT NULL`. Index on `(event_type,
enabled, priority, id)` — exactly the columns the evaluator's deterministic ordering query needs
(§7.3). No foreign-key constraints, consistent with the plugin's existing referential-integrity-
at-the-repository-layer convention; `bot_id`/`destination_id` are validated to exist (and be
enabled, for the destination) at rule save time and re-validated at dispatch time (§7.5) since
they may be deleted or disabled after a rule is saved.

`Automations\NotificationRule` — final immutable value object mirroring `BotProfile`/
`Destination`'s existing pattern; `Automations\NotificationRuleRepository` — `find()`,
`for_event_type( string $event_type, bool $enabled_only = true ): array` (the exact query the
evaluator uses), `all()`, `save()`, `delete()`.

### 7.2 Condition model (AND-only, no nesting)

A rule's `conditions_json` is a flat array; every clause must evaluate true for the rule to match
— logical AND across the whole array, with **no OR and no nesting**, exactly matching the
charter's explicit scope boundary. Each clause: `{"field": "subject.order_status", "operator":
"equals", "value": "failed"}`. `field` must be a member of
`Registry::allowed_variable_fields_for($event_type)` — the same allowlist used for templates,
§7.4, which may include both `PUBLIC` and `INTERNAL` fields (§5.2) since conditions are evaluated
transiently, in-memory, and never persist the field's value anywhere — checked at rule-save time
(`NotificationRuleRepository::save()` throws `Automations\InvalidConditionFieldException`
otherwise) and defensively re-checked at evaluation time in case the registry changed since the
rule was saved. `operator` is one of a fixed enum (`Automations\ConditionOperator`): `EQUALS`,
`NOT_EQUALS`, `CONTAINS`, `GREATER_THAN`, `LESS_THAN`, `IN`. An unknown field or operator at
evaluation time makes that one rule evaluate to "rejected — invalid configuration" (§7.3, §7.5)
without affecting any other rule.

### 7.3 Evaluation: deterministic order, multi-match, failure isolation

`Automations\RuleEvaluator::evaluate( EventEnvelope $event ): void`, called only by
`Events\EventDispatcher::handle()` (§5.5), immediately after the history-projection write:

1. Load `NotificationRuleRepository::for_event_type($event->event_type(), enabled_only: true)` —
   the repository's own query orders by `priority ASC, id ASC`, so evaluation order is fully
   determined by data already in the query, not by application-level sorting — this is what makes
   "rule evaluation is deterministic" (the charter's first acceptance criterion) mechanically true
   rather than merely intended.
2. For each rule, in that fixed order, inside its own `try { } catch ( \Throwable $e )`: evaluate
   every clause in `conditions_json` against `$event`'s actor/subject/context/payload (only fields
   present in `allowed_variable_fields_for()` are ever read). If any clause fails, or the rule's
   own configuration is invalid, delegate to `Automations\DispatchLogRepository` to record a
   `rejected` outcome (§7.5) and move to the next rule. If all clauses pass, delegate to
   `Automations\NotificationDispatcher::dispatch($rule, $event)` (§7.5) and move to the next rule
   regardless of that call's outcome. If an exception is thrown anywhere in this rule's own
   evaluation or dispatch, it is caught here, a fixed diagnostic code is recorded, and evaluation
   moves to the next rule. **One rule's exception never stops evaluation of the remaining rules.**
3. A single event may match, and independently dispatch through, more than one enabled rule —
   each match is dispatched independently, subject only to that rule's own cooldown (§7.5), never
   suppressed because another rule already matched the same event.

Every outcome above is what the rule-simulation tool (§9.2) surfaces, in the same order, giving
"a clear explanation of matched and rejected rules" directly from the same code path real
evaluation uses.

### 7.4 Message templates

A fixed-grammar interpolator, `Automations\TemplateRenderer::render( string $template,
EventEnvelope $event, array $allowed_fields ): string`, replacing every `{{ field.path }}` token
where `field.path` is a member of `$allowed_fields` (`allowed_variable_fields_for()`, `PUBLIC` or
`INTERNAL`, transient use only) with that field's value from the event, MarkdownV2-escaped for
Telegram (escaping the fixed `_*[]()~\`>#+-=|{}.!` character set Telegram's MarkdownV2 parse mode
requires — see §10 for the citation). A token referencing a field not in `$allowed_fields`, or
missing from the event's actual data, renders as an empty string and increments a fixed "template
field missing" counter recorded on the dispatch-log row (§7.5) — never a PHP notice, never the raw
unescaped value, never a fatal. No conditionals, loops, or function calls exist in the grammar.

### 7.5 Deduplication and dispatch: an honest state model

New table `universal_telegram_notification_dispatch_log` (migration step 10): `id`,
`rule_id BIGINT UNSIGNED NOT NULL`, `event_id CHAR(64) NOT NULL` (§5.1's deterministic identity),
`outbound_message_uuid CHAR(36) NULL`, `result VARCHAR(32) NOT NULL`, `reason_code VARCHAR(64)
NULL`, `dispatched_at DATETIME NOT NULL`, `updated_at DATETIME NOT NULL`.
**`UNIQUE KEY (rule_id, event_id)`.**

`result` is one of exactly seven values, `Automations\DispatchLogResult`:

- **`claimed`** — an atomic `INSERT IGNORE (rule_id, event_id, result='claimed', dispatched_at=NOW(),
  updated_at=NOW())` succeeded (affected exactly one row) for a rule whose conditions matched. This
  is a transient, in-progress state, never a final outcome an operator should read as "handled."
- **`rejected`** — the rule's conditions did not match, or the rule's own configuration was
  invalid. Written directly (no prior `claimed` row) via the same atomic `INSERT IGNORE` pattern
  keyed on `(rule_id, event_id)`.
- **`skipped_duplicate`** — the atomic claim/reject insert affected zero rows because a row for
  this exact `(rule_id, event_id)` pair already existed from an earlier evaluation of the same
  replayed event against the same rule. **No further write of any kind occurs; the pre-existing
  row is left exactly as it stands and is authoritative.** This is the entire duplicate-prevention
  mechanism, stated precisely: it prevents a second *rule-engine decision* for the same pair, not
  a second *anything-at-all* — see the guarantee statement below.
- **`skipped_cooldown`** — the `claimed` row's rule has `cooldown_seconds > 0` and the same rule's
  most recent `handed_off_to_m01` row is within that window; the `claimed` row is updated to this
  terminal state, no template is rendered, no send is attempted.
- **`skipped_disabled_reference`** — the `claimed` row's rule references a `bot_id`/`destination_id`
  that no longer exists or is disabled at dispatch time; updated from `claimed`, no send attempted.
- **`handed_off_to_m01`** — `MessageDispatcher::send()` was called and its `DispatchResult`
  indicated the message was successfully written to `outbound_messages` and enqueued;
  `outbound_message_uuid` is set; the `claimed` row is updated to this terminal state. **This is
  the only state that means "M01 has taken durable, queued ownership of sending this message."**
  It does not mean the Telegram API call itself has happened or succeeded — that remains entirely
  M01's own, unchanged, at-least-once responsibility (ADR-0014).
- **`failed_before_handoff`** — `MessageDispatcher::send()` returned a `DispatchResult` indicating
  failure (per M01's existing never-throwing contract) before any durable enqueue occurred; the
  `claimed` row is updated to this terminal state with `reason_code` set from the `DispatchResult`.
  No message was sent and none is pending.

**Sequence:** (1) atomic claim-or-reject insert as above; if `skipped_duplicate`, stop — nothing
else runs for this (rule, event) pair, ever. (2) if `claimed`, check cooldown against the rule's
most recent `handed_off_to_m01` row (never against `claimed` rows, to avoid an in-flight claim
confusing cooldown timing) — if within window, update to `skipped_cooldown`, stop. (3) re-validate
`bot_id`/`destination_id` (`BotProfileRepository::find()`, `DestinationRepository::find()`) — if
missing/disabled, update to `skipped_disabled_reference`, stop. (4) render the template (§7.4),
call `MessageDispatcher::send()` unchanged — no new queue job type, no new `JobEnvelope` shape, no
parallel dispatch path is introduced anywhere in this plan, which is the exact mechanism by which
"M01's opaque queue-payload rule remains enforced," by construction. (5) update to
`handed_off_to_m01` (with `outbound_message_uuid`) or `failed_before_handoff` (with `reason_code`)
based on the returned `DispatchResult`.

**What happens if the PHP request terminates, at each point:**

- **Before the claim insert**: no row exists. If the source never re-emits this exact
  `idempotency_key`, this occurrence is simply never evaluated against this rule again — no
  message was ever promised, so nothing is lost that was owed. If the source does re-emit
  (a legitimate retry at the WordPress-hook or Action-Scheduler level), evaluation runs fresh and
  correctly, exactly as if this were the first attempt.
- **After the claim insert (`claimed`) but before step 5's update**: the row is stuck in
  `claimed` indefinitely — this is the **one deliberately accepted, diagnosable, non-silent
  limitation** in this design. A `claimed` row older than a fixed staleness threshold (30 minutes,
  matching the order of magnitude of ADR-0014's own staleness concept) is surfaced as a distinct,
  named `automations_stuck_claim_count` field on the Diagnostics page (§9.4) — visible, counted,
  never hidden. **No automatic recovery/resume job is built for this case** (§3's explicit
  exclusion): safely resuming would require re-running rule evaluation from outside the normal
  event-emission code path with its own re-entrancy guarantees, which is out of M02's scope. A
  stuck `claimed` row is never displayed, logged, or reported as `handed_off_to_m01` — it is
  always distinguishable, by its own `result` column value, from every state that means a message
  was actually queued.
- **After `MessageDispatcher::send()` returns successfully but before the row's own update to
  `handed_off_to_m01` completes**: the underlying Telegram send is **not** at risk — M01's
  `send()` has already durably written the `outbound_messages` row and enqueued the `JobEnvelope`
  before returning, independent of M02's own bookkeeping. The narrow risk here is purely
  cosmetic: the dispatch-log row may remain visible as `claimed` (and, past the staleness
  threshold, counted in `automations_stuck_claim_count`) even though the message is in fact
  correctly proceeding through M01's queue. This is documented explicitly as a **harmless
  bookkeeping undercount, not a duplicate-delivery risk and not a lost notification** — the actual
  send already happened and is governed entirely by M01's own, unchanged guarantees.

**The guarantee, stated exactly:** M02 prevents a second rule-engine handoff *decision* for the
same stable `(rule_id, event_id)` pair — once any row exists for that pair, no second call to
`MessageDispatcher::send()` is ever made for it. M01's transport underneath remains at-least-once,
not exactly-once, and may still independently set `possible_duplicate_delivery` on an
already-handed-off message per ADR-0014's own, unchanged, transport-level guarantee. **M02 does
not claim exactly-once Telegram delivery** — it claims exactly-once *decision-making*, with one
narrow, explicitly diagnosable, non-retried exception (the mid-request-termination `claimed` case
above), which is never mislabeled as a successful dispatch.

## 8. Core WordPress event coverage

Every emitter below is a thin, reviewed callback on an existing WordPress hook that builds a
`$data` array and an idempotency key, then calls `universal_telegram_emit_event($event_type,
$data, $idempotency_key)` (§5.3) — no emitter performs any Telegram call, any queue interaction,
or any DB write beyond what `EventEmitter`/`EventDispatcher` already do downstream. Test seam for
every emitter: WP integration tests fire the underlying WordPress hook directly against a
`Registry`/`EventEmitter` wired to fake repositories, asserting the emitted `event_type`, `$data`
shape, and idempotency-key derivation.

| Event type | WordPress hook | Idempotency key | Deduplicable? |
|---|---|---|---|
| `wordpress.login_succeeded` | `wp_login` (`$user_login, WP_User $user`) | fresh `wp_generate_uuid4()` per call | No — WordPress core fires this exactly once per successful login attempt and never retries it; two occurrences with the same user are always genuinely independent logins |
| `wordpress.login_failed` | `wp_login_failed` (`$username, ?WP_Error $error`) | fresh UUID per call | No — same rationale |
| `wordpress.admin_login` | derived from `wp_login` when `user_can($user, MANAGE)` | fresh UUID per call | No — emitted alongside `login_succeeded`, same occurrence semantics |
| `wordpress.user_registered` | `user_register` (`$user_id`) | fresh UUID per call | No — one-shot per registration; WordPress does not retry this hook |
| `wordpress.user_role_changed` | `set_user_role` (`$user_id, $role, $old_roles`) | fresh UUID per call | No — a repeated role change by an admin is a genuinely new occurrence, not a replay |
| `wordpress.password_reset` | `after_password_reset` (`$user, $new_pass`) | fresh UUID per call | No; `$new_pass` is never read |
| `wordpress.post_published` | `transition_post_status`, filtered `$new_status==='publish' && $old_status!=='publish'` | `sha256("post:{$post_id}:publish")` | **Yes** — this hook is documented to fire more than once for a single logical publish action in some WordPress code paths (e.g. meta-box save interplay); the stable per-post key collapses these into one occurrence |
| `wordpress.comment_submitted` | `comment_post` (`$comment_ID, $comment_approved`) | `sha256("comment:{$comment_ID}")` | **Yes** — collapses any duplicate firing for the same inserted comment row |
| `wordpress.plugin_activated` | `activated_plugin` (`$plugin, $network_wide`) | fresh UUID per call | No — a later re-activation of the same plugin is a genuinely new occurrence |
| `wordpress.plugin_deactivated` | `deactivated_plugin` | fresh UUID per call | No — same rationale |
| `wordpress.update_available` | daily recurring Action Scheduler check against `get_site_transient('update_core'\|'update_plugins'\|'update_themes')` | `sha256("update_available:{$component}:{$new_version}:{$day}")` | **Yes** — one notification per pending update per calendar day, not one per daily check |
| `wordpress.update_completed` | `upgrader_process_complete` (`$upgrader, $hook_extra`) | `sha256("update_completed:{$hook_extra_signature}")` derived from action type + plugin/theme slug + target version | **Yes** — collapses a single upgrade action's own internal multiple-callback firing, if any |
| `wordpress.scheduled_task_failed` | `action_scheduler_failed_action` — **excluding the `universal-telegram` queue group, see §8.7** | `sha256("as_failed:{$action_id}")` | **Yes** — repeated failure notifications for the same queued action instance collapse to one occurrence |
| `wordpress.rest_request_failed` | `rest_request_after_callbacks`, when `is_wp_error()` — **excluding the plugin's own REST namespace, see §8.7** | fresh UUID per call | No — each failing request is generated by a distinct caller/context; collapsing them would hide genuinely independent failures |
| `wordpress.email_sending_failed` | `wp_mail_failed` (`WP_Error $error`) | fresh UUID per call | No — WordPress does not itself retry `wp_mail()`; consecutive firings are independent failures |
| `wordpress.fatal_error` | not a direct hook — see §8.6 | the promoted marker's own stable identity (§8.6) | **Yes** — the promotion job is idempotent by construction via this same event-identity mechanism |

### 8.6 Fatal-error coverage: bounded, privacy-safe, no synchronous shutdown-time dispatch

**Scope boundary, stated explicitly:** this mechanism observes only fatal-class PHP errors
(`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`) encountered **after** this plugin's own
shutdown handler has been registered, which happens during this plugin's own bootstrap
(composition-root `init()`, itself hung off `plugins_loaded`). **A parse or compile error in this
plugin's own bootstrap file, in a plugin that loads earlier than this one, or in `wp-config.php`
itself, occurs before the shutdown handler can be registered and is not, and cannot be, observed
by this mechanism.** This is a hard PHP-execution-model boundary, not a design gap this plan
attempts to work around — it is stated here so the capability is never overclaimed.

A PHP-fatal/shutdown execution context cannot safely perform a DB write through the plugin's own
full stack (autoloader/object-graph state is unreliable at that point) and must never attempt any
external call. This plan implements a two-phase, bounded mechanism:

**Phase 1 (shutdown-safe marker write):** the registered `register_shutdown_function()` callback,
on detecting a fatal-class `error_get_last()`, performs a single, defensively-guarded raw
`$wpdb->query()` upsert against `universal_telegram_fatal_error_markers` (part of migration step
8, alongside `event_history`), wrapped so any failure is silently swallowed (a shutdown handler
must never itself fatal). Table columns: `id`, `error_type VARCHAR(32) NOT NULL` (the fixed PHP
error constant name, not free text), `location_hash CHAR(64) NOT NULL` (SHA-256 of `file:line`,
location-identifying without exposing a path an attacker-controlled input might have influenced),
`status VARCHAR(16) NOT NULL DEFAULT 'pending'` (`pending` | `promoted`), `occurred_at DATETIME
NOT NULL`, `promoted_at DATETIME NULL`, `created_at DATETIME NOT NULL`. **Unique key
`(error_type, location_hash)`** — the bounded-deduplication mechanism: the upsert is
`INSERT ... ON DUPLICATE KEY UPDATE occurred_at = VALUES(occurred_at), status = IF(status =
'promoted' AND promoted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR), 'pending', status)` — a fatal error
at the same location that recurs while its marker is still `pending`, or within an hour of its
last promotion, only bumps `occurred_at` on the existing row (no flood of duplicate markers for a
repeatedly-hit fatal); one recurring more than an hour after its last promotion reopens the same
row to `pending` so it is promoted (and thus notified about, if a rule matches) again — a bounded
"re-alert" window, not an unbounded one. **Never stored, at any point, in any column: the error
message string, a stack trace, the raw unredacted file path, or any request content.**

**Phase 2 (safe promotion, idempotent):** a new recurring Action Scheduler action,
`universal_telegram_promote_fatal_error_markers`, running every five minutes in the normal queued-
job execution context (the same safe context M01's own `WorkerRunner` already runs in), selects
`status = 'pending'` marker rows and, for each, calls `universal_telegram_emit_event(
'wordpress.fatal_error', ['payload' => ['error_type' => ..., 'location_hash' => ...]],
$idempotency_key = "fatal_marker:{$marker_id}:{$occurred_at}" )`, then updates that row to
`status = 'promoted', promoted_at = NOW()`. **Idempotency, not a lock:** if the job is interrupted
between the `emit()` call and the status update and re-runs the same `pending` row, the second
`emit()` call carries the same idempotency key (same marker id and `occurred_at`, since the row
was never updated), derives the same `event_id` (§5.1), and is recognized as `skipped_duplicate`
by the dispatch log (§7.5) and as a no-op `INSERT IGNORE` by event history (§5.4) — no second
notification results. This is the same general-purpose identity mechanism from §5.1, deliberately
reused rather than building a separate promoter-specific lock.

**Retention:** promoted markers older than `universal_telegram_fatal_marker_retention_days` (new
`Settings` field, default 30) are deleted by the retention-cleanup job (§5.5). `pending` markers
older than a fixed 24-hour ceiling are also deleted, on the basis that a fatal error too old to
have been promoted within a day is no longer usefully actionable — each such drop increments a
`automations_stale_fatal_markers_dropped_count` diagnostics counter (§9.4), so the behavior is
visible, not silent. **Uninstall:** the `fatal_error_markers` table is one of the four tables
covered by §5.5's uninstall list.

### 8.7 Preventing notification feedback loops

Two exclusions, both enforced inside the emitter itself (the event is never emitted at all — not
filtered later, not suppressed at rule-evaluation time):

- **`wordpress.scheduled_task_failed` excludes any failed action whose Action Scheduler group
  equals `Queue\WorkerRunner::GROUP`** (the `universal-telegram` group used by every job this
  plugin itself enqueues, including its own outbound Telegram sends and its own retention/
  promotion jobs). **Rationale:** if the Telegram transport itself is the thing failing, attempting
  to notify about that failure *via Telegram* is either pointless (the same transport is broken)
  or actively counterproductive (adds load to an already-failing or rate-limited transport, and
  risks masking the real failure behind another failure). This class of failure is already fully
  surfaced by M01's own existing, unchanged mechanism — the diagnostics page and
  capability-gated `admin_notices` banner (ADR-0014) — which this plan does not duplicate.
- **`wordpress.rest_request_failed` excludes any request whose route starts with
  `/universal-telegram/v1/`** (the plugin's own REST namespace, which includes M01's inbound
  webhook route). **Rationale:** the webhook route is a public, unauthenticated-until-verified
  endpoint by design (ADR-0013) — it will legitimately receive malformed or malicious traffic from
  the open internet. Emitting a normalized event, and potentially a Telegram notification, for
  every such rejected request would (a) create a trivial amplification vector where an attacker
  causes this plugin to send itself Telegram traffic merely by sending malformed webhook requests,
  and (b) add pure noise with no operator value, since ADR-0013's own uniform-401/400 design
  already treats all such rejections identically and deliberately non-diagnostically at the
  transport layer.

Both exclusions are unconditional and are not configurable via any admin setting in M02 — making
them configurable would reopen exactly the feedback-loop and amplification risks they exist to
close, and no charter requirement calls for that configurability.

## 9. Administration and diagnostics

### 9.1 Event catalog and rule builder

New `Automations` admin subdomain under `Administration`, gated by
`CapabilityRegistrar::MANAGE_AUTOMATIONS` on the menu-registration capability parameter itself
plus independent capability+nonce re-verification inside every write handler, mirroring
`DiagnosticsPage`'s existing pattern exactly. Event catalog screen lists `Registry::all()` — event
type, schema version, and its declared allowlisted fields — read-only. Operator-facing presentation
later adds plain-language description and field labels beside the technical identifiers (see
`Administration\Automations\EventCatalogLabels`); the registry contract and technical event-type /
field paths remain authoritative for rules, simulators, and history filters. Rule builder screen:
CRUD over `NotificationRuleRepository`, condition-clause editor constrained to the selected event
type's allowlist and the fixed operator enum, validated identically client-side and server-side
(the server-side check in `NotificationRuleRepository::save()` is authoritative).

### 9.2 Rule simulation (no live external traffic)

`Automations\RuleSimulator::simulate( string $event_type, array $sample_data, string
$idempotency_key ): SimulationResult` — constructs an `EventEnvelope` from either a hand-entered
sample payload or an existing `event_history` row, then calls the identical
`RuleEvaluator::evaluate()` code path used for real events, with dispatch's step 4 (§7.5) replaced
by a no-op "would send" marker — no `MessageDispatcher::send()` call, no queue enqueue, no HTTP
traffic, and **no write to `notification_dispatch_log`** (simulation never consumes the
`(rule_id, event_id)` idempotency space a real occurrence might later need). The result surfaces
every rule's outcome in the same deterministic order §7.3 guarantees. Because conditions/templates
may reference `INTERNAL` fields (§5.2, §7.2), the simulation UI may display those field values
transiently to the capability-gated admin viewing the result — this is a live, in-memory render,
never a write to any table, and is explicitly distinguished in the UI from the PUBLIC-only content
the event-history browser (§9.3) shows.

### 9.3 Event history browser

Read-only admin screen over `event_history` rows — event type, schema version, occurred_at,
source, and the already-PUBLIC-only `projected_fields_json` (§5.4). Paginated, filterable by event
type and date range, using the `(event_type, occurred_at)` index.

### 9.4 Diagnostics integration

`DiagnosticsReport::generate()` gains: `automations_event_count_24h`, `automations_rule_count`,
`automations_enabled_rule_count`, `automations_dispatch_failed_count_24h`,
`automations_stuck_claim_count` (§7.5), `automations_stale_fatal_markers_dropped_count` (§8.6),
`automations_last_evaluation_error_code` (fixed code only, never a message string). No new
Diagnostics class is created; `DiagnosticsPage::render()` gains one additional read-only section
using these fields. Rule/event health never generates a Telegram-message notification about its
own failure — the same principle ADR-0014 already established, now also the explicit mechanism
behind §8.7's exclusions.

## 10. External research

- Action hook and hook-parameter references for `wp_login`, `wp_login_failed`,
  `user_register`, `set_user_role`, `after_password_reset`, `transition_post_status`,
  `comment_post`, `activated_plugin`, `deactivated_plugin`, `upgrader_process_complete`,
  `rest_request_after_callbacks`, `wp_mail_failed` — WordPress Developer Reference, Plugin
  Hooks (developer.wordpress.org/reference/hooks/), including the documented behavior that
  `transition_post_status` can fire more than once for a single logical status transition,
  motivating §8's `post_published` idempotency-key design.
- `register_shutdown_function()` and `error_get_last()` semantics, and the documented constraint
  that object state and autoloading are not reliably usable during shutdown after a fatal error,
  and that a fatal error occurring before a given plugin's own bootstrap code has run cannot be
  observed by anything that plugin registers — PHP Manual
  (php.net/manual/en/function.register-shutdown-function.php,
  php.net/manual/en/function.error-get-last.php) — the basis for §8.6's scope boundary and
  two-phase design.
- `$wpdb->prepare()`, `$wpdb->insert()`, `$wpdb->query()` (for `INSERT ... ON DUPLICATE KEY
  UPDATE` and `INSERT IGNORE` patterns) — WordPress Developer Reference, `wpdb` class
  (developer.wordpress.org/reference/classes/wpdb/) — governs every new repository's query
  construction, including the atomic claim-or-reject insert in §7.5 and the marker upsert in §8.6.
- WordPress capabilities and roles (`add_menu_page()`'s capability parameter, `current_user_can()`,
  nonces via `wp_verify_nonce()`) — WordPress Developer Reference, Roles and Capabilities /
  Plugin Security (developer.wordpress.org/plugins/security/nonces/,
  developer.wordpress.org/reference/functions/current_user_can/) — governs §6.3.
- `WP_Error` and REST API error/response conventions for `rest_request_after_callbacks`, and the
  REST route-namespace convention this plugin's own webhook route uses — WordPress REST API
  Handbook (developer.wordpress.org/rest-api/) — governs the `rest_request_failed` emitter's
  detection logic and its own-namespace exclusion in §8.7.
- Action Scheduler's own hook surface (`action_scheduler_failed_action`, its exposed group/action
  accessors, `as_schedule_recurring_action()`) — Action Scheduler documentation
  (actionscheduler.org/api/) — governs the `scheduled_task_failed` emitter, its own-group
  exclusion in §8.7, and the two new recurring cleanup/promotion jobs.
- Telegram Bot API MarkdownV2 formatting and required character-escaping rules — Telegram Bot API
  documentation, Formatting options (core.telegram.org/bots/api#markdownv2-style) — governs
  §7.4's template-rendering escape set. No live Telegram API call is made by any test in this plan.

## 11. Test, CI, and validation strategy

Both WordPress-only and WooCommerce-present integration configurations continue to run, since
`Events`/`Automations` must function with WooCommerce absent — M02 adds no WooCommerce-conditional
code path at all.

**Per work package (focused validation only):** PHPCS and PHPStan restricted to that work
package's changed files; targeted unit tests for new pure-logic classes (`EventIdentity`,
`Registry`, `RuleEvaluator`'s clause matching, `TemplateRenderer`, `ConditionOperator`,
`DispatchLogResult` transitions); targeted integration tests for anything touching the database,
WordPress hooks, Action Scheduler, or admin request handlers. No work package requires the full
three-configuration matrix or a package/ZIP build.

**Once, after the final work package, immediately before PR (full matrix only):** full PHPCS/
PHPStan across all of `src/`; full unit suite across PHP 8.1/8.3/8.4; full integration suite across
all three configurations; `bin/docker/build-zip.sh` producing `universal-telegram-0.2.0.zip`;
`bin/docker/test-package.sh` across the same three configurations, extended with package-
acceptance assertions that (a) no `event_history` row in the packaged test fixtures contains
anything outside its `PUBLIC`-only declared allowlist, (b) no `fatal_error_markers` row contains
message text or a stack trace, and (c) the two diagnostic surfaces never render a raw exception
message; `docs/link` checker. If any final-gate check fails, only the failed check and directly
affected tests are rerun after the fix; the full matrix reruns once all final-gate failures are
resolved.

## 12. Work packages

Ten work packages, each following M01's exact template (Objective / Files added / Files modified /
Test files / Validation commands / Acceptance evidence / Planned commit message).

### WP1 — Boundary scaffolding, schema, and capability

**Objective:** Create the `Events`/`Automations` directory scaffolding, extend `Migrator` with
steps 8–10 (four new tables — `event_history`, `fatal_error_markers` in step 8;
`notification_rules` in step 9; `notification_dispatch_log` in step 10), update the structural
guard test, add the new capability constant, and sync the architecture reference.

**Files added:** `src/Events/.gitkeep`, `src/Automations/.gitkeep` (both removed once WP2/WP5 add
real files).

**Files modified:** `src/Persistence/Migrator.php` (add `step_8_create_events_and_markers_tables()`
+ `verify_step_8()` — `event_history` with columns per §5.4 and `fatal_error_markers` with columns
per §8.6, including the `(error_type, location_hash)` unique key and `status`/`promoted_at`
lifecycle columns; `step_9_create_notification_rules_table()` + `verify_step_9()`;
`step_10_create_notification_dispatch_log_table()` + `verify_step_10()` with `result`,
`reason_code`, `updated_at` columns per §7.5; bump `target_version()` to `10`);
`src/Core/Capabilities/CapabilityRegistrar.php` (add `MANAGE_AUTOMATIONS` constant); `src/Core/
Lifecycle/Uninstaller.php` (four new `DROP TABLE IF EXISTS` lines per §5.5); `tests/unit/Core/
StructuralBoundariesTest.php` (permit `Events`/`Automations`, continue guarding
`Conversations`/`ChatWidget`/`AI`); `docs/ARCHITECTURE.md` (boundary table, schema version,
plugin version sections).

**Test files added:** `tests/integration/Persistence/MigratorEventsSchemaTest.php` (asserts steps
8–10 create the four new tables with the exact columns/indexes/unique-keys above).

**Test files modified:** `tests/unit/Core/StructuralBoundariesTest.php`,
`tests/unit/Core/Capabilities/CapabilityRegistrarTest.php`.

**Validation commands:** `bin/docker/phpcs.sh src/Persistence/Migrator.php
src/Core/Capabilities/CapabilityRegistrar.php src/Core/Lifecycle/Uninstaller.php`;
`bin/docker/phpstan.sh src/Persistence src/Core`; `bin/docker/test-unit.sh --filter
'StructuralBoundariesTest|CapabilityRegistrarTest'`; `bin/docker/test-integration-wp-only.sh
--filter MigratorEventsSchemaTest`.

**Acceptance evidence:** `db_version` reaches `10`; all four new tables exist with documented
columns and unique keys; `StructuralBoundariesTest` passes with `Events`/`Automations` now
permitted.

**Planned commit message:** `feat(persistence): add Events/Automations schema and capability scaffolding for M02 (WP1)`

### WP2 — Event identity, envelope, registry, and the safe emission façade

**Objective:** `Events\EventIdentity`, `EventEnvelope`, `EventSource`, `Registry` (with its
PUBLIC-only history-field validation), `EventEmitter`, `EventDispatcher`, and the
`universal_telegram_emit_event()` function and `universal_telegram_register_event_types` hook —
the complete, safety-wrapped event contract from §5.1–§5.3, §5.5.

**Files added:** `src/Events/EventIdentity.php`, `src/Events/EventEnvelope.php`,
`src/Events/EventSource.php`, `src/Events/Registry.php`, `src/Events/EventEmitter.php`,
`src/Events/EventDispatcher.php`, `src/Events/InvalidIdempotencyKeyException.php`,
`src/Events/UnregisteredEventTypeException.php`, `src/Events/UnclassifiedFieldException.php`,
`src/Events/NonPublicHistoryFieldException.php`,
`src/Events/EventTypeAlreadyRegisteredException.php`, `universal-telegram-functions.php` (or the
plugin's existing established procedural-include file — declares
`universal_telegram_emit_event()`, delegating to the composition root's `EventEmitter` singleton).

**Files modified:** the composition root (`Plugin::init()`) to construct `Registry`,
`EventDispatcher`, and `EventEmitter` unconditionally, and to fire
`do_action('universal_telegram_register_event_types', $registry)` at `init` priority 20.

**Test files added:** `tests/unit/Events/EventIdentityTest.php` (same inputs always derive the
same `event_id`; different `idempotency_key` values derive different `event_id`s),
`tests/unit/Events/EventEnvelopeTest.php` (fail-closed classification, invalid idempotency key,
unregistered-type rejection), `tests/unit/Events/RegistryTest.php` (duplicate registration,
allowlist-subset validation, **and** an `INTERNAL`-classified field listed in
`history_projection_fields` throwing `NonPublicHistoryFieldException`),
`tests/integration/Events/EventEmitterTest.php` (an exception anywhere in envelope construction,
history write, or rule evaluation — via a fake `EventDispatcher` made to throw — never propagates
out of `universal_telegram_emit_event()`, and no third-party `do_action()` subscription point for
emission exists — asserted by confirming no action named `universal_telegram_event` is fired).

**Validation commands:** `bin/docker/phpcs.sh src/Events`; `bin/docker/phpstan.sh src/Events`;
`bin/docker/test-unit.sh --filter 'EventIdentityTest|EventEnvelopeTest|RegistryTest'`;
`bin/docker/test-integration-wp-only.sh --filter EventEmitterTest`.

**Acceptance evidence:** identity determinism test passes; a field classified `INTERNAL` and
listed in `history_projection_fields` is rejected at registration; calling
`universal_telegram_emit_event()` with a downstream exception injected never throws back to the
caller.

**Planned commit message:** `feat(events): add deterministic event identity, envelope, registry, and safety-wrapped emission facade (WP2)`

### WP3 — Event history projection and retention

**Objective:** PUBLIC-only durable event-history storage and its retention cleanup job, including
the `fatal_error_markers` retention/stale-drop rules from §8.6.

**Files added:** `src/Events/EventHistoryRepository.php`, `src/Events/RetentionCleanup.php`
(handles `universal_telegram_events_retention_cleanup`, covering `event_history`,
`notification_dispatch_log`, and `fatal_error_markers` per §5.5/§8.6).

**Files modified:** `EventDispatcher::handle()` (call `EventHistoryRepository::record()` before
rule evaluation); `src/Core/Configuration/Settings.php` (add `event_retention_days`,
`dispatch_log_retention_days`, `fatal_marker_retention_days` to the existing allowlist sanitizer,
default 90/90/30); composition root (register the new recurring action on activation).

**Test files added:** `tests/integration/Events/EventHistoryRepositoryTest.php` (only
`history_projection_fields` columns appear in stored JSON; an `INSERT IGNORE` replay with the same
`event_id` produces no second row), `tests/integration/Events/RetentionCleanupTest.php` (rows
older than each configured window are deleted; `pending` fatal markers older than 24h are dropped
and increment the stale-drop counter; `promoted` markers respect their own retention window).

**Validation commands:** `bin/docker/phpcs.sh src/Events src/Core/Configuration/Settings.php`;
`bin/docker/phpstan.sh src/Events src/Core`; `bin/docker/test-integration-wp-only.sh --filter
'EventHistoryRepositoryTest|RetentionCleanupTest'`.

**Acceptance evidence:** a field outside `history_projection_fields` never appears in a stored
`event_history` row; a replayed `event_id` never produces a duplicate history row; cleanup deletes
only rows past each configured window.

**Planned commit message:** `feat(events): add PUBLIC-only event history projection and retention cleanup (WP3)`

### WP4 — Core WordPress event emitters, fatal-error capture, and feedback-loop exclusions

**Objective:** All emitters from §8 with their documented idempotency-key derivations, the
two-phase fatal-error mechanism from §8.6, and the two feedback-loop exclusions from §8.7.

**Files added:** `src/Events/Emitters/LoginEmitter.php`, `UserLifecycleEmitter.php`,
`ContentEmitter.php`, `PluginLifecycleEmitter.php`, `UpdateEmitter.php`,
`ScheduledTaskFailureEmitter.php`, `RestRequestFailureEmitter.php`, `MailFailureEmitter.php`,
`FatalErrorMarkerWriter.php`, `FatalErrorPromotionJob.php`.

**Files modified:** composition root (wire each emitter's hook registration and the shutdown-
function registration, unconditionally, at bootstrap, after `EventEmitter` exists per WP2).

**Test files added:** one integration test per emitter (e.g. `tests/integration/Events/Emitters/
LoginEmitterTest.php` … `MailFailureEmitterTest.php`) asserting the emitted `event_type`, `$data`
shape, and — for the deduplicable emitters — that two firings with the same underlying identity
produce the same idempotency key; `tests/integration/Events/Emitters/
ScheduledTaskFailureEmitterTest.php::test_excludes_universal_telegram_group_actions` (a fake
failed action in the `universal-telegram` group never reaches
`universal_telegram_emit_event()`); `tests/integration/Events/Emitters/
RestRequestFailureEmitterTest.php::test_excludes_own_rest_namespace` (a failing request under
`/universal-telegram/v1/...` never reaches emission); `tests/integration/Events/Emitters/
FatalErrorPromotionJobTest.php` (a `pending` marker is promoted exactly once even if the job runs
twice against the same unmarked row — idempotent by identity, per §8.6; the promoted event never
contains message text, stack trace, or raw file path); a unit test asserting the shutdown writer
never throws even when `$wpdb` is unavailable, and that a marker recurring within its dedup window
does not create a second row.

**Validation commands:** `bin/docker/phpcs.sh src/Events/Emitters`;
`bin/docker/phpstan.sh src/Events/Emitters`; `bin/docker/test-integration-wp-only.sh --filter
'Emitters'`; `bin/docker/test-integration-wc-present.sh --filter 'Emitters'`.

**Acceptance evidence:** every listed WordPress hook produces the documented `event_type` and
idempotency-key behavior; the `password_reset` emitter's test asserts `$new_pass` is never
dereferenced; the fatal-error promotion idempotency test passes; both feedback-loop exclusion
tests pass.

**Planned commit message:** `feat(events): add core WordPress event emitters with bounded fatal-error capture and feedback-loop exclusions (WP4)`

### WP5 — Rule and condition model

**Objective:** `NotificationRule`, `NotificationRuleRepository`, `ConditionOperator`, and
condition-field-allowlist validation from §7.1–§7.2.

**Files added:** `src/Automations/NotificationRule.php`,
`src/Automations/NotificationRuleRepository.php`, `src/Automations/ConditionOperator.php`,
`src/Automations/InvalidConditionFieldException.php`.

**Files modified:** none outside `Automations`.

**Test files added:** `tests/unit/Automations/ConditionOperatorTest.php`,
`tests/integration/Automations/NotificationRuleRepositoryTest.php` (save rejects a field outside
the event type's allowlist; `for_event_type()` returns rows ordered `priority ASC, id ASC`).

**Validation commands:** `bin/docker/phpcs.sh src/Automations`;
`bin/docker/phpstan.sh src/Automations`; `bin/docker/test-unit.sh --filter ConditionOperatorTest`;
`bin/docker/test-integration-wp-only.sh --filter NotificationRuleRepositoryTest`.

**Acceptance evidence:** `for_event_type()`'s returned order is byte-identical across repeated
calls; saving a condition referencing an unregistered field throws.

**Planned commit message:** `feat(automations): add notification rule storage and condition model (WP5)`

### WP6 — Rule evaluation and template rendering

**Objective:** `RuleEvaluator` (§7.3) and `TemplateRenderer` (§7.4).

**Files added:** `src/Automations/RuleEvaluator.php`, `src/Automations/TemplateRenderer.php`.

**Files modified:** `EventDispatcher::handle()` (call `RuleEvaluator::evaluate()` after the
history-projection write).

**Test files added:** `tests/unit/Automations/RuleEvaluatorTest.php` (deterministic ordering;
multi-match dispatches independently; one rule throwing does not prevent the next rule's
evaluation), `tests/unit/Automations/TemplateRendererTest.php` (disallowed/missing field renders
empty string; MarkdownV2 special characters escaped; `INTERNAL`-classified allowed fields render
correctly since templates may use them transiently).

**Validation commands:** `bin/docker/phpcs.sh src/Automations`;
`bin/docker/phpstan.sh src/Automations`; `bin/docker/test-unit.sh --filter
'RuleEvaluatorTest|TemplateRendererTest'`.

**Acceptance evidence:** the failure-isolation test (a throwing rule followed by a matching rule)
passes.

**Planned commit message:** `feat(automations): add deterministic rule evaluation and safe template rendering (WP6)`

### WP7 — Idempotent dispatch log and dispatch wiring

**Objective:** The full `DispatchLogResult` state model and sequence from §7.5 — atomic claim-or-
reject, cooldown, disabled-reference check, and the honest `handed_off_to_m01`/
`failed_before_handoff` distinction — plus the call into `MessageDispatcher::send()`.

**Files added:** `src/Automations/DispatchLogResult.php` (the seven-value enum),
`src/Automations/DispatchLogRepository.php`, `src/Automations/NotificationDispatcher.php`.

**Files modified:** `RuleEvaluator` (delegate matched-rule handling to
`NotificationDispatcher::dispatch()`; delegate rejected-rule handling to
`DispatchLogRepository::record_rejected()`).

**Test files added:** `tests/integration/Automations/DispatchLogRepositoryTest.php` (the atomic
claim-or-reject insert; a second attempt for the same `(rule_id, event_id)` is rejected and
produces zero further writes and zero further `MessageDispatcher::send()` calls, asserted via a
call-counting fake dispatcher), `tests/integration/Automations/NotificationDispatcherTest.php`
(cooldown skip checked against `handed_off_to_m01` rows only; disabled-destination skip;
successful dispatch transitions `claimed` → `handed_off_to_m01` with `outbound_message_uuid` set;
a `DispatchResult` failure transitions `claimed` → `failed_before_handoff` with `reason_code` set;
confirms `MessageDispatcher::send()` is called with exactly the existing M01 signature).

**Validation commands:** `bin/docker/phpcs.sh src/Automations`;
`bin/docker/phpstan.sh src/Automations`; `bin/docker/test-integration-wp-only.sh --filter
'DispatchLogRepositoryTest|NotificationDispatcherTest'`.

**Acceptance evidence:** the unique-constraint test is the falsifiable evidence that no second
rule-engine handoff decision is ever made for the same `(rule_id, event_id)` pair; a static test
confirms `NotificationDispatcher` has no dependency on `Queue\JobEnvelope`/`Queue\Dispatcher` —
the falsifiable evidence that the opaque-payload rule remains enforced by construction; the
`failed_before_handoff` test is the falsifiable evidence that a failed send is never recorded as
if it had been dispatched.

**Planned commit message:** `feat(automations): add idempotent, honestly-scoped dispatch state model and M01 transport wiring (WP7)`

### WP8 — Admin: event catalog and rule builder

**Objective:** The capability-gated admin screens from §9.1.

**Files added:** `src/Administration/Automations/EventCatalogPage.php`,
`src/Administration/Automations/RuleBuilderPage.php`,
`src/Administration/Automations/RuleBuilderRequestHandler.php`.

**Files modified:** composition root (register the two new admin pages under
`CapabilityRegistrar::MANAGE_AUTOMATIONS`).

**Test files added:** `tests/integration/Administration/Automations/
RuleBuilderRequestHandlerTest.php` (missing capability → denied even with a valid nonce; missing
nonce → denied even with capability; a condition referencing a disallowed field is rejected
server-side regardless of client-side validation).

**Validation commands:** `bin/docker/phpcs.sh src/Administration/Automations`;
`bin/docker/phpstan.sh src/Administration/Automations`; `bin/docker/test-integration-wp-only.sh
--filter RuleBuilderRequestHandlerTest`.

**Acceptance evidence:** both the capability-only-denied and nonce-only-denied cases pass.

**Planned commit message:** `feat(administration): add capability-gated event catalog and rule builder screens (WP8)`

### WP9 — Rule simulation and event history browser

**Objective:** §9.2 and §9.3 — the in-process simulator (no live Telegram traffic, no
dispatch-log write) and the PUBLIC-only read-only history browser.

**Files added:** `src/Automations/RuleSimulator.php`, `src/Automations/SimulationResult.php`,
`src/Administration/Automations/RuleSimulatorPage.php`,
`src/Administration/Automations/EventHistoryPage.php`.

**Files modified:** none.

**Test files added:** `tests/unit/Automations/RuleSimulatorTest.php` (asserts
`MessageDispatcher::send()` is never invoked during a simulation run; asserts no row is ever
written to `notification_dispatch_log` during simulation; asserts per-rule outcome ordering
matches `RuleEvaluatorTest`'s real-evaluation ordering fixture byte-for-byte),
`tests/integration/Administration/Automations/EventHistoryPageTest.php` (renders only
`projected_fields_json` content, confirmed to contain no `INTERNAL`-classified value).

**Validation commands:** `bin/docker/phpcs.sh src/Automations src/Administration/Automations`;
`bin/docker/phpstan.sh src/Automations src/Administration`; `bin/docker/test-unit.sh --filter
RuleSimulatorTest`; `bin/docker/test-integration-wp-only.sh --filter EventHistoryPageTest`.

**Acceptance evidence:** the zero-Telegram-calls and zero-dispatch-log-write assertions in
`RuleSimulatorTest` are the falsifiable evidence for "safe preview/test mechanism that does not
require real external traffic" and does not consume real idempotency space; the ordering-parity
assertion is the falsifiable evidence that simulation matches real evaluation.

**Planned commit message:** `feat(automations): add rule simulation and event history admin screens (WP9)`

### WP10 — Diagnostics integration, versioning, docs sync, and final validation

**Objective:** Wire the new health fields (including `automations_stuck_claim_count` and
`automations_stale_fatal_markers_dropped_count`) into the existing Diagnostics page, bump
versioning, sync `README.md`/`CHANGELOG.md`/`docs/ARCHITECTURE.md`, run the one full pre-PR
matrix, and perform the final consistency pass (§16).

**Files modified:** `src/Administration/Diagnostics/DiagnosticsReport.php` (seven new fields, §9.4),
`src/Administration/Diagnostics/DiagnosticsPage.php` (one new render section), the version
constant and its defining file, `readme.txt` stable tag, `README.md`, `CHANGELOG.md` (new
`[0.2.0]` entry), `docs/ARCHITECTURE.md` (version-history table row).

**Test files added:** `tests/integration/Administration/Diagnostics/
DiagnosticsReportAutomationsTest.php` (new fields present, aggregation-only, never a raw error
string).

**Validation commands:** the full matrix from §11.

**Acceptance evidence:** all full-matrix commands green; ZIP `universal-telegram-0.2.0.zip`
builds; package-acceptance passes on all three configurations including the redaction-fidelity
assertions from §11.

**Planned commit message:** `docs(m02): wire diagnostics, bump to 0.2.0, and sync architecture reference (WP10)`

## 13. Requirements traceability

| Charter requirement | Satisfied by | Test evidence |
|---|---|---|
| Event model and registry | §5.1–5.3, 5.5, WP2 | `EventIdentityTest`, `EventEnvelopeTest`, `RegistryTest`, `EventEmitterTest` |
| Core WordPress event coverage | §8, WP4 | one integration test per emitter (WP4) |
| Rule engine with AND condition groups | §7.1–7.3, WP5–WP6 | `NotificationRuleRepositoryTest`, `RuleEvaluatorTest` |
| Telegram notification action | §7.5, WP7 | `NotificationDispatcherTest` |
| Message templates | §7.4, WP6 | `TemplateRendererTest` |
| Deduplication and cooldown | §7.5, WP7 | `DispatchLogRepositoryTest` (idempotent claim-or-reject), cooldown case in `NotificationDispatcherTest` |
| Event history | §5.4, WP3 | `EventHistoryRepositoryTest` |
| Rule simulation tooling | §9.2, WP9 | `RuleSimulatorTest` |
| Acceptance: rule evaluation is deterministic | §7.3 (query-level ordering) | `RuleEvaluatorTest` ordering assertion |
| Acceptance: no duplicate delivery across retries | §7.5 (deterministic identity + unique constraint), stated precisely as "no second handoff decision" | `DispatchLogRepositoryTest`, `EventIdentityTest` |
| Acceptance: clear explanation of matched/rejected rules | §9.2 (simulation reuses real evaluation) | `RuleSimulatorTest` ordering-parity assertion |
| Architectural constraint: events are structured data, never preformatted messages | §5.1 (`EventEnvelope` carries structured sub-arrays; rendering happens only at dispatch time, §7.4) | `EventEnvelopeTest`, `TemplateRendererTest` |
| Architectural constraint: privacy classification applies to every event, history is PUBLIC-only | §5.1/§5.2/§6.1 | `EventEnvelopeTest`, `RegistryTest` (`NonPublicHistoryFieldException`), `EventHistoryRepositoryTest` |
| Architectural constraint: dedup prevents duplicate delivery across retries, honestly scoped | §7.5 | `DispatchLogRepositoryTest`, `NotificationDispatcherTest` |
| No notification feedback loops | §8.7, WP4 | `ScheduledTaskFailureEmitterTest`, `RestRequestFailureEmitterTest` |
| Bounded, privacy-safe fatal-error capture | §8.6, WP1, WP3, WP4 | `FatalErrorPromotionJobTest`, `RetentionCleanupTest` |

## 14. Definition of done

- All ten work packages committed in order, each individually PHPCS/PHPStan-clean and passing its
  own focused tests.
- The full matrix in §11/WP10 green on all applicable configurations.
- `universal-telegram-0.2.0.zip` built and package-acceptance passed on all three configurations.
- Every row in §13 has a passing, named test.
- `Events` and `Automations` boundaries implemented; `StructuralBoundariesTest` updated and
  passing; `Conversations`/`ChatWidget`/`AI` absence still enforced.
- No M01 file modified beyond the strictly additive extensions listed in §2.
- No WooCommerce, visitor/browser, nested-OR, or generic-webhook code present anywhere in the
  diff.
- No public `do_action()` emission hook exists anywhere in the diff; the only public extension
  surfaces are `universal_telegram_register_event_types` and `universal_telegram_emit_event()`.
- Every `event_history` row in every test fixture contains only `PUBLIC`-classified fields.
- Every `notification_dispatch_log` row's `result` value is one of the seven defined states, and
  no test or fixture treats `claimed` as equivalent to `handed_off_to_m01`.
- `docs/ARCHITECTURE.md`, `README.md`, `CHANGELOG.md`, `readme.txt` all consistent with the
  shipped `0.2.0` state.
- Closure record drafted per `docs/closure/milestone-closure-template.md`, citing this plan's
  freeze commit SHA, and submitted for Product Owner (Vlad Stormhaven) acceptance.

## 15. Proposed ADRs

Numbered 0015–0017 (next available per `docs/adr/README.md`), each with all eight required
sections. Not created as files by this plan — full text below for review and inclusion in the
freeze commit once approved.

---

### ADR-0015 — Event Identity, Envelope, Registry, and Safety-Wrapped Emission

**Status:** Proposed

**Context:** M01 delivered Telegram connectivity with fixed, hard-coded notification paths. M02's
charter requires a generic, WordPress-only normalized event model that later milestones (M03, M04)
will feed. The master plan sketches a `do_action`-based emission convention; a first draft of this
plan followed that sketch directly and generated `event_id` at dispatch time as a fresh UUID —
Master Architect review identified two defects in that approach: (1) a fresh, ungrounded UUID per
emission means a replayed or re-fired occurrence of the same underlying business event is
indistinguishable, at the identity level, from a genuinely new occurrence, undermining any
downstream deduplication built on `event_id`; (2) exposing emission itself as a public
`do_action()` hook makes the plugin's own request-safety guarantee dependent on the behavior of
every third-party listener attached to that hook, which `do_action()`'s own execution model cannot
structurally protect against.

**Decision:** Event identity is derived, not generated: `Events\EventIdentity::derive($event_type,
$schema_version, $idempotency_key)` computes a deterministic SHA-256-based `event_id` from a
mandatory, source-supplied `$idempotency_key` — re-emitting the same logical occurrence with the
same key always yields the same `event_id`, which is the entire basis for downstream deduplication
(ADR-0016). `Events\EventEnvelope` is the immutable, transient carrier of this identity plus a
fixed `EventSource` enum, four structured sub-arrays (`actor`, `subject`, `context`, `payload`),
and per-field classification enforced at registration time. `Events\Registry` is the per-request,
always-freshly-constructed catalog of registered event types, their schema versions, their
classification maps, their allowed condition/template fields, and — critically — their
`PUBLIC`-only history-projection fields (ADR-0017). Emission is exposed as exactly one stable
public PHP function, `universal_telegram_emit_event()`, delegating to a single internal
`Events\EventEmitter::emit()` service that wraps the entire downstream call graph — envelope
construction, history write, rule evaluation — in one `try/catch`, reducing any failure anywhere in
that graph to a fixed diagnostic code. There is no public `do_action()` hook for emission; the only
remaining public hook is `universal_telegram_register_event_types`, used purely for registration,
which carries no equivalent safety requirement since registration failures are expected to surface
loudly during the registering code's own development, not silently in a live request triggered by
unrelated WordPress activity.

**Alternatives considered:** (a) Generating `event_id` fresh per emission and relying on a
separate, explicit "correlation key" field for deduplication instead of deriving identity from it
— rejected as needless indirection; deriving `event_id` itself from the key makes every downstream
consumer (history, dispatch log) automatically identity-aware with no second field to keep in sync.
(b) Keeping emission as a `do_action()` hook but wrapping every individual registered listener call
in its own `try/catch` via a custom dispatch loop — rejected as reimplementing WordPress's own hook
machinery just to retrieve a safety property a plain function call already provides for free, with
none of the downside. (c) A single flat associative-array event shape with no formal envelope class
— rejected because it provides no structural place to attach the mandatory per-field classification
map. (d) Allowing arbitrary, unclassified fields to pass through as `INTERNAL` by default —
rejected; it would silently weaken ADR-0009's fail-closed guarantee.

**Consequences:** Every later milestone that emits an event (M03, M04, third-party extensions)
must choose and document a genuine idempotency key for each of its own event types, following the
worked examples in this plan's §8 table — a milestone that instead generates a fresh key per
emission is explicitly declaring that event type non-deduplicable, a decision that must be stated,
not defaulted into silently. Every later milestone's emission goes through the same
`universal_telegram_emit_event()` function; no milestone builds a second emission path.

**Security and privacy impact:** The safety-wrapped emission façade is the mechanism that makes
ADR-0009's fail-closed redaction model, and this plan's own PUBLIC-only history rule (ADR-0017),
unconditionally reachable — no failure anywhere in the event pipeline can propagate back into, or
be worked around by, the WordPress request that triggered it.

**Affected Documents/Milestones:** `docs/ARCHITECTURE.md` (new `Events` boundary, implemented);
`docs/adr/0009-privacy-classification-and-redaction-model.md` (this ADR is the "later milestone's
own architecture decision" ADR-0009 anticipated; ADR-0009's own text is not edited); M03 and M04
(both register their event types through this same contract and must define their own idempotency
keys per the pattern this ADR establishes).

**Compatibility/Migration Impact:** No schema change beyond the tables ADR-0016 and this plan's
WP1/WP3 introduce. `event_id` is CHAR(64) (hex SHA-256), not a UUID — this is a new, stable format
decision with no prior M00/M01 precedent to be compatible with. The public function signature and
the registration hook's signature are a new public contract; changing either after acceptance
requires a superseding ADR.

---

### ADR-0016 — Notification Rule Engine: Storage, Deterministic Evaluation, and an Honestly-Scoped Dispatch State Model

**Status:** Proposed

**Context:** M02's charter requires deterministic evaluation, no duplicate delivery across
retries, and a clear matched/rejected explanation, while preserving ADR-0012's opaque-queue-payload
outbound pattern and staying within AND-only conditions. A first draft of this plan wrote a single
`dispatched` state into the dispatch log at the moment a send was merely *attempted*, before
`MessageDispatcher::send()`'s own `DispatchResult` was known — Master Architect review identified
that this could label a failed handoff as a success, and that it conflated "no second rule-engine
decision for this pair" with a false claim of exactly-once Telegram delivery, which ADR-0014
already explicitly disclaims at the transport layer.

**Decision:** Rules are stored with a flat, non-nested, AND-only JSON condition array validated
against each event type's field allowlist and a fixed operator enum. Evaluation is synchronous,
in-process, strictly ordered by `(priority ASC, id ASC)` at the query level, with each rule's
evaluation and dispatch wrapped in its own `try/catch` so one rule's failure never affects another.
A single event may independently match and dispatch through multiple rules. Delivery idempotency
is enforced by `Events\EventIdentity`'s deterministic `event_id` (ADR-0015) combined with a
`UNIQUE(rule_id, event_id)` constraint on `notification_dispatch_log`, whose `result` column is one
of seven explicit states — `claimed`, `rejected`, `skipped_duplicate`, `skipped_cooldown`,
`skipped_disabled_reference`, `handed_off_to_m01`, `failed_before_handoff` — such that a row is
only ever written to `handed_off_to_m01` after `MessageDispatcher::send()`'s own `DispatchResult`
confirms success, and a failed attempt is distinctly recorded as `failed_before_handoff`, never
silently indistinguishable from success. The atomic claim-or-reject insert is the sole
duplicate-prevention mechanism: once any row exists for a given `(rule_id, event_id)` pair, no
further write of any kind occurs for it. A `claimed` row that never reaches a terminal state
(request termination mid-flight) is a deliberately accepted, diagnostically-surfaced, non-retried
limitation, never mislabeled as either success or failure.

**Alternatives considered:** (a) Writing the terminal `dispatched` state at claim time and treating
a later `MessageDispatcher::send()` failure as an exceptional case to be caught and rolled back —
rejected; the row's own history would then need to distinguish "was dispatched" from "was rolled
back," which is exactly what the seven-state model already does more simply and without any
rollback logic. (b) Relying solely on ADR-0014's `possible_duplicate_delivery` flag for M02's own
duplicate-delivery acceptance criterion — rejected; it addresses transport-level ambiguity, not
the rule engine's own re-decision risk, a genuinely different failure mode. (c) Building a
background job to automatically resume stuck `claimed` rows — rejected for this milestone as
requiring its own re-entrancy design outside M02's charter scope; documented instead as an
explicit, diagnosable, accepted limitation. (d) A new queue job type for notification dispatch
carrying a rule/event reference — rejected; it would duplicate ADR-0012's already-correct pattern
for no benefit.

**Consequences:** The dispatch log doubles as the audit trail from event to rule to outbound
message. Because dispatch always goes through the unmodified `MessageDispatcher::send()`, any
future change to M01's transport automatically applies to M02's notifications with zero M02-side
change required. Any later milestone (M08, M11) building its own rule-like dispatch is expected to
adopt this same claim/terminal-state pattern rather than a single-write "dispatched" flag.

**Security and privacy impact:** Condition clauses and templates are restricted to a fixed,
per-event-type field allowlist and a closed operator/grammar set, making injection of arbitrary
logic structurally impossible. No secret or credential-like data is ever readable by a condition or
template, since such fields cannot exist in an `EventEnvelope` at all (ADR-0015).

**Affected Documents/Milestones:** `docs/ARCHITECTURE.md` (new `Automations` boundary,
implemented); `docs/adr/0012-telegram-bot-cardinality-webhook-routing-and-outbound-delivery.md`
(this ADR is the concrete fulfillment of ADR-0012's own forward-looking consequence naming M02;
ADR-0012's text is not edited); `docs/adr/0014-telegram-reliability-rate-limits-circuit-breakers-and-dead-letter.md`
(this ADR's dedup mechanism is explicitly a distinct, additional layer above ADR-0014's
transport-level guarantee, not a replacement for it — both remain simultaneously true, and neither
claims exactly-once Telegram delivery); M08 and M11, per ADR-0012's own text, are expected to
follow this same pattern.

**Compatibility/Migration Impact:** Two new tables (`notification_rules`,
`notification_dispatch_log`), migration steps 9–10, additive only. `MessageDispatcher::send()`'s
existing signature is called, never changed. No existing table, queue job type, or public contract
from M00/M01 is altered.

---

### ADR-0017 — Event History as a PUBLIC-Only Redacted Projection

**Status:** Proposed

**Context:** ADR-0009 established fail-closed classification/redaction at M00 with four levels
(`PUBLIC`, `INTERNAL`, `SENSITIVE`, `SECRET`) and no public registration mechanism, deferring the
latter to M02 (ADR-0015 supplies it). A first draft of this plan allowed any classified field
(`PUBLIC` or `INTERNAL`) into the durable event-history projection, relying only on
`Privacy\Redactor`'s general-purpose masking behavior for `INTERNAL`-adjacent handling. Master
Architect review determined this was not strict enough for a durable, admin-browsable table meant
to represent "safe to look at, safe to keep" data specifically — `INTERNAL` data is appropriate for
transient, in-memory use during rule evaluation and template rendering (where it never leaves the
current request/job and is never written anywhere), but its accumulation in a permanent, queryable,
retention-governed table is a materially different and larger exposure than its momentary use.

**Decision:** `Events\Registry::register()` requires that every field named in
`history_projection_fields` be classified exactly `PUBLIC` in the same call's
`field_classification_map` — an `INTERNAL` field listed there is rejected at registration time with
`Events\NonPublicHistoryFieldException`, before any event of that type can ever be emitted.
`INTERNAL` fields remain fully usable in `allowed_variable_fields` (rule conditions, message
templates) since those uses are exclusively transient and in-memory (ADR-0015, ADR-0016). The
`event_history` table (ADR-0015's WP3) therefore contains, by construction and independent of
`Redactor`'s own runtime behavior, only data every registering milestone has explicitly declared
safe for indefinite, admin-visible, retention-governed storage.

**Alternatives considered:** (a) Allowing `INTERNAL` fields into history but masking them at
render time only (in the admin browser) rather than at storage time — rejected; it would still
accumulate the underlying data durably, at rest, subject to a future rendering bug or a direct
database-level query exposing it, whereas the chosen decision means the data structurally cannot
be there to expose. (b) A fifth classification level specifically for "history-eligible" data —
rejected as unnecessary; `PUBLIC` already means exactly this (data with no restriction on where it
may appear), and introducing a fifth level would require reopening ADR-0009's own four-level model
for a distinction the existing top level already captures.

**Consequences:** M03 and M04, when planned, must classify strictly more conservatively for any
field they want to appear in event history than for a field they only need transiently for
conditions/templates — this is a genuine, deliberate constraint on their own future event-type
design, not a cost imposed only on M02.

**Security and privacy impact:** This is a strictly additive, strictly narrowing constraint beyond
ADR-0009's own baseline — it does not weaken `Redactor`'s existing fail-closed default for any
existing M00/M01 call site, none of which are modified, and it closes an exposure surface (durable
accumulation of `INTERNAL` data) that ADR-0009 alone did not need to address because M00 had no
durable, admin-browsable event-like table.

**Affected Documents/Milestones:** `docs/adr/0009-privacy-classification-and-redaction-model.md`
(this ADR is the concrete fulfillment of ADR-0009's own forward pointer to the milestone
introducing event registration; ADR-0009's text is not edited); `docs/adr/0015-event-identity-envelope-registry-and-safety-wrapped-emission.md`
(this ADR's `PUBLIC`-only constraint is enforced inside `Registry::register()`, defined there).

**Compatibility/Migration Impact:** None — no schema change beyond `event_history` itself
(ADR-0015's WP3), no modification to any existing class's public signature.

---

## 16. Final consistency checks

- Every file listed across WP1–WP10 in §12 is added or modified by exactly one work package; no
  file is left unassigned; no wildcard paths were used anywhere in this plan.
- Every table introduced (`event_history`, `fatal_error_markers`, `notification_rules`,
  `notification_dispatch_log`) has a migration step (WP1), a postcondition-verify method (WP1), and
  an uninstall-cleanup line (WP1, four lines total, corrected from an earlier three-line count).
- `event_id` is CHAR(64) (deterministic SHA-256 hex) consistently in `event_history` and
  `notification_dispatch_log`'s schema descriptions (§5.4, §7.5) and in WP1's migration file list —
  no remaining reference to a UUID-format `event_id` anywhere in this document.
- No public `do_action()` hook for event emission is described anywhere in this document; every
  reference to emission names either the `universal_telegram_emit_event()` function or the
  internal `EventEmitter`/`EventDispatcher` call graph it wraps.
- Every reference to dispatch outcomes uses the seven-state `DispatchLogResult` model (§7.5); no
  remaining reference to a single `dispatched` terminal state written before `MessageDispatcher::
  send()`'s result is known.
- Every reference to event-history content states or implies PUBLIC-only (§5.2, §5.4, §6.1, §9.3,
  ADR-0017); rule simulation (§9.2) is the only screen permitted to show `INTERNAL` fields, and
  only transiently, never persisted.
- §8's emitter table states an explicit idempotency-key source and deduplicability rationale for
  every listed event type, including the two engine-driven exclusions (§8.7) and the two-phase
  fatal-error mechanism (§8.6) with its scope boundary, marker lifecycle, and idempotent promoter.
- Every charter deliverable and acceptance criterion has a §13 traceability row with a named test,
  including the newly added feedback-loop and fatal-error-capture rows.
- No work package modifies `Queue\JobEnvelope`, `Queue\Dispatcher`, `Telegram\Outbound\
  MessageDispatcher`, `Telegram\Configuration\BotProfileRepository`,
  `Telegram\Configuration\DestinationRepository`, or any file under `src/Telegram/Reliability`.
- No work package touches WooCommerce, visitor/browser tracking, nested-OR condition storage, or a
  generic webhook action.
- Ten work packages total, unchanged in count from the prior revision; the seven corrections in
  this revision were absorbed into WP1–WP4, WP7, and WP9's existing scope rather than adding an
  eleventh package.
- Version target `0.2.0` and `db_version` `10` are stated once (§1) and consistent everywhere else
  referenced (§11, §12 WP10, §14) — unaffected by this revision's corrections, since the same three
  migration steps and four tables were already planned, only their column shapes changed.
- Development target remains WordPress 6.9+ (tested up to 7.1), PHP 8.1+, unchanged from M00/M01.
- All three proposed ADRs (0015–0017) include all eight required sections and correctly avoid
  editing any existing accepted ADR's Status/Decision/Consequences text.
- The only personal name appearing anywhere in this document is **Vlad Stormhaven**, referenced
  solely as Product Owner; no other personal name is present. This document does not modify
  `docs/governance.md` or any other historic governance material — the Product Owner identity used
  here applies to this document's own text only.
- Acceptance-model note: per Product Owner (Vlad Stormhaven) direction and ADR-0011, this
  milestone's closure gate is frozen plan + code review + full automated matrix + green CI +
  closure record — no separate independent manual acceptance session is scheduled or required
  before M10.

**Recommendation:** Ready for final Master Architect freeze review and Product Owner
(Vlad Stormhaven) approval, followed by a documentation-only freeze commit including this plan and
ADR-0015 through ADR-0017 before implementation begins.

**Revision history:** v1 revision 1 — initial draft. v1 revision 2 — corrected event identity/
idempotency, dispatch-log state honesty, emission-hook safety, fatal-error capture boundaries and
marker lifecycle, notification feedback-loop exclusions, and PUBLIC-only durable history, per
Master Architect review.

**No unresolved decisions remain.**
