# Test Strategy — Universal Telegram

## Layers

- Unit — pure domain logic, no WordPress bootstrap.
- Integration — real WordPress core against an isolated, ephemeral database. Exact tooling is an M00 implementation-plan decision, not fixed here.
- Structural guard tests — automated checks that a milestone's boundaries are not silently violated.
- Independent acceptance (Vlad) — functional and exploratory testing independent of and in addition to the automated suite. Automated tests passing is never sufficient for closure on its own.

## WooCommerce test posture

WooCommerce is an optional integration (docs/adr/0003-optional-woocommerce-integration.md). Integration testing requires both of the following configurations wherever a milestone's scope could interact with WooCommerce:

- WordPress-only — WooCommerce absent, confirming the plugin remains fully functional and makes no assumption of its presence.
- WooCommerce-present — confirming HPOS, Classic checkout, and Cart/Checkout blocks compatibility where applicable.

Milestones scoped entirely within WooCommerce, such as M03, naturally run only the WooCommerce-present configuration for their own feature tests, but must not regress the WordPress-only configuration for the rest of the plugin.

## Reliability-mechanism testing

Circuit breaking, dead-letter handling, and queue-health alerting are introduced in M01 for the Telegram transport and in M09 for the AI-provider transport. For the v1.0 release, M12 validates only the M01 Telegram mechanisms under production-representative failure injection, per docs/adr/0004-v1-release-boundary-and-hardening-sequence.md. M09's AI-provider reliability mechanisms are validated by the future, post-v1.0 hardening gate that covers M08 through M11, not by M12's v1.0 execution.

## Traceability

Every milestone's acceptance criteria must be traceable to at least one automated test or one Vlad acceptance scenario, recorded via docs/testing/requirements-traceability-template.md.
