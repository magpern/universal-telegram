# Implementation Plan Conventions

A definitive implementation plan is required before any milestone's implementation begins (docs/governance.md, step 4).

## Required structure

Each plan, stored at docs/plans/mNN-slug-plan-vN.md, must contain:

1. Reference to the milestone charter it implements and every ADR it relies on or introduces.
2. Repository findings at plan-drafting time.
3. Assumptions and open questions, separated from decisions with evidence.
4. Architectural decisions with alternatives and tradeoffs, each citing an existing accepted ADR or proposing a new one.
5. Directory, namespace, schema, and API impact, scoped to what this milestone genuinely adds.
6. Security and privacy impact.
7. Test and CI impact, including which of the WordPress-only and WooCommerce-present configurations apply.
8. Work packages in execution order: exact files touched, validation commands, acceptance criteria.
9. Risks and mitigations.
10. Explicit out-of-scope list.
11. Definition of done, matching the milestone charter's acceptance and exit criteria one-to-one.

## Freeze, revision, and supersession

- A plan is committed standalone, code-free, together with every new ADR it depends on, or after those ADRs already exist from an earlier documentation-only commit — see docs/governance.md, Freeze model.
- Once committed, a plan is immutable. A required change is a new file that supersedes the prior plan; the prior plan file is never edited or deleted.
- The implementation report produced at the end of the milestone must reference the plan-freeze commit SHA it implemented, and every superseding plan's SHA if the plan was revised during the milestone.
