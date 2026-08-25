# M06.4 — Professional Chat-Widget Visual Revamp (Plan v1)

## 1. Charter and ADRs

- Charter: [`docs/milestones/m06-4-professional-chat-widget-visual-revamp.md`](../milestones/m06-4-professional-chat-widget-visual-revamp.md)
- Prior: ADR-0022, ADR-0024, ADR-0025
- Related: ADR-0034 (forbids live/offline claims until M07.2)

## 2. Repository findings

- Widget: `assets/js/chat-widget.js`, `assets/css/chat-widget-*.css`, `ChatWidgetAssets`, JS tests under `tests/js/`.
- Authenticated-only; server-derived display name; date separators and reduced-motion already present in theme CSS.
- Business hours / real support status not implemented (`ChatWidgetAvailability` = enabled + destination eligibility only).

## 3. Assumptions

Authenticated-only retained. No attachments. No draggable launcher. Status chrome may exist but must not claim online/offline until M07.2.

## 4. Architectural decisions

Presentation-only milestone except optional Request-support control wired to M05.2 escalation when available. Do not implement schedule engine here.

## 5. Directory / schema / API impact

- **ChatWidget** assets and config island only.
- Settings keys for title, greeting, logo/avatar URL as needed.
- No DB migration required unless a settings key needs it (prefer existing Settings).
- Config island may expose a neutral `support_status: unavailable|pending_m07_2` — never `online`/`offline` until M07.2.

## 6. Security and privacy

Cache-safe config (ADR-0022); no conversation content in page cache; retain CSRF + bearer rules.

## 7. Test and CI impact

`bin/docker/test-js.sh` behavioural tests; update `docs/testing/m06-chat-widget-manual-checklist.md`.

## 8. Work packages

| WP | Objective | Acceptance |
|----|-----------|------------|
| WP1 | Circular launcher + icon/X morph + reduced-motion | Visual + automated class/state tests |
| WP2 | Header/greeting polish + configurable copy | Admin settings round-trip; no AI implication in default greeting |
| WP3 | Status chrome reserved / neutral only | Assert no online/offline visitor strings |
| WP4 | Request-support control (if M05.2 API ready) or stub hook | Does not break human-first |
| WP5 | A11y checklist + CSS contract regression | Checklist updated; selectors stable |

## 9. Risks

Misleading status if M07.2 flag forgotten — gate behind explicit enable. CSS contract breakage — snapshot selectors.

## 10. Out of scope

M07.2 schedule; M10 AI; anonymous chat; M08.1/M08.2.

## 11. Definition of done

Charter acceptance met; no live/offline claims; closure filed.

## Regressions to prevent

- Live/offline lies before M07.2.
- Breaking `.ut-chat-widget` selectors.
- Cache leakage of conversation state.
- Implying AI responses before M10.
