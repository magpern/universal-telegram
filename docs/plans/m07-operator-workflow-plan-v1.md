# M07 — Operator Workflow: Implementation Plan v1 (DRAFT, revision 2)

Deliverable path (per task instruction): `/home/magpern/.claude/plans/m07-operator-workflow-plan-v1.md` (not materialized to the repository; this file is the working draft under Plan Mode's file restriction).

**Revision 2** applies a correction pass: (1) the manually maintained operator-identity mapping is now the actual inbound Telegram authorization gate, not a display-only convenience; (2) presence/unread/notification deliverables are now planned as a bounded WordPress-admin-only slice instead of deferred; (3) assignment uses compare-and-set, not last-write-wins; (4) manual deletion reuses a small shared purge service instead of describing direct reuse of handler-private steps; (5) version baseline corrected to the actual current `0.8.1`.

**Revision 3** applies a privacy and account-deletion correction pass: (6) `telegram_sender_user_id` and `telegram_username` are reclassified `SENSITIVE` throughout, and the rejected-sender audit entry is stripped of the raw id — it now records a fixed rejection code plus `bot_id`/`conversation_id` only; (7) the `deleted_user` lifecycle is extended to clear a deleted operator's Telegram-sender attribution on existing message rows and anonymize their authorship on internal notes, in addition to the mapping/availability/assignment cleanup already planned.

## 0. Baseline

- Verified: `main == origin/main == a126a85`, `git status --porcelain -uno` clean (no unstaged tracked changes).
- Verified current plugin version at plan-drafting time: `universal-telegram.php` defines `UNIVERSAL_TELEGRAM_VERSION = '0.8.1'` (the M06.3.1 companion release); `readme.txt`'s `Stable tag: 0.8.0` is a pre-existing, unrelated lag between the two files, not something this plan touches or needs to reconcile.
- No repository files, branches, commits, dependencies, or config were modified while producing this draft. All repository reads were read-only (`Read`, `grep`).

## 1. Charter and ADR references

- Charter: `docs/milestones/m07-operator-workflow.md`, mirrored in `docs/master-plan.md` §"M7 — Operator workflow" (lines 994-1013). Deliverables: operator identity mapping; assignment; conversation status controls; **online, busy, and offline state**; **notifications and unread state**; internal notes; resolution and reopening; conversation search in WordPress. Acceptance: **unauthorized Telegram users cannot act**; operator actions audited; concurrent operators cannot silently overwrite state.
- Governance: `docs/governance.md` — freeze model, closure statuses, scope-change ADR trigger.
- `docs/ARCHITECTURE.md` — Conversations boundary (#9): operator workflow is a Conversations subdomain. Administration boundary (#12): Hub subdomain is the sole administration-screen pattern — this plan adds no new menu or settings system.
- ADR-0010 (capability model): anticipates this milestone's own capability need ("operator identity... are expected to extend this same model").
- ADR-0009 (privacy/redaction): mandatory, fail-closed classification map on every audit call.
- ADR-0021: `assigned_operator_id`, `Conversation::assign()`, `ConversationStatus::RESOLVED` reachability reserved for M07; confirmed unused before this plan.
- ADR-0025 (M06.3.1): visitor-facing contract (`owner_user_id`, bearer secret, `release_owner_conversations()`) — untouched; this plan only reads/acts over it from the operator side.

## 2. Repository findings (plan-drafting time)

- `ConversationStatus` map has no `resolved→open` path — reopen is a genuine gap.
- `ConversationRepository::assign()` exists, unused, and is **not** compare-and-set (plain `UPDATE ... WHERE id = ?`) — must be replaced/extended for concurrency safety.
- `RetentionCleanupHandler::run()` inlines its own 90-day purge sequence (null-check not required, since deletion happens outright): `messages->delete_for_conversation()` → `destinations->delete()` (if `destination_id` present) → `conversations->delete()`. No shared service exists yet; this exact three-call sequence must be extracted, not duplicated.
- `WebhookController::maybe_route_to_conversation()` (`src/Telegram/Inbound/WebhookController.php:163-195`) performs **no per-sender check at all** — chat-id match, topic match, and (upstream) `(bot_id, update_id)` dedup are the only existing gates before a reply is captured as `direction='operator'` and, if the conversation is `open`, transitioned to `waiting_for_visitor`. This is the exact code path the correction requires gating.
- `ConversationMessage` / `universal_telegram_conversation_messages` (Migrator step 12) has **no column recording the inbound Telegram sender's numeric id** — `telegram_message_id` records the *Telegram message* id, not the *sender* id. A new column is required for attribution.
- `CapabilityRegistrar` has exactly `MANAGE` (broader, Hub-menu-level) and `MANAGE_AUTOMATIONS`. `HubPage::register_menu()` gates the whole menu on `MANAGE`, the broader of the two, with each tab independently re-verifying its own (possibly narrower) capability — this is the established precedent for a "narrower self-service action, broader administrator override" split.
- `AuditLogger::record()` requires an explicit classification map on every call; only `'system'` has been used as `actor_type` so far.
- `Migrator`: `db_version` is currently **16**; next step is `17`.
- `DestinationRepository::delete()` is a DB-only row delete — never calls the Telegram Bot API. Confirms deletion of the local destination-routing row cannot delete Telegram-side content, regardless of which code path triggers it.
- `store_display_name()` (`ConversationRepository.php:830-864`) already demonstrates the exact compare-and-set idiom this plan reuses for assignment: `$wpdb->update()`'s own `$where` array with a literal `null` value (`'display_name_ciphertext' => null`) is translated by WordPress core into `... WHERE display_name_ciphertext IS NULL`, not a broken `= NULL` comparison — confirmed working precedent already in this codebase, so assignment CAS needs no new raw-SQL branch, no new column, and no `<=>` operator trick.
- `CredentialVault::encrypt()/decrypt()` per-row-context pattern remains the template for internal notes (unchanged from revision 1).

## 3. Assumptions vs. decisions (resolved, not left open)

| # | Question | Resolution | Evidence |
|---|---|---|---|
| A | New capability, or reuse `MANAGE`? | New `CapabilityRegistrar::MANAGE_CONVERSATIONS`, for self-service inbox/assignment/notes/own-availability actions. Administrator-level overrides (setting another operator's availability; overriding a busy/offline assignment) are gated on the broader, existing `CapabilityRegistrar::MANAGE` — mirroring `HubPage`'s own established "broader menu cap, narrower per-tab cap" split. | ADR-0010 names operator identity as the next distinct authorization need; `HubPage.php:21-25` is the direct precedent for a two-tier capability split. |
| B | Where does the WP↔Telegram operator identity live, and is it ever automatic? | A new table, admin/operator-entered only (WP user id ↔ Telegram numeric user id + optional username); never auto-derived from a Telegram username string or webhook payload. | Unchanged from revision 1; task correction explicitly reaffirms this. |
| **C** | **Does "unauthorized Telegram users cannot act" require per-sender Telegram-side gating in M07?** | **Yes — corrected.** The manually maintained operator-identity mapping is the inbound authorization gate: `WebhookController::maybe_route_to_conversation()` reads the inbound update's `message.from.id` (Telegram's own numeric sender id, present on every Telegram Bot API message update), and only proceeds to create a message row / transition status when that id resolves to an active row in `OperatorIdentityRepository`. An unmapped sender's update is rejected **before** any decryption, storage, transition, or forwarding — the method returns immediately after the identity check fails, identically to how it already returns immediately on a chat/topic mismatch. Existing chat/topic match and the caller's own `(bot_id, update_id)` dedup are retained unchanged, and run **before** the new identity check (cheapest, most general gates first). Operationally: every human who may reply inside the support topic must have a mapping recorded first; an operator who forgets this step will see their replies silently not reach the visitor side (surfaced in the audit log as a **fixed rejection code plus bot/conversation identifiers only — never the raw Telegram sender id or username, both SENSITIVE** — see §3I) for operational troubleshooting, never as an error back to Telegram. | Direct instruction; Telegram Bot API message updates always carry `message.from.id` as the sender's own numeric account id — a value neither the sender nor a third party can spoof at the payload level once webhook authenticity is already verified (`WebhookSecretVerifier`, ADR-0013), so this is a trustworthy signal, unlike the previously-considered username string. |
| D | Can `resolved` conversations reopen back to `open`? Can `archived` ones? | `RESOLVED → OPEN` added. `ARCHIVED` remains terminal. | Unchanged from revision 1 — reopening an already-purged/secret-revoked archived row has no coherent visitor-side contract. |
| E | Manual deletion — from which states, and how does it avoid duplicating retention internals? | Manual "Delete now" remains restricted to `archived` conversations only, but now calls a new, small, shared `ConversationPurgeService::purge()` that both `RetentionCleanupHandler`'s own 90-day step and the new admin action call — the sequence exists in exactly one place. | Corrected: revision 1 described the admin action as directly reusing `RetentionCleanupHandler`'s own private steps, which is not a real reuse mechanism (those steps are private to a different class) and risks behavioral drift between the two call sites. Extracting a shared service is the smallest correct fix. |
| **F** | **Presence, unread, and notifications — deferred or in-scope?** | **In-scope — corrected.** A bounded, WordPress-admin-only slice: three-state operator availability (`available`/`busy`/`offline`), a genuine per-operator unread model (not a heuristic), and an in-Hub badge/notice — no push, email, WebSocket, polling daemon, or Telegram-side notification. See §5 and §8 (WP4-WP6) for the exact mechanism. | Direct instruction; explicitly bounded to remove the prior over-broad reading of "notifications" as requiring a live-delivery channel, which the charter's own text does not require and which item 6/7 of the task's original scope explicitly excluded (no parallel outbound channel). |
| G | Internal notes — encrypted like message bodies? | Unchanged: `CredentialVault`, own AAD context, classified `SENSITIVE`. | Direct precedent from `MessageRepository`. |
| **H** | **Assignment concurrency — last-write-wins or compare-and-set?** | **Compare-and-set — corrected.** `ConversationRepository::assign_with_expected( int $id, ?int $expected_operator_id, ?int $new_operator_id ): bool` updates only when the row's current `assigned_operator_id` still matches the caller's displayed expectation (including the `null`/"unassigned" case), reusing the exact `$wpdb->update()` null-in-`$where` idiom `store_display_name()` already uses. A losing caller's request affects zero rows; the handler reports a visible, controlled conflict, never a silent overwrite. | Direct instruction; idiom already proven in this codebase (`ConversationRepository::store_display_name()`), so no new raw-SQL branch is needed. |
| **I** | **Are `telegram_sender_user_id` and `telegram_username` INTERNAL or SENSITIVE?** | **SENSITIVE — corrected.** Both are personal identifiers of a real individual (a Telegram account), not mere internal bookkeeping. `telegram_sender_user_id` may exist in the `conversation_messages` metadata column only as a protected join key for mapped-operator attribution — never rendered, never placed in an admin URL/GET parameter, never usable as a search filter, and never written into a raw audit context. `telegram_username` may be displayed only on the `MANAGE`-gated `OperatorIdentityPage` itself (the one screen whose entire purpose is managing these mappings, for the administrator's own reference while doing so), never on the conversation inbox/detail views, never as a URL parameter beyond the identity mapping's own row id. | Corrected per explicit instruction: a personal Telegram account identifier is materially different from the plugin's own internal bookkeeping ids (conversation_uuid, message_uuid) that INTERNAL is meant for. |
| **J** | **What must the `deleted_user` cleanup do beyond mapping/availability/assignment deletion?** | **Extended — corrected.** In addition to deleting the operator-identity and availability rows and unassigning conversations (with `assignee_last_seen_message_id` reset), the cleanup now also (a) nulls `telegram_sender_user_id` on every existing `conversation_messages` row attributable to the deleted operator's mapped Telegram id, and (b) anonymizes (nulls) the `operator_user_id` author reference on that operator's internal notes — never deleting the note/message content itself, which remains governed by the existing conversation retention model, labelled "former operator" in the detail view once unattributed. The deleted operator's own Telegram id must be resolved from the mapping **before** the mapping row itself is deleted, since it is the lookup key for step (a). The whole cleanup is audited as a single system entry with no personal values (no wp_user_id, telegram_id, or username) in its context. | Corrected per explicit instruction: without this, an already-sent operator reply or an internal note would either misattribute to a future, reused WordPress user id, or (for messages) retain a now-orphaned personal Telegram identifier with no owning mapping left to protect it. |

## 4. Proposed ADR — ADR-0026

**Full ADR text** (to be committed verbatim as `docs/adr/0026-operator-workflow-identity-gated-authorization-availability-and-concurrency-safe-assignment.md`):

```markdown
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
purge steps inline in the new admin action (revision 1's original approach) — rejected on
correction: not a real reuse mechanism and risks the two call sites silently diverging over time.
Classifying the raw Telegram sender id as INTERNAL (revision 2's original approach) — rejected on
correction: it identifies a real individual's Telegram account and is materially different from
this plugin's own internal bookkeeping ids. Leaving a deleted operator's `telegram_sender_user_id`
and internal-note authorship untouched after account deletion — rejected: it would either strand a
personal Telegram identifier with no protecting mapping left, or risk a future, reused WordPress
user id inheriting authorship of a note it never wrote. Deleting note/message content outright on
operator account deletion instead of anonymizing authorship — rejected: content remains subject to
the existing, already-reviewed conversation retention model; deleting it early on operator (not
visitor) account deletion has no charter basis and would destroy conversation history the visitor
side, or a differently-assigned operator, may still need.

## Consequences

The transition map, capability registrar, message/conversation schema, and audit call sites gain
new, additive members; no existing entry is changed or removed. Operators who reply in Telegram
without first being mapped will find their replies silently not reach the visitor side — this is a
deliberate fail-closed behavior, but requires the operational documentation this ADR's decision 2
calls for (every operator must be mapped before their first reply). A future milestone that wants
richer presence/notification delivery (push, email, Telegram-side) introduces its own ADR.

## Security and privacy impact

Closes the "resolution and reopening," "operator identity mapping," and — materially — the
"unauthorized Telegram users cannot act" charter gaps, the last of which revision 1 of this plan
had incorrectly left unaddressed. Assignment and manual deletion are both now safe against
concurrent/accidental misuse: CAS prevents a silent assignment overwrite, and manual deletion is
capability- and nonce-gated, audited before execution, restricted to a terminal state, and reuses
a single, shared, independently tested purge path — it cannot delete an active or resolved
conversation, and never reaches into Telegram itself. The raw Telegram sender id and username are
both treated as SENSITIVE personal data end-to-end: retained only as a protected join key or an
administrator-only mapping-management display, never rendered elsewhere, never placed in a URL or
search filter, and never written into an audit context — including the one path (the rejected-
sender entry) that would otherwise have been the most tempting place to log it for troubleshooting.
Operator account deletion now fully unwinds an operator's personal-data footprint (identity
mapping, availability, message attribution, note authorship) while preserving conversation content
itself under the existing, unchanged retention model.

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
```

## 5. Directory, namespace, schema, and API impact

- **Namespace**: unchanged from revision 1 — `UniversalTelegram\Conversations` (identity mapping, notes, availability, purge service — Conversations subdomain per ARCHITECTURE.md #9) and `UniversalTelegram\Administration\Hub`/`Conversations` (Hub tab + pages, mirroring `Administration\Automations`'s shape).
- **New files**:
  - `src/Conversations/OperatorIdentity.php`, `OperatorIdentityRepository.php`
  - `src/Conversations/OperatorAvailability.php`, `OperatorAvailabilityRepository.php`
  - `src/Conversations/ConversationNote.php`, `ConversationNoteRepository.php`
  - `src/Conversations/ConversationPurgeService.php` (new — shared purge sequence)
  - `src/Administration/Conversations/ConversationInboxPage.php` (list/search/filter + own-availability control + unread badge)
  - `src/Administration/Conversations/ConversationDetailPage.php` (detail, attribution, notes, mark-seen on view)
  - `src/Administration/Conversations/ConversationActionHandler.php` (admin-post: `assign` [CAS], `unassign` [CAS], `transition`, `add_note`, `delete_archived`, `set_availability`, `set_availability_for_operator`)
  - `src/Administration/Conversations/OperatorIdentityPage.php` (manual mapping entry, `MANAGE`-gated since it grants inbound-acting trust)
- **Modified files**: `RetentionCleanupHandler.php` (its 90-day branch now calls `ConversationPurgeService::purge()` instead of its own three inline calls — behavior-identical, code moved not changed); `WebhookController.php` (new identity-gate check, `telegram_sender_user_id` persisted on message creation); `ConversationRepository.php` (`assign_with_expected()`, `mark_seen()`, `unread_assigned_conversations( int $operator_user_id ): array`, `clear_assignment_for_operator( int $operator_user_id ): bool`); `MessageRepository.php` (`create()` gains an optional `telegram_sender_user_id` parameter; new `clear_sender_attribution( int $telegram_user_id ): bool`); `ConversationNoteRepository.php` (new `anonymize_author( int $operator_user_id ): bool`); `OperatorIdentityRepository.php` (new `find_by_wp_user_id()`, `delete_for_wp_user()`); `OperatorAvailabilityRepository.php` (new `delete_for_operator()`); `CapabilityRegistrar.php` (`MANAGE_CONVERSATIONS`); `Plugin.php` (`deleted_user` hook extended with the full operator-cleanup sequence, ADR-0026 decision 12; new Hub tab registration).
- **Schema** (`db_version` 16 → 18):
  - Step 17 — new tables: `universal_telegram_operator_identities`; `universal_telegram_conversation_notes` (**`operator_user_id BIGINT UNSIGNED NULL`** — nullable specifically so authorship can be anonymized on operator account deletion, ADR-0026 decision 12(b2)); `universal_telegram_operator_availability` (columns per ADR-0026 §4/5).
  - Step 18 — alters: `universal_telegram_conversations` + `assignee_last_seen_message_id BIGINT UNSIGNED NULL`; `universal_telegram_conversation_messages` + `telegram_sender_user_id BIGINT UNSIGNED NULL` with a `KEY telegram_sender_user_id (telegram_sender_user_id)` index — SENSITIVE, join-key only (§6).
- **Code-level change (no schema)**: `ConversationStatus::map()` gains `RESOLVED => [ARCHIVED, OPEN]`.
- **No REST contract change.** M05/M06.3.1's public `/conversations/*` routes are untouched; Telegram remains the sole operator messaging channel — no WordPress composer is introduced.

## 6. Security and privacy impact / data exposure matrix

| Field/surface | Classification | Exposed in Hub inbox/detail? |
|---|---|---|
| `conversation_uuid`, status, assignment, last activity, chat_profile | INTERNAL | Yes — list + detail |
| Decrypted message bodies / display name | SENSITIVE | Detail view only, capability-gated |
| `telegram_topic_id` deep-link | INTERNAL | Detail view, only when present |
| `owner_user_id` → WP display name | SENSITIVE | Detail view only; anonymous/legacy rows shown distinctly, never inferred |
| Internal notes | SENSITIVE | Detail view only, own encrypted column; a null (anonymized) author renders as "— former operator —" |
| **`telegram_sender_user_id` (raw)** | **SENSITIVE — protected join key only** | **Never rendered anywhere, never in an admin URL/GET parameter, never a search filter, never in a raw audit context. The detail view shows only the resolved operator's WP display name; absence of a mapping (or a since-anonymized attribution) renders as "unmapped sender" / "— former operator —" respectively — never the numeric id** |
| **`telegram_username` (optional, on the identity mapping)** | **SENSITIVE** | **Displayed only on the `MANAGE`-gated `OperatorIdentityPage` itself; never on the conversation inbox/detail views; never a search filter; never in an audit context** |
| **Operator availability state** | INTERNAL | Yes — inbox, own state and (for `MANAGE` holders) other mapped operators' state |
| **Unread flag/count** | INTERNAL | Yes — derived per-request, never separately persisted beyond `assignee_last_seen_message_id` |
| `secret_hash` (bearer secret) | SECRET | **Never** |
| Message/conversation/note ciphertext columns | SECRET (at rest) | Only via existing `decrypt()`, never raw |
| IP, user agent | N/A | Not stored anywhere in this schema — nothing to redact |
| Audit context for every M07 action | Explicit per-call classification map (ADR-0009) | Never includes secret_hash, ciphertext, decrypted body/name, raw Telegram sender id, or Telegram username; the rejected-sender entry carries only a fixed rejection code plus `bot_id`/`conversation_id`; the account-deletion cleanup entry carries an empty context |

Capability/nonce model: every self-service screen/action re-verifies `current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS )`; every administrator-override action (availability-for-another-operator, busy/offline assignment override, operator-identity mapping entry) re-verifies the broader `CapabilityRegistrar::MANAGE`; every state-changing action independently re-verifies its own nonce inside the handler, matching `RuleBuilderRequestHandler`'s established pattern. `telegram_sender_user_id` and `telegram_username` are excluded from the WP9 conversation-search filter set entirely (§8).

## 7. Test and CI impact

- WordPress-only configuration throughout.
- Unit: `ConversationStatusTest` (`resolved→open`); `OperatorIdentityRepositoryTest` (including `find_by_wp_user_id()`, `delete_for_wp_user()`), `OperatorAvailabilityRepositoryTest` (including `delete_for_operator()`), `ConversationNoteRepositoryTest` (including `anonymize_author()` — asserts content untouched, only the author reference nulled); `ConversationRepositoryTest` extended for `assign_with_expected()` (match/mismatch/null cases), `mark_seen()`, `unread_assigned_conversations()`, `clear_assignment_for_operator()`; `MessageRepositoryTest` extended for `clear_sender_attribution()` (asserts body/ciphertext untouched, only the attribution column nulled, and that a non-matching row is unaffected); `ConversationPurgeServiceTest` (exact three-call sequence, destination-absent case).
- Integration:
  - `WebhookControllerTest` — new cases: mapped sender → message created and routed as today; unmapped sender → no message row, no ciphertext write, no status transition, one rejected-sender audit entry asserted to contain **only** the fixed rejection code, `bot_id`, and `conversation_id` — explicitly asserting the raw `telegram_user_id` and any username are **absent** from the recorded context; chat/topic mismatch still short-circuits before the identity check (existing behavior unchanged).
  - `ConversationActionHandlerTest` — capability/nonce denial paths; CAS success and **CAS conflict** (two competing `assign` calls against the same stale expected value — only one succeeds, the loser gets a visible conflict, no audit entry for the loser); busy/offline assignment blocked without override, permitted with `MANAGE` + override (audited with a distinct action code); reopen; note; `delete_archived` restricted to `archived` status only.
  - `ConversationInboxPageTest` / `ConversationDetailPageTest` — redaction assertions per §6; unread badge count correctness; raw `telegram_sender_user_id` and `telegram_username` never present in rendered inbox/detail output, in any admin URL generated by either page, or in the search-filter parameter set.
  - `OperatorAccountDeletionCleanupTest` (new, integration-level, exercising the full `deleted_user` sequence from ADR-0026 decision 12 end-to-end): a mapped operator with an assigned conversation, an authored operator-reply message, and an authored note; after simulating `deleted_user`, asserts (a) the message row still exists with its ciphertext untouched but `telegram_sender_user_id` NULL, (b) the note still exists with its ciphertext untouched but `operator_user_id` NULL, (c) the conversation's `assigned_operator_id`/`assignee_last_seen_message_id` are both NULL, (d) the availability and identity-mapping rows are gone, and (e) exactly one audit entry exists for the cleanup with an **empty** context.
  - `RetentionCleanupHandlerTest` — unchanged expected behavior after the `ConversationPurgeService` extraction (regression guard that the refactor is behavior-preserving).
- Audit: every new action asserted to produce exactly one classified `AuditLogger::record()` call with `actor_type='operator'` (human actions) or `'system'` (the rejected-Telegram-sender case and the account-deletion cleanup) — and that a failed CAS attempt produces **zero** audit entries, per instruction "audit only successful state changes." A dedicated assertion helper checks that no audit context produced anywhere in this milestone's tests ever contains a raw Telegram numeric id or username string.
- Tests are written alongside each work package but not run until one final focused gate; GitHub Actions remains independent validation.

## 8. Work packages (execution order)

1. **WP1 — Schema foundation.** Files: `Migrator.php` (steps 17-18, three new tables + two column alters), `CapabilityRegistrar.php` (`MANAGE_CONVERSATIONS`). DB impact: db_version 16→18. Tests: `MigratorTest` per step. Acceptance: `wp eval` confirms `db_version=18`, all three tables and two altered columns/index exist. Commit: "Add M07 operator-workflow schema and MANAGE_CONVERSATIONS capability (WP1, ADR-0026)".
2. **WP2 — Reopen transition + purge service extraction.** Files: `ConversationStatus.php`, new `ConversationPurgeService.php`, `RetentionCleanupHandler.php` (refactored to call it). Tests: `ConversationStatusTest`, `ConversationPurgeServiceTest`, `RetentionCleanupHandlerTest` (regression). Acceptance: existing retention behavior byte-for-byte unchanged; `resolved→open` valid, `archived→open` invalid. Commit: "Add reopen transition; extract shared conversation purge service (WP2, ADR-0026)".
3. **WP3 — Operator identity mapping + full account-deletion cleanup.** Files: `OperatorIdentity.php`, `OperatorIdentityRepository.php` (including `find_by_wp_user_id()`, `delete_for_wp_user()`), `OperatorIdentityPage.php` (`MANAGE`-gated; `telegram_username` displayed here only, per §3I), `OperatorAvailabilityRepository.php` (`delete_for_operator()`), `MessageRepository.php` (`clear_sender_attribution()`), `ConversationNoteRepository.php` (`anonymize_author()`), `ConversationRepository.php` (`clear_assignment_for_operator()`), `Plugin.php` (`deleted_user` extended with the full, ordered sequence from ADR-0026 decision 12). Tests: the repository-level tests plus `OperatorAccountDeletionCleanupTest` listed in §7. Acceptance: mapping creation requires `MANAGE` + nonce; deleting the mapped WP user runs the full sequence in order — Telegram id resolved before the mapping row is deleted, message attribution cleared, note authorship anonymized (content untouched), assignment/unread state cleared, availability and identity rows deleted, one empty-context audit entry recorded. Commit: "Add operator identity mapping and full account-deletion cleanup (WP3, ADR-0026)".
4. **WP4 — Inbound authorization gate.** Files: `WebhookController.php` (identity check + `telegram_sender_user_id` persistence), `MessageRepository.php` (`create()` param). Tests: the `WebhookControllerTest` cases listed in §7, including the explicit assertion that a rejected-sender audit entry never contains the raw Telegram id. Acceptance: unmapped-sender rejection produces zero message rows, zero transitions, one audit entry containing only the fixed rejection code and bot/conversation ids; mapped-sender path unchanged from today. Commit: "Gate inbound Telegram operator replies on the mapped identity table (WP4, ADR-0026)".
5. **WP5 — Availability.** Files: `OperatorAvailability.php`, `OperatorAvailabilityRepository.php`, availability controls in `ConversationActionHandler.php`/`ConversationInboxPage.php`. Tests: repository + handler tests (self-service vs. `MANAGE` override). Acceptance: self-set requires `MANAGE_CONVERSATIONS`; setting another operator's state requires `MANAGE`; every change audited. Commit: "Add operator availability state (WP5, ADR-0026)".
6. **WP6 — Unread model + inbox/detail pages.** Files: `ConversationInboxPage.php`, `ConversationDetailPage.php`, `ConversationRepository.php` (`mark_seen()`, `unread_assigned_conversations()`), Hub tab registration in `Plugin.php`. Tests: unread-derivation tests, page redaction tests. Acceptance: unread badge reflects `assignee_last_seen_message_id` correctly; opening the detail view as the assigned operator updates it; reassignment resets it to NULL; matches §6 field-by-field. Commit: "Add operator conversation inbox, detail view, and unread state (WP6, ADR-0026)".
7. **WP7 — Concurrency-safe assignment + lifecycle actions.** Files: `ConversationRepository.php` (`assign_with_expected()`), `ConversationActionHandler.php` (`assign`, `unassign`, `transition`, `add_note`, availability-aware assignment with `MANAGE` override). Tests: the CAS success/conflict and override cases from §7. Acceptance: a stale assignment request never applies; an override is always audited and visibly warned. Commit: "Add concurrency-safe assignment and lifecycle actions (WP7, ADR-0026)".
8. **WP8 — Manual deletion.** Files: `ConversationActionHandler.php` (`delete_archived`, calling `ConversationPurgeService`). Tests: restricted-to-`archived` test, audit-before-delete test, "never touches Telegram" test (no Bot API client invoked). Commit: "Add manual conversation deletion via the shared purge service (WP8, ADR-0026)".
9. **WP9 — Conversation search.** Files: extends `ConversationInboxPage.php` (uuid prefix, status, bot, assignment, date-range — no decrypted-content search, unchanged rationale from revision 1). Commit: "Add bounded conversation search to the operator inbox (WP9)".
10. **WP10 — Version/docs/closure prep.** Files: `universal-telegram.php` (`UNIVERSAL_TELEGRAM_VERSION` `0.8.1` → `0.9.0`, minor bump — new functional-capability class: identity-gated authorization, presence, unread, concurrency-safe assignment), `readme.txt` stable tag, `ARCHITECTURE.md` versioning section, `docs/milestones/m07-operator-workflow.md` status, closure-record scaffold. Commit: "M07 version bump and documentation updates (WP10)".

## 9. Risks and mitigations

- **Operators forgetting to register their identity mapping before replying** — fail-closed by design (§3C); mitigated by the operational documentation requirement in ADR-0026 decision 2 and a troubleshooting-visible audit entry for every rejected sender.
- **Reopen-from-archived temptation** — mitigated by ADR-0026 explicitly excluding it and a test asserting it stays false.
- **CAS starvation under high concurrent assignment contention** — out of scale for this product; a losing caller simply retries against the fresh displayed state, no queueing needed.
- **Availability/unread scope creep toward live delivery** — explicitly bounded in ADR-0026 decision 5/§10; any push/email/WebSocket request is a new milestone's own ADR.
- **Purge-service extraction regressing retention behavior** — mitigated by WP2's explicit regression test suite against `RetentionCleanupHandler`'s pre-existing behavior before any new admin caller is added.
- **A raw Telegram sender id or username leaking into a rendered page, URL, search filter, or audit context** — mitigated by treating both as SENSITIVE end-to-end (§3I, §6), by WP4/WP3's explicit negative-assertion tests, and by the dedicated audit-context assertion helper in §7.
- **Operator-cleanup ordering bug (mapping deleted before its Telegram id is used to clear message attribution)** — mitigated by ADR-0026 decision 12 specifying the exact order and WP3's `OperatorAccountDeletionCleanupTest` exercising the full sequence end-to-end.

## 10. Explicit out-of-scope

AI drafting/response (M09); WooCommerce order context; email transcript delivery; custom-CSS/editor changes to the ChatWidget; a parallel WordPress→Telegram outbound composer; Telegram-side (bot-command) operator identity or presence controls (M08's boundary); push notifications, email notifications, WebSocket/polling-daemon delivery, or Telegram-side notification of unread state; reopening an `archived` conversation; searching over decrypted message/name content; any change to the `/conversations/*` public REST contract or the ChatWidget frontend.

## 11. Definition of done (mirrors charter acceptance/exit criteria)

- [ ] Unauthorized Telegram users cannot act — enforced in code by the WP4 identity gate, verified by `WebhookControllerTest`'s unmapped-sender case.
- [ ] Operator actions are audited — every WP3, WP5, WP7, WP8 action produces one classified `AuditLogger::record()` call; failed CAS/conflict attempts produce none, per instruction.
- [ ] Multiple operators cannot silently overwrite state — status transitions via the existing CAS `transition()` guard; assignment via the new `assign_with_expected()` CAS guard (WP7). No remaining last-write-wins path in any new UI code.
- [ ] Automated unit/integration/CI evidence covering identity mapping, authorization gating, availability, unread derivation, assignment concurrency, and audit logging (§7).
- [ ] Requirements-traceability instance and Vlad's acceptance report — produced during/after implementation, outside this plan's own scope.
- [ ] Frozen plan SHA and ADR-0026 cited in the eventual closure record.

## 12. Manual Product Owner acceptance checklist

- [ ] Inbox lists active/resolved/archived conversations with status, assignment, last-activity, owned-vs-anonymous distinction, unread badge, pagination, and status filter.
- [ ] Detail view shows decrypted message history, attributed operator name for Telegram-originated replies (never a raw Telegram id), notes, and — if owned — the visitor's display name to an authorized operator only.
- [ ] Replying in the Telegram support topic from an **unmapped** Telegram account produces no visible effect on the WordPress side and no visitor-facing delivery (verify by attempting a reply from an account not yet mapped).
- [ ] Operator availability: an operator can set their own state; an administrator can set another mapped operator's state; assigning a busy/offline operator is blocked unless an administrator explicitly overrides, with a visible warning and an audit entry.
- [ ] Two browser sessions concurrently assigning the same conversation: the loser sees a visible conflict, never a silent overwrite; same for a concurrent status transition.
- [ ] A conversation can be resolved, reopened once (before archival), and — once archived — deleted manually with a clear warning; this delete never contacts Telegram.
- [ ] Search finds a conversation by uuid/status/bot/date without any full decrypt-and-scan happening, and never accepts a Telegram id or username as a filter.
- [ ] The Hub shows exactly one new tab under the existing Telegram Hub menu — no new top-level menu, no duplicate settings surface.
- [ ] The operational requirement — every Telegram-side operator must have a WordPress-recorded identity mapping before their first reply — is understood and accepted as an onboarding step.
- [ ] A raw Telegram numeric id or username is never visible anywhere outside the `MANAGE`-gated `OperatorIdentityPage` itself (spot-check the inbox, detail view, any generated admin URL, and the audit log).
- [ ] Deleting a mapped operator's WordPress account: their prior Telegram-originated replies and notes remain visible in conversation history (content intact), now attributed as "former operator"; their assigned conversations become unassigned; their availability/identity rows are gone; the account-deletion audit entry carries no personal values.

## Self-review (per task instructions)

- No bearer secret or ciphertext is exposed anywhere in §5-§6; the raw Telegram sender id and username are both classified SENSITIVE, used only as a protected join key / administrator-only mapping-management display respectively, and are never rendered on the inbox/detail views, never placed in a URL, never a search filter, and never written into a raw audit context (including the rejected-sender case, which now carries only a fixed code plus bot/conversation ids).
- No invalid conversation transition is planned — reopen is scoped to `resolved→open` only; `archived` stays terminal.
- Anonymous and account-owned conversations remain correctly separated (§6).
- M06.3.1 anonymous-chat policy is preserved — no change to `/conversations/*`, `ConversationOutboundDispatcher`, or the ChatWidget frontend.
- Manual deletion cannot bypass retention/audit — restricted to `archived`, routed through the one shared `ConversationPurgeService`, audited before executing (WP8, ADR-0026 §9).
- Telegram remains the sole operator messaging channel — no new outbound composer or REST route.
- No M08+ feature scope is introduced — Telegram-side bot commands, order/stock queries, and Telegram-side notification delivery are explicitly out of scope (§10).
- Unauthorized Telegram users cannot act — now enforced by an actual code gate (WP4), not merely an unchanged pre-existing assumption.
- Assignment cannot be silently overwritten — now enforced by compare-and-set (WP7), not last-write-wins.
- A deleted operator's personal Telegram identifiers are fully unwound (mapping, availability, message attribution) while conversation content itself remains governed by the existing, unchanged retention model — never silently deleted early and never left misattributable to a future reused WordPress user id (WP3, ADR-0026 decision 12).
