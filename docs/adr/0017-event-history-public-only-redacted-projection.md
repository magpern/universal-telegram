# ADR-0017 — Event History as a PUBLIC-Only Redacted Projection

## Status

Accepted

## Context

ADR-0009 established fail-closed classification/redaction at M00 with four levels (`PUBLIC`,
`INTERNAL`, `SENSITIVE`, `SECRET`) and no public registration mechanism, deferring the latter to
M02 (ADR-0015 supplies it). A first draft of this plan allowed any classified field (`PUBLIC` or
`INTERNAL`) into the durable event-history projection, relying only on `Privacy\Redactor`'s
general-purpose masking behavior for `INTERNAL`-adjacent handling. Master Architect review
determined this was not strict enough for a durable, admin-browsable table meant to represent "safe
to look at, safe to keep" data specifically — `INTERNAL` data is appropriate for transient,
in-memory use during rule evaluation and template rendering (where it never leaves the current
request/job and is never written anywhere), but its accumulation in a permanent, queryable,
retention-governed table is a materially different and larger exposure than its momentary use.

## Decision

`Events\Registry::register()` requires that every field named in `history_projection_fields` be
classified exactly `PUBLIC` in the same call's `field_classification_map` — an `INTERNAL` field
listed there is rejected at registration time with `Events\NonPublicHistoryFieldException`, before
any event of that type can ever be emitted. `INTERNAL` fields remain fully usable in
`allowed_variable_fields` (rule conditions, message templates) since those uses are exclusively
transient and in-memory (ADR-0015, ADR-0016). The `event_history` table (ADR-0015's WP3) therefore
contains, by construction and independent of `Redactor`'s own runtime behavior, only data every
registering milestone has explicitly declared safe for indefinite, admin-visible,
retention-governed storage.

## Alternatives

- Allowing `INTERNAL` fields into history but masking them at render time only (in the admin
  browser) rather than at storage time — rejected; it would still accumulate the underlying data
  durably, at rest, subject to a future rendering bug or a direct database-level query exposing it,
  whereas the chosen decision means the data structurally cannot be there to expose.
- A fifth classification level specifically for "history-eligible" data — rejected as unnecessary;
  `PUBLIC` already means exactly this (data with no restriction on where it may appear), and
  introducing a fifth level would require reopening ADR-0009's own four-level model for a
  distinction the existing top level already captures.

## Consequences

M03 and M04, when planned, must classify strictly more conservatively for any field they want to
appear in event history than for a field they only need transiently for conditions/templates —
this is a genuine, deliberate constraint on their own future event-type design, not a cost imposed
only on M02.

## Security and privacy impact

This is a strictly additive, strictly narrowing constraint beyond ADR-0009's own baseline — it does
not weaken `Redactor`'s existing fail-closed default for any existing M00/M01 call site, none of
which are modified, and it closes an exposure surface (durable accumulation of `INTERNAL` data)
that ADR-0009 alone did not need to address because M00 had no durable, admin-browsable event-like
table.

## Affected Documents/Milestones

`docs/adr/0009-privacy-classification-and-redaction-model.md` (this ADR is the concrete fulfillment
of ADR-0009's own forward pointer to the milestone introducing event registration; ADR-0009's text
is not edited); `docs/adr/0015-event-model-catalog-and-emission-contract.md` (this ADR's
`PUBLIC`-only constraint is enforced inside `Registry::register()`, defined there).

## Compatibility/Migration Impact

None — no schema change beyond `event_history` itself (ADR-0015's WP3), no modification to any
existing class's public signature.
