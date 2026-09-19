# Support-desk Telegram integration — implementation notes (companion to ADR-0046)

ADR-0046 is Accepted and immutable; this note records implementation-level findings and the
one structural refinement discovered after the freeze. None of it changes the ADR's decisions or
contracts.

## Correction to a reviewed assumption

ADR-0046 states the WP-admin reply handler calls `Biopentra_Contact_Inbox_Mailer::send_ticket_reply()`.
It also records the legacy Fluent reply-history row for `fluent`-source tickets. FISD therefore
extracts mailer + history into `Biopentra_Contact_Inbox_Ticket_Reply::send()` (FISD 2.1.0, see its
freeze addendum 1); `TicketReplyHandler` calls that. Return contract is identical
(`true` | `WP_Error`, checked with `true === $result`).

## Structure (no contract change)

- `Telegram\Inbound\NotificationReplyRouter` performs the post-dedup lookup
  (destination by `(bot_id, chat_id, message_thread_id)` → outbound row by
  `(destination_id, replied-to message id)` → `correlation_token`) and dispatches by token
  prefix to a registered `CorrelatedReplyHandler`. With no handler registered (support desk
  inactive) it is inert. `WebhookController` only calls the router.
- A reply whose text is a bot command (`CommandParser`) falls through to command dispatch, so
  `/stock` etc. still work when typed as a reply to a notification.
- `TicketReplyHandler` depends on two small ports (`SupportDeskGateway`, `TicketReplyEnvironment`)
  so its full decision table is unit-testable; production adapters wrap the FISD classes,
  `OperatorIdentityMapRepository` + `MANAGE_CONVERSATIONS`, `RateLimiter` and `MessageDispatcher`.
- Digest settings are stored in one option (`universal_telegram_support_digest`) and edited on a
  hub tab that exists only while the support desk is active.
