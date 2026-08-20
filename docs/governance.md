# Governance — Universal Telegram (Telegram Operations Hub for WordPress)

## Roles and authority

| Role | Holder | Authority |
|---|---|---|
| Product Owner | Magnus | Approves product scope, priorities, milestone acceptance, and any material product decision. |
| Master Architect | ChatGPT project thread | Owns architecture, milestone boundaries, ADR decisions, plan review, and the recommendation to close a milestone architecturally. |
| Implementation Agent | Claude / Cursor | Inspects the repository, prepares evidence-backed proposals, and implements only plans that are approved and frozen. Never treats its own drafts as decisions. |
| Independent Tester | Vlad | Performs functional and exploratory acceptance testing independent of the Implementation Agent's own test suite. |

No role approves its own work product as final.

## Milestone lifecycle

1. Draft — Implementation Agent produces a definitive implementation plan per docs/plans/README.md.
2. Architectural review — Master Architect reviews the draft against the milestone's charter and existing ADRs.
3. Product Owner approval — Magnus approves scope and priority.
4. Frozen documentation commit — plan and every ADR it depends on are committed, code-free, as one freeze package (see Freeze model below). No implementation begins before this commit exists.
5. Implementation — only the frozen plan is executed; deviations return to step 1.
6. Technical verification — automated tests and CI per docs/testing/test-strategy.md.
7. Independent Vlad acceptance — per docs/testing/vlad-acceptance-template.md. Per ADR-0011, this step does not apply to milestones M00 through M09: for those milestones, required quality evidence is the frozen plan, code review, mandatory automated validation, and green CI, without a separate manual acceptance session. It applies as originally defined from milestone M10 onward, unless a later ADR changes this again.
8. Remediation — fixes only what steps 6 and 7 found.
9. Closure — per docs/closure/milestone-closure-template.md, with Product Owner acceptance.

## Freeze model

- A milestone's implementation plan and every new ADR that authorizes it must be accepted and included in the same code-free freeze commit, or already exist from an earlier documentation-only commit. No implementation code may precede the ADRs it relies on.
- A revised plan supersedes the earlier plan; it never edits or deletes the earlier plan file. Both remain in docs/plans/ permanently.
- Closure records must cite the frozen plan's commit SHA and every superseding plan's SHA, if the plan was revised during the milestone's lifetime, so evidence traces the full lineage.

## Closure statuses (Product Owner milestone-closure decision)

| Status | Meaning |
|---|---|
| PASS | All acceptance criteria met, no known defects or limitations. |
| PASS WITH LIMITATIONS | Core acceptance criteria met; specific, documented limitations explicitly accepted by the Product Owner. |
| FAIL | Acceptance criteria not met; returns to remediation or re-planning. |
| DEFERRED | Milestone intentionally paused or reordered by Product Owner decision; not a quality judgment. |

These are distinct from Vlad's own test-session recommendation vocabulary (PASS / PASS WITH LIMITATIONS / FAIL / NOT COMPLETED — see docs/testing/vlad-acceptance-template.md). Vlad recommends; the Product Owner decides the milestone's closure status, which may differ from Vlad's recommendation.

## Scope-change and closure approval authority

- Scope changes require Master Architect review followed by Product Owner approval, and an ADR if the change touches architecture, a security boundary, a persistence model, a public contract, a milestone boundary, or a previously accepted decision.
- Milestone closure requires Product Owner acceptance, informed by the Master Architect's recommendation and Vlad's independent results. The Implementation Agent cannot self-certify closure.

## Changing a frozen milestone charter

Once a milestone charter is frozen, changing it requires, in order:

1. A new ADR, if the change alters architecture or milestone boundaries.
2. Master Architect review.
3. Product Owner approval.
4. A standalone, documentation-only commit recording the change, never bundled with implementation code.
