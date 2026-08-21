# ADR-0018: WooCommerce Event Catalog and Hook Binding for M03

## Status

Accepted.

## Context

M02 (ADR-0015, ADR-0016, ADR-0017) established the event identity,
registration, emission, rule-evaluation, and history-projection contracts as
a generic, WordPress-core-driven event system, exercised so far only by
WordPress-core event types (`wordpress.*`). M03 ("WooCommerce Event
Coverage") activates a second family of event types, `woocommerce.*`,
sourced from WooCommerce lifecycle hooks (order, payment, refund, stock,
cart, coupon, checkout validation), gated on
`Integrations\WooCommerce\WooCommerceSupport::is_active()` per ADR-0003's
optional-WooCommerce-integration boundary.

This is the first time the event catalog is extended by a second, optional
subsystem, and the first time hook-selection decisions must resolve
WooCommerce API-specific complications not present in WordPress core: hooks
that fire identically for classic and block checkout, hooks that can fire
from two independent internal code paths for the same logical occurrence
(stock threshold notifications), a hook family that fires once per status
transition versus dedicated per-status hooks that would double-fire if
combined naively, and one event class (checkout validation) with no
block-checkout equivalent in WooCommerce core at all. These are genuine
architectural choices, not merely an application of the existing M02
machinery, and constitute a public contract extension (new event types
consumed by the Rule Builder, Event Catalog page, and any future
third-party rule authors), triggering ADR-0005's "a public contract" and
"significant product behaviour with no prior precedent" criteria for
requiring an ADR.

## Decision

1. Eleven `woocommerce.*` event types (thirteen WooCommerce hook/filter
   bindings) are introduced (schema version 1 each): `order_created`
   (2 bindings), `order_status_changed`, `payment_completed`,
   `order_failed`, `order_cancelled`, `refund_created`,
   `stock_threshold_crossed` (2 bindings), `checkout_validation_failed`,
   `cart_item_added`, `coupon_applied`, `coupon_rejected`. Full
   field/classification/hook specification is authoritative in the frozen
   M03 plan document (`docs/plans/m03-woocommerce-event-coverage-plan-v1.md`,
   §5) and is not repeated here to avoid drift between two normatively
   competing sources; this ADR governs the *decisions*, the plan governs the
   *field-by-field detail*.
2. Registration is gated behind `WooCommerceSupport::is_active()`, on the
   existing `universal_telegram_register_event_types` hook fired at
   WordPress `init` priority 20 (unchanged M02 hook/priority) — no new
   registration hook, no WooCommerce-specific bootstrap hook is introduced.
3. Where WooCommerce exposes both a classic and a block/Store-API hook for
   the same logical occurrence (order creation), the emitter binds both,
   using an idempotency key derived solely from the order's own identity —
   relying on the existing `EventIdentity::derive()` plus history/dispatch-
   log unique constraints to absorb any theoretical double-fire, rather than
   adding request-scoped dedup state.
4. Where a WooCommerce action can fire from two independent internal code
   paths for the same logical occurrence (low/no-stock notifications), the
   idempotency key is derived from `(product_id, status, stock_quantity)` —
   not a fresh UUID or per-call data — for the same reason. This key
   deliberately coalesces any two firings that observe the identical
   quantity/status combination regardless of the time elapsed between them;
   it is a bounded-state guarantee, not a complete per-invocation audit
   trail — see Decision §11.
5. `woocommerce.order_failed` and `woocommerce.order_cancelled` are
   registered as dedicated event types on the `woocommerce_order_status_{x}`
   hook family, in addition to the generic `woocommerce.order_status_changed`
   type firing on `woocommerce_order_status_changed` for every transition.
   Both firing for the same transition is accepted: they are two distinct,
   independently useful, differently keyed event *types* describing one
   occurrence from two angles, which is not "double-counting" in the M03
   charter's sense — double-counting means the same event type firing twice
   via redundant instrumentation (e.g. server and browser both reporting one
   add-to-cart), not two purposefully distinct named types both keying off
   one underlying WooCommerce action.
6. "Order paid" (a `docs/master-plan.md` candidate name) is not implemented
   as a separate event type from `payment_completed` — they describe the
   same occurrence, and introducing both would be genuine, not merely
   apparent, double-counting.
7. The event type is named `woocommerce.order_failed`, not
   `woocommerce.payment_failed`. It is sourced from
   `woocommerce_order_status_failed`, part of the generic
   `woocommerce_order_status_{status}` hook family, which fires whenever an
   order's status transitions to `failed` by any means — a payment gateway
   reporting failure, a manual admin status change, or a third-party
   extension calling `set_status()`/`update_status()` directly.
   WooCommerce core exposes no gateway-agnostic "payment attempt failed"
   signal distinct from this status transition. Naming the type
   `order_failed` and documenting it as "order entered failed status"
   avoids overstating the certainty of what caused the transition; naming it
   `payment_failed` would imply a guarantee the underlying hook does not
   provide.
8. `woocommerce.checkout_validation_failed` is implemented for classic
   (shortcode) checkout only, bound to `woocommerce_after_checkout_validation`.
   No block/Store-API equivalent hook exists in WooCommerce core as of
   11.0.1 (block validation is exception/schema-based with no catch-all
   validation-failure action). This is accepted as a documented, known gap
   rather than an unofficial Store API workaround (e.g. hooking REST
   exception handling), which would rely on undocumented internals and risk
   breaking across WooCommerce block-checkout refactors.
9. "Cart value crosses a threshold" (a `docs/master-plan.md` candidate) is
   not implemented as its own event type. It is a derived/computed
   condition, not a raw hook occurrence. `woocommerce.cart_item_added`'s
   `payload.cart_total` field lets an administrator build the equivalent
   behaviour using the existing `ConditionOperator::GREATER_THAN` rule
   condition, reusing the existing rule engine rather than inventing new
   threshold-detection machinery.
10. `woocommerce.cart_item_removed` and coupon-removal events are deferred
    (not implemented in M03), as a deliberate scope trim to keep the initial
    catalog's classification-review surface smaller; they may be added in a
    later milestone via the existing `register()` extension point without
    requiring any change to this ADR's decisions.
11. No customer PII (name/email/phone/address) and no payment-sensitive data
    (gateway tokens/transaction ids/card data/raw gateway responses) appears
    in any field of any classification level across the whole catalog — not
    merely excluded from history, excluded from the envelope entirely. Where
    a candidate field would have required such data (refund reason,
    validation error message text, transaction id, coupon error message),
    the field is omitted rather than included-and-masked.
12. Idempotency keys follow a stated, bounded-coalescing policy rather than
    guaranteeing a distinct `event_id` for every physical hook invocation:
    state-keyed coalescing (stock threshold: keyed on quantity/status, no
    time component — a repeat firing at the identical quantity/status
    collapses to one event regardless of elapsed time), attempt-window
    coalescing (order status transitions: keyed with second-resolution
    timestamps — repeats within the same second collapse), coarse
    time-bucket coalescing (checkout validation, coupon apply/reject: keyed
    with a 5-second bucket, chosen because WooCommerce core exposes no more
    precise, stable, pre-write identity for these occurrences), and
    line-identity coalescing (`cart_item_added`: keyed on the cart line's
    own identity with no quantity or time component at all — every
    add-to-cart call for the same cart line collapses permanently to one
    event, and the recorded `quantity`/`cart_total` reflect the state at the
    first such call for that line, never a later one). This is an explicit,
    accepted trade-off — these event types report bounded, notify-worthy
    state changes, not a complete audit log of every underlying hook
    invocation.

## Alternatives

- **Naming the order-status-transition-sourced type `woocommerce.payment_failed`**:
  rejected, see Decision §7 — the underlying hook
  (`woocommerce_order_status_failed`) observes any cause of a transition to
  `failed` status, not exclusively a verified gateway payment failure;
  `order_failed` names the type for what it actually observes.
- **Deriving `order_failed`/`order_cancelled` only from
  `order_status_changed`, without dedicated hooks**: rejected — it would
  push status-string matching into rule *conditions* instead of the event
  *catalog*, which works but is less discoverable in the Event
  Catalog/Rule Builder UI and loses the precise semantic naming
  `docs/master-plan.md`'s candidate list explicitly calls for. The
  dual-registration approach costs nothing (no double-counting in the
  relevant sense, per Decision §5) and is strictly more usable.
- **A single generic `woocommerce.order_event` type carrying a `kind`
  field** instead of eleven distinct types: rejected — this would break
  ADR-0017's per-type PUBLIC/INTERNAL classification precision (different
  order occurrences legitimately warrant different field sets) and would
  require rule authors to branch on a `kind` field the rule engine's fixed
  `{{ field.path }}` template grammar cannot conditionally branch on anyway
  (no conditionals, per M02's frozen `TemplateRenderer` design) — one type
  per occurrence is the only approach compatible with the existing template
  and condition model.
- **A Store API REST-exception-based workaround for block-checkout
  validation failures**: rejected, see Decision §8.
- **A dedicated `woocommerce.cart_value_threshold_crossed` event type**,
  computed by comparing successive cart totals: rejected, see Decision §9 —
  would require new server-side state (last-seen cart total per session)
  with no precedent in the existing event model, which is stateless-per-
  hook-fire by design.
- **Timestamp- or UUID-free exact-occurrence idempotency keys for the
  bounded-coalescing event types** (stock threshold, status transitions,
  checkout validation, coupon apply/reject): rejected, see Decision §12 —
  no stable, WooCommerce-assigned identity exists for these occurrences (no
  per-notification id, no checkout-attempt id, no coupon-application id);
  achieving exact per-invocation fidelity would require introducing new
  server-side request/session state with no precedent in the existing
  event model. The bounded-coalescing policy is accepted instead as
  strictly simpler and consistent with the stateless-per-hook-fire design
  used everywhere else in the catalog.
- **A quantity- or time-inclusive idempotency key for `cart_item_added`**
  (which would emit a fresh event on every quantity change to the same
  cart line): rejected — the notify-worthy occurrence is "this product was
  added to this cart," a one-time signal per line, not a per-quantity-
  change stream; including quantity in the key would also reintroduce the
  near-identical-event flooding risk the original key design was chosen to
  avoid (§5.11). Administrators needing live, per-change cart visibility are
  not served by any event-based design in this catalog and are out of scope
  for M03.

## Consequences

- The Event Catalog, Rule Builder, and Rule Simulator admin pages
  automatically surface all eleven new types with zero code changes to
  those pages, since they iterate `Registry::all()` generically —
  validating that ADR-0005/ADR-0015's registry design correctly scales to a
  second event source.
- Administrators get two related-but-distinct signals for failed/cancelled
  orders (dedicated + generic); the Event Catalog's descriptions for these
  types should make this pairing clear to avoid confusion, though no code
  changes are needed to enforce it — this is an operational/UX note, not an
  architectural risk.
- Block-checkout validation failures remain unobservable via this plugin
  until WooCommerce core exposes an equivalent hook; if/when it does, adding
  it is a pure additive change (new hook binding on the same event type or a
  new schema version) and does not require revisiting this ADR's other
  decisions.
- `woocommerce.cart_item_removed`/coupon-removal are absent; a future
  milestone adding them only needs new `register()` calls plus emitter
  methods, no changes to this ADR.
- Administrators and any downstream integrator consuming
  `stock_threshold_crossed`, `order_status_changed`, `order_failed`,
  `order_cancelled`, `checkout_validation_failed`, `coupon_applied`,
  `coupon_rejected`, or `cart_item_added` should understand these types
  report bounded, notify-worthy state changes under the coalescing policy
  in Decision §12, not a complete audit log of every underlying WooCommerce
  hook invocation — this should be reflected in each type's Event Catalog
  description text at implementation time. `cart_item_added` in particular
  reports the state at first add for a given cart line, not a live-updating
  running total for that line.

## Security and privacy impact

All eleven event types were reviewed field-by-field against ADR-0009's
four-level classification model; none include customer PII or
payment-sensitive data at any classification level (fields that would have
required such data are omitted from the envelope entirely, not merely
reclassified). No field of any type carries a payment gateway identifier or
any other payment-method detail: an earlier draft's INTERNAL-classified
`payload.payment_method` (gateway id only) on `order_created`,
`payment_completed`, and `order_failed` was removed outright, on review,
because it is not required by the M03 charter and its presence made the
"no payment method details" boundary read as ambiguous even though the
field carried no token or gateway response data. `Registry::register()`'s
existing fail-closed validation
(ADR-0017: `NonPublicHistoryFieldException`, `UnclassifiedFieldException`)
continues to be the structural enforcement mechanism — no new privacy
mechanism is introduced. `woocommerce.coupon_rejected`'s emission from a WC
filter callback (`woocommerce_coupon_error`) rather than an action is a
novel call pattern for this codebase's event emitters, reviewed and
confirmed safe: `EventEmitter::emit()` is synchronous and non-throwing, and
the callback returns the filtered value unmodified, so it introduces no new
privacy or control-flow risk beyond any other emitter callback.

## Affected Documents/Milestones

- `docs/milestones/m03-woocommerce-event-coverage.md` (this ADR is the
  governing architecture document for that milestone's event catalog).
- `docs/master-plan.md` §5.2 (this ADR records the final selected subset of
  the candidate event list and the justification for each exclusion).
- Future milestone(s) that may add `cart_item_removed`/coupon-removal or a
  block-checkout-validation signal reference this ADR as prior art but do
  not need to amend it (additive registrations only).

## Compatibility/Migration Impact

None. No database schema change, no new table, no new option. No change to
any M00–M02 frozen contract (`Registry`, `EventEnvelope`, `EventEmitter`,
`EventDispatcher`, `RuleEvaluator`, `NotificationDispatcher`,
`TemplateRenderer`, `Queue\Dispatcher`). WordPress-only installations
(WooCommerce absent) are entirely unaffected — no `woocommerce.*` type is
ever registered, per `WooCommerceSupport::is_active()` gating, and the
WP-only integration test suite includes a structural guard test asserting
this. Sites with WooCommerce already active gain the new event types
automatically on plugin update, with no action required from site
administrators; no existing rule, dispatch log row, or history row is
altered or migrated.
