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
- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v4.md` (superseded by v5; ADR-0041; pins Support Chat ADR-0009 SHA `590b53ba898aa4054ec65c65965c152a3612149b` — the legacy binding preparation follow-up, work package 9, implemented and Product Owner accepted)
- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v5.md` (current; ADR-0042; pins Support Chat ADR-0010 — the final-cutover follow-up, work package 10; documentation freeze only, no implementation)

Support Chat SC-M03 Work Package 2 (legacy-chat quiescence, ADR-0040) adds:

- `docs/plans/wp2-legacy-chat-quiescence-plan-v1.md` (current; ADR-0040; fulfils the forward commitment made by ADR-0039 §3; depends on Support Chat PR #9, `a61aa09`)

SC-M03 final-cutover disposable DEV rehearsal — **CLOSED by ADR-0044 (2026-08-28): Universal Telegram becomes transport/adapter only; legacy chat is retired and discarded, not migrated; there is no cutover.** The documents below are retained unedited as historical records:

- `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md` (superseded by v2 as the operative runbook; retained unedited as the historical record of the halted first attempt; pins UT `31519ee` / SC `ce46912`. **Tier 1 attempted 2026-08-27 → HALTED by finding F1**; closure `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`; harness validated, no production code changed, no bypass used)
- `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` (ADR-0043 **Accepted** 2026-08-27; documentation-only plan for finding F1 — corrects the adapter to send `ChannelBinding::support_conversation_uuid()` as the Contract v1 `channel_case_ref`, with the exhaustive `finish()` classification; no schema change. **F1 implementation and its closure are MERGED** — UT #53 `7d4cc4f` / closure #54 `32f17ea`; SC #26 `9144cb1` / closure #27 `5d81b5b`; real dual-plugin interop green on both WP/PHP variants post-merge)
- `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md` (current; primary operator runbook, superseding v1. Pins the immutable Product-Owner-approved Tier 1 execution baselines UT `6eed0228286e84b4e56e0119f242b483f138a58e` / SC `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — operators fetch origin, verify these exact commits exist, and check them out before execution; runtime trees byte-identical to the F1 implementation commits (`7d4cc4f` / `9144cb1`), documentation only added since; future documentation merges must not alter the baseline without a new PO approval. Revises only the F1-invalidated parts of v1 — pinned baselines, the `channel_case_ref` wire identity (now the Support Chat conversation UUID; `binding_uuid` off the wire), Run 1's handoff fixture/assertions (real distinct `binding_uuid`), and the exhaustive fail-closed replay classifier (`unresolved_case_reference` / `handoff_rejected`) referenced by Runs 2 and 3; adds a Run 1 F1-correction gate and Run 3 fail-closed incident scenarios. All v1 safety boundaries, evidence/redaction/teardown requirements, the Tier 1/Tier 2 distinction, and blockers B1–B5 carried forward. The Approval A addendum (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`) is **RECORDED / Product Owner accepted 2026-08-28** and authorizes **exactly one (1)** Tier 1 re-attempt at the two immutable baseline SHAs — nothing else. the single authorised Tier 1 re-attempt ran 2026-08-28 and **PASSED** on both WP/PHP variants — closure `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`; the addendum's one-time authorisation is consumed. Tier 2 stays blocked on B1/B2 and pending Approval B)
- `docs/plans/sc-m03-final-cutover-tier2-dev-rehearsal-prerequisites-plan-v1.md` (current; **primary** Tier 2 prerequisites plan, planning-only / FROZEN. Specifies **B1** (isolated full-WordPress rehearsal instance as its own `scm03rehearsal` Docker Compose project — own DB/Redis/networks/volumes/reverse-proxy vhost/TLS/`CredentialVault` key, sharing nothing with `dev.biopentra.eu` or `/opt/biopentra/{dev,data}/*`; public listeners unchanged 2222/80/443; a 10-check B1 verification gate signed off by an independent reviewer) and **B2** (dedicated non-production Telegram bot + forum supergroup + three test topics; token/secret stored only encrypted in the rehearsal DB via `CredentialVault`; `setWebhook`/`getWebhookInfo`/`deleteWebhook` lifecycle against the dedicated bot; `X-Telegram-Bot-Api-Secret-Token` ingress auth; full `@BotFather` `/revoke` + `/deletebot` + supergroup-deletion revocation procedure; a B2 verification gate). Defines the Tier 2 operator sequence (real WP-Cron/Action Scheduler drain, Redis object cache, authenticated webhook ingress, real `forum_topic_closed`/`forum_topic_deleted` service messages, real outbound `sendMessage`, real `409 quiescence_active`) and a **proposed, unsigned Approval B** authorizing **exactly one** Tier 2 rehearsal at the immutable execution baselines `6eed022…` / `4f833c3…` after B1/B2 are provisioned **and independently verified**. Four-phase separation: planning (this) → provisioning → Approval B recording → one-time execution. **Provisions nothing, creates no Telegram resource, records no Approval B, executes no Tier 2.**)
- `docs/plans/ut-transport-only-retire-legacy-chat-plan-v1.md` (current; **ADR-0044**; the frozen implementation blueprint for retiring Universal Telegram's legacy website chat and the whole SC-M03 migration/cutover track — UT becomes Telegram transport / Support Chat adapter only. Ten tranches: docs supersession → schema → transport core → reclassify topic-lifecycle + operator-identity-map → delete migration/cutover → delete legacy chat → bootstrap + guarded `wp universal-telegram legacy-chat purge` → tests → validation → closure. No DEV/production/Telegram/database/release change.)
- `docs/plans/ut-interactive-chat-delivery-priority-plan-v2.md` (current; **ADR-0045 + Amendment 1**; supersedes v1 — retained unchanged. Records that the Support Chat counterpart is now fully asynchronous (its ADR-0014 Amendment 1 removed the in-request delivery attempt); **no Universal Telegram runtime change** — `ensure_channel_case`'s synchronous `createForumTopic` only ever runs inside Support Chat's async worker now, and every v1 runtime decision stands. The "no Telegram I/O in the Support Chat visitor/Hub request" proof lives in the Support Chat interop suite.)
- `docs/plans/ut-interactive-chat-delivery-priority-plan-v1.md` (**superseded by v2**, retained unchanged; **ADR-0045**; low-latency interactive Support Chat → Telegram delivery. Adds a fixed `outbound_messages.delivery_class` (`standard` | `interactive_chat`, additive nullable-safe, `db_version` 37 → 38), fail-closed acceptance of an optional `delivery_class` on Contract `deliver_message`, `interactive_chat` placement ahead of `standard` in the Action Scheduler queue via an earlier `scheduled_date` (FIFO within class, same worker / claim-lease / rate-limit / circuit-breaker / retry / dead-letter path), and wires the existing ADR-0023 `ExpeditedDispatchTrigger` into the Support Chat adapter delivery path. Diagnostics / alerts / digests / admin Test Message / backfill stay `standard`. Counterpart: Support Chat ADR-0014. No DEV/production/Telegram/release change.)

- A plan is committed standalone, code-free, together with every new ADR it depends on, or after those ADRs already exist from an earlier documentation-only commit — see docs/governance.md, Freeze model.
- Once committed, a plan is immutable. A required change is a new file that supersedes the prior plan; the prior plan file is never edited or deleted.
- The implementation report produced at the end of the milestone must reference the plan-freeze commit SHA it implemented, and every superseding plan's SHA if the plan was revised during the milestone.
