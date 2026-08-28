# SC-M03 Final-Cutover Disposable DEV Rehearsal — Product Owner Approval A Addendum (Tier 1 re-attempt under runbook v2)

## Status

**Proposed — awaiting Product Owner signature.** Not recorded, not accepted. No Tier 1 re-attempt
is authorized until this addendum is signed and merged.

The original **Approval A**
([`sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`](sc-m03-final-cutover-dev-rehearsal-tier1-approval.md))
was consumed by the Tier 1 attempt of 2026-08-27, which was correctly **halted by finding F1**
([`sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`](sc-m03-final-cutover-dev-rehearsal-tier1-closure.md)).
F1 has since been corrected and merged in both repositories and verified green by the real
dual-plugin interop suite on both supported WP/PHP variants — see
[`sc-m03-final-cutover-f1-identity-correction-implementation-closure.md`](sc-m03-final-cutover-f1-identity-correction-implementation-closure.md).
A Tier 1 re-attempt therefore requires this fresh, narrowly-scoped authorization.

## Baselines (freshly fetched `origin/main`, both HEAD — include the F1 correction and its closure)

| Repository | Pinned SHA |
|---|---|
| `magpern/universal-telegram` | `32f17ea904a33cdd1f9b0225ba9638f95a09d883` |
| `magpern/universal-support-chat` | `5d81b5b7795ee50f3a79e535a483d7677b36d1c0` |

## Verbatim authorization text (to be signed)

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
> I authorize a **single Tier 1 re-attempt** of the SC-M03 final-cutover disposable automated
> operational-sequence / integration validation, exactly as described in DEV rehearsal runbook
> **v2** (`docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md`) and its Support Chat
> companion, pinned to universal-telegram `32f17ea904a33cdd1f9b0225ba9638f95a09d883` and
> universal-support-chat `5d81b5b7795ee50f3a79e535a483d7677b36d1c0` (freshly fetched
> `origin/main`, both HEAD).
>
> This authorization is limited to:
> - the container/PHPUnit interop harness only — `docker/docker-compose.yml` +
>   `docker/docker-compose.interop.yml`, driven through `bin/docker/*.sh`, with
>   `docker compose … down -v` before and after every run;
> - the ephemeral Docker resources that harness creates intrinsically — Docker containers,
>   networks, and named volumes brought up by `docker/docker-compose.yml` together with
>   `docker/docker-compose.interop.yml`, solely for fresh synthetic test databases and harness
>   services, and removed by `docker compose … down -v` after every run;
> - fresh throwaway repository checkouts at the two pinned SHAs above;
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

## Scope authorized (summary)

Fresh throwaway checkouts at the two pinned SHAs; the existing disposable
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
- Support Chat companion decision record: `https://github.com/magpern/universal-support-chat/blob/main/docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md`.

## Next step after this addendum is signed and merged

Execute a single Tier 1 re-attempt (Runs 1–3) in fresh throwaway checkouts and the disposable
harness on both supported WP/PHP variants, capture the redacted evidence bundle per runbook v2
§9.1 / §9.2, run both repositories' full quality gates, and record a Tier 1 re-attempt
closure/evidence document. Approval B (Tier 2) remains a separate, later Product Owner action and
cannot take effect until this Tier 1 re-attempt passes and B1/B2 are proven resolved.
