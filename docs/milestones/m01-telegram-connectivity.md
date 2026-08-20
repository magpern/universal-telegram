# M01 — Telegram Connectivity

## Status

Not Started

## Dependencies

M00

## Objective

Provide reliable bidirectional Telegram communication.

## Product value

Makes the plugin's core premise real: the admin can connect a bot and confirm messages flow, before any event, rule, or chat logic exists on top.

## Included scope

Bot configuration; multiple destinations; connection testing; outbound queue; inbound webhook; forum topic support; retry, rate limiting, delivery log; diagnostics; circuit breaking, dead-letter handling, and queue-health alerting for the Telegram transport.

## Explicit exclusions

Event capture and rule evaluation (M02); WooCommerce-specific events (M03); conversations and chat (M05 onward); AI (M09 onward); administrative bot commands (M08); any feature listed in docs/future-scope.md.

## Architectural constraints

- Tokens must never be exposed to frontend code.
- All sends go through M00's queue abstraction, never synchronously.
- The webhook must be authenticity-verified.
- A circuit breaker must open on sustained Telegram failure and a dead-letter path must retain undeliverable messages for inspection rather than discarding them; queue-health alerting must surface a stuck or failing queue to the administrator.
- The plugin must operate without a separately deployed companion bot application or vendor-hosted SaaS backend. Telegram webhook handling, routing, and delivery orchestration run within WordPress. Calling Telegram's external API does not violate this constraint.

## Deliverables

Bot configuration; multiple Telegram destinations; connection testing; outbound queue integration; inbound webhook handling; forum topic support; retry and rate limiting; delivery log; diagnostics; circuit breaking, dead-letter handling, and queue-health alerting for the Telegram transport.

## Acceptance criteria

- Send and receive test messages.
- Webhook authenticity is validated.
- The plugin recovers from temporary Telegram failures.
- No token reaches browser output.
- The circuit breaker activates under sustained simulated failure.
- Dead-lettered messages are retained and inspectable.
- A queue-health alert fires on a stalled queue.
- A fresh installation requires only WordPress, the plugin, and Telegram bot credentials.
- No separately deployed application is needed for bidirectional Telegram communication.

## Vlad's independent test focus

Configure a real sandbox bot end-to-end; disconnect network mid-send and confirm recovery; force sustained failures and confirm the circuit breaker opens and a queue-health alert appears; inspect browser output for token leakage; attempt to replay a webhook request; install the plugin on a fresh WordPress instance with no other services running and confirm bidirectional Telegram communication works with no companion application deployed.

## Required evidence

- Automated unit and integration test/CI results covering outbound queueing, webhook verification, retry, circuit-breaker, and dead-letter behaviour, and token-exposure checks.
- A completed requirements-traceability instance for M01.
- Vlad's completed acceptance report for M01.
- The frozen M01 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), and any ADR M01's own plan introduces for transport, webhook-verification, or reliability-mechanism design.

## Entry criteria

- M00 closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M01 implementation plan and every ADR it depends on reviewed, approved, and frozen.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
