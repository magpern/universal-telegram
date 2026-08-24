# ADR-0031 — Conversation Topic Lifecycle: Archive, Remote Forum-Topic Deletion, and Topic-Unavailable Repair

## Status

Proposed

## Context

M05/ADR-0021 creates one Telegram forum topic per visitor conversation and one generated destination row (`supergroup` + `message_thread_id`). M07/ADR-0026 archives locally and purges locally; `ConversationPurgeService` and retention must not call the Bot API. Operators can delete those topics in Telegram. The plugin then keeps a healthy-looking conversation whose outbound sends dead-letter as generic `telegram_terminal_rejection` (HTTP 400/403), with no stored API text (ADR-0009/0014) and no safe cleanup except database edits. ADR-0014 classifies every 400/403 as terminal without distinguishing a missing topic from parse errors or a missing chat. Conversation messages are marked `sent` when handed to the outbound table, before Telegram accepts them.

## Decision

1. **Split state.** Operator workflow remains `ConversationStatus`. Telegram-topic health lives in `topic_lifecycle_state` (`none|active|unavailable|delete_pending|delete_failed`) plus `topic_lifecycle_code` (fixed codes only).
2. **Manual Archive** is a local transition to `archived` plus `revoke_secret()`, from `new|open|waiting_*|resolved`. It does not call Telegram and does not delete the generated destination.
3. **Manual Delete permanently** exists only on the operator-inbox conversation detail (not the Bots destination list). It requires `archived`, `MANAGE_CONVERSATIONS`, nonce, and a second confirming POST. GET/render never deletes. If `ConversationTopicEligibility` passes, the request CAS-enqueues `conversation_delete_topic` and returns; `TelegramApiClient::delete_forum_topic` runs only in that job. Success or **explicit missing-topic/missing-thread** then calls `ConversationPurgeService`. `chat not found` is **not** already-absent: it marks `delete_failed` with `telegram_topic_delete_chat_not_found` and retains local rows. Ineligible rows (no exclusive plugin-created topic) purge this conversation locally with no API call; a shared `destination_id` is ineligible and the destination row is not deleted.
4. **Eligibility is structural:** conversation-owned `destination_id`, `topic_creation_state=created`, `telegram_topic_id === destination.message_thread_id`, both `> 1`, same `bot_id`, kind `supergroup`, and **exclusive ownership** (exactly one conversation references that `destination_id` — this one). Schema UNIQUE on `conversations.destination_id` enforces exclusivity after a duplicate-nulling upgrade repair. Manual destinations, General (`NULL` or thread `1`), unrelated topics, missing rows, malformed ids, and shared destinations are ineligible.
5. **Retention** keeps the 30/90-day clocks. The 90-day step uses the same remote-delete-then-purge path. No second retention mechanism.
6. **Topic-unavailable:** inbound `forum_topic_closed`/`forum_topic_deleted`, or outbound terminal errors whose description allow-list matches thread-not-found / TOPIC_CLOSED **and** whose destination thread id is `> 1`, set `unavailable` with a fixed code. **Every inbound topic lookup and mark-unavailable path requires the exact `(bot_id, chat_id, message_thread_id)` tuple**; a thread id alone is never identity. Generic 400/403 and `chat not found` stay `telegram_terminal_rejection` (send) or `telegram_topic_delete_chat_not_found` (delete) and do not change topic state to unavailable / already-absent. Raw descriptions are never stored, audited, or returned to visitors.
7. **Visitor contract:** archived/purged remain non-enumerating 404 `conversation_expired`. Open-but-unavailable POST returns 409 `conversation_unavailable` without inserting a message. `delivery_state` `sent` means Telegram accepted the send.
8. **Crash safety:** delete uses compare-and-set plus `topic_delete_claim_expires_at`. Duplicate jobs do not double-delete. Transient failures leave local rows in `delete_failed` or still-pending retry. Forbidden deletion and `chat not found` never purge locally.

## Alternatives

- Synchronous `deleteForumTopic` inside the admin POST — rejected: request timeout and crash would desynchronize Telegram and WordPress; M06.2 already forbids visitor-path sync except the bounded Test Message.
- Reusing `ConversationStatus` for `deleting`/`unavailable` — rejected: would collide with reopen/retention and `owner_active_slot`.
- Force local purge when the bot cannot delete — rejected: would strand a live Telegram topic.
- Scanning Telegram during migration — rejected: migrations must not call providers.

## Consequences

ADR-0026’s “manual delete never contacts Telegram” is superseded for **eligible plugin-created topics only**. ADR-0021’s 90-day local-only purge is superseded by remote-then-local for those topics. ADR-0014’s TERMINAL class remains; send-path reason codes gain `telegram_topic_not_found` / `telegram_topic_closed`; delete-path adds `telegram_topic_delete_forbidden`, `telegram_topic_delete_chat_not_found`, and `telegram_topic_delete_attempts_exhausted`. Bots-tab conversation topics stay read-only and may link to the inbox.

## Security and privacy impact

Destructive Bot API use is confirmation-gated, capability-gated, queued, and eligibility-checked, including exclusive destination ownership. Audit context carries conversation id and fixed codes only (INTERNAL). Visitor REST never receives Telegram descriptions. Secrets remain revoked at archive before purge. Inbound conversation identity is `(bot_id, chat_id, message_thread_id)`, never a thread id alone.

## Affected Documents/Milestones

M07.1 on the combined M11 branch; `docs/ARCHITECTURE.md` versioning; operator inbox/detail; Telegram client; webhook inbound; conversation REST; retention handler. M08 command surface unchanged.

## Compatibility/Migration Impact

`db_version` 28→29, additive columns plus UNIQUE `conversations.destination_id` after duplicate-nulling repair, backfill `active` where `topic_creation_state=created`. Existing stale conversations become cleanable via Archive then Delete permanently without SQL. Plugin version 0.13.0→0.14.0 on the combined M11 branch only; no production release in this slice.
