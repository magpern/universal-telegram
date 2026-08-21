# ADR-0022 — Chat Widget Client-Side State and Cache-Safe Configuration

## Status

Accepted (M06-core)

## Context

M05 (ADR-0021) defines a bearer-secret visitor credential with no client-facing rotation/reissue/
revocation endpoint — a `404` on post/poll is terminal. M06's chat widget must hold this secret in
the browser across a visitor's interaction without introducing cookies, cross-site identity, or any
secret/per-visitor data in server-rendered (and therefore cacheable) HTML. No existing ADR covers
client-side browser state or frontend asset/config delivery — ADR-0019 and ADR-0021 are both
backend-only.

## Decision

The widget stores conversation state (`conversation_uuid`, `secret`, start time) exclusively in
`sessionStorage`, under key `utChatConversation` (with a transient `utChatPendingStart` entry while a
start request is in flight — see the ADR-0021 amendment for that protocol), scoped to the browser
tab's session lifetime. The message cursor is held only in memory and re-derived on each fresh page
load via a hydration poll (`since_id=0`), so no message content or cursor is ever persisted to
`sessionStorage`. Once a conversation exists, the secret is attached only as a `fetch`
`Authorization: Bearer` header for message-post/poll requests, never in a URL or DOM attribute; during
the one-time start exchange it is instead sent as `X-Universal-Telegram-Conversation-Secret`, per the
ADR-0021 amendment.

Server-rendered configuration (REST base URL/namespace only, identical for all visitors) is emitted as
a static `<script type="application/json">` data island — never an executable inline script, and never
containing any per-visitor value — encoded via `wp_json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP |
JSON_HEX_APOS | JSON_HEX_QUOT`, printed at `wp_footer` priority 5 (ahead of WordPress core's own
`wp_print_footer_scripts` at priority 20).

A `404` from any authenticated route, or a `resolved`/`archived` conversation `status`, is treated as
terminal: the client clears its local state and requires a new conversation on next send. Ending a
conversation from the widget clears local state only and calls no M05 endpoint, since none exists for
client-initiated termination.

## Consequences

Each browser tab holds at most one independent, non-recoverable conversation; there is no cross-tab
sync and no persistence beyond the tab's session, which matches M05's no-reissue guarantee and keeps
the secret's blast radius bounded. Full-page caching is unaffected because no per-visitor value is
ever written into cacheable HTML — the config island's content is a fixed array literal, not
request-derived data. Reload history is served by a from-scratch hydration poll rather than a
persisted transcript, keeping stored client state minimal. A future milestone introducing token
rotation or multi-tab continuity would need to revisit this ADR.

## Affected Documents/Milestones

ADR-0021 (extended by its own amendment for the idempotency protocol this widget relies on for safe
retry, not superseded); ADR-0019 (the established unauthenticated-but-abuse-controlled visitor-facing
endpoint pattern this widget's REST client follows); M06 (this boundary's sole owner); M07 (operator
workflow — no dependency on this ADR's client-side decisions).

## Compatibility/Migration Impact

No schema change of its own (the schema change — `db_version` `12 → 13` — belongs to the ADR-0021
amendment, not this ADR). Purely additive: one new frontend asset pair
(`assets/js/chat-widget.js`, `assets/css/chat-widget.css`), one new settings field
(`chat_widget_enabled`), no change to any existing REST route's response shape for callers outside the
new `Idempotency-Key`/`X-Universal-Telegram-Conversation-Secret` headers this milestone introduces.
