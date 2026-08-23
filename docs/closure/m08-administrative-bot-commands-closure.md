# M08 — Administrative Bot Commands — Closure Record

## Status

**PASS** (automated/technical verification). Product Owner acceptance is **PENDING** — manual
Telegram command testing against the configured dev bot has not yet been performed (no live
Telegram calls, bot/destination/webhook changes, releases, tags, or deployments occurred as part
of this task).

## Baseline, freeze, PR, merge, and closure SHAs

- Baseline (prior `main`): `f2868268fcb30cf0e3cb034adf821f903b8c3bc8`
- Feature branch: `feature/m08-administrative-bot-commands`
- Freeze commit (plan doc): `af609b0` — `docs: freeze M08 administrative bot commands plan`
- PR: [#22](https://github.com/magpern/universal-telegram/pull/22)
- Merge commit: `fcc6bccbf4ab9877db0c3e4adc4fc98b5d9ff5a0` (into `main`, merge strategy: merge commit)
- Closure commit: pushed directly to `main` immediately following this document

## Work-package and repair commits

| WP | Commit | Summary |
|----|--------|---------|
| WP1 | `53a7c32` | `CommandCatalogue` (17-literal fixed allow-list, context/argument-shape metadata), `CommandParser` (entity-based recognition), `ParsedCommand` value object |
| WP2 | `47b978f` | `BotCommandDispatcher` authorization/context pipeline; `WebhookController` extended with the recognized-command branch (mutually exclusive with reply capture); `ChatProfileResolver::conversation_destination_id()` added; `CommandAcknowledgements` fixed-string catalogue |
| WP3 | `b4bceac` | `/help`, `/whoami`, `/conversations` |
| WP4 | `11849c2` | `/status`, `/errors`, `/visitors` (reused `QueueHealth`/`EventHistoryRepository`, `event_registry`/`event_history_repository` construction moved earlier in `Plugin::init()` to be ready in time) |
| WP5 | `36de1ec` | `WooCommerceCommandQueryService` (bounded count-only-probe + paged-fetch strategy, `is_within_safe_cap()`/`pages_needed()` pure helpers); `/orders`, `/order`, `/stock`, `/sales` |
| WP6 | `67b1dc3` | `ConfirmationStore` (WordPress transient, 60s TTL); `/here`, `/presence`, `/claim`, `/release`, `/resolve`, `/reopen`, `/confirm` |
| WP7 | `c7cd8a1` | Diagnostics "Bot commands" panel (`bot_commands_active`, `bot_commands_rejected_unauthorized_24h`, `bot_commands_rejected_wrong_context_24h`) |
| WP8 | `11fce6d` | ADR-0027 (frozen), milestone/registry status updates, `ARCHITECTURE.md` version-history entry, `readme.txt` changelog, version bump |

**Repair commit (Phase C lean-gate defects):**

| Commit | Defect class |
|--------|--------------|
| `826a35e` | PHPCS formatting (67 auto-fixable violations via `phpcbf`) and three manual doc-comment sniffs across WP1–WP7 files; PHPStan return-type/narrowing errors in `WooCommerceCommandQueryService` (`order_summary()`/`stock_summary()` now use `instanceof WC_Order`/`instanceof WC_Product` narrowing instead of `method_exists()`, and the `total` field's declared type corrected to `float`); two pre-existing test files (`WebhookControllerTest`, `WebhookControllerConversationRoutingTest`, predating M08) constructed `WebhookController` without the new required `BotCommandDispatcher` argument — updated to inject one; added `BotCommandDispatcherFamilyDTest.php` closing a real coverage gap (`/orders`/`/order`/`/stock`/`/sales` were previously exercised only at the `WooCommerceCommandQueryService` layer, never dispatched end-to-end); `BotCommandDispatcherFamilyBCTest` hardened against cross-test event-history/queue pollution within the same PHPUnit process (a fixture user's creation emits a real `wordpress.user_registered` event). |

## ADR-0027

`docs/adr/0027-administrative-bot-commands-entity-based-recognition-two-factor-authorization-bounded-woocommerce-queries-and-confirmation-gated-lifecycle-transitions.md`
— Accepted. Entity-based command recognition; two-factor (identity mapping + live capability)
authorization with a merged, non-enumerating rejection outcome; unknown-topic silence; bounded
read-only WooCommerce queries via a new `WooCommerceCommandQueryService`; confirmation-gated
`/resolve`/`/reopen` via a WordPress core transient; `/reopen` tightened to assignee-only.

## Exact command catalogue

| Command | Context | Argument | Confirmation |
|---|---|---|---|
| `/help` | any | none | — |
| `/whoami` | any | none | — |
| `/status` | General | none | — |
| `/errors` | General | none | — |
| `/visitors` | General | none | — |
| `/orders` | General | none | — |
| `/order` | General | numeric id (1–20 digits) | — |
| `/stock` | General | token (1–100 chars, no `%`/`*`) | — |
| `/sales` | General | `today`\|`week`\|`month` | — |
| `/conversations` | General | none | — |
| `/here` | conversation | none | — |
| `/presence` | General | `available`\|`busy`\|`offline` | — |
| `/claim` | conversation | none | No |
| `/release` | conversation | none | No |
| `/resolve` | conversation | none | Yes |
| `/reopen` | conversation | none | Yes |
| `/confirm` | conversation | none | (is the confirmation) |

## Authorization, confirmation, unknown-topic, and WooCommerce query/privacy boundaries

- **Authorization**: `OperatorIdentityRepository::find_by_telegram_user_id()` (M07 mapping, unchanged)
  plus a freshly evaluated `user_can($wp_user_id, MANAGE_CONVERSATIONS)` on every command. Both
  failure causes — unmapped, or mapped-but-capability-revoked — produce the identical outcome: no
  reply, no state change, one `bot_command.rejected_unauthorized` audit entry carrying only
  `bot_id`. M07's own inbound-reply-capture gate (mapping-only) is unchanged.
- **Context**: General topic (`message_thread_id === null`) / a known conversation topic
  (`ConversationRepository::find_by_topic()` hit) / an unknown topic (miss). Unknown-topic is fully
  silent for every command (audit-only, `bot_command.rejected_wrong_context`); a known-context
  mismatch receives a bounded acknowledgement plus the same audit code.
- **Confirmation**: `ConfirmationStore`, a thin wrapper over `set_transient`/`get_transient`/
  `delete_transient`, 60-second TTL (matching `DiagnosticsPage`'s existing precedent), keyed on
  `(bot_id, conversation_id, wp_user_id)`. Single-use (`consume()` reads then immediately deletes).
  `/confirm` re-validates the same preconditions fresh before calling
  `ConversationRepository::transition()`, whose own database-level CAS remains the actual
  safety-critical guard regardless of any transient-layer race.
- **WooCommerce**: `WooCommerceCommandQueryService`, read-only, documented HPOS-safe APIs only
  (`wc_get_order()`, `wc_get_product_id_by_sku()`, `wc_get_product()`, `wc_get_orders()`). `/orders`
  and `/sales` never call `wc_get_orders()` with `limit => -1`: a `paginate => true` count-only
  probe first (`PAGE_SIZE = 100`), then at most `ceil(total / 100)` further pages (≤5) when
  `total <= SAFE_PROCESSING_CAP` (500), otherwise the fixed `Too many matching orders — use the
  Hub.` acknowledgement with no partial count or total. `/order` returns only `status`,
  `date_created`, `currency`, `total`, `item_count`; `/stock` returns only `name`, `manages_stock`,
  `stock_quantity` (only when managed), `stock_status` — the submitted SKU is never echoed back.
  Not-found and unreadable collapse to the identical `Not found or unavailable.` response.
  WooCommerce-inactive returns the identical `WooCommerce is not active on this site.`
  acknowledgement for all four Family D commands, checked before argument parsing.

## Lean local validation and CI evidence

**Local lean gate (final, after repair commit):**
- PHPCS (all changed files, source and tests): clean
- PHPStan (all changed source files): `[OK] No errors`
- PHPUnit unit suite: 224/224 pass
- PHPUnit integration suite, scoped to changed seams (`Telegram/Inbound`, `Telegram/Commands`,
  `Integrations/WooCommerce`, `Administration/Diagnostics`), run both WordPress-only (7.1/PHP 8.3)
  and WordPress+WooCommerce (7.1/PHP 8.3/WooCommerce 11.0.1): all pass, WooCommerce-gated tests
  correctly skip or run per configuration
- Package acceptance (WordPress 7.1/PHP 8.3/WooCommerce 11.0.1): PASSED — `db_version` 18
  confirmed unchanged, activation/deactivation/default-retention-and-opt-in-uninstall all correct,
  no new tables

**GitHub Actions (PR #22, final commit `826a35e`, all 14 checks):** build, phpcs, static-analysis,
unit (8.1/8.3/8.4), integration-wp-only-floor, integration-wp-only-current,
integration-wc-present-current, js-behavioural, package-acceptance (6.9/8.1, 7.1/8.3,
7.1/8.3/WooCommerce 11.0.1) — all **pass**.

**Post-merge CI (merge commit `fcc6bcc`, all 13 checks):** all **pass**.

## Version/database transition

- Plugin version: `0.9.0` → `0.10.0` (minor bump — a fixed, allow-listed administrative-bot command
  surface with entity-based recognition, two-factor authorization, bounded read-only WooCommerce
  queries, and confirmation-gated lifecycle transitions together constitute a genuine new
  functional-capability class).
- Database: unchanged. `db_version` stays `18`. No new table, column, or migration step.

## Deviations

None from the frozen plan (`docs/plans/m08-administrative-bot-commands-plan-v1.md`) or ADR-0027.
All eight work packages were implemented in the planned order and scope. The Phase C repair commit
(`826a35e`) addressed only actual defects surfaced by the lean validation gate — formatting,
static-analysis findings, two pre-existing test files that predated M08's constructor-signature
change, and one real test-coverage gap (Family D end-to-end dispatch) — never a scope change.

## Product Owner acceptance

**PENDING.** Manual dev-bot acceptance testing (per the checklist in the frozen plan, §12) has not
yet been performed and was explicitly outside this task's own authorized scope, which excluded all
live Telegram/WooCommerce/bot/webhook actions.

## Confirmations

- No live Telegram calls, bot configuration, destination changes, webhook changes, or WooCommerce
  changes occurred at any point.
- No release, tag, or deployment occurred.
- No configuration change outside this milestone's own repository content occurred.
- M09 has not started — no file, test, or documentation for it was added or modified.
- `main == origin/main` at `fcc6bccbf4ab9877db0c3e4adc4fc98b5d9ff5a0`, verified via
  `git fetch && git rev-parse HEAD origin/main`.
- The tree is clean.
