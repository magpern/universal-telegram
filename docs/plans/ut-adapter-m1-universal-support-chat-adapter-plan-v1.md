# UT Adapter M1 — Universal Support Chat Adapter — Implementation Plan v1

## 1. References

- Charter: `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md`
- Authorising ADR: `docs/adr/0037-support-chat-extraction-supersession-and-optional-adapter-consumer.md`
- **Canonical Contract v1 (do not duplicate in full):**
  - SHA: `dff2730e24b7d3f70f15f706305e12e14fdcc6c8`
  - URL: `https://github.com/magpern/universal-support-chat/blob/dff2730e24b7d3f70f15f706305e12e14fdcc6c8/docs/adr/0005-canonical-support-channel-contract-v1.md`
- Related Support Chat ADRs (external): optional-channel failure model; migration principles (adapter before SC-M03)
- Existing UT transport foundations: ADR-0012, ADR-0013, ADR-0014 (bots, webhooks, outbound reliability) — reused, not redesigned

## 2. Repository findings (plan-freeze time)

- Chat SoR, widget, Hub conversation admin, and AI drafts currently live in Universal Telegram (`Conversations`, `ChatWidget`, `Administration/Conversations`, `AI/Draft`) as **legacy runtime** until Support Chat SC-M03.
- ADR-0033–0036 / M05.2–M10 UT implementation paths are **superseded** by ADR-0037; **no M05.2 code exists**.
- Outbound send pipeline (`OutboundMessageRepository`, send handlers) and webhook inbound stack exist and should be reused for adapter delivery/retry.
- Operator Telegram identity map already exists for authorisation of inbound operators.
- Support Chat Contract v1 is published and pinned above; Universal Telegram must consume it, not fork it.

## 3. Assumptions and open questions

### Assumptions

- Support Chat SC-M01/SC-M02 expose Contract v1 server operations and discovery before Adapter M1 integration tests run.
- Legacy UT chat write paths remain until SC-M03; Adapter M1 may run beside legacy during development but production cutover sequencing is SC-owned.
- Table prefix follows existing `universal_telegram_*` conventions.

### Open questions (resolve at implementation without changing Contract v1)

- Exact WordPress action/REST/discovery mechanism names on the Support Chat side (follow SC public contract package when published).
- Whether binding rows for pre-cutover topics are created by UT WP-CLI invoked from SC-M03 tooling vs SC calling an adapter “import binding” API — both must write **only** UT-owned binding storage.

## 4. Architectural decisions

| Decision | Choice | Alternative rejected |
|---|---|---|
| Contract text | Pin SHA/URL; reference only | Copy full Contract v1 into UT docs |
| Binding storage | UT-owned table(s) | Columns on SC conversations |
| Plaintext custody | SC exports in memory on delivery/backfill calls; UT encrypts outbound queue after accept | UT decrypts SC vault |
| Domain mutations | SC executes via Contract callbacks | UT writes SC tables |
| Failure domain | Telegram channel only | Take down Hub/widget |
| Ordering | Adapter M1 before SC-M03 | Migrate then invent bindings |
| Traffic filter | Escalated/support-channel only | Mirror all AI-only chat |

## 5. Directory, namespace, schema, and API impact

### Planned modules (names illustrative until code freeze)

- `src/SupportChatAdapter/` (or equivalent subdomain under Telegram/Integrations) — discovery client, delivery acceptors, inbound mappers, binding repository.
- Do **not** place new website widget or Hub inbox code under ChatWidget/Conversations for product SoR (legacy remains until SC-M03).

### Binding table (logical schema)

Minimum columns/concepts:

- `binding_uuid` (opaque; shared with Support Chat as `channel_case_ref`)
- `support_conversation_uuid`
- bot identity / `destination_id` / `telegram_topic_id` (UT-native)
- lifecycle/CAS version or lease fields for ensure idempotency
- remote delivery cursors / last remote message ids as needed
- status (`active|unavailable|closed`) timestamps
- unique constraints preventing duplicate active bindings per conversation and per topic

### API surface

- Inbound: existing webhook → adapter mapper → Support Chat Contract ops (`ingest_operator_reply`, claim/release, resolve/reopen, assignment, presence, failure reports).
- Outbound: Support Chat → UT acceptor for `ensure_channel_case`, `notify_operators`, `deliver_transcript_backfill`, `deliver_message`.
- Discovery/handshake endpoint or registered capability advertisement pinned to Contract version id `support-channel-contract/v1`.

## 6. Security and privacy impact

- Authenticate every SC → UT and UT → SC call; capability checks on both sides.
- Never log plaintext message bodies or bot tokens.
- Never store Telegram tokens or topic IDs in Support Chat.
- No direct SQL to Support Chat tables.
- Exclude internal notes/audits/secrets from backfill (eligibility enforced by SC; UT must not invent transcript content).
- Visitors never receive binding UUIDs, remote IDs, or operator internals.
- Operator authorisation continues to use UT operator-identity map before lifecycle callbacks.

## 7. Test and CI impact

WordPress-only configuration required; WooCommerce-present not required for adapter core.

### Contract tests

- Discovery success against compatible Contract v1; mismatch disables adapter features.
- Ensure idempotency: same key → same `binding_uuid` / no duplicate topics.
- Backfill page retries do not duplicate remote messages.
- `deliver_message` idempotency with outbound queue dedupe.
- Inbound duplicate Telegram update → single `ingest_operator_reply` effect.

### Negative tests

- Support Chat plugin absent → adapter inert; non-chat notifications still work.
- Adapter deactivated mid-open binding → `report_channel_unavailable` path; no fatal global failure.
- Unauthenticated/forged callback rejected.
- Attempted SQL/cross-table access patterns absent (architectural/guard test if feasible).

### CI

- Unit + integration coverage for mapper/idempotency.
- `check-doc-links` remains green.
- No requirement to modify Automations/M08.x tests.

## 8. Work packages (execution order)

### WP1 — Skeleton and discovery

- Files: new adapter bootstrap registration; capability advertisement; settings flag “Support Chat adapter enabled”.
- Validation: handshake unit tests; plugin activates with SC absent.
- Acceptance: discovery fails closed without SC; no chat SoR writes.

### WP2 — Binding repository and migration

- Files: migration step adding binding table; repository with CAS/ensure helpers.
- Validation: migration up/down in WP test install; unique constraint tests.
- Acceptance: can insert/reuse binding by conversation UUID and by topic identity.

### WP3 — Outbound acceptors (ensure / notify / backfill / deliver)

- Files: acceptors invoking existing outbound pipeline; encrypt-at-rest queue after accept; map failures to `report_delivery_failure` / `report_channel_unavailable`.
- Validation: idempotency tests; retry tests; no SC SQL.
- Acceptance: Contract outbound ops behaviour matches pin; plaintext not persisted in adapter beyond outbound encryption model.

### WP4 — Inbound webhook mapping

- Files: route topic-scoped operator messages and `/support`-class commands (when adapter mode) to SC Contract ops; keep non-chat bot commands on existing paths.
- Validation: duplicate update fixtures; unauthorised operator rejected.
- Acceptance: claim/release/resolve/reopen/assignment/presence/ingest paths covered.

### WP5 — Failure and deactivation

- Files: deactivation hook; unavailable reporting; feature flags.
- Validation: mid-conversation deactivation test; notifications still send.
- Acceptance: Telegram-only fail-closed.

### WP6 — SC-M03 readiness hooks

- Files: WP-CLI or authenticated admin tool to create bindings from legacy topic mappings (read legacy UT chat tables **read-only** for import metadata; write only binding table).
- Validation: dry-run + apply; idempotent re-run.
- Acceptance: documented entry point for SC-M03 cutover.

### WP7 — Docs and closure

- Files: architecture touch-ups if needed; closure record at milestone end.
- Validation: `bin/docker/composer.sh run-script check-doc-links`.
- Acceptance: Product Owner closure.

## 9. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Drift from Contract v1 | Pin SHA/URL in code constants/tests; fail handshake on mismatch |
| Dual SoR during development | Feature-flag adapter; forbid UT Conversations writes from adapter module |
| Duplicate topics at ensure | CAS + idempotency keys + unique constraints |
| Breaking non-chat Telegram | Isolate adapter registration; negative tests for notifications |
| Migrating before bindings | Charter hard-depends Adapter M1 before SC-M03 |

## 10. Explicit out-of-scope list

- M05.2 / M06.4 / M07.2 / M09.1 / M10 implementation in this plugin
- Support Chat runtime code in this repository
- Dual-write chat messages to UT + SC SoR
- Copying Contract v1 document body into UT
- Changing M08.1 / M08.2 / ADR-0032 / Automations / digests product behaviour
- SC-M03 migration execution (consumes this milestone’s binding APIs)
- Deleting legacy chat tables
- Plugin release/tag/deployment as part of docs freeze (this plan’s documentation freeze predecessor already forbids it)

## 11. Definition of done

Matches charter acceptance and exit criteria:

1. Contract discovery/capability negotiation works against pinned Contract v1 SHA/URL semantics.
2. Binding table owns opaque UUID, SC conversation UUID, bot/destination/topic identity, lifecycle/CAS, remote delivery cursors/IDs.
3. SC → UT ensure/backfill/deliver/notify implemented with UT-owned queue/retry.
4. UT → SC ingest/claim/release/resolve/reopen/assignment/presence/unavailable/delivery-failure implemented without SC SQL.
5. Adapter failure/deactivation fails closed for Telegram only.
6. Idempotency/retry/contract/negative tests green in CI.
7. SC-M03 can create/import bindings for existing topics via documented tool.
8. Closure record committed; Product Owner acceptance recorded.
