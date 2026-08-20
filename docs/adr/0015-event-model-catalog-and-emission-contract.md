# ADR-0015 — Event Identity, Envelope, Registry, and Safety-Wrapped Emission

## Status

Accepted

## Context

M01 delivered Telegram connectivity with fixed, hard-coded notification paths. M02's charter
requires a generic, WordPress-only normalized event model that later milestones (M03, M04) will
feed. The master plan sketches a `do_action`-based emission convention; a first draft of this plan
followed that sketch directly and generated `event_id` at dispatch time as a fresh UUID — Master
Architect review identified two defects in that approach: (1) a fresh, ungrounded UUID per emission
means a replayed or re-fired occurrence of the same underlying business event is indistinguishable,
at the identity level, from a genuinely new occurrence, undermining any downstream deduplication
built on `event_id`; (2) exposing emission itself as a public `do_action()` hook makes the plugin's
own request-safety guarantee dependent on the behavior of every third-party listener attached to
that hook, which `do_action()`'s own execution model cannot structurally protect against.

## Decision

Event identity is derived, not generated: `Events\EventIdentity::derive($event_type,
$schema_version, $idempotency_key)` computes a deterministic SHA-256-based `event_id` from a
mandatory, source-supplied `$idempotency_key` — re-emitting the same logical occurrence with the
same key always yields the same `event_id`, which is the entire basis for downstream deduplication
(ADR-0016). `Events\EventEnvelope` is the immutable, transient carrier of this identity plus a
fixed `EventSource` enum, four structured sub-arrays (`actor`, `subject`, `context`, `payload`),
and per-field classification enforced at registration time. `Events\Registry` is the per-request,
always-freshly-constructed catalog of registered event types, their schema versions, their
classification maps, their allowed condition/template fields, and — critically — their
`PUBLIC`-only history-projection fields (ADR-0017). Emission is exposed as exactly one stable
public PHP function, `universal_telegram_emit_event()`, delegating to a single internal
`Events\EventEmitter::emit()` service that wraps the entire downstream call graph — envelope
construction, history write, rule evaluation — in one `try/catch`, reducing any failure anywhere in
that graph to a fixed diagnostic code. There is no public `do_action()` hook for emission; the only
remaining public hook is `universal_telegram_register_event_types`, used purely for registration,
which carries no equivalent safety requirement since registration failures are expected to surface
loudly during the registering code's own development, not silently in a live request triggered by
unrelated WordPress activity.

## Alternatives

- Generating `event_id` fresh per emission and relying on a separate, explicit "correlation key"
  field for deduplication instead of deriving identity from it — rejected as needless indirection;
  deriving `event_id` itself from the key makes every downstream consumer (history, dispatch log)
  automatically identity-aware with no second field to keep in sync.
- Keeping emission as a `do_action()` hook but wrapping every individual registered listener call
  in its own `try/catch` via a custom dispatch loop — rejected as reimplementing WordPress's own
  hook machinery just to retrieve a safety property a plain function call already provides for
  free, with none of the downside.
- A single flat associative-array event shape with no formal envelope class — rejected because it
  provides no structural place to attach the mandatory per-field classification map.
- Allowing arbitrary, unclassified fields to pass through as `INTERNAL` by default — rejected; it
  would silently weaken ADR-0009's fail-closed guarantee.

## Consequences

Every later milestone that emits an event (M03, M04, third-party extensions) must choose and
document a genuine idempotency key for each of its own event types, following the worked examples
in this plan's §8 table — a milestone that instead generates a fresh key per emission is explicitly
declaring that event type non-deduplicable, a decision that must be stated, not defaulted into
silently. Every later milestone's emission goes through the same `universal_telegram_emit_event()`
function; no milestone builds a second emission path.

## Security and privacy impact

The safety-wrapped emission façade is the mechanism that makes ADR-0009's fail-closed redaction
model, and this plan's own PUBLIC-only history rule (ADR-0017), unconditionally reachable — no
failure anywhere in the event pipeline can propagate back into, or be worked around by, the
WordPress request that triggered it.

## Affected Documents/Milestones

`docs/ARCHITECTURE.md` (new `Events` boundary, implemented); `docs/adr/0009-privacy-classification-and-redaction-model.md`
(this ADR is the "later milestone's own architecture decision" ADR-0009 anticipated; ADR-0009's own
text is not edited); M03 and M04 (both register their event types through this same contract and
must define their own idempotency keys per the pattern this ADR establishes).

## Compatibility/Migration Impact

No schema change beyond the tables ADR-0016 and this plan's WP1/WP3 introduce. `event_id` is
CHAR(64) (hex SHA-256), not a UUID — this is a new, stable format decision with no prior M00/M01
precedent to be compatible with. The public function signature and the registration hook's
signature are a new public contract; changing either after acceptance requires a superseding ADR.
