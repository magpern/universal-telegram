# SC-M03 Final-Cutover Disposable DEV Rehearsal — Product Owner Approval A (Tier 1)

> **CLOSED — superseded by [ADR-0044](../adr/0044-universal-telegram-transport-only-retire-legacy-chat-and-cutover.md) (2026-08-28).** Universal Telegram becomes transport/adapter only; its legacy website chat is retired and **discarded, not migrated**. There is no UT→SC data migration, no cutover, and no Tier 2 rehearsal; the proposed Approval B is withdrawn unsigned. This document is retained unedited as a historical record.


## Status

**Approval A recorded.** Tier 1 (the disposable automated operational-sequence / integration
validation) is authorized to execute. Tier 2 (the actual disposable DEV rehearsal) is **not**
authorized and remains blocked on B1 and B2.

## Authority

> Product Owner authorizes SC-M03 final-cutover Tier 1 prerequisite validation exactly as
> specified in the merged disposable-rehearsal runbooks, pinned to Universal Telegram
> `31519ee3ae297369118bf2deda6eae05d13a3d8b` and Universal Support Chat
> `ce4691241eb843485117b323516899df916fdaf7`.
>
> This authorizes only fresh throwaway checkouts and the disposable container/PHPUnit interop
> harness, synthetic fixtures, and zero Telegram network traffic. It does not authorize Tier 2,
> any DEV VPS action, any Telegram resource, or any production quiescence, migration, activation,
> route switch, cutover, deployment, release, tag, rollback, deletion, or retention change.

## Scope authorized

- Fresh throwaway repository checkouts at the two pinned SHAs above — never the bind-mounted
  `/opt/biopentra/dev/*` checkouts, never `dev.biopentra.eu`.
- The existing disposable harness only: `docker/docker-compose.yml` + `docker/docker-compose.interop.yml`,
  driven through `bin/docker/*.sh`, `docker compose down -v` before and after each run.
- Entirely synthetic fixture data created by the rehearsal's own code.
- Zero Telegram network traffic — no bot token, no `setWebhook`, no message send, no group/topic
  action, no external API call. The harness `pre_http_request` boundary must be confirmed in
  place before each run.
- Runs 1, 2, and 3 of the frozen runbook
  ([`docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md`](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md) §7).

## Explicitly not authorized

Tier 2; any isolated-instance or dedicated-Telegram-resource creation (B1/B2 remain blockers);
any DEV VPS action; any production quiescence, migration, cohort activation, route switch,
cutover, soak, deployment, release, tag, rollback, deletion, or retention change; any acknowledge
/ overwrite / repair of an incident row to make a run pass.

## Reference

- Primary runbook: [`docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md`](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md) §10 "Approval A".
- Support Chat companion decision record: `https://github.com/magpern/universal-support-chat/blob/main/docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md`.

## Next step after this approval

Execute Tier 1 (Runs 1–3) in fresh throwaway checkouts and the disposable harness, capture the
redacted evidence bundle per runbook §9.1, run both repositories' full quality gates, and record
Tier 1 closure/evidence documents. Approval B (Tier 2) remains a separate, later Product Owner
action and cannot take effect until Tier 1 passes and B1/B2 are proven resolved.
