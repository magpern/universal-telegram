# M03 — WooCommerce Event Coverage

## Status

Resolved via ADR-0018. The frozen implementation plan
(`docs/plans/m03-woocommerce-event-coverage-plan-v1.md`) and ADR-0018
(`docs/adr/0018-woocommerce-event-catalog-and-hook-binding.md`) are the
governing documents for this milestone's event catalog, hook bindings, and
idempotency policy.

Per ADR-0011, M00–M09 (including M03) are exempt from Vlad's separate manual
acceptance session; this milestone's "Vlad's independent test focus" and
"Vlad's completed acceptance report" evidence requirements below are
satisfied by the automated-evidence substitute ADR-0011 already defines
(frozen plan, code review, mandatory automated validation, and green CI) —
not by a literal separate Vlad session.

## Dependencies

M02

## Objective

Cover the commerce journey reliably. This milestone activates only when WooCommerce is present, per the optional-integration decision in ADR-0003.

## Product value

Extends the automation engine to commerce-critical moments: checkout failures, payment failures, stock.

## Included scope

Product, cart, checkout, payment, order, and stock events; HPOS compatibility; Classic and block checkout compatibility; failure and validation context; sensitive-field redaction.

## Explicit exclusions

Visitor and browser-side events not tied to a WooCommerce object (M04); AI-assisted analysis of these events (M09 onward).

## Architectural constraints

Server-authoritative events preferred over browser-side where available; Telegram failures must never affect checkout; no double-counting between server and browser events; the plugin remains fully functional with WooCommerce absent, per ADR-0003 (verified by the WordPress-only test configuration, not by M03 itself).

## Deliverables

Product, cart, checkout, payment, order, and stock event coverage; HPOS compatibility; Classic and block checkout compatibility; failure and validation context capture; sensitive-field redaction.

## Acceptance criteria

- Classic and block checkout are both tested.
- Order events work correctly under HPOS.
- Telegram failures cannot affect checkout.
- Server and browser events do not double-count.
- All of the above are verified in the WooCommerce-present test configuration.

## Vlad's independent test focus

Force a payment failure and a checkout validation failure on both Classic and block checkout; verify Telegram outage does not block checkout completion; verify identical order-created events do not double-fire from server and browser paths.

## Required evidence

- Automated unit and integration test/CI results in the WooCommerce-present configuration, covering HPOS, Classic checkout, and block checkout.
- A completed requirements-traceability instance for M03.
- Vlad's completed acceptance report for M03.
- The frozen M03 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance) and ADR-0003 (optional WooCommerce integration), plus any ADR M03's own plan introduces.

## Entry criteria

- M02 closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M03 implementation plan and every ADR it depends on reviewed, approved, and frozen.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
