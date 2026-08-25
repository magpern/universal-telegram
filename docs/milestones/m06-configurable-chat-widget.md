# M06 — Configurable Chat Widget

## Status

Closed (PASS) for delivered core and follow-ons (M06–M06.3.1); deferred charter items (profiles, business hours, custom CSS, etc.) remain unscheduled except where later follow-ons explicitly claim them. See `docs/closure/m06-chat-widget-core-closure.md` and later M06.* closures.

### Architecture amendment note (documentation only)

Follow-on **M06.4** covers professional visual revamp; live/offline status claims remain gated on **M07.2**. Business-hours **engine** is M07.2, not a silent reopen of this charter's deferred list as completed.

## Dependencies

M05

## Objective

Deliver the complete administrator-configurable frontend chat.

## Product value

The visible, customer-facing half of customer chat.

## Included scope

Floating and inline modes; open, close, minimize, and reopen behaviour; desktop and mobile placement; chat profiles and targeting; business hours; pre-chat form; visual controls; scoped custom CSS; live preview; localization; accessibility compliance.

## Explicit exclusions

Operator-side Telegram workflow tooling (M07); AI-driven widget behaviour (M09 onward); draggable chat launcher (docs/future-scope.md).

## Architectural constraints

Must remain functional behind full-page caching; documented CSS selectors must stay backward compatible once published, a public-contract decision requiring an ADR; accessibility requirements are acceptance-blocking, not optional polish.

## Deliverables

Floating and inline widget modes; open, close, minimize, and reopen behaviour; desktop and mobile placement controls; chat profiles and targeting; business hours; pre-chat form; visual customization controls; scoped custom CSS; live preview; localization; accessibility compliance.

## Acceptance criteria

- Keyboard and screen-reader acceptance is demonstrated.
- The widget passes a responsive viewport matrix.
- Theme-conflict tests pass.
- Profile-priority tests pass.
- Closed and minimized state tests pass.
- The widget is compatible with cached pages.

## Vlad's independent test focus

Full keyboard-only navigation and screen-reader pass; test on cached pages; test multiple overlapping chat profiles and confirm documented priority resolution; test custom CSS injection for scope leakage.

## Required evidence

- Automated unit and integration test/CI results, plus accessibility and cross-viewport test results.
- A completed requirements-traceability instance for M06.
- Vlad's completed acceptance report for M06.
- The frozen M06 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), and any ADR M06's own plan introduces for the CSS public-contract or profile-priority resolution.

## Entry criteria

- M05 closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M06 implementation plan and every ADR it depends on reviewed, approved, and frozen.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
