# M06.4 — Professional Chat-Widget Visual Revamp

## Status

Planned (documentation frozen; implementation not started)

## Dependencies

M06 / M06.3.1 (closed). Soft: M05.2 for Request-support wiring. Hard gate for live/offline claims: M07.2.

## Objective

Deliver the professional circular launcher, header/greeting polish, and reserved status chrome without misleading availability claims.

## Product value

Improves the visible customer chat experience early while later milestones supply real support status and AI-first behaviour.

## Included scope

- Circular launcher; chat icon when closed and X when open; subtle morph with `prefers-reduced-motion` support.
- Polished header: avatar/logo, configurable title, close control; status slot reserved.
- Configurable professional greeting; retain date separators, bubbles, local timestamps, delivery states, a11y/focus.
- Responsive layout; cache-safe config; preserve `.ut-chat-widget` CSS selector contract.
- Keep authenticated-only access (ADR-0025).
- Optional Request-support control for escalation UX (not the sole human path under `human-first`).

## Explicit exclusions

- Live/offline visitor claims or online/offline indicator driven by real schedule until M07.2.
- Attachments; draggable launcher.
- Business-hours engine (M07.2); direct AI (M10); anonymous chat; M08.1/M08.2.

## Architectural constraints

ADR-0022 / ADR-0024 / ADR-0025. Pre-M10 greeting must not imply an AI response will occur. Status chrome must not lie.

## Deliverables

Updated widget JS/CSS/assets/config; manual a11y checklist updates; automated JS behavioural tests.

## Acceptance criteria

- Morph/reduced-motion demonstrated.
- No live/offline claim before M07.2 activation.
- Cache compatibility and CSS contract preserved.
- Keyboard/screen-reader acceptance per checklist.

## Entry criteria

Architecture freeze committed; plan frozen; branch from `origin/main`.

## Exit criteria

Acceptance met; verification complete; closure with Product Owner acceptance.
