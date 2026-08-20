# ADR-0012 — Telegram Bot Cardinality, Webhook Routing, and Outbound Delivery Architecture

## Status

Accepted

## Context

M01's charter requires "bot configuration" and "multiple Telegram destinations" without stating explicitly whether a single WordPress installation may configure more than one Telegram bot; master-plan.md's product vision separately names "multiple bots" as an eventual capability. During drafting of this plan, the Product Owner resolved this ambiguity explicitly: M01 must support multiple, generic, interchangeable bot profiles per installation, with no functional role assigned to any bot by M01 itself. Separately, M00's `Queue\JobEnvelope` enforces a fail-closed payload-classification policy (docs/adr/0006) under which any `SENSITIVE`- or `SECRET`-classified field, or any unclassified field, causes construction to fail immediately — meaning a decrypted bot token or raw outbound message text can never be placed in a queued job's payload, since both are at minimum `SENSITIVE`.

## Decision

- A WordPress installation may configure any number of independent Telegram bot profiles. Each bot profile owns, independently: its own encrypted token, its own encrypted webhook secret, its own webhook route identity, its own set of destinations, its own outbound delivery log, its own dead-letter records, its own rate-limiter state, and its own circuit-breaker state. No cross-bot sharing of any of the above exists.
- Each bot profile is assigned a random, opaque, non-secret UUID (`bot_uuid`) at creation time, generated once and never regenerated for the lifetime of the profile. This UUID identifies the bot in its webhook URL path (a WordPress REST API route, `universal-telegram/v1/webhook/{bot_uuid}`). The UUID is not a secret — it selects which bot profile a request is *for*; Telegram's own `secret_token`/`X-Telegram-Bot-Api-Secret-Token` header, verified via `CredentialVault` and `hash_equals()`, is the sole authentication mechanism (see ADR-0013 for full webhook-authenticity design). The bot token itself never appears in any URL.
- Outbound message content is written to a dedicated, `CredentialVault`-encrypted table (`universal_telegram_outbound_messages`) **before** any queue job is constructed. The `Queue\JobEnvelope` built for a send contains only an opaque `message_uuid` and `INTERNAL`-classified `bot_id`/`destination_id` metadata — never the message text, never the token. The registered handler re-reads and decrypts the message row at execution time, using the message's own `CredentialVault` context binding (`telegram.outbound_message:{message_uuid}`) so a ciphertext cannot be substituted between rows even if two rows' raw bytes were somehow swapped.
- Reliability state (rate limiting, circuit breaking) is keyed by `(scope_type, scope_id)` where `scope_type` is `bot` or `destination`. A failure affecting one bot's token, or one specific destination, never throttles or trips the breaker for an unrelated bot or destination. Full reliability mechanics are the subject of a separate ADR (ADR-0014); this ADR establishes only the per-bot/per-destination isolation principle and the schema keys (`bot_id`, `destination_id`) that make it enforceable.

## Alternatives

- *Single bot per installation.* Rejected by explicit Product Owner decision during this milestone's planning; would also foreclose the master-plan's stated long-term multi-bot vision without a later, more disruptive superseding ADR and schema migration.
- *Encode bot identity in the secret token rather than the URL path (i.e., look up the bot by trying to match the incoming secret against every bot's decrypted secret).* Rejected: forces a linear scan and repeated decryption across every configured bot for every inbound request, scales poorly, and conflates the concerns of "which bot is this for" (fine to be public) and "prove you are Telegram" (must stay secret) into a single value.
- *Use `admin-ajax.php` instead of a WordPress REST API route for the webhook.* Rejected: the REST API is WordPress's current, documented mechanism for a structured public JSON endpoint with clean request/response typing (`WP_REST_Request`/`WP_REST_Response`), and is the pattern this plan's other read/write concerns (admin screens) do not need, keeping the one genuinely public endpoint architecturally distinct from the capability-gated `admin-post.php` actions.
- *Place a reference to the message content directly in the `JobEnvelope` payload after classifying it `INTERNAL` instead of `SENSITIVE`.* Rejected outright: message text is not internal operational metadata, it is user- or business-facing content that may contain personal or commercially sensitive information; misclassifying it to route around `JobEnvelope`'s fail-closed check would defeat the purpose of that check entirely.

## Consequences

Every later milestone that sends a Telegram message (M02's rule-engine "Telegram action", M08's administrative bot, M11's digests) constructs a durable `OutboundMessage` row and dispatches only an opaque reference, following this same pattern, rather than inventing a parallel content-carrying dispatch path. Any future milestone wanting to narrow multi-bot support back to a single implicit "default" bot for convenience (e.g., a simplified setup wizard) may do so as a UI convenience without a schema change, since the schema already supports zero-or-more bots uniformly. A genuinely different bot-identity model (e.g., allowing a single bot to be shared read-only across sites) would require a new superseding ADR.

## Security and privacy impact

This ADR is the architectural foundation that keeps bot tokens and message content out of Action Scheduler's own (unencrypted, longer-retained) storage entirely, extending — not weakening — ADR-0006's and ADR-0008's existing security boundaries to a milestone that, for the first time, has a real secret and real content to protect.

## Affected Documents/Milestones

M02 (Telegram send action must construct an `OutboundMessage` + opaque envelope, not its own dispatch path); M08 (administrative bot commands are a subdomain of this same `Telegram` boundary and must reuse this same bot-profile/destination model); M11 (digest delivery is another Telegram send, same pattern).

## Compatibility/Migration Impact

None — no Telegram schema or code exists before this milestone.
