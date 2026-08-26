# UT Adapter M1 — Universal Support Chat Adapter — Implementation Plan v4

Supersedes [plan v3](ut-adapter-m1-universal-support-chat-adapter-plan-v3.md) (`docs/plans/README.md`: v3 is retained, unedited, permanently; this file is the frozen plan going forward) only to add work package 9 below. Plan v3's work package 8 (`LegacyExportServiceV1`) is complete — see `docs/closure/ut-adapter-m1-legacy-export-service-closure.md` — and is not reproduced or re-scoped here.

## 1. References

- Charter: `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` (§0c legacy binding preparation follow-up amendment)
- ADRs: ADR-0002, ADR-0007, ADR-0037, ADR-0038, ADR-0039, ADR-0040 (unedited), **ADR-0041** (new — Support Chat ADR-0009 pin and legacy binding preparation follow-up)

## 2. Repository findings (plan-freeze time)

Plan v3's work package 8 is implemented and merged: `LegacyExportServiceV1` (`src/SupportChatAdapter/Migration/LegacyExportServiceV1.php`). Support Chat's SC-M03 work packages 2–4 (real quiescence, batch migration engine) are implemented and Product Owner accepted. Support Chat's SC-M03 work package 5 is architecturally authorised by Support Chat's own ADR-0009 but blocked on this repository supplying three new capabilities: the `prepared` binding status, `LegacyBindingImportServiceV1`, and a lock-scoped quiescence assertion usable from a second caller.

Confirmed by direct source read at plan-freeze time:
- `universal_telegram_support_chat_bindings.status` (`src/Persistence/Migrator.php:1974`) is `ENUM('active','unavailable','closed') NOT NULL DEFAULT 'active'`, created at schema step 31. Highest existing schema step is 33 (`step_33_create_quiescence_tables`); this plan's schema change lands as step 34.
- `ChannelBindingRepository::create()` (`src/SupportChatAdapter/ChannelBindingRepository.php:127-165`) hardcodes `'status' => ChannelBinding::STATUS_ACTIVE` at line 153.
- `ChannelBinding::is_active()` (`src/SupportChatAdapter/ChannelBinding.php:150-151`) is `true` only for `status === STATUS_ACTIVE` — unchanged by this plan, and the reason a `prepared` row is structurally excluded from routing without touching this class.
- `InboundAdapterBridge::try_handle()` (`src/SupportChatAdapter/Inbound/InboundAdapterBridge.php:59-73`) and `WebhookController::process_update()` (`src/Telegram/Inbound/WebhookController.php:206-231`, unconditional call at 216-217 before legacy routing at 228) are the routing paths this plan must not alter and does not alter.
- `Migration\QuiescenceGate::decide_webhook_disposition()` (`src/Migration/QuiescenceGate.php:261-286`) and `attempt_replaying_to_idle()` (`:304-349`) establish the exact `START TRANSACTION` / `SELECT ... FOR UPDATE` / commit-or-rollback lock discipline this plan's new quiescence assertion (§4) reuses against the same singleton `quiescence_state` row (`Migrator::QUIESCENCE_STATE_TABLE`).
- `Core\Plugin::quiescence_status()` (`src/Core/Plugin.php:2073-2079`) is the existing read-only, non-lock-scoped accessor already consumed by Support Chat's Phase B and this plan's own early pre-check equivalent on Support Chat's side; ADR-0041 §2 requires this plan's new assertion be a distinct, lock-scoped capability, not a reuse of this accessor.
- `db_version` is `33`; plugin version unchanged at plan-freeze time by this plan itself (bumped only at implementation).

## 3. Assumptions and open questions

**Assumptions:**
- Both plugins continue to run in the same WordPress install for the duration of this work package (unchanged from plan v2 §3 / plan v3 §3).
- Support Chat implements its own SC-M03 work package 5 (candidate identification, the `legacy-bind` WP-CLI command, its own schema columns) entirely in its own repository; this plan does not implement or specify Support Chat-side code.
- `ChannelBinding`'s existing hydration/value-object shape needs only a new recognized status string, not a structural change, to represent `prepared` — confirmed by `ChannelBinding.php`'s constant-based status pattern (§2).

**Open questions — none remaining as unresolved architecture.** ADR-0041 fixes the write boundary's shape, the `prepared` status's routing-non-interference guarantee, and the lock-scoped quiescence assertion's requirements precisely. One implementation-time API-shape choice is explicitly left open by ADR-0041 §2 itself: whether `ChannelBindingRepository::create()` gains an optional initial-status parameter or a dedicated `create_prepared()` method — resolved in §5 below.

## 4. Architectural decisions

- Follow ADR-0041 §2 exactly for `LegacyBindingImportServiceV1`: versioned, in-process, `WP_CLI`-context-only (self-enforced, unconditional rejection outside WP-CLI, identical to `LegacyExportServiceV1`'s own gate), a 100-candidate-per-call batch ceiling enforced server-side, typed per-candidate results rather than batch-aborting exceptions, and the full status-specific outcome vocabulary Support Chat's ADR-0009 §4 fixes (this repository returns these typed outcomes; it does not invent its own).
- Follow ADR-0041 §2 exactly for the `prepared` status: additive `ENUM` value only; `ChannelBinding::is_active()` unchanged; every write this service performs uses `prepared`, never `active`.
- Follow ADR-0041 §2 exactly for the lock-scoped quiescence assertion: reuse `QuiescenceGate`'s existing `START TRANSACTION` / `SELECT state, token FROM {quiescence_state} WHERE id = 1 FOR UPDATE` pattern, verify `state === 'quiescent' AND deferred_update_backlog_count() === 0` while still holding that lock, perform the live topic-state re-check, and only then write — all inside one transaction, committed or rolled back together.
- Follow ADR-0041 §3 exactly: the non-interference guarantee is proven by a permanent regression test, not asserted by design intent alone.
- Follow ADR-0041 §5/§6 exactly: no modification to `BindingImportCommand`, `InboundAdapterBridge`, `DeliverMessageService`, `WebhookController`, or `EnsureChannelCaseService`.
- No Contract v1 operation-allow-list change; no new REST route, Ajax handler, or cron-invoked path.

## 5. Directory, namespace, schema, and API impact

- **Schema step 34** (`step_34_add_prepared_binding_status` or equivalent naming): `ALTER TABLE {bindings} MODIFY COLUMN status ENUM('active','unavailable','closed','prepared') NOT NULL DEFAULT 'active'` — additive only; existing rows and the existing default are unaffected. `verify_step_34` confirms the column accepts `'prepared'` (e.g. by checking `INFORMATION_SCHEMA.COLUMNS.COLUMN_TYPE` contains `'prepared'`, mirroring this repository's existing `table_has_columns()`-style verification pattern used by `verify_step_31`).
- `ChannelBinding` gains `STATUS_PREPARED = 'prepared'` as a new class constant, alongside the existing `STATUS_ACTIVE`/`STATUS_UNAVAILABLE`/`STATUS_CLOSED`. `is_active()` is not modified.
- **`ChannelBindingRepository::create()` gains a new optional parameter**, `string $status = self::class::STATUS_ACTIVE` (or equivalent, exact parameter name at implementation time), inserted in place of the current hardcoded literal at line 153 — **resolving ADR-0041 §2's open API-shape choice in favor of the optional-parameter form**, not a dedicated `create_prepared()` method, because it requires no change to `BindingImportCommand`'s or `EnsureChannelCaseService`'s existing call sites (both omit the new parameter and get today's unchanged `'active'` default) while still giving the new service an explicit, typed way to request `'prepared'`.
- New class: `SupportChatAdapter\Migration\LegacyBindingImportServiceV1` (namespace illustrative, final naming at implementation time), implementing `import_batch( array $candidates, bool $dry_run = false ): array` per ADR-0041 §2, internally calling `ChannelBindingRepository::find_by_conversation_uuid()` / `find_by_bot_topic()` (pre-check) then `create( ..., status: ChannelBinding::STATUS_PREPARED )`.
- New method on `Migration\QuiescenceGate` (or a new, narrowly-scoped collaborator class, implementation-time choice): the lock-scoped assertion (§4), returning a typed result (`quiescent-and-locked-write-performed` / `not-quiescent` / `backlog-nonempty`) rather than a bare boolean, so `LegacyBindingImportServiceV1` can map it directly to ADR-0009's `binding_retry_not_quiescent` outcome without re-deriving state itself.
- No new REST route. No change to `SupportChatAdapter\Outbound\OutboundContractController` or any existing Contract v1 acceptor. No new WP-CLI command in this repository — invocation is driven entirely by Support Chat's own `legacy-bind` command calling this class in-process.

## 6. Security and privacy impact

Per ADR-0041 in full: no plaintext, message content, or per-message delivery correlation is read, stored, or transmitted anywhere in this boundary. The `WP_CLI`-context check is real and closes external paths without overstating what it can guarantee against code already running inside an authorized WP-CLI process. The `prepared` status is this plan's core safety contribution — proven by permanent regression test (§7), not asserted by design intent.

## 7. Test and CI impact

- Unit tests: `WP_CLI`-context rejection (mirroring `LegacyExportServiceV1`'s existing test shape); batch-limit enforcement (≤100 regardless of caller request); live topic-state re-check correctly excludes a candidate whose lifecycle changed after being seeded; **every `create()`/`import_batch()` call in this service's test suite asserted to write `status === 'prepared'`, never `'active'`** — a permanent regression test, the single most load-bearing test this plan adds.
- Integration tests: the lock-scoped quiescence assertion as its own dedicated suite — proving the check and the `create()` write share one transaction (a forced exception after the lock/check but before `create()` leaves no binding row and releases the lock, verified by a second connection successfully acquiring the lock immediately after); pre-check-then-`create()` sequence reusing `ChannelBindingRepository`'s existing methods, with conflict/idempotency behavior extended from `BindingImportCommand`'s existing test precedent to the new caller; dry-run writes nothing and acquires no lock that outlives the call.
- **A permanent, dedicated non-interference test** (ADR-0041 §3, symmetric to ADR-0040 §7's `DeliverMessageService` requirement): proves `InboundAdapterBridge::try_handle()` never claims an inbound update for a topic whose only binding is `status = 'prepared'`, across all four ADR-0040 quiescence states (`idle`, `draining`, `quiescent`, `replaying`).
- No integration test in this repository writes to any Support Chat table or asserts anything about Support Chat's own migration-map behavior — that verification belongs to Support Chat's own `tests/integration/Interop/LegacyBindingImportIntegrationTest.php`, consistent with the existing `tests/integration/Interop/` pattern this repository already established for the WP6 gate and the export-boundary interop coverage.
- Full CI matrix (unit, integration, phpcs, phpstan, package-acceptance) per this repository's existing `.github/workflows/ci.yml` — no new job required; no new WP/PHP/WooCommerce matrix variant introduced.

## 8. Work packages (execution order)

### WP9 — Legacy binding preparation boundary (`LegacyBindingImportServiceV1`, `prepared` status, lock-scoped quiescence assertion)

- Schema step 34: additive `prepared` ENUM value, `verify_step_34`.
- `ChannelBinding::STATUS_PREPARED` constant.
- `ChannelBindingRepository::create()` optional status parameter (§5).
- Lock-scoped quiescence assertion (§5), with its own dedicated test suite (§7).
- `LegacyBindingImportServiceV1::import_batch()` per ADR-0041 §2: `WP_CLI`-context self-check, batch ceiling, live re-check, status-specific outcome vocabulary, `dry_run` parameter.
- Permanent non-interference regression test (§7) and permanent "never writes active" regression test (§7).
- Documentation: closure record for this work package, citing this plan's freeze SHA.
- **Explicitly excluded from this work package** (ADR-0041 §6, restated for execution clarity): any activation mechanism (`prepared → active`); any modification to `BindingImportCommand`, `InboundAdapterBridge`, `DeliverMessageService`, `WebhookController`, or `EnsureChannelCaseService`; any Support Chat-side code; any cutover/soak/rollback code.
- *Gate: ADR-0041 merged before this work package's implementation begins.*

## 9. Risks and mitigations

- Implementing against an unmerged ADR-0041 — mitigated by the explicit gate stated in §8 and in the milestone charter's §0c.
- A `create()` API-shape choice that accidentally changes `BindingImportCommand`'s or `EnsureChannelCaseService`'s existing behavior — mitigated by the optional-parameter design (§5), defaulting to today's unchanged `'active'` literal, plus this plan's own regression tests for both existing callers remaining green.
- Building the lock-scoped assertion as a second, subtly-different lock implementation rather than genuinely reusing `QuiescenceGate`'s existing discipline — mitigated by §4/§5's explicit instruction to reuse the identical `SELECT ... FOR UPDATE` pattern against the same singleton row, and a dedicated multi-connection lock-contention test (§7).
- Scope creep into `BindingImportCommand` hardening during implementation (a real, adjacent, and tempting fix) — mitigated by ADR-0041 §5/§6's explicit exclusion, restated in this plan's WP9 scope.

## 10. Explicit out-of-scope list

`prepared → active` activation and its future cutover work package; any modification to `BindingImportCommand`; Support Chat's SC-M03 work package 5 candidate identification, schema, or WP-CLI command (Support Chat repository); atomic route switch, soak, rollback (future work packages); any production binding creation, cutover, or activation; any AI-related migration; this plugin's legacy Conversations tab, AI tab, chat widget, and chat settings decommission (separate future task, gated on SC-M03 acceptance); any Contract v1 operation-allow-list change; any new REST/Ajax/cron path.

## 11. Definition of done

1. ADR-0041 merged to `main`.
2. `LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion implemented and passing the full test suite (§7) in this repository's CI, including the two permanent regression tests (never-writes-active; non-interference across all four quiescence states).
3. Closure record committed; Product Owner acceptance recorded.
4. Only then does Support Chat's SC-M03 work package 5 implementation proceed past its own two-repository merge gate (ADR-0009 Compatibility/Migration Impact; this plan's §8 gate, mirrored from Support Chat's side).
