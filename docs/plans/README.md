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

M02's frozen plan is `docs/plans/m02-normalized-events-and-notifications-plan-v1.md`, together with
ADR-0015 through ADR-0017.

Chat-experience architecture amendment (PR #30 documentation freeze) added — now **historical**; UT implementation superseded by ADR-0037:

- `docs/plans/m05-2-escalation-aware-conversation-routing-plan-v1.md` (ADR-0033; superseded for UT implementation)
- `docs/plans/m06-4-professional-chat-widget-visual-revamp-plan-v1.md` (superseded for UT implementation)
- `docs/plans/m07-2-site-support-availability-and-waiting-queue-plan-v1.md` (ADR-0034, ADR-0035; superseded for UT implementation)
- `docs/plans/m09-1-operator-approved-ai-draft-delivery-plan-v1.md` (ADR-0036; superseded for UT implementation)

M10 had no implementation plan in that freeze; its UT path is also superseded (rehomed to Support Chat SC-AI2).

Support Chat extraction supersession (ADR-0037) adds the authorised adapter plan:

- ~~`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md`~~ (ADR-0037; pins Support Chat Contract v1 SHA `dff2730e24b7d3f70f15f706305e12e14fdcc6c8`; superseded)
- ~~`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md`~~ (ADR-0038; pins Support Chat ADR-0007 SHA `8ee396d8b8edcbf526797c0a1f5741f3842df57a` — the signed Contract client follow-up; work packages 1–7 implemented; superseded)
- ~~`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md`~~ (ADR-0039; pins Support Chat ADR-0008 SHA `7546d43be66f8e3b2f179f03a1c81c9aadef59db` — the legacy export boundary follow-up, work package 8; superseded)
- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v4.md` (current; ADR-0041; pins Support Chat ADR-0009 SHA `590b53ba898aa4054ec65c65965c152a3612149b` — the legacy binding preparation follow-up, work package 9)

Support Chat SC-M03 Work Package 2 (legacy-chat quiescence, ADR-0040) adds:

- `docs/plans/wp2-legacy-chat-quiescence-plan-v1.md` (current; ADR-0040; fulfils the forward commitment made by ADR-0039 §3; depends on Support Chat PR #9, `a61aa09`)

- A plan is committed standalone, code-free, together with every new ADR it depends on, or after those ADRs already exist from an earlier documentation-only commit — see docs/governance.md, Freeze model.
- Once committed, a plan is immutable. A required change is a new file that supersedes the prior plan; the prior plan file is never edited or deleted.
- The implementation report produced at the end of the milestone must reference the plan-freeze commit SHA it implemented, and every superseding plan's SHA if the plan was revised during the milestone.
