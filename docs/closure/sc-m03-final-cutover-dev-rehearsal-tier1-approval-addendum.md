# SC-M03 Final-Cutover Disposable DEV Rehearsal — Product Owner Approval A Addendum (Tier 1 re-attempt under runbook v2)

## Status

**Accepted / recorded — Product Owner, 2026-08-28. Authorised re-attempt EXECUTED and PASSED
2026-08-28 — one-time authorisation now consumed** (see § "Execution record — 2026-08-28" and the
[Tier 1 re-attempt closure](sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md)).

This addendum authorizes **exactly one (1)
Tier 1 re-attempt** of the SC-M03 final-cutover disposable automated operational-sequence /
integration validation, at the two immutable execution baseline SHAs below
(universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e`, universal-support-chat
`4f833c3344c3cff2adcc0227f93832c0c3a4427a`). It does **not** authorize Tier 2, any DEV VPS
action, any Telegram network traffic, any production activity, or any operational cutover action;
Tier 2 remains blocked on B1 and B2 and pending Approval B. The Product Owner acceptance is
recorded verbatim in **§ "Product Owner acceptance — recorded 2026-08-28"** below; the
"as proposed" text and the decision history above it are retained unchanged.

The original **Approval A**
([`sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`](sc-m03-final-cutover-dev-rehearsal-tier1-approval.md))
was consumed by the Tier 1 attempt of 2026-08-27, which was correctly **halted by finding F1**
([`sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`](sc-m03-final-cutover-dev-rehearsal-tier1-closure.md)).
F1 has since been corrected and merged in both repositories and verified green by the real
dual-plugin interop suite on both supported WP/PHP variants — see
[`sc-m03-final-cutover-f1-identity-correction-implementation-closure.md`](sc-m03-final-cutover-f1-identity-correction-implementation-closure.md).
A Tier 1 re-attempt therefore required this fresh, narrowly-scoped authorization, now recorded.

## Immutable Tier 1 execution baselines

| Repository | Pinned SHA |
|---|---|
| `magpern/universal-telegram` | `6eed0228286e84b4e56e0119f242b483f138a58e` |
| `magpern/universal-support-chat` | `4f833c3344c3cff2adcc0227f93832c0c3a4427a` |

These are the **immutable, Product-Owner-approved Tier 1 execution baselines**. Before any
execution, operators must fetch origin, verify these exact commits exist, and check out these
exact SHAs. Each commit includes DEV rehearsal runbook v2 and this corrected proposed Approval A
addendum; its runtime tree (`src/`, `tests/`, configuration, CI workflows) is **byte-identical to
the F1 implementation commits** (universal-telegram `7d4cc4f`, universal-support-chat `9144cb1`)
— documentation only was added after F1; no code, schema, `db_version`, test, configuration,
workflow, or runtime change occurred. **Future documentation merges must not alter this
authorised execution baseline unless a new Product Owner approval is recorded.**

## Authorization text (as proposed — retained verbatim for decision history)

The text below is the addendum exactly as proposed. It is retained unchanged, including its
`Signed:` line, as the decision-history record. The Product Owner's acceptance of this exact text
is recorded in the next section.

> **Product Owner authorization — SC-M03 final-cutover Tier 1 prerequisite validation, re-attempt under DEV rehearsal runbook v2 (Approval A addendum)**
>
> The original Approval A (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`)
> was consumed by the Tier 1 attempt of 2026-08-27, which was correctly halted by finding F1.
> F1 has since been corrected and merged in both repositories (universal-telegram #53 →
> `7d4cc4fecb97f862721cea0fec427ade26b46ea7`, closure #54 →
> `32f17ea904a33cdd1f9b0225ba9638f95a09d883`; universal-support-chat #26 →
> `9144cb1e2362c2be8d4c74f1461bba7ffe236575`, closure #27 →
> `5d81b5b7795ee50f3a79e535a483d7677b36d1c0`) and verified green by the real dual-plugin interop
> suite on both supported WP/PHP variants.
>
> The **immutable, Product-Owner-approved Tier 1 execution baselines** for this authorization are
> universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
> `4f833c3344c3cff2adcc0227f93832c0c3a4427a`. Before execution, operators must fetch origin,
> verify these exact commits exist, and check out these exact SHAs. These commits include DEV
> rehearsal runbook v2 and this corrected proposed Approval A addendum; their runtime trees
> remain byte-identical to the F1 implementation commits (universal-telegram `7d4cc4f`,
> universal-support-chat `9144cb1`) — no code, schema, `db_version`, test, configuration,
> workflow, or runtime change occurred after F1, only documentation. Future documentation merges
> must not alter this authorised execution baseline unless a new Product Owner approval is
> recorded.
>
> I authorize a **single Tier 1 re-attempt** of the SC-M03 final-cutover disposable automated
> operational-sequence / integration validation, exactly as described in DEV rehearsal runbook
> **v2** (`docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md`) and its Support Chat
> companion, at the immutable Tier 1 execution baselines universal-telegram
> `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
> `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — operators must fetch origin, verify these exact
> commits exist, and check out these exact SHAs before execution.
>
> This authorization is limited to:
> - the container/PHPUnit interop harness only — `docker/docker-compose.yml` +
>   `docker/docker-compose.interop.yml`, driven through `bin/docker/*.sh`, with
>   `docker compose … down -v` before and after every run;
> - the ephemeral Docker resources that harness creates intrinsically — Docker containers,
>   networks, and named volumes brought up by `docker/docker-compose.yml` together with
>   `docker/docker-compose.interop.yml`, solely for fresh synthetic test databases and harness
>   services, and removed by `docker compose … down -v` after every run;
> - fresh throwaway repository checkouts at the two immutable Tier 1 execution baseline SHAs
>   above — each contains DEV rehearsal runbook v2 and this Approval A addendum, and its `src/` /
>   `tests/` / configuration / CI-workflow trees are byte-identical to the F1 implementation
>   commits;
> - entirely synthetic fixture data created by the rehearsal's own code;
> - Runs 1, 2, and 3 of runbook v2 §7, including the Run 1 step 11a F1-correction gate and the
>   Run 3 `unresolved_case_reference` / `handoff_rejected` incident scenarios;
> - both supported WP/PHP variants, each in a fresh disposable database.
>
> It does **NOT** authorize, under any circumstance:
> - Tier 2 or any disposable DEV rehearsal;
> - any action against `/opt/biopentra/dev/*`, `dev.biopentra.eu`, its database, its Redis, its
>   bot(s), its webhook, its SWAG vhost, or any existing conversation;
> - any Telegram network traffic whatsoever — no bot token (real or dedicated), no `setWebhook`,
>   no `sendMessage`, no group or topic action, no `api.telegram.org` request; the harness
>   `pre_http_request` boundary must be confirmed in place before each run;
> - any real, dedicated, or newly-created Telegram bot, supergroup, or topic;
> - any real user, operator, or production conversation data in any fixture;
> - any infrastructure or resource creation beyond the ephemeral harness Docker resources named
>   above — in particular no DEV VPS instance, WordPress site, Redis service, SWAG configuration,
>   DNS record, TLS certificate, Telegram resource, credential, host-level persistent service, or
>   any resource under `/opt/biopentra/dev/*` or `dev.biopentra.eu`;
> - any production or DEV quiescence window, migration, binding preparation, cohort activation,
>   deferred-update replay outside the disposable harness, route switch, cutover, soak,
>   deployment, release, tag, rollback, deletion, or retention change;
> - any acknowledge, overwrite, hand-edit, or repair of an incident row to make a run pass, and
>   any use of `cutover incident-acknowledge` outside the explicitly synthetic §7.5 scenario;
> - any schema, `Migrator::target_version()`, `universal_support_chat_db_version`, plugin-version,
>   Contract-operation, configuration, CI-workflow, or test change.
>
> The operator must halt on any runbook v2 §8.2 hard stop condition and escalate to me. A Tier 1
> re-run is PASS only when every §9.2 evidence item is captured (redacted per §5) and teardown is
> proven; Run 3 legitimately ends "blocked-as-designed" without reaching `confirm-complete`.
>
> Approval B (Tier 2) remains a separate, later authorization and cannot take effect until this
> Tier 1 re-attempt passes and B1 and B2 are proven resolved.
>
> Signed: __________________________  Date: __________

## Product Owner acceptance — recorded 2026-08-28

**The Product Owner has accepted the Approval A addendum text above, verbatim, on 2026-08-28.**
Recorded per the acceptance-record convention used for
[`sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`](sc-m03-final-cutover-dev-rehearsal-tier1-approval.md)
and
[`sc-m03-final-cutover-f1-identity-correction-implementation-approval.md`](sc-m03-final-cutover-f1-identity-correction-implementation-approval.md).

This acceptance:

- **authorizes exactly one (1) Tier 1 re-attempt** of the SC-M03 final-cutover disposable
  automated operational-sequence / integration validation (Runs 1, 2, and 3 of DEV rehearsal
  runbook v2 §7), at the two **immutable execution baseline SHAs** — universal-telegram
  `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
  `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — which operators must fetch from origin, verify to
  exist, and check out exactly before execution;
- runs **only** in the disposable `docker/docker-compose.yml` + `docker/docker-compose.interop.yml`
  container/PHPUnit interop harness (`docker compose … down -v` before and after every run,
  including the ephemeral Docker containers, networks, and named volumes that harness creates
  intrinsically for fresh synthetic test databases and harness services), with entirely synthetic
  fixtures and **zero Telegram network traffic**, on both supported WP/PHP variants;
- does **not** authorize Tier 2 or any disposable DEV rehearsal; any DEV VPS action or any action
  against `/opt/biopentra/dev/*` or `dev.biopentra.eu`; any Telegram network traffic, bot token,
  webhook, group, or topic; any production activity; any operational cutover action — no
  quiescence window, migration, binding preparation, cohort activation, deferred-update replay
  outside the disposable harness, route switch, cutover, soak, deployment, release, tag,
  rollback, deletion, or retention change; any incident-row acknowledge / overwrite / repair to
  force a pass; or any code, test, schema, `db_version`, plugin-version, configuration,
  CI-workflow, or immutable-baseline-SHA change;
- leaves **Approval B (Tier 2)** a separate, later Product Owner action that cannot take effect
  until this single Tier 1 re-attempt passes and B1 and B2 are proven resolved.

**A second Tier 1 attempt, or any change to the immutable baseline SHAs, requires a new Product
Owner approval recorded here.**

> **Product Owner authorization — SC-M03 final-cutover Tier 1 prerequisite validation, re-attempt under DEV rehearsal runbook v2 (Approval A addendum) — ACCEPTED**
>
> The original Approval A (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`) was consumed by the Tier 1 attempt of 2026-08-27, which was correctly halted by finding F1. F1 has since been corrected and merged in both repositories (universal-telegram #53 → `7d4cc4fecb97f862721cea0fec427ade26b46ea7`, closure #54 → `32f17ea904a33cdd1f9b0225ba9638f95a09d883`; universal-support-chat #26 → `9144cb1e2362c2be8d4c74f1461bba7ffe236575`, closure #27 → `5d81b5b7795ee50f3a79e535a483d7677b36d1c0`) and verified green by the real dual-plugin interop suite on both supported WP/PHP variants.
>
> The **immutable, Product-Owner-approved Tier 1 execution baselines** for this authorization are universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a`. Before execution, operators must fetch origin, verify these exact commits exist, and check out these exact SHAs. These commits include DEV rehearsal runbook v2 and this corrected proposed Approval A addendum; their runtime trees remain byte-identical to the F1 implementation commits (universal-telegram `7d4cc4f`, universal-support-chat `9144cb1`) — no code, schema, `db_version`, test, configuration, workflow, or runtime change occurred after F1, only documentation. Future documentation merges must not alter this authorised execution baseline unless a new Product Owner approval is recorded.
>
> I authorize a **single Tier 1 re-attempt** of the SC-M03 final-cutover disposable automated operational-sequence / integration validation, exactly as described in DEV rehearsal runbook **v2** (`docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md`) and its Support Chat companion, at the immutable Tier 1 execution baselines universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — operators must fetch origin, verify these exact commits exist, and check out these exact SHAs before execution.
>
> This authorization is limited to:
> - the container/PHPUnit interop harness only — `docker/docker-compose.yml` + `docker/docker-compose.interop.yml`, driven through `bin/docker/*.sh`, with `docker compose … down -v` before and after every run;
> - the ephemeral Docker resources that harness creates intrinsically — Docker containers, networks, and named volumes brought up by `docker/docker-compose.yml` together with `docker/docker-compose.interop.yml`, solely for fresh synthetic test databases and harness services, and removed by `docker compose … down -v` after every run;
> - fresh throwaway repository checkouts at the two immutable Tier 1 execution baseline SHAs above — each contains DEV rehearsal runbook v2 and this Approval A addendum, and its `src/` / `tests/` / configuration / CI-workflow trees are byte-identical to the F1 implementation commits;
> - entirely synthetic fixture data created by the rehearsal's own code;
> - Runs 1, 2, and 3 of runbook v2 §7, including the Run 1 step 11a F1-correction gate and the Run 3 `unresolved_case_reference` / `handoff_rejected` incident scenarios;
> - both supported WP/PHP variants, each in a fresh disposable database.
>
> It does **NOT** authorize, under any circumstance:
> - Tier 2 or any disposable DEV rehearsal;
> - any action against `/opt/biopentra/dev/*`, `dev.biopentra.eu`, its database, its Redis, its bot(s), its webhook, its SWAG vhost, or any existing conversation;
> - any Telegram network traffic whatsoever — no bot token (real or dedicated), no `setWebhook`, no `sendMessage`, no group or topic action, no `api.telegram.org` request; the harness `pre_http_request` boundary must be confirmed in place before each run;
> - any real, dedicated, or newly-created Telegram bot, supergroup, or topic;
> - any real user, operator, or production conversation data in any fixture;
> - any infrastructure or resource creation beyond the ephemeral harness Docker resources named above — in particular no DEV VPS instance, WordPress site, Redis service, SWAG configuration, DNS record, TLS certificate, Telegram resource, credential, host-level persistent service, or any resource under `/opt/biopentra/dev/*` or `dev.biopentra.eu`;
> - any production or DEV quiescence window, migration, binding preparation, cohort activation, deferred-update replay outside the disposable harness, route switch, cutover, soak, deployment, release, tag, rollback, deletion, or retention change;
> - any acknowledge, overwrite, hand-edit, or repair of an incident row to make a run pass, and any use of `cutover incident-acknowledge` outside the explicitly synthetic §7.5 scenario;
> - any schema, `Migrator::target_version()`, `universal_support_chat_db_version`, plugin-version, Contract-operation, configuration, CI-workflow, or test change.
>
> The operator must halt on any runbook v2 §8.2 hard stop condition and escalate to me. A Tier 1 re-run is PASS only when every §9.2 evidence item is captured (redacted per §5) and teardown is proven; Run 3 legitimately ends "blocked-as-designed" without reaching `confirm-complete`.
>
> Approval B (Tier 2) remains a separate, later authorization and cannot take effect until this Tier 1 re-attempt passes and B1 and B2 are proven resolved.
>
> Accepted and recorded by the Product Owner — 2026-08-28.

## Scope authorized (summary)

Fresh throwaway checkouts at the two immutable Tier 1 execution baseline SHAs above (fetched and
verified to exist on origin); the existing disposable
`docker/docker-compose.yml` + `docker/docker-compose.interop.yml` harness only, `down -v` before
and after each run — including the ephemeral Docker containers, networks, and named volumes that
harness creates intrinsically for fresh synthetic test databases and harness services; entirely
synthetic fixtures; zero Telegram network traffic; Runs 1–3 of runbook v2 on both supported
WP/PHP variants.

## Explicitly not authorized (summary)

Tier 2; any DEV VPS or `dev.biopentra.eu` action; any Telegram bot token, webhook, group, topic,
or `api.telegram.org` request; any real user data; any `/opt/biopentra/dev/*` checkout; any
infrastructure or resource creation beyond the ephemeral harness Docker resources named above
(no DEV VPS instance, WordPress site, Redis service, SWAG configuration, DNS record, TLS
certificate, Telegram resource, credential, or host-level persistent service); any production or
DEV quiescence, migration, binding preparation, cohort activation, route switch, cutover, soak,
deployment, release, tag, rollback, deletion, or retention change; any incident-row acknowledge / overwrite /
repair to force a pass; any schema, `db_version`, version, Contract, config, CI, or test change.

## Reference

- Primary runbook: [`docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md`](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md) §10.
- Original Approval A: [`sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`](sc-m03-final-cutover-dev-rehearsal-tier1-approval.md).
- Tier 1 halt closure: [`sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`](sc-m03-final-cutover-dev-rehearsal-tier1-closure.md).
- F1 implementation closure: [`sc-m03-final-cutover-f1-identity-correction-implementation-closure.md`](sc-m03-final-cutover-f1-identity-correction-implementation-closure.md).
- Tier 1 re-attempt closure: [`sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`](sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md).
- Support Chat companion decision record: `https://github.com/magpern/universal-support-chat/blob/main/docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md`.

## Execution record — 2026-08-28

The single authorised Tier 1 re-attempt **was executed on 2026-08-28 and PASSED**, at the
immutable execution baselines Universal Telegram `6eed0228286e84b4e56e0119f242b483f138a58e` and
Universal Support Chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a`, on both supported WP/PHP
variants (WP 6.9 / PHP 8.1 and WP 7.1 / PHP 8.3), from fresh throwaway checkouts and fresh
disposable databases, `docker compose … down -v` before and after every run, zero Telegram
network traffic. The dual-plugin interop suite reported `OK (47 tests, 722 assertions)` on both
variants; the F1-correction gate held with a real `legacy-bind`-prepared binding; the fail-closed
classifier and every incident path were confirmed blocked-as-designed. Full detail and evidence
bundle: [`sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`](sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md).

**This addendum's one-time authorisation is now consumed.** No further Tier 1 run is authorised;
a second Tier 1 attempt, or any change to the immutable baseline SHAs, requires a new Product
Owner approval.

## Next step

**Tier 1 is complete.** The next possible activity is Tier 2 — the actual disposable DEV
rehearsal — which remains a separate, later Product Owner action (Approval B) and cannot take
effect until B1 and B2 are proven resolved. Nothing here authorises Tier 2, any DEV VPS action,
any Telegram network traffic, or any production or operational cutover action.
