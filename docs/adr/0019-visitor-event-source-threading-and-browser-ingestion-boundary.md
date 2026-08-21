# ADR-0019: Visitor Event Source Threading and Browser Ingestion Boundary

## Status

Proposed

## Context

M02 (ADR-0015) established `universal_telegram_emit_event( string $event_type, array $data, string $idempotency_key ): void` as the sole sanctioned emission surface, and `Events\EventSource` as a closed enum identifying the emitting subsystem on every recorded event. `EventSource::VISITOR` was reserved at M02 for exactly this milestone but is unreachable: `Events\EventEmitter::build_envelope()` hard-codes `EventSource::WORDPRESS_CORE` on every call, with no parameter to override it. M04 (`docs/milestones/m04-visitor-and-browser-events.md`) requires capturing anonymous browser-originated events — page views, navigation, search, clicks, JS errors, and (WooCommerce-gated) product-view and classic add-to-cart intent signals — attributed as `VISITOR`-sourced, and requires a public, unauthenticated ingestion boundary through which an anonymous browser can reach that emission surface, since no such boundary exists in the plugin today (the only prior public REST route, `Telegram\Inbound\WebhookController`, is secret-token-authenticated and not reusable for anonymous visitors, who cannot hold a secret).

## Decision

1. `universal_telegram_emit_event()` gains a fourth, optional parameter: `EventSource $source = EventSource::WORDPRESS_CORE`, threaded unchanged through `EventEmitter::emit()` and `build_envelope()` into `EventEnvelope`'s existing constructor parameter. Every existing three-argument call site is unaffected; behavior for those call sites is unchanged. An unrecognised source is impossible by construction (PHP enum typing), so no new failure mode is introduced.
2. A new public REST route, `POST universal-telegram/v1/visitor-events`, is added, gated on a same-origin `Origin`/`Referer` header check (browser-CSRF friction only, not authentication — the endpoint is treated as writable by any non-browser client that forges these headers), a two-tier rate limiter reusing `Telegram\Reliability\RateLimiter` (a per-client HMAC-keyed daily bucket for fairness, and a global site-wide bucket, keyed independently of any client-supplied value, as the actual non-bypassable hard cap on write volume), a strict allow-listed short-code event schema, and a uniform `202 Accepted` response for every accepted-or-silently-dropped case. It accepts only the nine registered `visitor.*` event types and forwards each, after validation, sampling, and bot-filtering, to `universal_telegram_emit_event()` with `EventSource::VISITOR`.
3. No WordPress nonce is used for this route, because a nonce embedded in full-page-cached HTML would be stale or absent for any cached page view — a hard constraint from the M04 charter.
4. The catalog's fields exclude any per-visitor correlation token (`visit_ref` is used only as transient idempotency-key input, never as an event field), any search term or derivative, and any client error location or derivative, so that the endpoint's privacy guarantee does not depend on trusting an unverifiable consent signal (§4.3 of the M04 plan).

## Alternatives

- Add a dedicated `EventSource::VISITOR`-only emission function instead of extending `universal_telegram_emit_event()`. Rejected: this would create a second emission path, contradicting ADR-0015's "the sole sanctioned emission surface" guarantee, and would require the same envelope-construction logic to exist twice.
- Use a WordPress nonce for the ingestion endpoint, refreshed via a short-lived AJAX call before every batch. Rejected: adds a synchronous round-trip before any telemetry can be sent, defeating the "no telemetry-induced frontend delay" constraint, and still fails for the very first page view under a full-page cache.
- Route browser events through the existing Telegram webhook controller's pattern (secret-token-per-installation). Rejected: a secret embedded in publicly-served frontend JavaScript is not a secret; this would provide no real authentication while adding false confidence.
- Rely solely on the per-client rate-limit bucket, with no site-wide bucket. Rejected: a per-client bucket keyed even partly on client-supplied or client-observable values can be evaded by an attacker willing to vary IP or generate fresh identifiers per request; only a bucket keyed independently of any client-supplied value provides a genuine, non-bypassable hard cap.

## Consequences

`EventEmitter`'s public signature grows by one optional parameter — a strictly additive, non-breaking change. A new top-level attack surface (an unauthenticated public endpoint) exists in the plugin for the first time; its blast radius is bounded to inserting rate-limited, schema-constrained, non-PII rows into `event_history` and triggering existing notification rules — never database, filesystem, or credential access. `EventSource::VISITOR` becomes a real, populated value across `event_history.source` for the first time. Administrators must understand, per the settings-page copy, that the `visitor_consent_mode` signal is advisory and client-side only, not a compliance guarantee.

## Security and privacy impact

The endpoint accepts no field capable of carrying PII (§4.2–§4.3 of the M04 plan): every accepted field is either a bounded enum, a validated numeric ID, or absent entirely. Classification enforcement (ADR-0009) and PUBLIC-only history projection (ADR-0017) apply unchanged and unweakened to every visitor event type. The per-client rate-limit bucket key is an HMAC of IP+UA+day keyed with a per-install secret never exposed outside the plugin's own storage, itself never reversible to the raw IP or UA and never treated as visitor-tracking data — it is transient security processing outside the event/classification model entirely. No cookie, no persistent identifier, and no cross-session or cross-device tracking is introduced. Consent enforcement is explicitly non-verifiable server-side; this ADR does not claim otherwise, and the plan's privacy guarantee is structural (what the schema cannot accept), not consent-dependent.

## Affected Documents/Milestones

`docs/milestones/m04-visitor-and-browser-events.md` (implements this ADR); `docs/ARCHITECTURE.md` (Events boundary description gains the visitor/browser subdomain, per its own existing statement that M04 is such a subdomain); no other milestone charter or frozen plan is altered.

## Compatibility/Migration Impact

No database schema change; `db_version` remains `10`. No breaking change to any existing call site of `universal_telegram_emit_event()`. Plugin version moves `0.2.0 → 0.3.0` per `docs/ARCHITECTURE.md`'s existing minor-bump-per-capability-class convention.
