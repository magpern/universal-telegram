# M03 — WooCommerce Event Coverage — Definitive Implementation Plan (v1)

Status: Frozen — implementation authorized. This document is self-contained: it does not require a reader to consult any earlier conversation draft or planning-session transcript.

Implements: `docs/milestones/m03-woocommerce-event-coverage.md`. Extends the M02-frozen event/notification system (ADR-0015, ADR-0016, ADR-0017) with a second, optional event source, gated on `docs/adr/0003-optional-woocommerce-integration.md`. Proposes ADR-0018, full text included below (§10) and materialized alongside this plan under `docs/adr/`.

## 1. Executive summary and version recommendation

M03 extends the existing, frozen M02 event system (`Events\Registry`,
`EventIdentity`, `EventEnvelope`, `EventEmitter`) with a second event family,
`woocommerce.*`, comprising eleven event types sourced from thirteen
WooCommerce core hook/filter bindings covering order lifecycle, payment,
refund, stock, cart, coupon, and checkout-validation occurrences. It
introduces **no new top-level module boundary** (WooCommerce
emitters are a subdomain of the existing `Integrations` boundary per
ADR-0005), **no new database table or migration**, and **zero admin-UI code
changes** (the Event Catalog, Rule Builder, and Rule Simulator pages already
iterate `Registry::all()` generically). The only new persistent artifact is
one new ADR (0018) and five new PHP emitter classes under
`src/Integrations/WooCommerce/Events/`.

Recommendation: **ship this as v1**, no revision needed before Master
Architect review. The design resolves every ambiguity the charter and
master-plan leave open (order-paid vs payment-completed, cart-value
threshold, checkout-validation block-checkout gap, dual-hook and dual-path
dedup) with an explicit decision and justification, so the plan can go
straight to architectural review without a round of clarifying questions.

---

## 2. Verified baseline

```
$ git rev-parse HEAD
8172794332569c31f3ebb3f702bea019cd2f0f82
$ git rev-parse origin/main
8172794332569c31f3ebb3f702bea019cd2f0f82
$ git status --short
(clean)
```

`main` and `origin/main` are identical; working tree clean. Required reading
completed in full: `docs/governance.md`, `docs/master-plan.md`,
`docs/milestones/m03-woocommerce-event-coverage.md`, `docs/future-scope.md`,
`docs/adr/README.md`, all 17 ADRs (`docs/adr/0001`–`0017`), the M00/M01/M02
frozen plans and closure records (`docs/plans/`, `docs/closure/`),
`docs/testing/test-strategy.md`, and the current source tree (`src/`,
`tests/`, `.github/workflows/ci.yml`, `composer.json`,
`universal-telegram.php`, `readme.txt`). Two source-level spot checks
(`src/Core/Plugin.php`, `src/Administration/Diagnostics/DiagnosticsReport.php`)
were re-verified directly against this plan's assumptions after drafting;
both confirmed exact match (see §4 and §7).

No conflict was found between the M03 charter and M02's frozen contracts —
the charter's scope (WooCommerce server-side events feeding the existing rule
engine) is exactly what M02's `Registry`/`EventEmitter` design was built to
extend. No workaround or contract change is required.

---

## 3. Exact scope and exclusions

**In scope** (per charter): product, cart, checkout, payment, order, and
stock server-side WooCommerce events; HPOS compatibility; classic and block
checkout compatibility; failure/validation context; sensitive-field
redaction (interpreted, and applied, as *exclusion* of sensitive fields from
the catalog entirely — see §5.7).

**Explicitly out of scope** (per charter and `docs/future-scope.md`):

- Browser-side/visitor events not tied to a WooCommerce object, including
  page-view signals (`product viewed`, `checkout viewed`) — M04.
- AI-assisted analysis of these events — M09 onward.
- Generic webhook rule action, nested OR condition groups — both remain in
  `docs/future-scope.md`; this plan does not require either and proposes no
  change to `docs/future-scope.md`.
- A new rule engine, queue path, settings page, or Telegram transport — none
  are introduced; M03 is additive registrations only against M02's existing
  machinery.
- Customer address/email/phone, payment method details, payment tokens,
  gateway responses, checkout request bodies, order notes, arbitrary product
  metadata — none of these appear in any field of any classification level
  in the catalog defined in §5.

---

## 4. WooCommerce dependency boundary

**Gating point**: inside `Core\Plugin::init()`, in the same scope where
`WooCommerceSupport` is already constructed unconditionally
(`src/Core/Plugin.php:407`) and where the existing core emitters
(`LoginEmitter`, `UserLifecycleEmitter`, `ContentEmitter`, etc.) are
constructed and wired via `add_action( 'universal_telegram_register_event_types', array( $emitter, 'register_event_types' ), 10 )`
(`src/Core/Plugin.php:573-590`), which itself fires from a WordPress `init`
priority-20 closure (`src/Core/Plugin.php:609`). Verified directly against
current source — the pattern below is a literal extension of the existing
block, not a new mechanism:

```php
if ( $this->woocommerce_support->is_active() ) {
    $order_event_emitter    = new OrderEventEmitter();
    $stock_event_emitter    = new StockEventEmitter();
    $cart_event_emitter     = new CartEventEmitter();
    $coupon_event_emitter   = new CouponEventEmitter();
    $checkout_event_emitter = new CheckoutEventEmitter();

    add_action( 'universal_telegram_register_event_types', array( $order_event_emitter, 'register_event_types' ), 10 );
    add_action( 'universal_telegram_register_event_types', array( $stock_event_emitter, 'register_event_types' ), 10 );
    add_action( 'universal_telegram_register_event_types', array( $cart_event_emitter, 'register_event_types' ), 10 );
    add_action( 'universal_telegram_register_event_types', array( $coupon_event_emitter, 'register_event_types' ), 10 );
    add_action( 'universal_telegram_register_event_types', array( $checkout_event_emitter, 'register_event_types' ), 10 );

    $order_event_emitter->register_hooks();
    $stock_event_emitter->register_hooks();
    $cart_event_emitter->register_hooks();
    $coupon_event_emitter->register_hooks();
    $checkout_event_emitter->register_hooks();
}
```

**Why this timing is safe**: WooCommerce's own bootstrap is
`plugins_loaded` (WC class loads) → `before_woocommerce_init` (feature
compatibility, already used by M00 for HPOS) → `init` priority 0
(`WC()->init()` wires WC's own checkout/order machinery) → later `init`
priorities. WordPress `init` priority 20 is always after WC's own priority-0
wiring, and WC's checkout/order hooks (`woocommerce_checkout_order_processed`,
etc.) only ever fire on a later page/AJAX/REST request, never during `init`
itself — so there is no ordering race to guard against.

**WooCommerce absent / inactive / incompatible version**:
`WooCommerceSupport::is_active()` (unchanged, `src/Integrations/WooCommerce/WooCommerceSupport.php`)
returns `false` from `class_exists( 'WooCommerce' )` / `defined( 'WC_VERSION' )`
/ `version_compare( WC_VERSION, '8.0', '>=' )` checks alone — no WC API is
touched, so no fatal error is possible. The entire gated block above is
skipped: no emitter objects are constructed, no `add_action`/`add_filter`
calls happen, no `woocommerce.*` type is ever registered in `Registry` for
that request. `Registry::all()` — which drives the Event Catalog and Rule
Builder — simply contains zero `woocommerce.*` entries.

**WooCommerce loading / partially loaded**: not a real state at `init`
priority 20 — by then `plugins_loaded` has completed for every active
plugin, so WC is either fully available or genuinely inactive.

**WooCommerce deactivated mid-request**: not reachable — plugin activation
state is read once per request during `plugins_loaded`.

**WooCommerce deactivated between requests**: `Registry` is explicitly a
per-request, freshly-constructed object (per its own docblock — never cached
across requests). The next request after deactivation simply never executes
the gated block, so `woocommerce.*` types are absent from that request's
registry from the start. No special-case code is needed; this is a direct
consequence of the existing per-request design.

**Existing rules referencing a `woocommerce.*` event_type after WooCommerce
is deactivated**: `RuleEvaluator::evaluate()` is reachable only via
`EventDispatcher::handle()`, reachable only via `EventEmitter::emit()`,
called only from WooCommerce hook callbacks that no longer execute (their
`add_action`/`add_filter` calls were never made that request). No
`woocommerce.*` event is ever emitted while WC is inactive, so no rule
referencing one is ever evaluated. The rule is not deleted, not flagged
invalid — it goes **dormant**: present in `notification_rules`, visible (but
unreachable) in the Rule Builder, evaluated zero times, resuming normally the
moment WooCommerce is reactivated. This requires zero new code in
`RuleEvaluator`, `NotificationRuleRepository`, or any admin page.

---

## 5. Event catalog

Conventions applied throughout: `EventSource::WOOCOMMERCE` for every type;
`schema_version = 1`; idempotency keys always derived from stable WooCommerce
entity identity, never a fresh UUID; `actor` = who caused it (WP user id,
`0` for guest — never name/email); `subject` = the primary WC entity
affected; `context` = situational metadata; `payload` = event-specific
business data, always scalars or arrays of scalars — never a `WC_Order`/
`WC_Product`/`WC_Coupon`/`WC_Order_Refund` object and never a `serialize()`d
structure.

### 5.1 Final selected list (11 event types, 13 hook/filter bindings) and excluded candidates

| # | Event type | Hook(s) | Bindings |
|---|---|---|---|
| 1 | `woocommerce.order_created` | `woocommerce_checkout_order_processed` (classic) + `woocommerce_store_api_checkout_order_processed` (blocks) | 2 |
| 2 | `woocommerce.order_status_changed` | `woocommerce_order_status_changed` | 1 |
| 3 | `woocommerce.payment_completed` | `woocommerce_payment_complete` | 1 |
| 4 | `woocommerce.order_failed` | `woocommerce_order_status_failed` | 1 |
| 5 | `woocommerce.order_cancelled` | `woocommerce_order_status_cancelled` | 1 |
| 6 | `woocommerce.refund_created` | `woocommerce_order_refunded` | 1 |
| 7 | `woocommerce.stock_threshold_crossed` | `woocommerce_low_stock`, `woocommerce_no_stock` | 2 |
| 8 | `woocommerce.checkout_validation_failed` | `woocommerce_after_checkout_validation` (classic only, documented gap for blocks) | 1 |
| 9 | `woocommerce.cart_item_added` | `woocommerce_add_to_cart` (shared classic/blocks) | 1 |
| 10 | `woocommerce.coupon_applied` | `woocommerce_applied_coupon` | 1 |
| 11 | `woocommerce.coupon_rejected` | `woocommerce_coupon_error` (filter) | 1 |

Total: **11 event types**, **13 hook/filter bindings** (2+1+1+1+1+1+2+1+1+1+1 = 13).

**Naming note — `woocommerce.order_failed` (renamed from an earlier working
name `payment_failed`)**: this type is sourced from
`woocommerce_order_status_failed`, part of WooCommerce's generic
`woocommerce_order_status_{status}` hook family — it fires whenever an
order's status transitions to `failed` by **any** means: a payment gateway
reporting a failure, a manual admin status change, or a third-party
extension calling `WC_Order::set_status( 'failed' )`/`update_status( 'failed' )`
directly. It is **not** a guaranteed, gateway-verified "payment attempt
failed" signal — WooCommerce core exposes no such gateway-agnostic hook (see
§5 research). The type is named and documented as **"order entered failed
status"**, matching what the hook actually observes, rather than
`woocommerce.payment_failed`, which would overstate the semantic precision
of a status-transition-sourced signal. Every other reference to "payment
failed" in this plan and in `docs/master-plan.md`'s candidate list should be
read as satisfied, imperfectly but honestly, by this renamed type.

**Excluded, with justification:**

- **"Order paid" as a type distinct from `payment_completed`**: not
  implemented. Master-plan lists both as separate candidates, but they
  describe the same underlying occurrence (`WC_Order::payment_complete()`
  being called). Adding both would be genuine double-counting under the
  charter's constraint. `payment_completed` covers the precise
  "order was marked paid" semantic; an admin wanting the broader "reached
  processing/completed" signal uses `order_status_changed` with
  `ConditionOperator::IN` on `payload.status_to` — no rule-engine change
  needed.
- **Product viewed / Checkout viewed**: page-view signals, not
  server-authoritative mutations; charter reserves non-object-tied
  browser/page-view signals for M04.
- **"Cart value crosses a threshold"**: not a raw hook occurrence — a
  derived/computed condition. `cart_item_added`'s `payload.cart_total`
  field lets an admin build the equivalent with `GREATER_THAN`, reusing the
  existing rule-condition engine instead of inventing session-state-based
  threshold detection. Note (per §5.11's line-identity coalescing) this
  gives a threshold check at the moment each distinct cart line is first
  added, not a live-updating total for a line that later grows in quantity
  — a documented limitation of reusing this field for that purpose, not a
  defect.
- **`cart_item_removed` / coupon-removal**: deliberately deferred, not
  because they are technically hard, but to keep the initial catalog's
  classification-review surface smaller; addable later as a pure additive
  `register()` change with no impact on this plan's other decisions.
- **"Shipping unavailable"**: no dedicated core WC hook exists; it manifests
  as a checkout validation error, already covered generically by
  `checkout_validation_failed`'s `payload.error_codes`.

### 5.2 Duplicate-prevention mechanism and idempotency guarantee policy

Every dedup case below is solved purely by idempotency-key selection,
absorbed by three already-frozen M02 mechanisms: (1) `EventIdentity::derive()`
is a pure deterministic SHA-256 of `(event_type, schema_version,
idempotency_key)`; (2) `EventHistoryRepository::record()` does an idempotent
`INSERT IGNORE` keyed on `event_id`; (3) `DispatchLogRepository`'s
`UNIQUE(rule_id, event_id)` claim absorbs any resulting duplicate dispatch.
No M03 code introduces its own locking, request-scoped cache, or explicit
duplicate check — correct key selection is the entire mechanism.

**Stated policy — bounded coalescing, not a complete audit log.** Several of
the idempotency-key formulas in §5.3–§5.13 deliberately fold multiple
distinct physical hook invocations into one logical `event_id` whenever they
plausibly represent the same real-world, notify-worthy occurrence within a
bounded window or a bounded state space. This is an intentional trade-off,
stated here explicitly rather than left implicit in individual field
descriptions:

- **State-keyed coalescing** (`stock_threshold_crossed`): the key includes
  the *current stock quantity value*, not a timestamp. Two hook firings that
  both observe the *same* `(product_id, status, stock_quantity)` triple
  collapse to one event, regardless of how much wall-clock time separates
  them — including a firing today and a firing next week, if the product
  happens to return to the exact same quantity and status combination by
  coincidence (e.g., restocked to the same low-stock number twice). This is
  **not** "always distinct because it's later" — distinctness comes only
  from the quantity value differing, never from elapsed time alone. A
  genuinely new low-stock notification at a different quantity produces a
  different key and is correctly treated as a new occurrence; a repeat
  notification at the identical quantity is treated as the same occurrence,
  even if the intervening state changed and changed back. Administrators
  relying on `stock_threshold_crossed` should understand it reports
  "stock reached this level," not "every individual stock-mutation attempt."
- **Attempt-window coalescing** (`order_status_changed`, `order_failed`,
  `order_cancelled`): the key includes
  `$order->get_date_modified()->getTimestamp()` (second-resolution). Two
  transitions between the same status pair for the same order that complete
  within the same wall-clock second collapse to one event; transitions
  separated by at least one second are distinct. This bounds, but does not
  eliminate, the coalescing of rapid repeat transitions (e.g., a retry loop
  toggling status back and forth faster than once per second) — an accepted
  limitation of second-resolution timestamps, not a claim that time alone
  guarantees distinctness beyond that resolution.
- **Coarse time-bucket coalescing** (`checkout_validation_failed`,
  `coupon_applied`, `coupon_rejected`): the key includes a 5-second
  `floor( time() / 5 )` bucket. Any number of qualifying hook firings within
  the same 5-second bucket (e.g., a rapid double form-submit) collapse to
  one event; a firing in the next bucket is distinct regardless of whether
  the underlying condition is actually a fresh occurrence or a slow retry of
  the same one. This is a deliberate, bounded coalescing window chosen
  because no more precise, stable, pre-write identity is available from
  WooCommerce core for these hook types (no checkout-attempt id, no
  coupon-application id) — not a claim of exact occurrence-level fidelity.
- **Line-identity coalescing** (`cart_item_added`): the key is
  `'cart:' . WC()->session->get_customer_id() . ':' . $cart_item_key`,
  where `$cart_item_key` is WooCommerce's own deterministic hash of
  product+variation+cart-item-data — it contains **no** quantity or time
  component at all. Every `woocommerce_add_to_cart` firing for the same
  cart line (the same product/variation/cart-item-data combination in the
  same cart), across any number of subsequent quantity changes, produces
  the identical key and therefore the identical `event_id`. Because
  `EventHistoryRepository::record()`'s `INSERT IGNORE` keeps only the first
  row written for a given `event_id`, this means the recorded and
  dispatched event reflects the field values captured at the **first**
  `woocommerce_add_to_cart` firing for that cart line — not the most recent
  quantity/total as of any later call to the same line. This is the most
  aggressive coalescing category in the catalog (no time bound, no state
  bound — permanent for the life of that cart line) and is a deliberate
  choice: the notify-worthy occurrence is "this product was added to the
  cart," a one-time signal, not a per-quantity-change stream. See §5.11 for
  the corresponding field-semantics statement.
- **Exact-identity, no coalescing** (`order_created`, `payment_completed`,
  `refund_created`): keys derive from a WooCommerce-assigned identity that
  is unique per real occurrence (order id, refund id) with no time or state
  component — these are not subject to any coalescing trade-off above; the
  dual-hook `order_created` case collapses only two hooks describing the
  literal same order (see below), never two different orders.

**Consequence for test design (§9)**: tests verify the *stated* coalescing
behavior (same key inputs → same `event_id`) rather than asserting that
every possible pair of hook firings is either "always merged" or "always
distinct" — the correct outcome depends on which policy category above
applies to that event type.

**Dual-hook / dual-path cases, worked through the policy above:**

- **`order_created` classic-vs-block dual hook** (exact-identity category):
  key = `'order:' . $order->get_id()` regardless of which hook fired. Only
  one hook ever fires for a real checkout, but even a hypothetical
  double-fire produces the identical `event_id`.
- **`stock_threshold_crossed` dual code path** (`wc_trigger_stock_change_notifications()`
  order-driven path and `wc_trigger_stock_change_actions()` generic path can
  both fire for the same product reaching the same quantity): key =
  `'product:' . $id . ':' . $status . ':' . $stock_quantity` — state-keyed
  coalescing per the policy above; both firings for one quantity/status
  combination collapse to one `event_id` by design, whether they happen
  milliseconds or days apart.

### 5.3 `woocommerce.order_created`

- Hooks: `woocommerce_checkout_order_processed` (`$order_id, $posted_data, $order`),
  `woocommerce_store_api_checkout_order_processed` (`$order`).
- Idempotency key: `'order:' . $order->get_id()`.
- Fields:
  | Field | Classification | Type | Notes |
  |---|---|---|---|
  | `actor.user_id` | INTERNAL | int | `0` for guest |
  | `subject.order_id` | PUBLIC | int | |
  | `context.order_status` | PUBLIC | string | initial status |
  | `context.storage_backend` | INTERNAL | string | `hpos`\|`legacy`, diagnostic only |
  | `payload.order_total` | PUBLIC | float | |
  | `payload.currency` | PUBLIC | string | |
  | `payload.item_count` | PUBLIC | int | |
- `allowed_variable_fields`: all 7. `history_projection_fields`: `subject.order_id`,
  `context.order_status`, `payload.order_total`, `payload.currency`, `payload.item_count`.
- **No `payment_method` field**: an earlier draft included
  `payload.payment_method` (gateway id only, e.g. `stripe`) classified
  INTERNAL. It is removed entirely — not merely reclassified — from this
  and every other M03 event type. It is not required by the M03 charter,
  and its removal makes the "no payment method details" scope boundary
  unambiguous rather than relying on a gateway-id/gateway-token distinction
  that a reader could reasonably read as ambiguous. See §5.14.

### 5.4 `woocommerce.order_status_changed`

- Hook: `woocommerce_order_status_changed` (`$order_id, $status_from, $status_to, $order`) —
  the sole generic transition hook used; the per-status `woocommerce_order_status_{x}`
  family is deliberately **not** additionally bound to this same event type
  (that would double-fire for one transition). Per-status hooks are instead
  used for the two *dedicated* types below (§5.6, §5.7).
- Idempotency key: `'order:' . $order_id . ':' . $status_from . '->' . $status_to . ':' . $order->get_date_modified()->getTimestamp()`
  — attempt-window coalescing per §5.2 (second-resolution): two transitions
  between the same status pair completing within the same wall-clock second
  collapse to one event.
- Fields: `actor.user_id` (INTERNAL), `subject.order_id` (PUBLIC),
  `payload.status_from` (PUBLIC), `payload.status_to` (PUBLIC),
  `payload.order_total` (PUBLIC).
- `history_projection_fields`: `subject.order_id`, `payload.status_from`,
  `payload.status_to`, `payload.order_total`.

### 5.5 `woocommerce.payment_completed`

- Hook: `woocommerce_payment_complete` (`$order_id, $transaction_id`) —
  `$order_id` only; call `wc_get_order( $order_id )` for the rest. Fires only
  when a gateway/extension explicitly calls `WC_Order::payment_complete()`
  and the order's current status is in the valid-for-completion list — a
  known, accepted core limitation (some gateways set status directly without
  calling this method); `order_status_changed` remains available as the
  broader net for admins who need it.
- Idempotency key: `'order:' . $order_id . ':payment_complete'`.
- Fields: `subject.order_id` (PUBLIC), `payload.order_total` (PUBLIC),
  `payload.currency` (PUBLIC), `context.has_transaction_id` (PUBLIC, bool —
  **not** the transaction id itself, which is excluded entirely as
  gateway-format-ambiguous, potentially sensitive data). No `payment_method`
  field — removed entirely, see §5.3 and §5.14.
- `history_projection_fields`: `subject.order_id`, `payload.order_total`,
  `payload.currency`, `context.has_transaction_id`.

### 5.6 `woocommerce.order_failed`

- Hook: `woocommerce_order_status_failed` (`$order_id, $order, $status_transition`).
- Idempotency key: `'order:' . $order_id . ':failed:' . $order->get_date_modified()->getTimestamp()`
  — attempt-window coalescing per §5.2 (second-resolution).
- Fields: `subject.order_id` (PUBLIC), `payload.order_total` (PUBLIC),
  `payload.currency` (PUBLIC), `payload.status_from` (PUBLIC, from
  `$status_transition['from']`). No `payment_method` field — removed
  entirely, see §5.3 and §5.14.
- `history_projection_fields`: `subject.order_id`, `payload.order_total`,
  `payload.currency`, `payload.status_from`.
- **Semantics (why `order_failed`, not `payment_failed`)**: this type
  observes an order's status transitioning to WooCommerce's `failed` status
  via `woocommerce_order_status_failed`, part of the generic
  `woocommerce_order_status_{status}` hook family — it fires for **any**
  cause of that transition (a payment gateway reporting failure, a manual
  admin edit, or a third-party extension calling `set_status()`/
  `update_status()` directly), not exclusively a verified payment-gateway
  failure. WooCommerce core exposes no gateway-agnostic "payment attempt
  failed" signal distinct from the order-status transition itself (see §5
  research). The type is deliberately named and described as **"order
  entered failed status"** rather than `payment_failed`, so its Event
  Catalog description does not overstate the certainty of what caused the
  transition. Administrators who specifically want to distinguish
  gateway-driven failures from manual/extension-driven ones must do so
  outside this event type (M03 introduces no such distinction, since
  WooCommerce core does not expose one at the hook level).
- **Relationship to `order_status_changed`**: `woocommerce_order_status_changed`
  also fires for this same transition (it is WC's generic transition hook,
  fired for every status change). This is intentional and does **not**
  violate the charter's "no double-counting" constraint: that constraint
  targets the *same event type* firing twice via redundant instrumentation
  (e.g., a server hook and a duplicate browser script both reporting one
  add-to-cart) — not two independently-useful, differently-keyed,
  differently-named event *types* both triggered by one WordPress/WooCommerce
  action. See ADR-0018 Decision §5 for the full ruling. `order_cancelled`
  (§5.8) follows the identical pattern.

### 5.7 `woocommerce.order_cancelled`

- Hook: `woocommerce_order_status_cancelled` (`$order_id, $order, $status_transition`).
  (Confirmed via WooCommerce 11.0.1 source: no `woocommerce_cancelled_order`
  hook exists in core; this is the hook WC's own internals use for
  coupon-usage decrement and stock restoration.)
- Idempotency key: `'order:' . $order_id . ':cancelled:' . $order->get_date_modified()->getTimestamp()`
  — attempt-window coalescing per §5.2 (second-resolution).
- Fields: `subject.order_id` (PUBLIC), `payload.order_total` (PUBLIC),
  `payload.currency` (PUBLIC), `payload.status_from` (PUBLIC).
- `history_projection_fields`: all 4 (all PUBLIC).

### 5.8 `woocommerce.refund_created`

- Hook: `woocommerce_order_refunded` (`$order_id, $refund_id`) — chosen over
  `woocommerce_refund_created` (`$refund_id, $args`) because it gives both
  IDs directly without parsing `$args['order_id']`; fires after the refund's
  full DB write. `wc_get_order( $refund_id )` returns the `WC_Order_Refund`
  object if needed.
- Idempotency key: `'refund:' . $refund_id` — a refund id is freshly created
  per refund action via a single DB-write code path; no double-fire risk, so
  this key alone is sufficient.
- Fields: `subject.order_id` (PUBLIC), `subject.refund_id` (PUBLIC),
  `payload.refund_amount` (PUBLIC, float), `payload.currency` (PUBLIC).
  **`reason` deliberately excluded** — free text, potentially containing
  arbitrary/incidental PII, not classification-safe to include; simplest
  safe choice is omission, not masking.
- `history_projection_fields`: all 4 (all PUBLIC).

### 5.9 `woocommerce.stock_threshold_crossed`

- Hooks: `woocommerce_low_stock` and `woocommerce_no_stock` (both `$product`
  only), bound to the same emitter method, `status` derived from which hook
  fired (`low` / `out`).
- Idempotency key: `'product:' . $product->get_id() . ':' . $status . ':' . $product->get_stock_quantity()`
  — state-keyed coalescing per §5.2: this is not a complete audit log of
  every stock-notification hook firing. Two firings that observe the
  identical `(product_id, status, stock_quantity)` triple collapse to one
  event regardless of how much time separates them (including, in principle,
  a coincidental return to the same quantity/status combination on a later,
  unrelated occasion) — distinctness comes only from the quantity value
  differing, never from elapsed time alone. This is the intended trade-off
  that absorbs the order-driven vs. generic dual code-path duplication (see
  §5.2), accepted in exchange for not tracking additional per-notification
  state.
- Fields: `subject.product_id` (PUBLIC), `payload.status` (PUBLIC, enum
  `low`\|`out`), `payload.stock_quantity` (PUBLIC, int), `payload.product_sku`
  (PUBLIC, string — a catalog identifier, not PII).
- `history_projection_fields`: all 4 (all PUBLIC).
- **HPOS relevance**: none. Products/stock are never stored in the orders
  table, so HPOS enablement has zero effect on this emitter's correctness —
  `$product` object access is identical either way. This closes out the
  "HPOS compatibility" deliverable's stock-related scope with no
  storage-backend branching anywhere in `StockEventEmitter`.

### 5.10 `woocommerce.checkout_validation_failed` (classic checkout only — documented gap for blocks)

- Hook: `woocommerce_after_checkout_validation` (`$data, $errors` — `$errors`
  is `WP_Error`; the callback reads `$errors->get_error_codes()` and never
  mutates `$errors`, to avoid becoming a correctness risk in a shared,
  by-reference-mutated object).
- Idempotency key: `'checkout_validation:' . wp_hash( wp_json_encode( $errors->get_error_codes() ) ) . ':' . get_current_user_id() . ':' . floor( time() / 5 )` —
  coarse time-bucket coalescing per §5.2: any qualifying firings within the
  same 5-second bucket collapse to one event; a firing in a later bucket, or
  with a different error-code set, is treated as distinct. This is a bounded
  window chosen only because no stable pre-order "checkout attempt id"
  exists in WC core — it does not guarantee occurrence-level fidelity beyond
  that window (a slow retry landing in a new bucket is indistinguishable
  from a genuinely fresh attempt, and both are treated identically as
  "new").
- Fields: `actor.user_id` (INTERNAL, `0` for guest), `payload.error_codes`
  (PUBLIC, array of strings — WC's own stable machine error-code slugs, e.g.
  `billing_email_invalid`; **not** the free-text messages, which could echo
  user input), `context.checkout_type` (PUBLIC, hardcoded `'classic'`).
- `history_projection_fields`: `payload.error_codes`, `context.checkout_type`.
- **Documented gap**: no equivalent Store API/block-checkout hook exists in
  WooCommerce 11.0.1 core — block validation is exception/schema-based with
  no catch-all validation-failure action. Decision: do **not** attempt an
  unofficial Store API/REST-exception workaround (fragile against internal
  block-checkout refactors, relies on undocumented internals). This is
  accepted as a known, explicitly documented limitation, not a silent gap —
  see ADR-0018 Decision §7. It does not block the charter's "classic and
  block checkout compatibility" acceptance criterion, which concerns whether
  covered event types work correctly under both flows (they do — see
  `order_created`, `payment_completed`, etc.), not whether every diagnostic
  event type exists for both.

### 5.11 `woocommerce.cart_item_added`

- Hook: `woocommerce_add_to_cart` (`$cart_item_key, $product_id, $quantity,
  $variation_id, $variation, $cart_item_data`) — confirmed (WooCommerce
  11.0.1 Store API source) to fire identically for classic and Cart-block
  checkout; the Store API's `CartController::add_to_cart()` calls the same
  core hook rather than a separate block-specific one, so no dual-hook dedup
  concern exists for this type.
- Idempotency key: `'cart:' . WC()->session->get_customer_id() . ':' . $cart_item_key`
  — **line-identity coalescing per §5.2** (the most aggressive coalescing
  category in the catalog: no quantity or time component at all).
  `$cart_item_key` is WC's own deterministic hash of product+variation+item
  data; every add-to-cart call for the same cart line, across any number of
  subsequent quantity changes, produces the same key and therefore the same
  `event_id`.
- Fields: `actor.user_id` (INTERNAL, `0` for guest), `subject.product_id`
  (PUBLIC), `payload.quantity` (PUBLIC, int), `payload.variation_id`
  (PUBLIC, int, `0` if none), `payload.cart_total` (PUBLIC, float — enables
  the deferred "cart-value-threshold" use case via `GREATER_THAN`),
  `payload.currency` (PUBLIC). **Important**: because of the line-identity
  coalescing above, `payload.quantity` and `payload.cart_total` reflect the
  state captured at the **first** `woocommerce_add_to_cart` firing for that
  cart line — not the most recent quantity/total as of any later call to
  the same line (`EventHistoryRepository`'s `INSERT IGNORE` never overwrites
  an existing `event_id` row). An administrator building a cart-value
  threshold rule on this field should understand it reflects the cart total
  at the moment that specific line was first added, not a live running
  total updated on every subsequent change to that same line.
- `history_projection_fields`: `subject.product_id`, `payload.quantity`,
  `payload.variation_id`, `payload.cart_total`, `payload.currency`.

### 5.12 `woocommerce.coupon_applied`

- Hook: `woocommerce_applied_coupon` (`$coupon_code`, string only) —
  confirmed shared between classic and block checkout (same
  `WC_Cart::apply_coupon()` code path).
- Idempotency key: `'coupon_applied:' . WC()->session->get_customer_id() . ':' . strtolower( $coupon_code ) . ':' . floor( time() / 5 )`
  — coarse time-bucket coalescing per §5.2 (same 5-second-bucket caveat as
  §5.10 applies here).
- Fields: `actor.user_id` (INTERNAL), `subject.coupon_code` (PUBLIC — a
  merchant-defined promo code, not customer PII), `payload.cart_total`
  (PUBLIC, after the coupon applied).
- `history_projection_fields`: `subject.coupon_code`, `payload.cart_total`.

### 5.13 `woocommerce.coupon_rejected`

- Hook: `woocommerce_coupon_error` — **a filter, not an action**
  (`apply_filters( 'woocommerce_coupon_error', $message, $error_code, $coupon )`),
  confirmed in `WC_Discounts` source. The emitter callback is registered via
  `add_filter`, reads `$message`/`$error_code`/`$coupon`, calls
  `universal_telegram_emit_event()`, and returns `$message` **unmodified**.
  This is confirmed safe: `EventEmitter::emit()` is synchronous,
  non-throwing, in-process PHP — calling it from a filter callback is
  behaviorally no different from an action callback, and WC's own error
  display is unaffected since the filtered value passes through unchanged.
- Idempotency key: `'coupon_rejected:' . WC()->session->get_customer_id() . ':' . $error_code . ':' . floor( time() / 5 )` —
  coarse time-bucket coalescing per §5.2 (same 5-second-bucket caveat as
  §5.10 applies here); `$error_code` is WC's own stable integer
  coupon-error constant.
- Fields: `actor.user_id` (INTERNAL), `subject.coupon_code` (PUBLIC —
  `$coupon->get_code()` if `$coupon instanceof WC_Coupon`, else the attempted
  code string if available, else `''`), `payload.error_code` (PUBLIC, int).
  **`error_message` deliberately excluded** — may be a filtered/customized
  string from third-party code, not a stable machine value; `error_code`
  alone is sufficient for rule conditions and history.
- `history_projection_fields`: `subject.coupon_code`, `payload.error_code`.

### 5.14 No-PII / no-payment-sensitive-data confirmation

Across all 11 event types and every field listed in §5.3–§5.13, no field is
billing/shipping name, email, phone, address, IP address, payment method,
payment token, card data, gateway id, or gateway response body. The only
payment-adjacent field in the entire catalog is a boolean
`context.has_transaction_id` flag on `payment_completed` (§5.5) — never a
transaction id, token, gateway payload, or gateway identifier. An earlier
draft of this catalog included `payload.payment_method` (a gateway id
string, e.g. `stripe`, classified INTERNAL) on `order_created`,
`payment_completed`, and `order_failed`. It has been **removed entirely**
from all three — not reclassified — because it is not required by the M03
charter and its presence, even as a non-identifying gateway id, made the
"no payment method details" scope boundary read as ambiguous (a reader
could reasonably ask "isn't a gateway id itself a payment method detail?").
Removing it closes that ambiguity outright. Candidate fields that would have
required genuinely sensitive data (refund reason, validation error message
text, transaction id, coupon error message text) are similarly **omitted
from the envelope entirely**, not merely reclassified — consistent with the
charter's exclusion list and the `docs/master-plan.md`/planning-prompt
instructions being read as excluding these categories outright, not just
from history. This is structurally enforced by `EventEnvelope`'s fail-closed
validation (any field not present in the classification map is rejected at
construction) and additionally verified by `NoPiiFieldAuditTest` (§9).

---

## 6. Privacy, security, audit, diagnostics, retention, degraded-mode, and uninstall behavior

- **History**: every `history_projection_fields` list above is PUBLIC-only.
  `Registry::register()` fails closed (`NonPublicHistoryFieldException`, per
  ADR-0017) at registration time if this is ever violated — enforced
  structurally, not only by review. `EventHistoryRepository` is reused
  unchanged; no new table, repository, or migration.
- **No raw WC objects in envelopes**: every emitter method extracts
  individual scalar getter values (`$order->get_id()`, `$order->get_total()`,
  `$product->get_stock_quantity()`, etc.) into the flat envelope arrays —
  never a `WC_Order`/`WC_Product`/`WC_Coupon`/`WC_Order_Refund` object and
  never a `serialize()`d structure. `EventEnvelope`'s classification-map
  validation additionally cannot accept an object as a leaf value, providing
  a structural backstop.
- **Retention/uninstall**: M02's existing `RetentionCleanup` and uninstall
  routine operate on `EventHistoryRepository`/`DispatchLogRepository`
  generically by row age, not per event type — `woocommerce.*` rows are
  covered automatically with zero M03 changes.
- **No new DB migration**: confirmed achievable and required. M03 adds only
  `Registry::register()` calls (in-memory, per-request) and stateless,
  hook-bound emitter classes. Zero-migration deliverable.
- **Degraded mode**: WooCommerce absent/inactive → zero registration, zero
  runtime surface, no fatal errors (§4). WooCommerce present but a downstream
  persistence/queue failure occurs → `EventEmitter::emit()`'s existing
  never-throws contract (wraps envelope construction + dispatch in try/catch,
  reduces any failure to audit code `events.emission_failed`) already
  guarantees the WooCommerce request thread (checkout, stock update, etc.)
  is never blocked or errored by an event-system or Telegram-layer failure.
  Combined with M01's async queue boundary (`MessageDispatcher::send()` only
  enqueues an opaque `JobEnvelope` reference via Action Scheduler,
  fully decoupled from the request thread), this is the exact mechanism that
  satisfies the charter's "Telegram failures must never affect checkout"
  constraint — M03 does not invent a new safety mechanism, it relies on two
  already-frozen ones.
- **Diagnostics**: add two keys to the existing
  `Administration\Diagnostics\DiagnosticsReport::generate()` (constructor
  already receives `WooCommerceSupport $woocommerce_support` — confirmed by
  direct source read, no new constructor dependency needed):
  - `woocommerce_hpos_enabled` (bool) — via
    `\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()`,
    guarded by `class_exists(...)` / `$woocommerce_support->is_active()`
    (returns `false` when WC inactive, since the class won't exist).
  - `woocommerce_event_emitters_registered` (bool) — restates
    `$woocommerce_support->is_active()` under an explicit diagnostics label,
    since M03's gating is exactly 1:1 with WC activity; no separate state to
    track.
  No new page or class — this is the only diagnostics change, and it is the
  only minimal WooCommerce-specific diagnostic judged necessary.
- **Audit**: no new audit codes needed. `events.emission_failed` (existing,
  fixed) already covers any M03 emission failure identically to core
  emitters; no WooCommerce-specific audit code is warranted since failure
  handling is uniform across all event sources by design.

---

## 7. Administration behavior

Confirmed: **zero admin-UI code changes**. `EventCatalogPage`,
`RuleBuilderPage`, and `RuleSimulatorPage` all iterate `Registry::all()` /
`Registry::allowed_variable_fields_for()` generically — the moment M03's
emitters call `register()`, all three pages display and support the 11 new
`woocommerce.*` types automatically, with no code touched under
`src/Administration/Automations/`. The existing plugin-row Settings link
behavior is unaffected (no new settings page, no new top-level admin menu
item — only the diagnostics report body gains two new keys, §6).

---

## 8. Implementation work packages

Ordered for independent reviewability; per §11 timing rule, no
test/PHPCS/PHPStan run occurs per work package — validation is one full gate
at the end. **Governance note**: in actual milestone execution, ADR-0018
(WP0 below) must be committed and reviewed/approved *before* any of
WP1–WP7's code is written, per the milestone lifecycle's freeze-before-
implementation rule — it is listed first here to reflect that required real-
world ordering (unlike the design-narrative agent's draft, which listed it
last; this final plan corrects the ordering to match governance).

### WP0 — ADR-0018 and milestone/master-plan cross-references
- Objective: freeze the governing architecture document before any
  implementation code is written, per the milestone lifecycle.
- Files added: `docs/adr/0018-woocommerce-event-catalog-and-hook-binding.md`
  (full text, §10).
- Files modified: `docs/milestones/m03-woocommerce-event-coverage.md` (add a
  "Resolved via ADR-0018" cross-reference and one sentence resolving the
  ADR-0011/Vlad-evidence textual tension, §11); `docs/master-plan.md` §5.2
  (annotate excluded candidates — "order paid," "cart value threshold" — with
  "deferred, see ADR-0018").
- Migration impact: none.
- Tests added: none (documentation only).
- Commit message: `Add ADR-0018 (WooCommerce event catalog) and cross-reference M03 milestone/master-plan docs`
- Acceptance evidence: ADR contains all 8 required sections in the required
  order; Master Architect review and Product Owner approval recorded per
  governance; commit is code-free.

### WP1 — Order event emitter: order_created, order_status_changed
- Objective: stand up the emitter pattern and prove the classic/block
  dual-hook dedup design for the highest-value event.
- Files added: `src/Integrations/WooCommerce/Events/OrderEventEmitter.php`.
- Files modified: `src/Core/Plugin.php` (add the gated construction/wiring
  block from §4; initially just `OrderEventEmitter`).
- Migration impact: none.
- Tests added: `tests/integration/Integrations/WooCommerce/Events/OrderEventEmitterTest.php`
  (order_created + order_status_changed scenarios), and
  `tests/integration/Integrations/WooCommerce/StructuralGuardTest.php`
  (WP-only guard — can land now since it only needs the gating logic to
  exist).
- Commit message: `Add WooCommerce order-created and order-status-changed event emitters (M03)`
- Acceptance evidence: emitter registers only when WC active (structural
  guard test proves dormancy when absent); order_created dedup across
  classic/block hooks confirmed by test producing a single event_id.

### WP2 — Payment, cancellation, refund emitters
- Objective: complete order-lifecycle coverage.
- Files modified: `src/Integrations/WooCommerce/Events/OrderEventEmitter.php`
  (add `payment_completed`, `order_failed`, `order_cancelled`,
  `refund_created` registrations/methods; add shared
  `extract_order_fields( \WC_Order $order ): array` helper to avoid
  duplicating total/currency/item-count extraction across the six methods).
- Migration impact: none.
- Tests added: remaining scenarios in `OrderEventEmitterTest.php`.
- Commit message: `Add WooCommerce payment, cancellation, and refund event emitters (M03)`
- Acceptance evidence: each of the four types independently emits with
  correct fields; `order_failed`/`order_status_changed` dual-emission for
  one transition explicitly asserted (both fire, both recorded, distinct
  event_ids, per §5.6); `order_failed`'s Event Catalog description reads
  "order entered failed status," not "payment failed," matching its actual
  hook semantics.

### WP3 — Stock threshold emitter
- Objective: cover product stock events with the dual-code-path dedup
  design.
- Files added: `src/Integrations/WooCommerce/Events/StockEventEmitter.php`.
- Files modified: `src/Core/Plugin.php` (add to gated block).
- Migration impact: none (HPOS-irrelevant, per §5.9).
- Tests added: `tests/integration/Integrations/WooCommerce/Events/StockEventEmitterTest.php`
  (low/no-stock scenarios; dual-path dedup scenario simulating both
  `wc_trigger_stock_change_notifications()` and
  `wc_trigger_stock_change_actions()` reaching the same `(product, status,
  quantity)`).
- Commit message: `Add WooCommerce stock-threshold event emitter (M03)`
- Acceptance evidence: single event_id produced when both stock-notification
  code paths fire for the same occurrence.

### WP4 — Cart and coupon emitters
- Objective: cover cart-add and coupon apply/reject.
- Files added: `src/Integrations/WooCommerce/Events/CartEventEmitter.php`,
  `src/Integrations/WooCommerce/Events/CouponEventEmitter.php`.
- Files modified: `src/Core/Plugin.php` (add to gated block).
- Migration impact: none.
- Tests added: `CartEventEmitterTest.php`, `CouponEventEmitterTest.php`
  (including the filter-based `coupon_rejected` emission path, asserting the
  filter callback returns `$message` unmodified).
- Commit message: `Add WooCommerce cart and coupon event emitters (M03)`
- Acceptance evidence: `payload.cart_total` present and rule-condition-usable
  (asserted against `allowed_variable_fields_for()`); `coupon_rejected`'s
  filter contract verified non-mutating.

### WP5 — Checkout validation emitter (classic-only)
- Objective: cover the documented, scoped-down checkout-validation-failed
  signal.
- Files added: `src/Integrations/WooCommerce/Events/CheckoutEventEmitter.php`.
- Files modified: `src/Core/Plugin.php` (add to gated block).
- Migration impact: none.
- Tests added: `CheckoutEventEmitterTest.php` (classic validation-failure
  scenario; explicit assertion documenting no block-checkout binding exists
  for this class).
- Commit message: `Add WooCommerce checkout-validation-failed event emitter, classic checkout only (M03)`
- Acceptance evidence: `payload.error_codes` correctly extracted without
  mutating `$errors`; `context.checkout_type` is always `'classic'`.

### WP6 — Diagnostics line
- Objective: minimal operational visibility into M03 activation state.
- Files modified: `src/Administration/Diagnostics/DiagnosticsReport.php`
  (add `woocommerce_hpos_enabled`, `woocommerce_event_emitters_registered`
  to `generate()`'s return array and constructor docblock — no new
  constructor parameter needed, `WooCommerceSupport` is already injected).
- Migration impact: none.
- Tests added/modified: existing diagnostics-report test extended with
  WC-present and WC-absent assertions for the two new keys.
- Commit message: `Add WooCommerce activation and HPOS status to diagnostics report (M03)`
- Acceptance evidence: diagnostics correctly reports `false`/`false` when WC
  absent, and accurate HPOS state when WC present.

### WP7 — Cross-cutting safety/privacy tests
- Objective: consolidate the structural guarantees (no PII, checkout safety)
  into catalog-wide tests, rather than relying only on per-emitter spot
  checks.
- Files added:
  `tests/integration/Integrations/WooCommerce/Events/NoPiiFieldAuditTest.php`,
  `tests/integration/Integrations/WooCommerce/Events/CheckoutSafetyTest.php`.
- Files modified: none (extend `StructuralGuardTest.php` from WP1 only if a
  gap is found).
- Migration impact: none.
- Tests added: the two files above.
- Commit message: `Add cross-cutting WooCommerce event catalog privacy and checkout-safety tests (M03)`
- Acceptance evidence: `NoPiiFieldAuditTest` passes against the full
  11-type catalog (denylist scan of classification-map keys for
  email/phone/address/billing_/shipping_/token/payment_method/gateway-related
  substrings, with no exceptions — the catalog carries no `payment_method`
  or gateway-identifying field of any kind, per §5.14);
  `CheckoutSafetyTest` demonstrates `EventEmitter::emit()` never throws under
  a forced persistence/dispatch failure, fired from a real WooCommerce hook
  callback.

**Work-package test rationale (§9 of the design, consolidated here)**: no
live Telegram call is exercised in any M03 test. The
"Telegram failures cannot affect checkout" criterion is verified at the
`EventEmitter::emit()` boundary (never-throws contract) plus reliance on
M01's already-tested async queue decoupling — that boundary is M01's testing
responsibility, not re-tested here. HPOS correctness is verified via the
existing single WC-present CI job (WP 7.1 / WC 11.0.1, HPOS enabled by
default) plus code-level storage-agnosticity (`wc_get_order()` used
exclusively); no new CI matrix dimension is added — see §9.

---

## 9. Test strategy and requirements traceability

**New test location**: `tests/integration/Integrations/WooCommerce/Events/`
(WC-present config only, reusing the existing `UT_TEST_WC_ACTIVE` bootstrap
seam — `tests/integration/bootstrap.php` already installs WooCommerce via
`WC_Install::install()` at `init` priority -10 when that env var is set).
Plus one WP-only structural guard test at
`tests/integration/Integrations/WooCommerce/StructuralGuardTest.php`.

**New test classes** (one per emitter): `OrderEventEmitterTest.php`,
`StockEventEmitterTest.php`, `CartEventEmitterTest.php`,
`CouponEventEmitterTest.php`, `CheckoutEventEmitterTest.php`. Plus two
cross-cutting: `NoPiiFieldAuditTest.php`, `CheckoutSafetyTest.php`.

**Per-event-type scenarios** (applied per test class, tests are written to
verify the §5.2 coalescing policy as stated, not a stronger "every physical
occurrence gets a distinct event_id" guarantee that no formula in this plan
actually provides):
1. Hook fires → correct `event_type` and field values (via
   `EventHistoryRepository` round trip or a captured `EventEnvelope`).
2. Idempotency-key stability: fire the same hook twice with identical source
   data (including, for the bounded-coalescing types, the same coarse state
   — same status pair within the same second, same quantity/status
   combination, or a second `woocommerce_add_to_cart` call for the same
   cart line at a different quantity) → assert identical `event_id` /
   exactly one history row, and, for `cart_item_added` specifically, assert
   the recorded `payload.quantity`/`payload.cart_total` still match the
   *first* call's values, not the second call's. This is the *intended*
   coalescing behavior for those types, not merely an incidental pass.
3. Idempotency-key distinctness at the policy boundary (bounded-coalescing
   types only): fire the hook again with a *different* coalescing-relevant
   input (a different stock quantity for `stock_threshold_crossed`; a
   status pair one second apart for the status-transition types; a
   different error-code set, or the same set one 5-second bucket later, for
   `checkout_validation_failed`/`coupon_applied`/`coupon_rejected`; a
   different cart line — different product or variation — for
   `cart_item_added`) → assert a *distinct* `event_id`. This positively
   confirms distinctness comes from the stated key component (quantity,
   second-resolution timestamp, time bucket, or cart-line identity) and not
   from elapsed wall-clock time alone.
4. Duplicate suppression for the two dual-path cases (§5.2 — `order_created`
   classic-vs-block, `stock_threshold_crossed`'s two internal code paths),
   asserted directly.
5. HPOS order-retrieval correctness (`OrderEventEmitterTest` only): create a
   `WC_Order`, save it, fire the emitter, assert emitted fields match
   `wc_get_order( $id )`'s own getters.
6. No-PII field audit (`NoPiiFieldAuditTest`, run once against all five
   emitters' registrations collectively).
7. Telegram-outage-does-not-block-checkout (`CheckoutSafetyTest`, run once —
   the never-throws guarantee is a property of `EventEmitter::emit()` itself,
   not of any individual emitter's callback logic).

**HPOS CI matrix decision**: do **not** add a new CI matrix dimension. The
existing CI has exactly one WC-present job (WP 7.1 / WC 11.0.1 —
`bin/docker/test-integration-wc-present.sh`), and WC 11.0.1 defaults to HPOS
enabled for fresh installs. Combined with every M03 emitter accessing orders
exclusively via `wc_get_order()` (WooCommerce's own storage-agnostic
abstraction, confirmed HPOS-identical for every hook selected in §5 by the
official-source research underlying this plan), this single job is
sufficient evidence. Altering the frozen CI matrix structure is an
infrastructure decision orthogonal to M03's event-catalog scope and is left
to a future, separately-justified change if the Master Architect wants an
explicit legacy-CPT regression job.

**WordPress-only regression** (existing WP-only CI job, WC absent):
- Existing `tests/integration/Integrations/WooCommerceSupportTest.php`
  continues to pass unchanged.
- New `StructuralGuardTest.php` (WP1) boots the plugin with WC absent, then
  asserts `Registry::all()` contains zero `woocommerce.*` entries and
  `Registry::is_registered('woocommerce.order_created')` (and all ten other
  event types) returns `false` — the structural proof that §4's gating
  actually prevents registration, run in the environment where it matters.

### Requirements traceability

| Charter acceptance criterion | Verifying test(s) |
|---|---|
| Classic and block checkout both tested | `OrderEventEmitterTest` (classic + block order-creation scenarios); `CartEventEmitterTest` (shared hook covers both surfaces); `CheckoutEventEmitterTest`'s explicit assertion documenting the block-checkout validation gap |
| Order events work under HPOS | `OrderEventEmitterTest`'s HPOS order-retrieval-correctness scenario, run in the WC-present CI job (HPOS enabled by default at WC 11.0.1) |
| Telegram failures cannot affect checkout | `CheckoutSafetyTest` (WP7) |
| Server and browser events do not double-count | `OrderEventEmitterTest`'s dual-hook dedup scenario; `StockEventEmitterTest`'s dual-path dedup scenario; ADR-0018 Decision §5 documents why `order_failed` + `order_status_changed` co-firing is not a violation |
| All verified in WooCommerce-present test configuration | Every new test file lives under `tests/integration/Integrations/WooCommerce/Events/`, run only via the `UT_TEST_WC_ACTIVE`-gated bootstrap/CI job |
| Sensitive-field redaction (interpreted as field exclusion) | `NoPiiFieldAuditTest`; §5.14; per-type classification maps in §5.3–§5.13 need no SENSITIVE/SECRET level because sensitive data is excluded outright, not masked |
| Plugin remains fully functional with WooCommerce absent (charter notes this is verified by the WordPress-only configuration, not by M03 itself) | `StructuralGuardTest` (WP-only CI job) |

---

## 10. Proposed ADR text

Exactly one new ADR is required (next available number: **0018**).
Justification: the 11-type WooCommerce event catalog (13 hook/filter
bindings) and its hook-selection/dedup/gap decisions constitute "a public
contract" and "significant product behaviour with no prior precedent" under
ADR-0005's ADR-required criteria — new event types consumed by the Rule
Builder/Event Catalog/any future third-party rule author, plus several
non-obvious hook-selection judgment calls (classic-vs-block dual hooks, the
checkout-validation-failed block gap, the `order_failed`/`order_status_changed`
co-firing decision, the "order paid" exclusion, the `order_failed` naming
decision, and the bounded-coalescing idempotency policy) that the Master
Architect should ratify explicitly.
No second ADR is warranted for the checkout-validation block gap alone — it
is one field/hook-availability limitation, fully covered as a subsection of
this ADR's Decision/Alternatives/Consequences, not "significant product
behaviour with no prior precedent" on its own. Event identity (ADR-0015),
history projection (ADR-0017), and privacy classification (ADR-0009) are
reused unchanged and are **not** reopened by this ADR.

### ADR-0018: WooCommerce Event Catalog and Hook Binding for M03

File: `docs/adr/0018-woocommerce-event-catalog-and-hook-binding.md`

```markdown
# ADR-0018: WooCommerce Event Catalog and Hook Binding for M03

## Status

Proposed.

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
```

---

## 11. Final consistency checks

- **No new top-level module boundary**: confirmed — all new classes live
  under `Integrations\WooCommerce\Events` (subdomain of the existing
  `Integrations` boundary per ADR-0005); the 13-boundary list is unchanged.
- **No new queue job type**: confirmed — M03 never calls
  `Queue\Dispatcher::enqueue()` directly; all queue interaction remains
  inside M01's unchanged `NotificationDispatcher`/`MessageDispatcher` path,
  triggered indirectly via the unchanged `RuleEvaluator`.
- **No new public hooks beyond registration**: confirmed — M03 registers
  only on the existing `universal_telegram_register_event_types` action; no
  new `do_action`/`apply_filters` public extension point is introduced by
  this plugin (WooCommerce's own `woocommerce_coupon_error` filter is
  consumed, not introduced, by M03).
- **No parallel emission path**: confirmed — every emitter method calls the
  single global `universal_telegram_emit_event()` function; no M03 class
  directly constructs `EventEnvelope`/`EventDispatcher`.
- **No admin UI duplication**: confirmed §7 — zero changes to
  `EventCatalogPage`, `RuleBuilderPage`, `RuleSimulatorPage`; one minimal,
  additive change to `DiagnosticsReport` only.
- **No changes to frozen M01/M02 contracts**: confirmed — `Registry`,
  `EventEnvelope`, `EventEmitter`, `EventDispatcher`, `EventIdentity`,
  `RuleEvaluator`, `NotificationRuleRepository`, `TemplateRenderer`,
  `NotificationDispatcher`, `DispatchLogRepository`, `Queue\Dispatcher`,
  `MessageDispatcher` are read-only references throughout this plan; no work
  package touches `src/Events/*.php`, `src/Automations/*.php`,
  `src/Queue/*.php`, or `src/Telegram/Outbound/*.php`.
- **WordPress-only mode unaffected**: confirmed — `StructuralGuardTest` is
  the automated proof; §4's gating is the only integration point and it
  fails closed to "do nothing" when WooCommerce is absent.
- **No PII/payment-sensitive fields anywhere**: confirmed §5.14, verified by
  `NoPiiFieldAuditTest`.
- **Event/hook count consistency**: confirmed — §5.1's table lists 11 event
  types with a summed binding count of 13 (2 for `order_created` + 1 +1+1+1+1
  + 2 for `stock_threshold_crossed` + 1+1+1+1 = 13); §10's ADR-0018 Decision
  §1 names the same 11 types with the same 2 dual-binding call-outs; §8's
  work packages (WP1–WP5) collectively implement exactly those 11 types
  across 5 emitter classes; §9's traceability table and test-scenario list
  reference only these 11 types. No stray twelfth type or mismatched hook
  count remains anywhere in this document.
- **`order_failed` naming consistency**: confirmed — every reference to this
  event type in §5.1's table, §5.6, §8 (WP2), §9 (traceability), and §10
  (ADR-0018 Decision §5/§7/Alternatives) uses `woocommerce.order_failed`;
  the string `payment_failed` appears only inside explanatory naming-history
  text (§5.1, §5.6, §10 Decision §7 and Alternatives) that explicitly
  explains why that name was rejected — never as the type's actual name in
  a table, field spec, work package, or test reference.
- **Idempotency-policy consistency**: confirmed — §5.2 states the
  bounded-coalescing policy once, in full, across four categories
  (state-keyed, attempt-window, coarse time-bucket, and line-identity);
  §5.4, §5.6, §5.7, §5.9, §5.10, §5.11, §5.12, and §5.13 each cross-reference
  the applicable category rather than restating or contradicting it —
  `cart_item_added` (§5.11) was moved from the (now-removed)
  "exact-identity, no coalescing" list into the line-identity category, with
  its field description corrected to state first-emission semantics rather
  than "most recent as of this call"; §9's test scenarios (items 2–3) verify
  coalescing-within-bounds and distinctness-at-the-boundary, including the
  `cart_item_added` first-call-values assertion and different-cart-line
  boundary case, as the actual stated guarantee; ADR-0018 Decision §12 and
  its Alternatives entries record
  the same policy for governance sign-off. No section claims a stronger
  "every physical hook invocation gets a distinct event" guarantee than the
  key formulas actually provide.
- **No nested OR condition groups or generic webhook rule action proposed**:
  confirmed — zero changes proposed to `Automations\ConditionOperator`,
  `RuleEvaluator`'s condition-combination logic, or `NotificationDispatcher`'s
  delivery mechanism; the one rule-condition need identified (cart-value
  threshold) is satisfied with the existing single-operator, AND-only
  condition model and existing Telegram-only delivery, per
  `docs/future-scope.md`'s explicit deferral process (untouched by this
  plan).
- **ADR-0011 / charter Vlad-evidence tension**: the M03 charter text lists
  "Vlad's independent test focus" and "Vlad's completed acceptance report"
  as required evidence. Per ADR-0011, M00–M09 (including M03) are exempt
  from Vlad's separate manual acceptance session; required evidence is
  instead frozen plan + code review + mandatory automated validation + green
  CI. Decision, recorded here and to be added as one sentence to
  `docs/milestones/m03-woocommerce-event-coverage.md` in WP0: this charter
  language is treated as satisfied by the automated-evidence substitute
  ADR-0011 already defines (§9's full traceability table, WP-level
  acceptance evidence, and a green CI run) — not as requiring a literal
  separate Vlad session. This is a documentation-procedure clarification,
  not a scope or architecture change, and needs no ADR of its own since
  ADR-0011 already governs it.
- **No unresolved decisions**: every open question the charter and
  master-plan leave implicit (order-paid vs payment-completed, the
  `order_failed` naming and its "any cause of transition" semantics,
  cart-value threshold, block-checkout validation gap, dual-hook/dual-path
  dedup formulas, the bounded-coalescing idempotency policy and its
  boundaries, exact field classifications, HPOS CI-matrix scope,
  Vlad-evidence interpretation) is resolved above with a stated decision and
  justification.
- **Validation/testing held to the end**: confirmed — no work package in §8
  includes a "run phpcs/phpstan/tests now" step. The single final local
  validation gate, run once after WP0–WP7 are all complete, mirrors CI order
  exactly: `composer install` → `phpcs` → `phpstan` → unit tests (PHP
  8.1/8.3/8.4 matrix) → integration WP-only (floor WP 6.9/PHP 8.1, current
  WP 7.1/PHP 8.3) → integration WC-present (WP 7.1 / WC 11.0.1) →
  `build-zip` → package-acceptance (3-way matrix). If failures occur, only
  the failed/directly affected checks are rerun after fixes, followed by one
  final complete matrix run. GitHub Actions on the PR and merge commit
  provide the independent full validation; this changes timing only, not
  test coverage or quality standards.

---

## Critical files referenced throughout this plan

- `src/Core/Plugin.php`
- `src/Events/Registry.php`
- `src/Events/EventEmitter.php`
- `src/Events/EventEnvelope.php`
- `src/Events/EventIdentity.php`
- `src/Integrations/WooCommerce/WooCommerceSupport.php`
- `src/Administration/Diagnostics/DiagnosticsReport.php`
- `tests/integration/bootstrap.php`
- `tests/integration/Integrations/WooCommerceSupportTest.php`
- `docs/adr/0005-composition-root-and-product-module-boundaries.md`
- `docs/adr/0009-privacy-classification-and-redaction-model.md`
- `docs/adr/0011-deferred-formal-acceptance-testing-until-m10.md`
- `docs/adr/0015-event-model-catalog-and-emission-contract.md`
- `docs/adr/0017-event-history-public-only-redacted-projection.md`
- `docs/milestones/m03-woocommerce-event-coverage.md`
