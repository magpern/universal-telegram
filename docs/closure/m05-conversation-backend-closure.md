# Milestone Closure Record — M05 Conversation Backend

- **Starting baseline SHA (`main` before this milestone):** `2dce4e5` (M04.1 closure commit; clean, `main == origin/main`; M04.1 merged via PR #7, merge `e00fb1c`).
- **Frozen plan commit SHA:** `ab632ef` (`docs: freeze M05 conversation backend plan`), materializing `docs/plans/m05-conversation-backend-plan-v1.md` (v1). Not revised during implementation — no superseding plan SHA.
- **Implementation commits** (branch `feature/m05-conversation-backend`, merged to `main` via merge commit `9ff8a8e`):
  - `ab632ef` — docs: freeze M05 conversation backend plan
  - `4a01c5b` — WP0: freeze ADR-0021 and M05 plan cross-references
  - `34b1b8d` — WP1: add persistence layer and domain model
  - `707d989` — WP2: add visitor REST contract and bearer-secret authentication
  - `430ed0f` — WP3: create Telegram forum topic per conversation, idempotently, after the first visitor message
  - `0416009` — WP4: route visitor messages into the conversation's Telegram topic
  - `94e9c9c` — WP5: route Telegram topic replies back to their conversation
  - `7ea9a63` — WP6: add retention cleanup with fixed defaults; update architecture reference
  - `fb4e8cf` — fix: lean validation failures (rate-limit column width, db_version expectations, structural boundary guard, phpcs)
  - `2d400c5` — fix: package-acceptance script for M05's db_version and tables (CI-caught)
- **PR:** [magpern/universal-telegram#8](https://github.com/magpern/universal-telegram/pull/8), merged via merge commit `9ff8a8ec8ccd183a5e716a43d1efc6f9e2708825` (all ten commits preserved individually, not squashed, matching the M00–M04.1 merge-commit precedent).
- **Final `main` SHA:** `9ff8a8ec8ccd183a5e716a43d1efc6f9e2708825` (verified `main == origin/main`, clean working tree, immediately after merge).
- **Closure commit SHA:** recorded by this document's own commit, immediately following.

## Technical status

**PASS.** Every acceptance point of the frozen plan (`docs/plans/m05-conversation-backend-plan-v1.md`) and ADR-0021 is implemented, tested, and green in CI on both the PR and the merge commit. No known defect or unaccepted scope gap remains open.

## Implementation scope

Backend-only conversation boundary (`UniversalTelegram\Conversations`), per ADR-0005's pre-existing ownership assignment and ADR-0021:

- **Persistence (WP1):** `Migrator` steps 11–12 add exactly two tables — `universal_telegram_conversations`, `universal_telegram_conversation_messages` — `db_version` `10 → 12`. Domain model (`Conversation`, `ConversationMessage`, `ConversationStatus`), repositories (`ConversationRepository`, `MessageRepository`), and `VisitorTokenGenerator`.
- **Visitor REST contract (WP2):** `ConversationsController` — `POST /conversations` (start), `POST /conversations/{uuid}/messages` (post), `GET /conversations/{uuid}?since_id=` (short-poll) — unauthenticated at WP-REST, authenticated by a bearer secret verified only via `password_verify()` against a `password_hash()`-stored hash, uniform controlled `404` on every authentication failure mode, independent per-client/per-conversation and site-wide `RateLimiter` buckets (reused from the Telegram outbound boundary), no-store/no-CORS headers throughout.
- **Topic creation (WP3):** `create_forum_topic()` added to `TelegramApiClient`; `TopicCreationDispatcher`/`TopicCreationHandler` create a Telegram forum topic only after a conversation's first accepted visitor message, gated by an atomic compare-and-set (`ConversationRepository::try_begin_topic_creation()`) so at most one topic is ever created per conversation, with bounded retries ending in a surfaced `topic_creation_state = 'failed'`.
- **Outbound routing (WP4):** `ConversationOutboundDispatcher`/`ConversationOutboundHandler` — every visitor message is stored immediately regardless of topic state; routed into the existing, unmodified `OutboundMessageRepository`/queue pipeline once the topic exists; self-reschedules (outside the queue's counted retry budget) until a bounded 5-minute wait elapses, at which point the message is marked `delivery_state = 'failed'` but remains stored and visible — never silently dropped.
- **Inbound routing (WP5):** `WebhookController` extended narrowly — every one of ADR-0013's existing authenticity/dedup checks runs first, unchanged; conversation-scoped content capture (decrypt-on-write via `CredentialVault`, `direction = 'operator'` message row, `open → waiting_for_visitor` transition) proceeds only when dedup, chat-identity, and known-topic-mapping all hold. Every other inbound update remains byte-for-byte metadata-only.
- **Retention (WP6):** `Conversations\RetentionCleanupHandler` — one daily Action Scheduler action archives every `resolved` conversation (nulling its secret at the same moment), nulls message bodies 30 days after archival, and permanently deletes the conversation, its messages, and its destination row 90 days after archival. Idempotent by construction.

## ADR-0021

**Conversation Persistence, Bearer-Secret Visitor Authentication, and Topic-Scoped Inbound Message Capture**, accepted in commit `4a01c5b`. Narrowly extends ADR-0013's existing webhook-authenticity boundary (does not supersede it) and establishes the `Conversations` boundary's persistence model, visitor-token security model, and public REST contract. No second ADR — nothing else in this milestone introduces an independent architecture, security boundary, persistence model, or public contract.

## Version / database transition

- `UNIVERSAL_TELEGRAM_VERSION`: `0.3.1 → 0.4.0` — a **minor** bump: the conversation backend is a genuine new functional-capability class, not a restructuring.
- `universal_telegram_db_version`: `10 → 12` (Migrator steps 11–12; two new tables, no schema change to any existing table, including the reused pre-existing `universal_telegram_destinations` table).
- Distributable package: `universal-telegram-0.4.0.zip`, built and verified via package acceptance (local and CI).
- **No Git tag, no GitHub Release, and no deployment action was created or performed for this milestone.**

## Two-table confirmation

Exactly two new tables (`universal_telegram_conversations`, `universal_telegram_conversation_messages`). No third conversation table. The per-conversation Telegram destination is a row in the pre-existing `universal_telegram_destinations` table (M01, unchanged schema), not a new table.

## Token, endpoint, encryption, topic-routing, and retention guarantees

- **Visitor token:** public `conversation_uuid` is the sole database lookup key; the bearer secret is never persisted in plaintext, never placed in a URL/query string/log/diagnostics value/error message, stored only as `password_hash()`, verified only via `password_verify()`. Revocation is exclusively by nulling `secret_hash` — at `resolved → archived` and at retention deletion — never by deleting the row as the revocation act.
- **Endpoint posture:** every M05 response carries `Cache-Control: no-store, no-cache, must-revalidate` and `Pragma: no-cache`; no `Access-Control-Allow-Origin` header is ever emitted; polling is short-poll only. Every authentication failure mode returns the identical controlled `404` — no `401`, no distinguishing detail.
- **Encryption:** message bodies encrypted at rest via the existing `CredentialVault` (AES-256-GCM), bound to a per-message `conversation_message:{message_uuid}` additional-authenticated-data context; decrypted only per-request for the authenticated caller; a decrypt failure renders an opaque placeholder, never a plaintext leak or an exception.
- **Topic routing:** a Telegram forum topic is created only after a conversation's first accepted visitor message, via a single atomic compare-and-set, making topic creation idempotent and concurrency-safe by construction — verified by `TopicCreationDispatcherTest::test_maybe_create_enqueues_exactly_once_for_repeated_calls`.
- **Retention:** fixed defaults (30 days message-body nulling from archival, 90 days full deletion from archival), no settings UI, one daily Action Scheduler action, idempotent on rerun — verified by `RetentionCleanupHandlerTest`.

## Lean validation and CI evidence

Local (lean validation gate, per this milestone's execution authorization):

- Integration suite (WP-only, `bin/docker/test-integration-wp-only.sh --wp-version=6.9`) — **350 passed**, 38 skipped, 0 failures (rerun clean after fixing two defects found by the first run: a `RateLimiter` scope-type column-width truncation bug, and stale hardcoded `db_version=10` expectations in two pre-existing tests).
- Unit suite (`bin/docker/test-unit.sh`) — **172 passed**, 0 failures (after updating `StructuralBoundariesTest` to remove `Conversations` from the not-yet-implemented boundary list, since this milestone's frozen plan now authorizes it).
- PHPCS (`bin/docker/phpcs.sh`, full tree) — **0 errors, 0 warnings** (56 formatting violations auto-fixed via `phpcbf`, 5 remaining issues fixed by hand: doc-comment capitalization, one unused parameter).
- PHPStan (`bin/docker/phpstan.sh`, level 5, full tree) — **no errors**.
- Plugin ZIP build (`bin/docker/build-zip.sh`) — `universal-telegram-0.4.0.zip` built successfully.
- Package-acceptance (`bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3`) — **PASSED** locally after fixing the script's hardcoded `db_version=10` expectation and adding checks for the two new M05 tables (this defect was independently caught by CI's `package-acceptance (7.1, 8.3)` job on the first PR push and fixed in commit `2d400c5`).

CI (GitHub Actions), full matrix, both the `pull_request` trigger and the `push` trigger on the merge commit:

- PR #8, final commit `2d400c5`: every job green across both the `push` and `pull_request` workflow runs (`phpcs`, `static-analysis`, `unit` ×3 PHP versions, `integration-wp-only-floor`, `integration-wp-only-current`, `integration-wc-present-current`, `js-behavioural`, `build`, `package-acceptance` ×3 configurations).
- Merge commit `9ff8a8e` on `main`: green (run `32515918735`).

CI caught one real defect this local pass had initially missed: `package-acceptance (7.1, 8.3)` failed on the first PR push because `tests/package/run.sh` hardcoded `expected universal_telegram_db_version=10` from M02 — fixed in commit `2d400c5`, verified locally, and confirmed green on rerun.

## Deviations from the frozen plan

None material. Two implementation clarifications, neither specified precisely by the plan text nor contradicting any frozen decision:

- `chat_profile` (start's optional body field) resolves against a configured bot's own `name` field, reusing existing bot-profile configuration rather than introducing a new settings surface — consistent with the plan's explicit "no new settings UI" constraint.
- The conversation-support destination (the chat a topic is created in) resolves to a bot's one enabled `supergroup` destination with no `message_thread_id` of its own, reusing the pre-existing `universal_telegram_destinations` table and its existing configuration mechanism.

## Security and reliability confirmation

- No new WordPress capability, no new Hub tab. `MANAGE`/`MANAGE_AUTOMATIONS` (ADR-0010) untouched by this milestone.
- The visitor REST API cannot set assignment or arbitrary status: `ConversationRepository::assign()` exists only as the domain method the charter requires, called by no M05 code path; the public REST endpoints and inbound webhook path only ever perform the exact transitions frozen in the plan's §7 map.
- ADR-0013's webhook-authenticity boundary is extended, never weakened: every existing check (bot lookup, secret-token verification, body-size cap, JSON well-formedness, `update_id` dedup) runs first, unchanged, with identical failure responses.
- No plaintext message text, raw/hashed visitor secret, or `Authorization` header value ever reaches a log or the Audit/Diagnostics surfaces.

## Requirements-to-evidence mapping

Not produced as a separate document for this milestone; the plan's own §13 "Lean validation and evidence" enumerated scenario list (11 scenarios) maps directly to the test files listed above (`ConversationsControllerTest`, `TopicCreationDispatcherTest`, `TopicCreationHandlerTest`, `ConversationOutboundHandlerTest`, `WebhookControllerConversationRoutingTest`, `RetentionCleanupHandlerTest`, `ConversationRepositoryTest`, `MessageRepositoryTest`).

## Product Owner acceptance

**M05 Product Owner acceptance: PENDING.** Not attempted as part of this technical-closure work, per ADR-0011 (M00–M09 close on automated evidence alone; formal acceptance testing is deferred until M10).

**M04.1 Product Owner acceptance (Vlad Stormhaven's manual menu/navigation review): still PENDING**, unaffected by and unrelated to this milestone — recorded here only for continuity, per the M04.1 closure record.

## Other confirmations

- **M06 has not started.** This closure covers M05 only — the conversation backend. No ChatWidget-boundary code, plan, or branch exists as of this record.
- **No real Telegram bot was configured, and no live Telegram API call occurred anywhere in this milestone** — every `TelegramApiClient` test (including the new `create_forum_topic()` coverage) uses WordPress's own `pre_http_request` filter, exactly matching the M01–M04 precedent.
- **No Git tag, no GitHub Release, and no deployment action occurred anywhere in this milestone.**
