# ADR-0029 — M11A: Non-AI Visitor Activity Digest as a First Slice of M11

## Status

Accepted

## Context

M11's charter (`docs/milestones/m11-digests-and-operational-intelligence.md`) is a single undivided milestone spanning scheduled summaries, threshold alerts, checkout-failure detection, funnel summaries, error clustering, AI-assisted internal summaries, and destination-specific reporting, deliberately kept whole because its AI-summary deliverable depends on M09. M09 is implemented (ADR-0028) but its Product Owner acceptance is still pending. Meanwhile, M04's visitor/browser event coverage (ADR-0019) already produces enough routine event volume that an administrator with any notification rule configured against a high-frequency `visitor.*` event type receives one Telegram message per page view — a live, present-day noise problem entirely independent of AI and not requiring M09 to fix. Waiting for full M11 (and, transitively, M09's acceptance) to address it is disproportionate to the narrow, non-AI fix available today.

## Decision

M11 is executed in two slices. **M11A** — this ADR — covers exactly: scheduled site-wide aggregate visitor-activity digests, administrator-configurable threshold/max-wait batching, a bot+destination delivery target, and suppression of direct per-event Telegram delivery for a fixed set of low-severity `visitor.*` event types. M11A depends only on M02 and M04 (both closed), not on M09, and does not require M09's Product Owner acceptance to begin or close. The remainder of M11 — threshold alerts beyond the visitor-digest case, checkout-failure detection, funnel summaries, error clustering, AI-assisted internal summaries, and destination-specific reporting beyond the one visitor-digest target — remains a single future slice, still dependent on M09 exactly as the original charter states, and is not reduced in scope, reordered, or implied complete by M11A's closure. M11's own charter document is updated (docs-only, no code) to record this split and to state explicitly that M11A's closure does not close M11.

## Alternatives

- *Wait for M09 acceptance and execute all of M11 as one slice, as originally chartered.* Rejected: the visitor-noise problem is present today, has no technical dependency on M09 or AI, and delaying it couples an unrelated administrative-UX fix to an unrelated pending Product-Owner decision.
- *Fix visitor-event noise inside M04 itself, retroactively, without a new milestone or ADR.* Rejected: M04 is closed; reopening a closed milestone's charter to add scheduled-digest behavior it never scoped would violate `docs/governance.md`'s "changing a frozen milestone charter" process more severely than a forward-looking, explicitly-scoped M11 sub-slice does.
- *Renumber this work as a new milestone (e.g. M11.5) rather than a lettered slice of M11.* Rejected: the work is squarely inside M11's own already-chartered "scheduled summaries" and "threshold alerts" deliverables — it is a slice of M11's existing scope, not a new product concern requiring its own milestone number.

## Consequences

M11's charter and closure record (once M11 as a whole eventually closes) must reference both this ADR and M11A's own closure record, so the milestone's full lineage — split, first-slice closure, and eventual full-M11 closure — is traceable. Any later architecture decision touching M11's remaining (AI-dependent) portion must treat M11A's suppression mechanism (`DigestEligibility::SUPPRESSED_EVENT_TYPES`, `RuleEvaluator` guard) as an existing, reusable pattern for any further event type that later portion decides to route through a digest rather than a direct rule.

## Security and privacy impact

None beyond what M11A's own implementation plan documents in full (privacy-minimal aggregate-only digest content, no new visitor-identifying data). This ADR itself is a scope/boundary decision, not a technical control.

## Affected Documents/Milestones

`docs/milestones/m11-digests-and-operational-intelligence.md` (status/scope note, no charter rewrite); `docs/governance.md` (cited, not amended — this ADR is itself the required scope-change record); `docs/plans/m11a-visitor-activity-digests-plan-v1.md` and its own closure record; the remainder of M11, unaffected in scope, still gated on M09 acceptance.

## Compatibility/Migration Impact

None directly — this ADR authorizes a milestone-execution split; M11A's own plan carries the actual schema impact (`db_version` 22→24).
