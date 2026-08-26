# ADR-0041 — Support Chat ADR-0009 Pin and Legacy Binding Preparation Follow-up

## Status

Accepted

## Context

ADR-0039 pinned Support Chat ADR-0008 (legacy export boundary and migration authority model) and authorised this plugin's `LegacyExportServiceV1` follow-up, which is implemented (`docs/closure/ut-adapter-m1-legacy-export-service-closure.md`, Product Owner acceptance pending) and consumed by Support Chat's SC-M03 work packages 3–4. ADR-0040 separately shipped this plugin's own real quiescence state machine (write blocking and drain).

Support Chat's SC-M03 work package 5 — creating bindings for Telegram topics that predate Support Chat — is the specific successor ADR-0039 §4 named as future, out-of-scope work: *"Binding creation for existing Telegram topics (work package 5) — a separate, later, unstarted unit of work in the Support Chat repository."* ADR-0039 §4 speculated at the time that this future work "per that plan's own design calls this plugin's *existing* `ensure_channel_case`/pairing infrastructure ... rather than requiring new code here." Support Chat's now-frozen **ADR-0009** supersedes that speculation with a concrete design: direct source verification during ADR-0009's drafting found that `SupportChatAdapter\Inbound\InboundAdapterBridge::try_handle()` is called unconditionally by `Telegram\Inbound\WebhookController::process_update()` for every inbound update, gated solely on an existing binding's `is_active()` — meaning a binding created through the existing `ensure_channel_case` path (which writes `status = 'active'`, identical to `SupportChatAdapter\Cli\BindingImportCommand`) would immediately and silently reroute a still-live legacy topic's traffic. ADR-0009 therefore requires new UT-side capability this repository has not previously scoped: a write boundary that is symmetric to `LegacyExportServiceV1` but structurally incapable of live routing until an explicit, separate, future activation step.

`docs/governance.md`'s freeze model ("No implementation code may precede the ADRs it relies on") requires this plugin to pin that mechanism and define its own implementation obligation before writing any binding-preparation code. This ADR does that. It amends no prior ADR's Decision text (ADR-0037, ADR-0038, ADR-0039, ADR-0040 are not rewritten) and changes no runtime code.

## Decision

### 1. Pin (exact, immutable reference)

| Item | Value |
|---|---|
| Support Chat ADR-0009 | Commit SHA `590b53ba898aa4054ec65c65965c152a3612149b` — `https://github.com/magpern/universal-support-chat/blob/590b53ba898aa4054ec65c65965c152a3612149b/docs/adr/0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md` |

Universal Telegram does not copy ADR-0009's text into this repository, mirroring the existing ADR-0038/ADR-0039 rule against duplicating Support Chat's ADRs. **The exact commit SHA above is filled in as part of the same documentation-freeze commit that adds this ADR, once Support Chat's ADR-0009 documentation-only PR has merged to its own `main`** — mirroring exactly how ADR-0039 §1 pinned ADR-0008's post-merge SHA, never a branch-head SHA.

### 2. Universal Telegram's obligation: `LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion — exactly as ADR-0009 defines them, nothing more

This ADR authorises only:

- **A new, additive `status` value, `prepared`,** on `universal_telegram_support_chat_bindings.status` (currently `ENUM('active','unavailable','closed') NOT NULL DEFAULT 'active'`, `src/Persistence/Migrator.php` schema step 31) — implemented as schema step 34 (the next available step after step 33's quiescence tables), additive only: `ENUM('active','unavailable','closed','prepared')`. `ChannelBinding::is_active()` (`src/SupportChatAdapter/ChannelBinding.php:150-151`, `return self::STATUS_ACTIVE === $this->status;`) requires **no code change** — a `prepared` row falls into its existing "not active" branch, which is exactly what makes `InboundAdapterBridge::try_handle()` (`src/SupportChatAdapter/Inbound/InboundAdapterBridge.php:59-73`) and `DeliverMessageService`'s outbound routing structurally unable to consult a `prepared` binding, without touching either class.
- **`ChannelBindingRepository::create()`** (`src/SupportChatAdapter/ChannelBindingRepository.php:127-165`, currently hardcoding `'status' => ChannelBinding::STATUS_ACTIVE` at line 153) gains either an optional initial-status parameter defaulting to `STATUS_ACTIVE` (preserving `BindingImportCommand`'s and `EnsureChannelCaseService`'s existing call sites byte-for-byte) or a new, dedicated `create_prepared()` method — an implementation-time API-shape choice, not fixed by this ADR.
- **A new, versioned, in-process, WP-CLI-context-only service, `LegacyBindingImportServiceV1`**, symmetric in shape to `LegacyExportServiceV1` (ADR-0039 §2) but for the write direction: never a REST route, never an Ajax handler, never a cron path, never an addition to Contract v1's operation allow-list (ADR-0007 §4, unmodified). It checks `defined( 'WP_CLI' ) && WP_CLI` itself and rejects every invocation outside a WP-CLI process, unconditionally — the identical self-enforced gate `LegacyExportServiceV1` already uses. Its method contract, candidate/result shape, and the full status-specific idempotency/conflict outcome vocabulary (`binding_skip_already_bound`, `binding_conflict_existing_mismatched`, `binding_conflict_existing_active`, `binding_conflict_existing_status_unresolved`, and the retryable outcomes) are fixed by Support Chat ADR-0009 §2–§4 and are not restated here; this repository implements them exactly as that ADR specifies. **Every call to `create()`/`create_prepared()` made by this service writes `status = 'prepared'`; this service never writes `status = 'active'`, under any condition.**
- **A new lock-scoped quiescence assertion capability**, exposed for use from a second caller besides the existing webhook buffer-vs-process and replay-to-idle paths. `Migration\QuiescenceGate::decide_webhook_disposition()` (`src/Migration/QuiescenceGate.php:261-286`) and `attempt_replaying_to_idle()` (`:304-349`) already establish the exact lock discipline this obligation reuses: `START TRANSACTION`, `SELECT state[, token] FROM {quiescence_state} WHERE id = %d FOR UPDATE`, verify the required condition while still holding that lock, then commit or roll back — never two separate statements. `LegacyBindingImportServiceV1::import_batch()` must, per candidate, open its own transaction, acquire this identical row lock, verify `state === 'quiescent'` **and** `deferred_update_backlog_count() === 0` while still holding it, perform its own live topic-state re-check, and only then call `create()`/`create_prepared()` — committing everything together or rolling back the entire candidate on any failure. **The existing, non-lock-scoped `Core\Plugin::quiescence_status()` accessor (`src/Core/Plugin.php:2073-2079`) is explicitly insufficient for this purpose** and must not be reused as this assertion's implementation — it is read-only and unlocked, exactly the TOCTOU gap ADR-0009 §5 requires this new capability to close.
- **Live re-validation of the candidate's own topic/lifecycle state at call time**, inside the same locked transaction, never trusting Support Chat's migration-map snapshot as sufficient proof by itself — mirroring `LegacyExportServiceV1`'s own precedent of this plugin never assuming the caller's side is safe without its own verification.
- **The identical redaction, batch-limit (100 per call, server-side), typed-per-candidate-result, and plaintext-discipline rules** ADR-0039 §2 already establishes for `LegacyExportServiceV1`, applied here: no message/note content, no `telegram_message_id`/`outbound_message_uuid` per-message delivery correlation, is read, stored, or transmitted anywhere in this boundary.

### 3. Explicit non-interference guarantee — a permanent regression test, not merely a design intent

Because `try_handle()`'s and `DeliverMessageService`'s only gate is `is_active()`, and `is_active()` is `true` only for `status === 'active'`, a `prepared` binding is structurally excluded from both inbound and outbound live routing by construction. This repository commits to proving this as a **permanent, dedicated test** — symmetric to ADR-0040 §7's own requirement for `DeliverMessageService` — asserting `try_handle()` never claims an inbound update for a topic whose only binding is `status = 'prepared'`, across all four ADR-0040 quiescence states, and a companion regression test asserting every row `LegacyBindingImportServiceV1` creates is `status === 'prepared'`, never `'active'`. Both tests are required, not optional, before this ADR's obligation is considered discharged.

### 4. Cross-reference correction — this ADR supersedes ADR-0039 §4's speculative binding-creation mechanism

ADR-0039 §4 is immutable and is not edited by this ADR. Its sentence speculating that work package 5 "calls this plugin's *existing* `ensure_channel_case`/pairing infrastructure ... rather than requiring new code here" is superseded, as a factual matter, by ADR-0009's actual design and this ADR's actual implementation obligation (§2 above): work package 5 requires the new `LegacyBindingImportServiceV1`, the new `prepared` status, and the new lock-scoped quiescence assertion — none of which are the `ensure_channel_case` path. `ensure_channel_case`/`EnsureChannelCaseService` remains exactly as ADR-0037/ADR-0038 already scoped it (new-topic creation for a live, escalating conversation) and is entirely unaffected by this ADR; it is simply not the mechanism work package 5 uses.

### 5. `BindingImportCommand`'s pre-existing gap — explicitly out of this ADR's scope

`SupportChatAdapter\Cli\BindingImportCommand` (`src/SupportChatAdapter/Cli/BindingImportCommand.php:75-138`), already shipped and in production, hardcodes `status = 'active'` via `create()`'s existing default and performs no liveness check on the source legacy conversation at import time. This is a pre-existing condition of this repository's own code, independent of and predating both ADR-0009 and this ADR. **This ADR does not modify, harden, retire, or otherwise touch `BindingImportCommand`.** Support Chat's own `binding_conflict_existing_active` outcome (ADR-0009 §4) is the accepted detection mechanism, from Support Chat's side, for a collision between that command's prior output and a later work-package-5 run; it is not, and this ADR does not claim it to be, a fix to `BindingImportCommand` itself. Any future hardening of that command is a separate, later, Universal-Telegram-owned decision, not authorised here.

### 6. Explicit scope boundary — what this ADR does not authorise

This ADR authorises `LegacyBindingImportServiceV1`, the `prepared` status, the lock-scoped quiescence assertion, and their tests (§2–§3). It authorises **nothing else**. In particular:

- No activation mechanism (`prepared → active`) — a separate, future, unauthorised design task (ADR-0009 § Consequences).
- No modification to `BindingImportCommand` (§5).
- No modification to `InboundAdapterBridge`, `DeliverMessageService`, or `WebhookController` — their existing `is_active()` gates are sufficient by construction and are not touched.
- No modification to `ensure_channel_case`/`EnsureChannelCaseService` (§4).
- No REST route, Ajax handler, cron path, Contract v1 operation, shared secret, or permanent cross-plugin SQL access.
- No production binding creation, cutover, route switch, soak, or rollback.

### 7. Implementation sequence (locked)

1. **Implemented.** `LegacyExportServiceV1` (ADR-0039 follow-up) and Support Chat SC-M03 work packages 3–4/2.
2. **This ADR's follow-up:** this repository's `prepared` status, `LegacyBindingImportServiceV1`, and the lock-scoped quiescence assertion (§2 above) — not yet implemented.
3. **Support Chat's SC-M03 work package 5:** the binding-preparation WP-CLI engine, consuming `LegacyBindingImportServiceV1` — Support Chat repository, gated on step 2 above merging here.
4. Only after SC-M03 acceptance: this plugin's legacy Conversations/AI/widget/settings decommission (unchanged, ADR-0038 §5 step 5 / ADR-0039 §5 step 4).

**No `LegacyBindingImportServiceV1` implementation code may begin until this ADR is merged to `main`. Support Chat's work package 5 implementation code may not begin until both this ADR and Support Chat's own ADR-0009 are merged to their respective `main` branches** — the identical two-repository gate ADR-0039 already established for the export boundary, and that Support Chat's ADR-0009 already states from its own side.

## Alternatives

- **Build work package 5's binding creation on top of the existing `ensure_channel_case`/pairing infrastructure**, as ADR-0039 §4 originally speculated — rejected: direct verification found `ensure_channel_case`'s underlying write path is `status = 'active'`, identical to `BindingImportCommand`'s existing defect; reusing it would inherit the immediate-live-routing gap ADR-0009 exists to close.
- **Extend `BindingImportCommand` itself with a liveness check and a non-`active` mode** — rejected for this ADR's scope: conflates fixing a pre-existing, already-shipped command (a separate, later decision, §5) with authorising new, narrowly-scoped work-package-5 functionality; keeping them separate lets each be reviewed on its own merits.
- **Reuse `Core\Plugin::quiescence_status()` directly as the write-time guard, without a new lock-scoped assertion** — rejected: that accessor is read-only and unlocked; ADR-0009 §5 requires the check and the write to be atomic, which requires the new capability described in §2.
- **A shared migration-authority secret/token for the binding-write call** — rejected, identical reasoning to ADR-0039's rejection of the same alternative for the export boundary.

## Consequences

- `LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion now have a precise, pinned target to implement against in this repository's own follow-up slice, closing the mechanism gap Support Chat's ADR-0009 identified from its side.
- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/adr/README.md`, `docs/milestones/README.md`, the UT Adapter M1 milestone charter, and a new UT Adapter M1 plan (or a new dedicated plan file for this obligation) are updated in this same freeze to reference this ADR.
- ADR-0037, ADR-0038, ADR-0039, ADR-0040 are unchanged; this ADR supplements them, it does not supersede or rewrite any of their Decision text. ADR-0039 §4's speculative binding-creation sentence is factually superseded per §4 above, without editing ADR-0039 itself.
- No plugin version, `db_version`, release, tag, or deployment change in this freeze.

## Security and privacy impact

- Legacy vault key material and message/note plaintext are never involved in this boundary at all — every field `LegacyBindingImportServiceV1` moves (ids, states, timestamps) is already non-content, consistent with ADR-0039 §2's redaction discipline applied to a boundary that carries no content in the first place.
- The real security boundary is unchanged from ADR-0039 §2: host authority to execute WP-CLI against this install. `LegacyBindingImportServiceV1`'s self-enforced `WP_CLI`-context check closes the same externally reachable paths (web, Ajax, REST, cron) `LegacyExportServiceV1`'s does, with the identical stated limitation that it cannot distinguish Support Chat's own command from any other code already executing inside an authorized WP-CLI process.
- The `prepared` status and its structural non-interference with `is_active()`-gated routing (§3) is this ADR's core safety contribution: a binding this boundary creates cannot participate in live inbound or outbound traffic, proven by a permanent regression test, not merely asserted by design intent.
- No new capability is granted to any WordPress user role, and no new network-reachable endpoint is created.

## Affected Documents/Milestones

- `docs/adr/README.md` (reserved-number table and index updated for ADR-0041).
- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/milestones/README.md` (cross-repo sequence and pin references updated, additive).
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` (additive amendment recording this ADR and the new implementation gate).
- A new plan file authorising this repository's own implementation detail for `LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion (new — referencing this ADR).
- ADR-0002, ADR-0007, ADR-0037, ADR-0038, ADR-0039, ADR-0040 (referenced, unedited).
- Support Chat repository: ADR-0009 (pinned, external, unedited by this ADR); SC-M03 charter §0c and the SC-M03 work package 5 plan (external, unedited by this ADR).

## Compatibility/Migration Impact

- No runtime code, schema, plugin version, `db_version`, release, tag, or deployment change in this freeze.
- This repository's `LegacyBindingImportServiceV1`/`prepared`/lock-scoped-assertion implementation, and Support Chat's SC-M03 work package 5, remain unimplemented until the sequence in §7 is followed. This ADR does not authorize, schedule, or execute any production binding creation, cutover, activation, or route switch.
