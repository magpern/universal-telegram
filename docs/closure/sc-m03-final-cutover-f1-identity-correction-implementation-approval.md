# SC-M03 Final-Cutover — F1 `channel_case_ref` Identity-Correction Implementation — Product Owner Acceptance

## Status

**F1 implementation acceptance recorded — 2026-08-27.** ADR-0043 (this repository) and Support
Chat ADR-0011 are **Accepted**. Implementation of the frozen F1 remediation work packages is
authorized. **Tier 1 remains halted and unexecuted; Tier 2 remains unexecuted and blocked on
B1, B2, and F1.** No DEV, production, or operational cutover action is authorized.

## Authority

> **Product Owner authorization — SC-M03 final-cutover F1 identity-correction implementation**
>
> I have reviewed ADR-0043 (Universal Telegram) and ADR-0011 (Universal Support Chat) and their F1 remediation plans, frozen as documentation-only. I accept the frozen identity rule: `channel_case_ref` in Contract v1 identifies the Support Chat conversation/case (resolved via Support Chat's own conversation repository); the Universal Telegram binding UUID is a UT-owned binding identity that never crosses the Contract v1 wire; equality of the two UUIDs is never required or assumed; no Support Chat binding→conversation resolver, shared map, or UT-binding-UUID fallback is added; a missing/malformed/non-existent case reference after an active binding is selected is a classified terminal incident, never an unbounded retry.
>
> I authorize implementation of exactly the work packages in `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` (and its Support Chat companion), pinned to the accepted baselines, in normal feature branches with per-repo CI and the interop harness. This authorizes **no** schema or `db_version` change, **no** new Contract operation, **no** DEV or production quiescence, migration, activation, route switch, cutover, deployment, release, tag, or rollback, and **no** execution of Tier 1 or Tier 2 of the DEV rehearsal.
>
> A Tier 1 re-attempt remains a separate authorization (a new Approval A addendum) after this implementation is merged, CI-green, and its real-binding handoff path passes, under DEV rehearsal runbook v2. Tier 2 stays blocked on B1, B2, and F1.

## Scope authorized

- Implementation of exactly the work packages in the primary F1 remediation plan
  (`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`, §11 WP-F1-3
  through WP-F1-7) and its Support Chat companion (comment corrections C1–C4, fixture
  alignment), pinned to the accepted baselines (Universal Telegram
  `31519ee3ae297369118bf2deda6eae05d13a3d8b`, Universal Support Chat
  `ce4691241eb843485117b323516899df916fdaf7`).
- Normal feature branches, per-repo CI, and the disposable container/PHPUnit interop harness
  (`bin/docker/test-integration-interop.sh`) on both supported WP/PHP variants.
- The frozen exhaustive `CutoverReplayDispatcher::finish()` classification and the two new
  closed incident codes `unresolved_case_reference` and `handoff_rejected` (ADR-0043 §3).
- The DEV rehearsal runbook v2 documentation deliverable (WP-F1-6) and the implementation
  report (WP-F1-7).

## Explicitly not authorized

- Execution or re-attempt of Tier 1 or Tier 2 of the DEV rehearsal (a Tier 1 re-attempt needs a
  separate Approval A addendum under runbook v2, only after this implementation is merged,
  CI-green, and its real-binding handoff path passes).
- Any schema, `Migrator::target_version()`, `universal_support_chat_db_version`, or
  plugin-version change; any new Contract v1 operation, route, or field; any SC-side
  binding→conversation resolver, shared map, or UT-binding-UUID fallback; any identifier-equality
  constraint.
- Any DEV VPS action, any Telegram resource, or any production or DEV quiescence, migration,
  cohort activation, route switch, cutover, soak, deployment, release, tag, rollback, deletion,
  or retention change.

## Reference

- Primary remediation plan: [`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`](../plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md) (§15 acceptance text; §11 work packages; §11a Tier 1 rerun gate).
- [ADR-0043](../adr/0043-support-chat-adr-0011-pin-and-channel-case-ref-conversation-uuid-correction.md) — **Accepted** 2026-08-27.
- Support Chat ADR-0011 and the Support Chat decision record (decision item 7, "F1 implementation acceptance — recorded"): `https://github.com/magpern/universal-support-chat/blob/main/docs/adr/0011-cutover-channel-case-ref-is-support-chat-conversation-uuid.md`, `https://github.com/magpern/universal-support-chat/blob/main/docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md`.
- Finding of record: [`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`](sc-m03-final-cutover-dev-rehearsal-tier1-closure.md).
- Acceptance PRs: universal-telegram `https://github.com/magpern/universal-telegram/pull/52`; universal-support-chat `https://github.com/magpern/universal-support-chat/pull/25`.

## Next step after this acceptance

Implement the frozen F1 remediation (separate task): the Universal Telegram runtime correction
(send `ChannelBinding::support_conversation_uuid()` as `channel_case_ref`; `binding_uuid` off
the wire; exhaustive `finish()` classification), the Support Chat C1–C4 comment corrections, the
frozen test matrix with real bindings, dual-plugin CI on both WP/PHP variants, ordered merge
(Universal Telegram first, then Support Chat re-verified against merged Universal Telegram
`main`), and implementation closure records. A Tier 1 re-attempt under runbook v2 remains a
separate, later Product Owner action and cannot begin until this implementation is merged,
CI-green, and its real-binding handoff path passes.
