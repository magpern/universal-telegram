# ADR-0016 — Notification Rule Engine: Storage, Deterministic Evaluation, and an Honestly-Scoped Dispatch State Model

## Status

Accepted

## Context

M02's charter requires deterministic evaluation, no duplicate delivery across retries, and a clear
matched/rejected explanation, while preserving ADR-0012's opaque-queue-payload outbound pattern and
staying within AND-only conditions. A first draft of this plan wrote a single `dispatched` state
into the dispatch log at the moment a send was merely *attempted*, before `MessageDispatcher::
send()`'s own `DispatchResult` was known — Master Architect review identified that this could label
a failed handoff as a success, and that it conflated "no second rule-engine decision for this pair"
with a false claim of exactly-once Telegram delivery, which ADR-0014 already explicitly disclaims
at the transport layer.

## Decision

Rules are stored with a flat, non-nested, AND-only JSON condition array validated against each
event type's field allowlist and a fixed operator enum. Evaluation is synchronous, in-process,
strictly ordered by `(priority ASC, id ASC)` at the query level, with each rule's evaluation and
dispatch wrapped in its own `try/catch` so one rule's failure never affects another. A single event
may independently match and dispatch through multiple rules. Delivery idempotency is enforced by
`Events\EventIdentity`'s deterministic `event_id` (ADR-0015) combined with a `UNIQUE(rule_id,
event_id)` constraint on `notification_dispatch_log`, whose `result` column is one of seven
explicit states — `claimed`, `rejected`, `skipped_duplicate`, `skipped_cooldown`,
`skipped_disabled_reference`, `handed_off_to_m01`, `failed_before_handoff` — such that a row is
only ever written to `handed_off_to_m01` after `MessageDispatcher::send()`'s own `DispatchResult`
confirms success, and a failed attempt is distinctly recorded as `failed_before_handoff`, never
silently indistinguishable from success. The atomic claim-or-reject insert is the sole
duplicate-prevention mechanism: once any row exists for a given `(rule_id, event_id)` pair, no
further write of any kind occurs for it. A `claimed` row that never reaches a terminal state
(request termination mid-flight) is a deliberately accepted, diagnostically-surfaced, non-retried
limitation, never mislabeled as either success or failure.

## Alternatives

- Writing the terminal `dispatched` state at claim time and treating a later `MessageDispatcher::
  send()` failure as an exceptional case to be caught and rolled back — rejected; the row's own
  history would then need to distinguish "was dispatched" from "was rolled back," which is exactly
  what the seven-state model already does more simply and without any rollback logic.
- Relying solely on ADR-0014's `possible_duplicate_delivery` flag for M02's own duplicate-delivery
  acceptance criterion — rejected; it addresses transport-level ambiguity, not the rule engine's
  own re-decision risk, a genuinely different failure mode.
- Building a background job to automatically resume stuck `claimed` rows — rejected for this
  milestone as requiring its own re-entrancy design outside M02's charter scope; documented instead
  as an explicit, diagnosable, accepted limitation.
- A new queue job type for notification dispatch carrying a rule/event reference — rejected; it
  would duplicate ADR-0012's already-correct pattern for no benefit.

## Consequences

The dispatch log doubles as the audit trail from event to rule to outbound message. Because
dispatch always goes through the unmodified `MessageDispatcher::send()`, any future change to M01's
transport automatically applies to M02's notifications with zero M02-side change required. Any
later milestone (M08, M11) building its own rule-like dispatch is expected to adopt this same
claim/terminal-state pattern rather than a single-write "dispatched" flag.

## Security and privacy impact

Condition clauses and templates are restricted to a fixed, per-event-type field allowlist and a
closed operator/grammar set, making injection of arbitrary logic structurally impossible. No secret
or credential-like data is ever readable by a condition or template, since such fields cannot exist
in an `EventEnvelope` at all (ADR-0015).

## Affected Documents/Milestones

`docs/ARCHITECTURE.md` (new `Automations` boundary, implemented); `docs/adr/0012-telegram-bot-cardinality-webhook-routing-and-outbound-delivery.md`
(this ADR is the concrete fulfillment of ADR-0012's own forward-looking consequence naming M02;
ADR-0012's text is not edited); `docs/adr/0014-telegram-reliability-rate-limits-circuit-breakers-and-dead-letter.md`
(this ADR's dedup mechanism is explicitly a distinct, additional layer above ADR-0014's
transport-level guarantee, not a replacement for it — both remain simultaneously true, and neither
claims exactly-once Telegram delivery); M08 and M11, per ADR-0012's own text, are expected to
follow this same pattern.

## Compatibility/Migration Impact

Two new tables (`notification_rules`, `notification_dispatch_log`), migration steps 9–10, additive
only. `MessageDispatcher::send()`'s existing signature is called, never changed. No existing table,
queue job type, or public contract from M00/M01 is altered.
