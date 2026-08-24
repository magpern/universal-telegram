# M08.1 — Friendly Rule Builder and Notification Presets: Exploratory Handoff

Branch: `feature/m08-1-friendly-rule-builder`
Baseline: `main` @ `27752e05697fb81a3341a121621c59aacb5ded77`
Freeze commit: `6012c75` (`docs: freeze M08.1 friendly rule builder plan`)
Latest commit: `59fd012` (`feat(admin/automations): add rule-builder error summary, accessibility pass, and package acceptance tests`)

## Status

**Implementation is complete on this feature branch, for exploratory Product Owner UI
testing only.** All seven frozen work packages have landed as focused commits, in order:

1. `a57cfc4` — engine: `match_mode` (ADR-0032), migration 30, three fixed operators, absent-field
   and any-mode evaluator semantics.
2. `339cc72` — `FieldTypeCatalog` (fail-closed field metadata) and `PresetCatalog` (sixteen
   presets + Store-essentials starter set), data only.
3. `c85985b` — friendly, family-grouped event picker and the visual "Only when…" condition-row
   builder, replacing the raw `event_type` select and `conditions_json` textarea.
4. `6dd9479` — friendly message editor, field-insert menu, and the "Example notification preview"
   (server-rendered via the real `TemplateRenderer`, fed only fixed sample values).
5. `d6f3225` — preset cards and the two-step Store-essentials review/confirmation flow.
6. `0101471` — existing-rule Edit action, `RuleEditor` representability logic, and the read-only
   compatibility fallback for legacy conditions the visual builder cannot represent.
7. `59fd012` — accessible save-error summary, collapsed "Advanced delivery options" disclosure,
   responsive/selected-state styling, and the plugin version bump (0.14.1 → 0.15.0).

**Tests were written alongside every work package but were not executed.** Per the frozen plan's
test-execution policy, the combined validation gate (full PHPUnit suite, PHPCS, PHPStan) runs only
once, after all implementation work packages are complete, immediately before PR/merge/technical
closure — that gate has **not** been run as part of this task and remains a pending, separate step.

## Known pre-existing condition

`main` carries known red CI from the combined M11/M07.1 exploratory-testing work, entirely
unrelated to this milestone. This task did not investigate, touch, or attempt to repair that CI
state. This branch's own correctness has not been confirmed by any automated run; do not treat
its current state as validated.

## What is NOT done

- No PR was opened and no merge to `main` occurred.
- The deferred validation gate (PHPUnit, PHPCS, PHPStan) has not been run.
- No code review of this branch has occurred.
- The pre-existing M11/M07.1 CI failures on `main` remain unrepaired — out of scope for this task.
- No release, tag, or deployment occurred.
- No live Telegram or other external provider call was made at any point.
- No bot, destination, webhook, or settings configuration was changed on this or any environment.

## Ready for

**Product Owner exploratory UI testing only**, against a local/staging WordPress install with this
branch checked out. Formal validation (the combined test/lint/static-analysis gate), code review,
CI repair on `main`, PR, merge, release, and any production approval all remain pending and are
separate, later steps.

### Suggested exploratory checks (from the frozen plan's manual PO checklist)

1. **A WooCommerce preset** — Rules tab → "New WooCommerce order" preset card → confirm
   destination → Save → confirm it appears in the rule list.
2. **A WordPress preset** — "Failed login attempt" preset card → Save → confirm no WooCommerce
   content appears if WooCommerce is inactive on the test site.
3. **A custom filtered rule** — "Create a custom notification" → pick an event → add a condition
   → choose all/any → pick a destination → write a message via the field-insert menu → check the
   "Example notification preview" → Save → open Edit and confirm everything re-renders exactly as
   configured, with no JSON visible anywhere.
4. **The Store-essentials two-step flow** — "Store essentials starter set" → review screen lists
   all three rules and their messages → choose one bot/destination → confirm → exactly three
   disabled `(draft)` rules appear in the list.
5. **Editing an existing rule** — use the new "Edit" action on any rule in the list; a rule with
   representable conditions should show them pre-filled and editable, a rule with conditions the
   builder cannot represent should show the read-only compatibility notice instead.
6. **The no-JSON/no-technical-identifiers experience** — confirm no screen in the normal Add
   Rule/Edit flow ever requires typing JSON, a schema field path (`payload.order_total`), a raw
   event type (`wordpress.login_succeeded`), or `{{template}}` syntax by hand.

## Confirmation

No repository changes beyond what is committed on this branch occurred outside this task's scope;
no external calls, configuration actions, release, tag, or deployment occurred at any point during
implementation.
