# M04 — Visitor and Browser Events

## Status

In Progress. Frozen implementation plan:
`docs/plans/m04-visitor-and-browser-events-plan-v1.md`. Proposes
`docs/adr/0019-visitor-event-source-threading-and-browser-ingestion-boundary.md`.

## Dependencies

M02

## Objective

Capture configurable frontend activity, including cached pages.

## Product value

Extends event coverage beyond what the server alone can see.

## Included scope

Lightweight tracking client; page and product views; navigation and configurable click events; search and funnel events; JavaScript error reporting; consent integration; bot filtering; batching and sampling.

## Explicit exclusions

Any conversation or chat client (M06); any AI-driven analysis of collected events (M09 onward); commerce-object-tied events already covered by M03's server-side events.

## Architectural constraints

Must work behind full-page caching; must respect consent and denied-tracking configuration; bounded request and storage overhead; privacy classification applies to every captured field, especially IP handling, with no raw IP transmission by default.

## Deliverables

Lightweight tracking client; page and product view capture; navigation and click event capture; search and funnel event capture; JavaScript error reporting; consent integration; bot filtering; batching and sampling.

## Acceptance criteria

- The tracking client works behind a full-page cache.
- Disabled or denied tracking is respected.
- Request and storage overhead is bounded.

## Vlad's independent test focus

Verify behavior behind a full-page cache; verify tracking is genuinely inert when consent is denied; inspect network payloads for raw IP or other undisclosed PII.

## Required evidence

- Automated unit and integration test/CI results covering caching behaviour, consent handling, and bot filtering.
- A completed requirements-traceability instance for M04.
- Vlad's completed acceptance report for M04.
- The frozen M04 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), and any ADR M04's own plan introduces for the tracking client or consent model.

## Entry criteria

- M02 closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M04 implementation plan and every ADR it depends on reviewed, approved, and frozen.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
