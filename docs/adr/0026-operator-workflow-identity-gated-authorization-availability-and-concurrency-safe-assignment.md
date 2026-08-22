# ADR-0026 — Operator Workflow: Identity-Gated Inbound Authorization, Availability/Unread State, and Concurrency-Safe Assignment

## Status

Proposed

## Context

M07's charter (docs/milestones/m07-operator-workflow.md) requires operator identity mapping,
assignment, conversation status controls including resolution and reopening, online/busy/offline
state, notifications and unread state, internal notes, and conversation search, while keeping
Telegram as the sole operator messaging channel, and requires that unauthorized Telegram users
cannot act and that concurrent operators cannot silently overwrite state. No existing mechanism
gates who may act as an operator from inside Telegram beyond membership in the configured support
group/topic: WebhookController::maybe_route_to_conversation() accepts any message from the
correctly matched chat/topic with no per-sender check. No presence, unread, or notification
mechanism exists. ConversationRepository::assign() is a plain, non-conditional update. No shared
purge sequence exists — RetentionCleanupHandler inlines its own 90-day deletion steps.

## Decision

1. **Operator identity mapping** is a new table, `universal_telegram_operator_identities`
   (wp_user_id BIGINT UNSIGNED UNIQUE, telegram_user_id BIGINT UNSIGNED UNIQUE, telegram_username
   VARCHAR(255) NULL, created_at, created_by BIGINT UNSIGNED), populated only through a manual,
   capability-gated WordPress admin action. This mapping is now the plugin's inbound Telegram
   operator-authorization gate (see decision 2), not merely a display convenience.
2. **Inbound authorization**: WebhookController::maybe_route_to_conversation() gains one
   additional check, run after the existing chat-id and topic-id match (both retained unchanged)
   and after the caller's own existing (bot_id, update_id) dedup: the inbound update's
   `message.from.id` (Telegram's own numeric sender id) must resolve to an existing row in
   OperatorIdentityRepository. If it does not, the method returns immediately — no message row is
   created, no ciphertext is written, no status transition occurs, and nothing is forwarded to the
   visitor side. A rejected-sender attempt is recorded via AuditLogger (actor_type 'system') using
   only a fixed rejection code (e.g. `conversation.operator_reply.rejected_unmapped_sender`) plus
   `bot_id` and `conversation_id` — the raw `message.from.id` (SENSITIVE, decision 11) is never
   written into the audit context, not even as a hash or other reversible derivative. Telegram
   usernames are never used as an identity signal, since they are self-chosen and unauthenticated.
   The resolved sender's raw Telegram id is stored on the created message row (decision 3) so the
   WordPress detail view can attribute the reply to a named operator without ever displaying that
   raw id itself.
3. **Attribution column**: `universal_telegram_conversation_messages` gains a nullable, indexed
   `telegram_sender_user_id BIGINT UNSIGNED NULL` column, populated only for `direction='operator'`
   rows created via the Telegram inbound path, holding the raw Telegram numeric sender id — treated
   as SENSITIVE personal data (decision 11), retained in this column solely as a protected join key.
   It is never rendered, never placed in an admin URL/GET parameter, and never usable as a search
   filter; the WordPress detail view uses it only internally, as a join key against
   OperatorIdentityRepository, to display the mapped operator's WordPress display name.
4. **Availability**: a new table, `universal_telegram_operator_availability`
   (operator_user_id BIGINT UNSIGNED UNIQUE, state VARCHAR(16) NOT NULL DEFAULT 'offline',
   updated_at, updated_by BIGINT UNSIGNED), restricted to `available|busy|offline`. A mapped
   operator sets their own state under `MANAGE_CONVERSATIONS`; an administrator (`MANAGE`, the
   broader existing capability) may set another mapped operator's state. Every change is audited.
   `telegram_username`, stored on the identity mapping itself, is likewise treated as SENSITIVE
   personal data: displayed only on the `MANAGE`-gated OperatorIdentityPage (the one screen whose
   purpose is managing these mappings), never on the conversation inbox/detail views.
5. **Unread**: `universal_telegram_conversations` gains a nullable
   `assignee_last_seen_message_id BIGINT UNSIGNED NULL` column, set to the highest message id the
   currently assigned operator has viewed (updated when that operator opens the conversation detail
   view) and reset to NULL on reassignment. Unread state for an assigned conversation is derived —
   never separately stored — as "at least one `direction='visitor'` message with id greater than
   this column's value (or any, if NULL)". The operator inbox surfaces a count of the current
   operator's own assigned-and-unread conversations as an in-page badge/notice on Hub page load.
   No push, email, WebSocket, polling daemon, or Telegram-side notification is introduced.
6. **Assignment respects availability**: the assignment UI blocks assigning a conversation to an
   operator whose current state is `busy` or `offline` unless the acting user holds the broader
   `MANAGE` capability and explicitly confirms an override; an override is always audited with a
   distinct action code and a visible warning shown to the acting user before confirmation.
7. **Reopen** is added to ConversationStatus's frozen transition map as `RESOLVED → OPEN` only;
   `ARCHIVED` remains fully terminal, unchanged from the reasoning already accepted in this plan's
   assumption D.
8. **Concurrency-safe assignment**: `ConversationRepository::assign_with_expected()` replaces
   direct use of the existing `assign()` method from any new M07 UI code path (the method itself is
   left in place, since M05's own doc-comment already reserves it for M07 and other internal
   callers may still want an unconditional set). The new method performs a conditional update whose
   WHERE clause includes the caller's expected current `assigned_operator_id` (including `null`),
   using the same `$wpdb->update()` null-in-WHERE idiom `store_display_name()` already establishes
   in this codebase. A losing caller's request matches zero rows and is reported as a visible
   conflict, never applied silently. Only a successful change is audited.
9. **Manual deletion** is a new, `MANAGE_CONVERSATIONS`-gated, nonce-protected admin action
   available only on a conversation already in `archived` status, auditing before it executes. It
   calls a new, small `ConversationPurgeService::purge( int $conversation_id, ?int $destination_id )`
   — the exact three-call sequence (`MessageRepository::delete_for_conversation()`,
   `DestinationRepository::delete()` when present, `ConversationRepository::delete()`) extracted out
   of `RetentionCleanupHandler`'s own 90-day step into one shared, independently testable class both
   the scheduled handler and the admin action call, preventing behavioral drift between the two.
   Matching `DestinationRepository::delete()`'s existing behavior, this never calls the Telegram Bot
   API: no Telegram-side message or forum topic is ever touched.
10. A new WordPress capability, `CapabilityRegistrar::MANAGE_CONVERSATIONS`, gates every operator
    inbox screen and self-service action; the broader, existing `MANAGE` gates administrator-level
    overrides (availability-for-another-operator, busy/offline assignment override), mirroring
    `HubPage`'s own established two-tier pattern. Granted to the administrator role on activation,
    revoked on uninstall, identically to the two existing capabilities.
11. Every state-changing action this ADR introduces is recorded via the existing AuditLogger,
    introducing `'operator'` as the first human actor_type value, with an explicit, fail-closed
    classification map per docs/adr/0009 — a conversation's bearer secret, ciphertext, decrypted
    body/name, IP, or user agent is never included in any audit context. The raw Telegram sender id
    (decision 2/3) and the optional Telegram username (decision 1/4) are both classified SENSITIVE
    personal data, not INTERNAL: they identify a real Telegram account. Neither is ever written into
    an audit context in raw form — the rejected-sender entry (decision 2) is the one place this
    would otherwise be tempting, and it is deliberately limited to a fixed rejection code plus
    bot_id/conversation_id instead.
12. On WordPress `deleted_user`, the full operator-cleanup sequence runs in addition to the existing
    ConversationRepository::release_owner_conversations() call (src/Core/Plugin.php, M06.3.1),
    which remains unchanged and continues to handle the visitor-owner side only. The operator side,
    in this exact order (the Telegram id must be read before the mapping row that holds it is
    deleted):
    a. Resolve the deleted user's mapped Telegram id via
       OperatorIdentityRepository::find_by_wp_user_id() before deleting anything.
    b. MessageRepository::clear_sender_attribution( int $telegram_user_id ): nulls
       `telegram_sender_user_id` on every existing message row matching that Telegram id. The
       message row and its (possibly still-encrypted, possibly already retention-nulled) body are
       never deleted or otherwise altered by this step — only the now-orphaned personal-identifier
       join key is cleared, so it can never later be matched against a different account that
       happens to reuse the same numeric Telegram id.
    b2. ConversationNoteRepository::anonymize_author( int $operator_user_id ): nulls the nullable
       `operator_user_id` author-reference column on every note that operator authored. Note content
       itself is untouched and remains subject to the same conversation retention model as before;
       a note with a null author renders as "— former operator —" in the detail view. Because a
       message row only ever exists here for a reply that was actually accepted (decision 2's
       rejection path never creates one), a null `telegram_sender_user_id` on an existing message is
       unambiguous evidence of this cleanup, never confusable with a rejected/unauthorized sender.
    c. ConversationRepository::clear_assignment_for_operator( int $operator_user_id ): sets
       `assigned_operator_id` and `assignee_last_seen_message_id` to NULL on every conversation
       currently assigned to that operator.
    d. OperatorAvailabilityRepository::delete_for_operator( int $operator_user_id ): deletes the
       availability row.
    e. OperatorIdentityRepository::delete_for_wp_user( int $wp_user_id ): deletes the mapping row
       itself, last, now that its Telegram id has already been used in step (b).
    f. One AuditLogger::record() call, actor_type 'system', action code
       `conversation.operator_identity.account_deleted_cleanup`, with an **empty context** (no
       wp_user_id, telegram_id, or username) — this is a system-lifecycle marker, not an
       investigative record, and personal values have no place in it per this correction.

## Alternatives

Continuing to treat Telegram group/topic membership alone as sufficient inbound authorization —
rejected per explicit correction: the charter's own acceptance criterion ("unauthorized Telegram
users cannot act") is not satisfied by a boundary this plugin cannot itself verify or audit.
Inferring identity from Telegram usernames — rejected: self-chosen, unauthenticated, spoofable.
Storing unread as an explicit boolean/counter column instead of deriving it from
assignee_last_seen_message_id — rejected: a derived value can never drift out of sync with the
message table it describes, at the cost of one comparison per row rendered, which is cheap at this
data scale. Building presence/notifications on a new polling or WebSocket layer — rejected: no
existing precedent in this codebase, and the task's own scope explicitly excludes it; a
page-load-computed badge fully satisfies "unread state" without new infrastructure. Reusing the
existing `assign()` method's unconditional update for the new UI — rejected: contradicts the
concurrency-safety acceptance criterion directly. Duplicating RetentionCleanupHandler's own private
purge steps inline in the new admin action (an earlier draft's original approach) — rejected on
correction: not a real reuse mechanism and risks the two call sites silently diverging over time.
Classifying the raw Telegram sender id as INTERNAL (an earlier draft's original approach) —
rejected on correction: it identifies a real individual's Telegram account and is materially
different from this plugin's own internal bookkeeping ids. Leaving a deleted operator's
`telegram_sender_user_id` and internal-note authorship untouched after account deletion —
rejected: it would either strand a personal Telegram identifier with no protecting mapping left, or
risk a future, reused WordPress user id inheriting authorship of a note it never wrote. Deleting
note/message content outright on operator account deletion instead of anonymizing authorship —
rejected: content remains subject to the existing, already-reviewed conversation retention model;
deleting it early on operator (not visitor) account deletion has no charter basis and would destroy
conversation history the visitor side, or a differently-assigned operator, may still need.

## Consequences

The transition map, capability registrar, message/conversation schema, and audit call sites gain
new, additive members; no existing entry is changed or removed. Operators who reply in Telegram
without first being mapped will find their replies silently not reach the visitor side — this is a
deliberate fail-closed behavior, but requires the operational documentation this ADR's decision 2
calls for (every operator must be mapped before their first reply). A future milestone that wants
richer presence/notification delivery (push, email, Telegram-side) introduces its own ADR.

## Security and privacy impact

Closes the "resolution and reopening," "operator identity mapping," and — materially — the
"unauthorized Telegram users cannot act" charter gaps. Assignment and manual deletion are both now
safe against concurrent/accidental misuse: CAS prevents a silent assignment overwrite, and manual
deletion is capability- and nonce-gated, audited before execution, restricted to a terminal state,
and reuses a single, shared, independently tested purge path — it cannot delete an active or
resolved conversation, and never reaches into Telegram itself. The raw Telegram sender id and
username are both treated as SENSITIVE personal data end-to-end: retained only as a protected join
key or an administrator-only mapping-management display, never rendered elsewhere, never placed in
a URL or search filter, and never written into an audit context — including the one path (the
rejected-sender entry) that would otherwise have been the most tempting place to log it for
troubleshooting. Operator account deletion now fully unwinds an operator's personal-data footprint
(identity mapping, availability, message attribution, note authorship) while preserving
conversation content itself under the existing, unchanged retention model.

## Affected Documents/Milestones

docs/adr/0021 (transition map and message schema extended, not amended in place). docs/adr/0010
(capability registrar extended per its own anticipated pattern, now with an explicit two-tier
self-service/administrator split). docs/adr/0013 (webhook authenticity) — decision 2 relies on
that ADR's existing signature verification already having run before this new sender-identity
check is ever reached. docs/adr/0009 (privacy/redaction) — decision 11 applies its classification
model to two new SENSITIVE fields. M06.3.1/ADR-0025's own `deleted_user` handling
(`release_owner_conversations()`) is extended, not replaced, by decision 12's operator-side
cleanup. M08 (administrative bot), if it later wants operator identity for bot-command
authorization, reuses this ADR's mapping table.

## Compatibility/Migration Impact

Two migration steps (db_version 16 → 18): step 17 creates
`universal_telegram_operator_identities`, `universal_telegram_conversation_notes`, and
`universal_telegram_operator_availability`; step 18 alters `universal_telegram_conversations`
(adds `assignee_last_seen_message_id`) and `universal_telegram_conversation_messages` (adds
indexed `telegram_sender_user_id`). `universal_telegram_conversation_notes.operator_user_id` is
NULLable specifically to support decision 12(b2)'s anonymization. No existing column or table is
altered destructively or dropped. `RESOLVED → OPEN` is added to the in-code transition map (no
schema change for the map itself).
