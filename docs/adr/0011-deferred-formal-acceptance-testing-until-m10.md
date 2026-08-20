# ADR-0011 — Deferred Formal Acceptance Testing Until M10

## Status

Accepted

## Context

docs/governance.md's milestone lifecycle, as originally accepted, includes a mandatory step-7 "Independent Vlad acceptance" gate for every milestone, and the milestone closure template and every milestone charter's own "Required evidence" and "Exit criteria" sections reference a completed independent-acceptance instance as a precondition for closure. The Product Owner has determined that for milestones M00 through M09 — none of which ship end-user-facing functionality integrated into a live conversational or AI surface a manual tester could meaningfully exercise end to end — this formal, independent, manual acceptance gate adds process overhead without commensurate risk reduction at this stage of the project, and that the more valuable point to introduce manual, cross-milestone acceptance testing is M10, once the conversational and AI surfaces those earlier milestones build toward actually exist for a tester to exercise together. This is a Product Owner scope and process decision, not an architectural one, but it changes a previously accepted governance decision (the milestone lifecycle in docs/governance.md) and is therefore recorded as an ADR per this project's own stated policy that a previously accepted decision that must change requires one.

## Decision

Milestones M00 through M09 have no formal, independent, manual acceptance gate. Required quality evidence for each of these milestones is: the milestone's own frozen implementation plan, code review, mandatory automated validation (unit and integration tests, coding-standard and static-analysis checks, and any packaged-plugin acceptance tests the plan defines), green continuous integration, and the milestone's own closure record documenting all of the above. Formal, independent, manual end-to-end acceptance testing begins with milestone M10 and applies to M10 onward, unless the Product Owner changes this posture through a later ADR. This decision does not weaken, relax, or substitute for any automated test requirement any milestone's own frozen plan defines, and does not authorize implementing or closing a milestone without the automated validation its plan requires.

## Alternatives

Retaining the mandatory independent-acceptance gate for every milestone including M00 through M09 — rejected by the Product Owner as disproportionate process overhead for milestones with no end-user-facing conversational or AI surface yet to exercise manually. Removing the independent-acceptance gate permanently, for every milestone including M10 onward — rejected, since the Product Owner explicitly wants a manual, cross-milestone acceptance gate reinstated once M10's conversational and AI surfaces exist to exercise it against. Silently skipping the gate on a milestone-by-milestone basis without recording the change — rejected, since this project's own governance model requires a documented decision, not an undocumented deviation from an accepted lifecycle step.

## Consequences

docs/governance.md's milestone lifecycle and the milestone closure template must both be updated to reference this ADR wherever they currently state or imply a mandatory independent-acceptance step for every milestone, so the two documents do not contradict this decision. Every M00 through M09 milestone charter's "Required evidence" and "Exit criteria" sections that currently name an independent tester by role must be read as satisfied by this ADR's own automated-validation-and-closure-record standard instead, for those milestones only. M10's own charter and any future milestone charter from M10 onward retain the original independent-acceptance requirement, unless a later ADR changes it again.

## Security and privacy impact

None directly. This is a process and governance decision; it does not change any technical security or privacy control any milestone's own architecture decisions establish, and it does not reduce the automated test coverage any milestone's own frozen plan requires for those controls.

## Affected Documents/Milestones

docs/governance.md (milestone lifecycle, step 7); docs/closure/milestone-closure-template.md (independent-acceptance-results field); every M00 through M09 milestone charter's "Required evidence," "Vlad's independent test focus" (retained as a template for M10 onward but not a requirement for M00–M09), and "Exit criteria" sections; M10's own charter, which retains the original manual-acceptance requirement unchanged.

## Compatibility/Migration Impact

None. No milestone has closed under the original gate yet; this decision applies from this point forward and does not reopen or invalidate anything already closed, since nothing has been closed yet.
