# M08.1 — Friendly Rule Builder and Notification Presets (Plan v1)

Baseline: `main` @ `27752e05697fb81a3341a121621c59aacb5ded77` (2026-08-24). Known red CI on
`main` (exploratory M11/M07.1 merge) — this plan does not repair it and does not treat the
baseline as validated. Planning only; no repository changes made while drafting this document.

## Context

`RuleBuilderPage::render_rule_form()` (`src/Administration/Automations/RuleBuilderPage.php:284`)
requires selecting a raw `event_type` string, typing a JSON conditions array by hand, and knowing
`{{payload.order_total}}`-style template tokens. `RuleBuilderRequestHandler::save_rule()`
(`src/Administration/Automations/RuleBuilderRequestHandler.php:69`) trusts nothing from the client:
`NotificationRuleRepository::save()` is the sole authority validating condition fields against
`Registry::allowed_variable_fields_for()`, and `TemplateRenderer` independently re-validates every
token at render time. That authoritative boundary is preserved unchanged — this milestone replaces
only what the admin sees and types, translating friendly selections into the exact same
`(event_type, conditions[], template, bot_id, destination_id, enabled, priority,
cooldown_seconds)` shape the repository already accepts.

Two UI-facing catalogs already exist and are reused, not rebuilt: `EventCatalogLabels`
(`src/Administration/Automations/EventCatalogLabels.php`) maps technical event types and field
paths to plain-language labels, and `DigestEligibility::eligible_destinations_for_bot()` /
`destination_is_eligible()` (`src/Automations/Digest/DigestEligibility.php:169,203`) already
implement the "never a conversation-linked destination" eligibility rule reused elsewhere (e.g.
`RuleBuilderPage::render_bot_destination_pair()`).

### One resolved architectural gap

The task requires an "All conditions must match" / "Any condition may match" toggle. The current
engine is deliberately AND-only: `RuleEvaluator::rejection_reason()`
(`src/Automations/RuleEvaluator.php:135`) rejects on the first non-matching clause, and ADR-0016
names "AND-only conditions" as an explicit, accepted decision. This is a genuine, load-bearing
engine change, not a UI-only concern — it is resolved here as an additive extension (new
`match_mode` column, default `'all'`, every existing/legacy rule unaffected) via **ADR-0032**
(full text below), which supersedes only the AND-only clause of ADR-0016; nothing else in ADR-0016
(dispatch idempotency, seven-state log, claim/reject mechanism) changes. The same ADR also covers
adding three operators the friendly operator matrix requires that don't exist yet
(`not_contains`, `at_least`, `at_most`) as an additive extension of `ConditionOperator`'s closed
enum — still a fixed, closed set, no expression language introduced.

No other open decisions remain; see "Resolved design choices" below for the rest.

### No new diagnostic surface

An earlier draft of this plan proposed a `RuleDiagnosticsPage` and a new Hub tab exposing raw
`conditions_json`. That is removed: it is scope creep against the "no technical knowledge
required" goal, and nothing in the required UX depends on it. Legacy rules whose conditions the
visual builder cannot represent stay safely preserved and are shown read-only inline in the Add
Rule/Edit screen itself (see "Existing-rule compatibility strategy"), not on a separate page. If a
genuine support/debugging need for raw rule inspection emerges later, it is a separate,
explicitly-scoped milestone.

## Current → new Add Rule screen mapping

| Current | New |
|---|---|
| Free `event_type` `<select>` of raw identifiers | §1 preset cards, then §2 grouped friendly event picker (uses `EventCatalogLabels::event_type_label()`) |
| `conditions_json` textarea | §3 visual condition-row builder (field label + typed operator + value input) + All/Any radio |
| `bot_id` / `destination_id` selects (unchanged mechanism) | §4, same selects, relabelled, WooCommerce/eligibility-filtered |
| `template` free textarea | §5 friendly editor + field-insert menu + non-sensitive preview |
| `priority` / `cooldown_seconds` / `enabled` bare inputs | §6 plain-language toggle + optional advanced disclosure |
| No preset concept | §0 preset cards (individual + Store essentials starter set) |

## Friendly labels

**Event families** (grouping only; membership derived from existing `event_type` prefixes/list,
no registry change):

- Website and users: `wordpress.login_succeeded`, `wordpress.admin_login`,
  `wordpress.login_failed`, `wordpress.user_registered`, `wordpress.user_role_changed`,
  `wordpress.password_reset`, `wordpress.post_published`, `wordpress.comment_submitted`,
  `wordpress.plugin_activated`, `wordpress.plugin_deactivated`, `wordpress.update_available`,
  `wordpress.update_completed`
- Store orders and payments (WooCommerce-only): `woocommerce.order_created`,
  `woocommerce.order_status_changed`, `woocommerce.payment_completed`,
  `woocommerce.order_failed`, `woocommerce.order_cancelled`, `woocommerce.refund_created`
- Stock and checkout (WooCommerce-only): `woocommerce.stock_threshold_crossed`,
  `woocommerce.cart_item_added`, `woocommerce.coupon_applied`, `woocommerce.coupon_rejected`,
  `woocommerce.checkout_validation_failed`
- Website health: `wordpress.scheduled_task_failed`, `wordpress.rest_request_failed`,
  `wordpress.email_sending_failed`, `wordpress.fatal_error`
- Visitor activity: `visitor.session_started`, `visitor.page_viewed`, `visitor.navigation`,
  `visitor.search_performed`, `visitor.javascript_error`, `visitor.product_viewed`,
  `visitor.add_to_cart_intent`, `visitor.checkout_started_intent`. `visitor.click` is
  intentionally excluded from this family's picker (task requirement); `EventCatalogLabels`
  keeps its existing label entry for other read paths (Events tab, history) — no removal there.

Event/field labels: reuse `EventCatalogLabels::event_type_label()` /
`::field_label()` verbatim; no new label source of truth. Full existing tables are the appendix
(already in `src/Administration/Automations/EventCatalogLabels.php:22-115`, unchanged).

### Field type metadata (new — required for the operator matrix, doesn't exist today)

New `FieldTypeCatalog` (UI-only, `Administration\Automations`), a static, explicit map from field
path → `{type: text|number|money|boolean|choice, label, operators: [...], preview_value,
choice_options?}`. Every one of the four properties (type, label, permitted-operator list, preview
value) is required and hand-authored per field; there is no derived or generic fallback. A field
is **fail-closed**: if it is not a complete entry in `FieldTypeCatalog`, it simply does not appear
in the condition builder's field picker or the message field-insert menu, regardless of whether
`Registry::allowed_variable_fields_for()` permits it for that event. `EventCatalogLabels`' own
generic `humanize_field_path()` fallback is explicitly **not** reused here — that fallback exists
for the (already-labelled, lower-stakes) read-only Events tab, not for a UI that lets an admin
pick operators for a field. Cataloguing a new field is a deliberate, reviewed addition to
`FieldTypeCatalog`, not an automatic side effect of the engine allowing it. This class performs no
server-side validation of its own; `NotificationRuleRepository::save()` and
`RuleEvaluator::rejection_reason()` remain the sole authority and are unaffected by what is or
isn't catalogued here — a field omitted from the UI is simply unreachable through it, never
insecurely accepted.

A dedicated coverage test (WP2, see below) enumerates every currently-catalogued field and asserts
it is (a) a member of `allowed_variable_fields_for()` for at least one event type, and (b) fully
specified — non-empty label, non-empty operator list drawn only from the fixed
`ConditionOperator`-mapped set, and a defined preview value. The initial `FieldTypeCatalog`
population for this milestone covers every field already listed in
`EventCatalogLabels::FIELD_LABELS` (`src/Administration/Automations/EventCatalogLabels.php:64-115`)
plus `actor.user_id`/`actor.user_login` and any other field an included preset or manual example
in this plan references — i.e. full coverage of the existing catalog, not a partial subset.

### Operator matrix

| Field type | Friendly operator | `ConditionOperator` |
|---|---|---|
| text | is / is not / contains / does not contain | `equals` / `not_equals` / `contains` / `not_contains` (new) |
| number / money | is / is not / greater than / less than / at least / at most | `equals` / `not_equals` / `greater_than` / `less_than` / `at_least` (new) / `at_most` (new) |
| boolean | is yes / is no | `equals` (value `true`/`false`) / `not_equals` |
| choice | is / is not | `equals` / `not_equals`, value from the fixed option list only |

### Safe comparison semantics (absent-field and empty-condition behavior)

The engine's current per-clause check already discards a clause outright if `$field` isn't a
string in the allowlist (`RuleEvaluator::rejection_reason()`), but does not otherwise special-case
a *present-in-allowlist, absent-on-this-event* field — `EventEnvelope::value_at()` simply returns
`null` for it, which today only `equals`/`not_equals` treat sensibly via `loose_equals_static()`.
This plan fixes that ambiguity explicitly, since "is not" / "does not contain" against a genuinely
absent field must not silently read as true:

- **A condition never matches when its own field is absent from the event** (`value_at()` returns
  `null`) — this applies uniformly, including `not_equals`/`is not` and `not_contains`/`does not
  contain`. Absence is never treated as "the value differs," only as "cannot evaluate," so it is
  always a non-match for that clause.
- **An empty condition list always matches** (unchanged from current behavior — an empty
  `foreach` in `rejection_reason()` already returns `null`).
- **`match_mode='any'` with one or more conditions matches only if at least one clause whose field
  is actually present on the event evaluates true.** A clause on an absent field never counts as
  the match that satisfies "any"; if every clause's field is absent, the rule does not match.
- **`match_mode='all'`** is unchanged: every clause must both have a present field and evaluate
  true (this already follows from the existing single-non-match-rejects logic once absence is
  itself always a non-match).

## ADR-0032 (full text — required, extends ADR-0016)

```markdown
# ADR-0032 — Any-Match Condition Mode and Three Additional Fixed Operators

## Status
Proposed during planning. The plan-freeze commit materializes this approved plan and changes this
ADR's status to Accepted, before any implementation work package begins — Accepted status reflects
the design decision being locked in, not that its tests have already run. This draft plan states
its text in full now so the design is fixed and reviewable ahead of that freeze.

## Context
M08.1 requires a plain-language rule builder offering "all conditions must match" and "any
condition may match" modes, plus is/is-not/at-least/at-most/does-not-contain operators that map
cleanly onto typed fields. ADR-0016 fixed evaluation as AND-only and named a closed six-operator
enum. Both were deliberate M02 decisions, not oversights, but neither constraint is required by
this milestone's actual architectural concerns: dispatch idempempotency (event_id + rule_id
uniqueness), the seven-state dispatch log, and the claim/reject mechanism are all independent of
how a rule's own conditions combine or how many comparison operators exist.

## Decision
`notification_rules` gains a `match_mode` ENUM('all','any') NOT NULL DEFAULT 'all' column
(migration step 30). Every existing row defaults to 'all', preserving current AND-only behavior
exactly; RuleEvaluator's rejection logic is unchanged for match_mode='all'. For 'any',
`RuleEvaluator::rejection_reason()` returns null (match) as soon as one clause matches, and
'condition_not_matched' only if none do — still short-circuiting, still no nesting, still a flat
clause array. `ConditionOperator` gains three cases — `NOT_CONTAINS`, `AT_LEAST` (`>=`),
`AT_MOST` (`<=`) — evaluated with the same never-throws, false-on-incomparable semantics as the
existing six. The enum remains closed; no expression syntax, scripting, or per-field custom
comparator is introduced.

A clause whose field is absent from the event (`EventEnvelope::value_at()` returns `null`) never
matches, for every operator without exception — including `not_equals` and the new
`not_contains`, where absence must not be conflated with "differs from." `match_mode='any'`
matches only if at least one clause with a present field evaluates true; a rule whose every clause
targets an absent field does not match under `any` either. An empty condition list continues to
always match, unchanged.

## Alternatives
- Nested condition groups (AND-of-ORs) — rejected; the flat single-mode toggle covers every
  preset and manual case this milestone requires, and nesting reopens exactly the complexity
  ADR-0016 correctly avoided.
- A separate `notification_rules_v2` table — rejected; an additive nullable-defaulted column on
  the existing table is sufficient and keeps one source of truth.
- Mapping "does not contain" / "at least" / "at most" onto existing operators client-side only
  (e.g. `at_least` as `greater_than` with value-1) — rejected; it silently breaks for non-integer
  money/decimal fields and produces a stored condition that doesn't say what it means.

## Consequences
Legacy rules (no explicit match_mode at write time) evaluate identically to before this change.
DispatchLogRepository and the seven dispatch-log states are untouched. RuleSimulator inherits the
absent-field and any-mode semantics automatically, since it evaluates through the same
`RuleEvaluator` code path (`RuleSimulator.php:94` subclasses it, not reimplements it); its own
test gains scenarios simulating an event that omits an allowed field, confirming the simulated
preview correctly shows non-match rather than a false positive. `ConditionOperatorTest` gains
cases for the three new operators and for absence-with-`not_equals`/absence-with-`not_contains`;
`RuleEvaluatorTest` gains match_mode='any' coverage including the all-clauses-absent case.

## Security and privacy impact
None: `match_mode` is not a data field and carries no classification; the three new operators
operate only on fields already permitted by the existing per-event-type allowlist enforced by
`Registry::allowed_variable_fields_for()`, unchanged.

## Affected Documents/Milestones
`docs/adr/0016-notification-rule-engine-storage-evaluation-and-delivery-idempotency.md` — this ADR
supersedes only its "AND-only conditions" and "fixed six-operator enum" clauses; the seven-state
dispatch model, claim/reject mechanism, and idempotency guarantees it documents are unaffected and
remain governing.

## Compatibility/Migration Impact
Migration step 30 (see `docs/adr/0007`'s framework): `ALTER TABLE ... ADD COLUMN match_mode ...
DEFAULT 'all'`, additive only, no backfill needed beyond the column default. No existing table
column removed or retyped.
```

## Version / database recommendation

- **Database**: one migration step (30, target_version 29→30) adding `match_mode` to
  `universal_telegram_notification_rules` per ADR-0032. This is the only schema change; presets,
  labels, and the friendly UI carry no persistence of their own (a preset is a client-side
  starting point for the existing save flow, never a new stored entity — task requirement).
- **Plugin version**: bump to `0.15.0` (current `0.14.1`) — a minor feature milestone with a
  additive schema change, following existing precedent (M11A/M11B each bumped minor).

## Preset catalogue

Each preset is a **starting configuration**, not an auto-created rule: selecting one fills the
builder's own §2–§6 fields (pre-selected event, default conditions, default message, default
match_mode 'all'); the admin must still choose bot/destination and press Save. `enabled` defaults
per-preset as noted; none silently activate.

Messages use clear, professional plain text with no emoji — a neutral default appropriate for
every site; an admin who wants emoji can add them manually in the message editor (task
requirement).

| Preset | Event type | Default conditions | Default message | WooCommerce absent |
|---|---|---|---|---|
| New WooCommerce order | `woocommerce.order_created` | none | "New order #{{subject.order_id}} — {{payload.order_total}} {{payload.currency}}." | hidden |
| Payment completed | `woocommerce.payment_completed` | none | "Payment received for order #{{subject.order_id}}." | hidden |
| Order failed | `woocommerce.order_failed` | none | "Order #{{subject.order_id}} failed." | hidden |
| Order cancelled | `woocommerce.order_cancelled` | none | "Order #{{subject.order_id}} was cancelled." | hidden |
| Refund created | `woocommerce.refund_created` | none | "Refund issued: {{payload.refund_amount}} for order #{{subject.order_id}}." | hidden |
| Low-stock alert | `woocommerce.stock_threshold_crossed` | none | "{{payload.product_sku}} stock is low: {{payload.stock_quantity}} left." | hidden |
| Checkout problem detected | `woocommerce.checkout_validation_failed` | none | "A customer encountered a checkout problem: {{payload.error_codes_csv}}." | hidden |
| Successful administrator login | `wordpress.admin_login` | none | "Administrator login: {{actor.user_login}}." | shown |
| Failed login attempt | `wordpress.login_failed` | none | "Failed login attempt for username {{context.username}}." | shown |
| New user registered | `wordpress.user_registered` | none | "New user registered: account #{{subject.user_id}}." | shown |
| Website fatal error | `wordpress.fatal_error` | none | "A website error occurred ({{payload.error_type}})." | shown |
| Scheduled task failed | `wordpress.scheduled_task_failed` | none | "Scheduled task failed: {{payload.hook}}." | shown |
| Email sending failed | `wordpress.email_sending_failed` | none | "An outgoing email failed to send." | shown |
| Website API request failed | `wordpress.rest_request_failed` | condition: `payload.status` at_least `500` | "API request failed: {{payload.route}} ({{payload.status}})." | shown |
| Visitor viewed a product | `visitor.product_viewed` | none | "A visitor viewed a product." | hidden (WooCommerce-only visitor event) |
| Visitor started checkout | `visitor.checkout_started_intent` | none | "A visitor opened checkout." | hidden |

**Store essentials starter set** (offered only when WooCommerce is active; not offered when
WooCommerce is inactive, since all three depend on WooCommerce events) — a guarded two-step flow,
never a single click that writes rows:

1. Clicking the "Store essentials" card opens a dedicated review screen (not the normal Add Rule
   form) listing the three rules — New WooCommerce order, Order failed, Low-stock alert — each
   with its fixed default message shown in full. The admin selects **one bot and one eligible
   destination**, applied to all three (the same `BotDestinationPairFields` /
   `DigestEligibility`-filtered selects used elsewhere); no per-rule destination choice, keeping
   the flow to a single decision.
2. The review screen requires an explicit second confirmation action (its own "Create draft
   rules" submit button, separate from simply landing on the page) before anything is written.
3. Confirming creates exactly three rules via `NotificationRuleRepository::save()`, each with
   `enabled = false`, the chosen bot/destination, and a name suffixed `(draft)`, so they appear in
   the existing rule list for review before the admin enables them individually.
4. If the admin selects no bot/destination and attempts to confirm, the request is rejected with
   an inline error and nothing is created — the flow cannot produce a rule with an invalid or
   missing destination (task requirement: "cannot create incomplete rules").

This is implemented as its own `op=create_starter_set` branch in `RuleBuilderRequestHandler`
(step-1 review render is a `GET`-triggered partial in `RuleBuilderPage`, not a POST; only the
final confirmation is a POST, matching the existing POST-only-mutation rule) — never a variant of
the individual-preset "fills the builder" pattern, since it must atomically apply one
bot/destination choice to three rules.

All preset conditions reference only fields already in that event type's
`allowed_variable_fields` (verified against `Registry` entries read during exploration); the
"Website API request failed" preset is the only one with a non-empty default condition, matching
its "detected" framing in the task's naming.

## Existing-rule compatibility strategy

`RuleEditor::from_existing()` (new, see work packages) attempts to render a legacy
`NotificationRule` through the friendly builder:

1. Event type → §2 selection if a friendly label/family exists (all current event types do).
2. Each condition clause → a §3 row if its `field` is in `FieldTypeCatalog` and its `operator`
   maps to a friendly operator for that field's type (all current + new operators do).
3. Template → §5 editor; any `{{token}}` not in the field-insert menu for the selected event
   still round-trips as literal text (the editor never strips unrecognized tokens).
4. If any condition clause fails step 2 (unmapped operator/field, or a field not fully catalogued
   in `FieldTypeCatalog` per the fail-closed rule above — not currently possible for a
   server-accepted rule given the closed enum and allowlist, but kept as a safety net for a field
   the engine allows before this milestone's UI has catalogued it, or for a future engine change),
   the entire condition set is rendered read-only in place, inside the Edit screen itself, with
   the exact stored JSON shown in a `<details>`-disclosed, clearly labelled "This rule's
   conditions were created with a format the visual builder cannot display; they still apply
   exactly as saved" block. The admin can still edit name/destination/template/delivery options
   and save — the unrepresentable conditions are round-tripped byte-for-byte (never mutated by an
   editing pass that didn't touch them) — but cannot add/remove condition rows while that block is
   showing.
5. No JSON textarea, and no separate raw-rule administration page, exists anywhere in this
   milestone (see "No new diagnostic surface" above). The read-only fallback block in point 4 is
   the only place stored JSON is ever shown, and it appears only for the specific rule that needs
   it, inline in that rule's own Edit screen — never as a general-purpose browsing surface.

## Resolved design choices (no open items)

- **Preset activation**: never auto-saves; always requires explicit review + Save (task
  requirement, reconfirmed against Telegram Notify's rejected "uncontrolled checkbox" model).
- **All/any UI**: two radio buttons above the condition rows, defaulting to "All conditions must
  match" (`match_mode='all'`), matching current behavior for anyone who adds no conditions or one
  condition (mode is moot with 0–1 rows either way).
- **Money vs number**: both use the same operator set; `FieldTypeCatalog` still distinguishes them
  only so the value `<input>` can render a currency-suffixed number field for money (display only,
  no new validation).
- **WooCommerce-inactive event picker**: the "Store orders and payments" and "Stock and checkout"
  families render as disabled/greyed groups with the text "Requires WooCommerce, which is not
  currently active on this site" (`WooCommerceSupport::is_active()`, reused unchanged), not
  omitted entirely — so an admin understands why they're missing rather than assuming a bug.
- **Field-insert menu**: inserts the literal `{{payload.order_total}}` token text at the cursor in
  the message textarea via a small vanilla-JS helper (no build step, matches "no SPA/React"
  requirement); the token syntax is visible once inserted (task only requires it not be the
  *primary* interaction, not that it be hidden after insertion) but the menu itself shows only
  friendly labels ("Order ID", "Order total", ...).
- **Preview definition**: labelled exactly "Example notification preview" in the UI. Rendered
  entirely client-side-triggered-server-render (a small AJAX/admin-ajax call is acceptable, or a
  pure PHP render on page load — implementation detail left to WP4) using
  `TemplateRenderer::render()` unchanged, fed a synthetic, hand-built `EventEnvelope` constructed
  solely from `FieldTypeCatalog`'s fixed `preview_value` entries (e.g. order total → "49.90",
  currency → "EUR", order id → "1042") — reusing the production rendering/escaping path so the
  preview reflects real MarkdownV2 escaping and real "disallowed field renders empty" behavior,
  while never touching `EventHistoryRepository`, `NotificationRuleRepository`, `wp_get_current_user()`,
  visitor/order/user tables, or any Telegram API call. This is enforced as a testable constraint:
  the preview code path takes only a template string and the event type's allowed-field list as
  input, has no database or HTTP client dependency injected, and WP4's test asserts this by
  constructing it with doubles that throw if touched.

## Page wireframe / section order

A single scrollable page inside the existing `rules` Hub tab (no new tab). Top-level state is
either "browsing presets" or "editing a rule" (custom or from a preset/edit action) — never both
visible at once, so the page never feels cluttered:

```
[ Existing rules list — unchanged table, now with an Edit action per row ]

──────────────────────────────────────────────────────────
  Add rule
──────────────────────────────────────────────────────────
  ┌ Start with a common notification ──────────────────┐
  │  [card] [card] [card] [card] ...                    │   ← WP-native postbox/card styling,
  │  [ Store essentials starter set ]  (if WooCommerce)  │     generous spacing, one-sentence
  │  [ Create a custom notification ]                    │     description per card, visible
  └──────────────────────────────────────────────────────┘     hover/selected state
                        │ (card or "custom" clicked)
                        ▼
  ┌ Builder (preset-filled or blank) ────────────────────┐
  │  « Back to presets                                   │   ← always present once past §1
  │                                                       │
  │  1. When this happens                                │
  │     [ family-grouped event picker ]                  │   short help text under the field,
  │     (WooCommerce-only families shown disabled with    │   not a wall of text
  │      "Requires WooCommerce..." when inactive)         │
  │                                                       │
  │  2. Only when…                                        │
  │     [ + Add a condition ]  ← empty state: no rows     │   progressive: rows only appear
  │     shown at all until clicked; once ≥1 row exists:    │   after this is clicked
  │       ( ) All conditions must match                   │
  │       ( ) Any condition may match                     │
  │       [ field ▾ ] [ operator ▾ ] [ value ] [ Remove ] │
  │       [ + Add another condition ]                     │
  │                                                       │
  │  3. Send notification to                              │
  │     [ Bot ▾ ]  [ Destination ▾ ]                       │
  │                                                       │
  │  4. Message                                            │
  │     [ Insert field ▾ ] (friendly labels only)          │
  │     [ message textarea ]                              │
  │     Example notification preview: <rendered text>     │
  │                                                       │
  │  ▸ Delivery options (collapsed by default)             │   collapsed disclosure, plain-
  │      [x] Enabled                                       │   language labels only
  │      Do not send repeated notifications more often     │
  │      than [ ___ ] minutes                              │
  │                                                       │
  │  [ Save rule ]                                         │
  └──────────────────────────────────────────────────────┘
```

Requirements carried into WP3–WP7: WordPress-native admin components only (`postbox`,
`form-table`, `button`/`button-primary`, `notice`) — no new design system, CSS framework, or
custom component library; cards show an obvious selected/focused state (WP's existing focus-ring
and `is-selected`-style class conventions); an empty condition list renders no rows and no
All/Any control at all (a zero-state, not a confusing pre-selected radio pair); the "« Back to
presets" link/button is present on every builder view, including when reached via Edit on an
existing rule; delivery options stay behind the collapsed disclosure on every visit, never
auto-expanded.

## Class/file/module design

New files (`Administration\Automations` namespace, mirroring existing page/handler split):

- `FieldTypeCatalog.php` — static, explicit per-field map: type, label, operator list, preview
  value, and choice options where applicable (see "Field type metadata" above). Fail-closed: a
  field absent here is absent from the UI.
- `ConditionRowRenderer.php` — renders one condition row's field/operator/value controls from
  `FieldTypeCatalog` + `Registry::allowed_variable_fields_for()`; pure rendering, no state.
- `PresetCatalog.php` — the table above as PHP data (event type, default conditions array,
  default template, woo-required flag); `PresetCatalog::starter_set()` for the three-rule bundle.
- `RuleEditor.php` — translates a `NotificationRule` (or POSTed friendly-builder fields) to/from
  the existing `(event_type, conditions[], template, ...)` shape `NotificationRuleRepository::save()`
  already accepts; `from_existing()` implements the compatibility strategy above.
- `RuleBuilderPage.php` — `render_rule_form()` rewritten to call `PresetCatalog`,
  `ConditionRowRenderer`, `FieldTypeCatalog`; `render_rule_list()` gains an "Edit" action routing
  through `RuleEditor::from_existing()`; gains the Store-essentials review-screen render (§ above).
  No new Hub tab is added — everything above lives inside the existing `rules` tab.
- `RuleBuilderRequestHandler.php` — `save_rule()` gains a small translation step (friendly
  condition-row POST fields → the same `conditions[]` shape) ahead of the existing, unchanged
  `$this->rules->save(...)` call; `InvalidConditionFieldException` handling unchanged.
- Engine (ADR-0032): `ConditionOperator.php` (+3 cases), `RuleEvaluator.php`
  (`rejection_reason()` branches on match_mode), `NotificationRule.php` (+`match_mode()`),
  `NotificationRuleRepository.php` (+match_mode param, save/hydrate), `Migrator.php` (step 30,
  `target_version()` → 30).

No new build pipeline, no JS framework — one small vanilla-JS file (condition row add/remove,
field-insert menu) enqueued the same way existing admin JS is (pattern in
`BotSetupWizardRenderer`/existing enqueue calls, to confirm exact hook during implementation).

## Work packages

1. **Engine: match_mode + operators (ADR-0032)** — `ConditionOperator.php`, `NotificationRule.php`,
   `NotificationRuleRepository.php`, `RuleEvaluator.php`, `Migrator.php` step 30. Tests:
   `ConditionOperatorTest` (+3 operator cases incl. non-numeric/false-on-incomparable),
   `RuleEvaluatorTest` (any-mode match/no-match, all-mode unchanged), a `MigratorTest` step-30
   postcondition case if that pattern exists for prior steps. Commit: "Add any-match condition
   mode and three comparison operators (ADR-0032)".
2. **FieldTypeCatalog + PresetCatalog (data only)** — new files, no rendering. Tests: a coverage
   test asserting every `FieldTypeCatalog` entry is (a) a member of some event's
   `allowed_variable_fields_for()` (via a real `Registry` built the same way existing catalog
   tests do, see `EventCatalogPageTest.php`) and (b) fully specified — non-empty label, an
   operator list drawn only from the fixed set, and a defined preview value — i.e. the fail-closed
   guarantee is directly tested, not just asserted in prose; plus every `PresetCatalog` entry's
   conditions use only that event's allowed fields and reference only fully-catalogued
   `FieldTypeCatalog` fields, and WooCommerce-gated presets are flagged correctly. Commit: "Add
   field-type and notification-preset catalogs".
3. **ConditionRowRenderer + friendly event picker in RuleBuilderPage** — replaces the raw
   `event_type` select and JSON textarea with grouped picker + condition rows +
   all/any radio; WooCommerce-inactive families disabled via `WooCommerceSupport`. Tests: new
   `tests/integration/Administration/Automations/RuleBuilderPageConditionsTest.php` asserting the
   page's **visible rendered text** (option/label text nodes, button/help text — not the complete
   HTML source, since technical identifiers legitimately remain present in `<option value="...">`
   and other non-visible POST-carrying attributes) contains only friendly labels, and disabled-
   state help text renders when WooCommerce is inactive (double `WooCommerceSupport`). The
   assertion strategy is: extract visible text nodes (e.g. via a small DOM-text-only helper or
   targeted string checks against label/option *text content*) and assert no raw event-type or
   field-path identifier appears there, while a separate, unasserted-against area of the same
   markup is allowed to carry `value="woocommerce.order_created"` for form submission. Commit:
   "Replace JSON condition editor with visual condition-row builder".
4. **Message editor + field-insert menu + "Example notification preview"** — friendly template
   editor, insert-menu JS, the labelled preview rendered via `TemplateRenderer::render()` against
   a synthetic envelope built only from `FieldTypeCatalog` preview values. Tests: integration test
   asserting inserted tokens match `FieldTypeCatalog` sample values, the preview renderer is
   constructed with no database/HTTP dependency (doubles that throw if invoked), and the visible
   preview text never contains a raw `{{...}}` token (i.e. it always renders through
   `TemplateRenderer`, never displays the template source as the "preview"). Commit: "Add friendly
   message editor with field-insert menu and example notification preview".
5. **Preset UI + Store essentials starter set (two-step review flow)** — §0 preset cards, "Create
   a custom notification" path, the Store-essentials review screen (single bot/destination
   picker, all three rules' full messages listed, explicit second confirmation), and its
   `op=create_starter_set` branch in `RuleBuilderRequestHandler` reusing `$this->rules->save()`
   per rule. Tests: request-handler test asserting (a) the review screen alone never creates any
   rule, (b) confirming with a valid bot/destination creates exactly 3 rules, all `enabled=false`
   and named with the `(draft)` suffix, (c) confirming with no bot/destination selected creates
   nothing and returns an inline error, (d) the flow is unavailable when WooCommerce is inactive.
   Commit: "Add notification presets and store-essentials starter set".
6. **RuleEditor legacy-rule compatibility + Edit action** — `from_existing()`, the inline read-only
   fallback block for unrepresentable conditions, rule-list Edit link. Tests: round-trip test for
   every current event type's typical condition shape, plus a synthetic unmapped-field/operator
   case exercising the read-only fallback and confirming the stored JSON is unchanged after an
   edit+save that only touched the message. Commit: "Render existing rules through the friendly
   builder with compatibility fallback".
7. **Accessibility + design polish + package acceptance pass** — fieldset/legend audit, keyboard
   add/remove-row operability, visible selected-state styling on preset cards, progressive
   disclosure (conditions hidden until "Add a condition", advanced delivery collapsed by default),
   "Back to presets" affordance, focus management on validation rejection (server rejects →
   redirect currently silently drops the attempt per `RuleBuilderRequestHandler::save_rule()`'s
   catch block; this package adds an admin-notice-based error summary so a rejected save is
   visible, without changing the authoritative rejection behavior itself), responsive check at
   narrow admin viewport. Tests: full flow integration tests per the manual PO checklist below,
   automated. Commit: "Add rule-builder error summary, accessibility/design pass, and package
   acceptance tests".

## Automated test scenarios (mapped to packages above)

Friendly event/field labels (WP2/3, incl. fail-closed coverage — an uncatalogued allowed field
never appears); event-specific field filtering (WP2/3); typed operators + invalid-value rejection
(WP1/3 — server-side rejection via existing `NotificationRuleRepository::save()` path unchanged,
exercised through the new UI's POST shape); all/any behavior including absent-field-never-matches
and all-any-clauses-absent (WP1, propagated into `RuleSimulatorTest`); template insertion +
server-side validation (WP4, existing `TemplateRenderer` untouched); presets + starter set
including the two-step review/confirm flow and the no-bot/destination rejection case (WP2/5);
WooCommerce-absent mode (WP2/3/5); destination eligibility (WP3, reusing `DigestEligibilityTest`
patterns); legacy-rule preservation incl. inline read-only fallback (WP6); capability/nonce/
accessibility (WP7); visible-text-only raw-identifier absence (WP3, see WP3's assertion strategy);
package acceptance (WP7, one end-to-end test per manual checklist item below).

### Test-execution policy

Unchanged from current project practice: the plan-freeze commit (which changes ADR-0032 from
Proposed to Accepted) happens first, before any implementation work package begins. Each work
package (WP1–WP7) then writes and commits its own tests as it implements, but does not run them
as a standalone pass/fail gate. Only after every implementation work package is complete does one
combined validation gate run the full suite, and only that gate's pass is required before
PR/merge/technical closure of the milestone.

## Manual Product Owner checklist

1. **New Order notification**: Add Rule → preset card "New WooCommerce order" → confirm
   destination → Save → confirm it appears enabled in the rule list with the friendly event label.
2. **Low Stock alert**: Add Rule → preset "Low-stock alert" → adjust nothing → check the "Example
   notification preview" text → Save → confirm rule list shows it, then trigger via Rule Simulator
   (existing tool, unchanged) to see the real simulated match.
3. **Failed Login notification**: Add Rule → preset "Failed login attempt" → Save → verify no
   WooCommerce-only content appears if WooCommerce is inactive on the test site.
4. **Custom filtered rule**: Add Rule → "Create a custom notification" → event "Successful user
   login" (Website and users family) → add condition "Username" is not "admin" → All conditions →
   pick destination → write a message using the field-insert menu → check the preview → Save →
   open Edit on it and confirm every choice re-renders exactly as configured (no JSON shown).
5. **Store essentials starter set** (WooCommerce active): Add Rule → "Store essentials" card →
   review screen lists all three rules and their messages → select one bot/destination → confirm
   → rule list shows exactly three new `(draft)` rules, all disabled.

## Verification (for implementation phase, not this planning task)

`docker compose run --rm wpcli wp eval` for constant/registry checks per CLAUDE.md conventions;
the full PHPUnit unit + integration suite run once at the combined validation gate (see
"Test-execution policy"); manual checklist above against a running `dev.biopentra.eu`-style
WordPress+WooCommerce install with WooCommerce toggled both on and off.
