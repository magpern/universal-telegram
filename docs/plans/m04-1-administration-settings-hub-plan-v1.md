# M04.1 — Administration Settings Hub — Definitive Implementation Plan (v1)

Status: Frozen — implementation authorized. This document is self-contained: it does not require a reader to consult any earlier conversation draft or planning-session transcript.

Implements the Product Owner's M04.1 product decision: replace the growing
WordPress left-hand submenu hierarchy with one coherent plugin
administration shell (`Telegram Hub`) using URL-driven horizontal tabs.
Pure administration-information-architecture change: no event, rule,
persistence, Telegram, WooCommerce, or AI behavior changes.

## 1. Verified baseline

`origin/main == HEAD == 1311410`, working tree clean (`git status` — nothing
to commit). The bootstrap activation hotfix (`7583e8a`, deferred `rest_url()`
evaluation) is present on `main`, merged via PR #6 (`01247a3`), with its own
closure addendum recorded at `1311410`. M04 (visitor/browser events)
implementation was merged to `main` via merge commit `ced5adf`; M04's own
closure record was committed separately, immediately after, at `3c837cc`
("docs: record technical closure and PASS evidence") — `3c837cc` is M04's
closure SHA, `ced5adf` is the merge SHA that landed the implementation, and
this plan uses that exact distinction throughout rather than treating the
two as interchangeable. M04's final status is PASS (per
`docs/closure/m04-visitor-and-browser-events-closure.md`). This baseline is
a valid, unbroken foundation for M04.1.

## 2. Charter/ADR references

Reused unmodified: ADR-0005 (Administration is the owning top-level
boundary; this milestone adds a `Hub` subdomain of `Administration`, not a
new top-level boundary), ADR-0010 (capability model — `MANAGE` and
`MANAGE_AUTOMATIONS`, both granted to the administrator role together, both
reused verbatim, no new capability constant), ADR-0011 (M04.1 falls after
M04 in numbering but is scoped as administration-IA-only, not a new
functional-capability milestone with a conversational/AI surface — no
independent Vlad acceptance gate; same automated-evidence standard as
M00–M09 applies by extension of the same rationale), ADR-0019 (unaffected —
no ingestion/event-catalog change).

One new ADR is proposed: **ADR-0020** (§14), because this milestone
establishes the Administration boundary's new default navigation pattern
(future screens join the Hub unless a documented exception is accepted)
and changes a public-facing contract (bookmarked admin URLs — via
permanent, `GET`-only, temporary-redirect compatibility entry points, not
by breaking them — and the plugin-row Settings link target).

## 3. Current-to-new navigation mapping

| Current | Slug (const) | Cap | New tab id | New URL |
|---|---|---|---|---|
| *(none — new)* | — | `MANAGE` | `overview` (default) | `admin.php?page=universal-telegram&tab=overview` |
| `BotManagementPage` | `universal-telegram-bots` | `MANAGE` | `bots` | `...&tab=bots` |
| `EventCatalogPage` | `universal-telegram-events` | `MANAGE_AUTOMATIONS` | `events` | `...&tab=events` |
| `RuleBuilderPage` | `universal-telegram-rules` | `MANAGE_AUTOMATIONS` | `rules` | `...&tab=rules` |
| `RuleSimulatorPage` | `universal-telegram-rule-simulator` | `MANAGE_AUTOMATIONS` | `simulator` | `...&tab=simulator` |
| `EventHistoryPage` | `universal-telegram-event-history` | `MANAGE_AUTOMATIONS` | `event-history` | `...&tab=event-history` |
| `VisitorTrackingPage` | `universal-telegram-visitor-tracking` | `MANAGE` | `visitor-tracking` | `...&tab=visitor-tracking` |
| *(none — new)* | — | `MANAGE` | `settings` | `...&tab=settings` |
| `DiagnosticsPage` (was top-level menu) | `universal-telegram-diagnostics` | `MANAGE` | `diagnostics` | `...&tab=diagnostics` |

Every current submenu maps exactly once; no page is dropped, split, or
duplicated. `DiagnosticsPage` today doubles as both the top-level landing
page and the diagnostics report; this milestone splits that into a new,
minimal `Overview` tab (static welcome + capability-gated links to the
other tabs — reuses no new data source, so it introduces no new query or
business logic) and an unchanged `Diagnostics` tab carrying the existing
report + self-test control verbatim. `Settings` is genuinely new: it did
not exist as an admin screen before (`Core\Configuration\Settings` fields
for retention/telegram-numeric defaults have never had admin UI — see §6).

## 4. Shared page-shell architecture

New subdomain: `src/Administration/Hub/`, namespace
`UniversalTelegram\Administration\Hub` (a subdomain of the existing
`Administration` boundary, per ADR-0005 — no new top-level boundary).

- **`Tab`** (`src/Administration/Hub/Tab.php`) — immutable value object:
  `__construct(string $id, string $label, string $capability, callable $render)`.
  `id()` is the URL `tab` value (lowercase, hyphenated, matches the mapping
  table above). `capability()` is `CapabilityRegistrar::MANAGE` or
  `::MANAGE_AUTOMATIONS`.
- **`TabRegistry`** (`src/Administration/Hub/TabRegistry.php`) —
  `register(Tab $tab): void` (insertion order = display order),
  `all(): array<Tab>`, `get(string $id): ?Tab`, `default(): Tab` (first
  registered — `overview`).
- **`HubPage`** (`src/Administration/Hub/HubPage.php`) — the shell:
  - `public const SLUG = 'universal-telegram';`
  - `register_menu()`: `add_menu_page( 'Telegram Operations Hub', 'Telegram Hub', CapabilityRegistrar::MANAGE, self::SLUG, [ $this, 'render' ] )`. Gated on `MANAGE` (the broader of the two existing capabilities) since both are always co-granted to the administrator role today (§6 notes the theoretical edge case).
  - `resolve_tab_id(): string` — reads `$_GET['tab']`, `sanitize_key()`s it, returns it only if `TabRegistry::get()` finds it, else returns the registry's `default()->id()`. No warning, no redirect for an unknown tab id — silent fallback to Overview (see §9 for the precise, non-conflated distinction from a known-tab capability denial).
  - `render(): void` — resolves the tab, `current_user_can( $tab->capability() )` (if false: `wp_die()` 403, matching every existing page's exact defense-in-depth message/pattern), prints one shared `.wrap` + `<h1>` + a `nav-tab-wrapper` (standard WP-admin tab markup: `<h2 class="nav-tab-wrapper" aria-label="...">`, each tab an `<a class="nav-tab" href="...">`, the active tab additionally `nav-tab-active` and `aria-current="page"`), then invokes `($tab->render())()` for the resolved tab's content only (each migrated page's content method no longer prints its own `.wrap`/`<h1>` — see §7 migration pattern).
  - `register_tabs( TabRegistry $registry ): void` — wired once from `Core\Plugin::init()`, registers all nine `Tab` instances against page objects already constructed there (no new construction, same instances currently passed to `add_action('admin_menu', ...)`).

- **URL parsing/validation**: `sanitize_key()` on `$_GET['tab']` (WordPress core, always available in admin context — unlike the WordPress-free `Settings` class, `HubPage` is not required to avoid WP functions), then a registry-membership check. No other query parameters are touched by the shell; each tab's own filter/pagination query args (e.g. `RuleSimulatorPage`, `EventHistoryPage` self-submitting filter forms) are preserved unchanged alongside `tab=`.
- **Default tab**: `overview`, first item registered.
- **WooCommerce-gated content**: unaffected by the shell — `VisitorTrackingPage`'s existing WooCommerce-family checkbox and the Diagnostics report's existing WooCommerce section already handle `WooCommerceSupport::is_active()` internally; the shell does not need to know about WooCommerce at all. No commerce-conditional tab is introduced (all nine tabs are always registered; a WC-absent site simply sees the same, already-existing "not active" copy inside affected tabs' own content).

## 5. Compatibility strategy for old URLs and plugin-row links

New **`LegacyUrlRedirector`** (`src/Administration/Hub/LegacyUrlRedirector.php`):
one class, a fixed `array<old_slug, new_tab_id>` covering all seven
retired slugs (`universal-telegram-diagnostics`, `-bots`, `-events`,
`-rules`, `-rule-simulator`, `-event-history`, `-visitor-tracking`). For
each entry it calls `add_submenu_page( null, '', '', $capability, $old_slug, [ $this, 'redirect' ] )` — `parent_slug = null` registers a reachable-by-URL,
capability-gated admin page that is never rendered as a visible menu item
(the standard WordPress hidden-page convention), and is retained
permanently as this milestone's compatibility entry point for that slug
(not a temporary shim scheduled for later removal).

`redirect( string $old_slug )` behaves as follows, in order:

1. Re-verify the same capability the original page used (from the same
   map) — `wp_die()` 403 on failure, before anything else runs. No tab
   mapping, redirect target, or restricted content is ever computed or
   disclosed to an unauthorized caller.
2. If `$_SERVER['REQUEST_METHOD'] !== 'GET'` (a mutation or non-safe
   request arriving at the old slug — no such route exists today, since
   every current mutation already goes through `admin-post.php` with its
   own `ADMIN_POST_ACTION`/nonce, entirely independent of
   `admin.php?page=`, but the redirector must not assume that remains
   true for a future integration or a malformed request), **do not
   redirect**. Render a minimal, capability-checked "this page has moved"
   notice with a link to the new tab URL, and take no other action — a
   redirect must never be issued in response to a non-`GET` request,
   since a redirect response to a `POST` can cause some clients to
   silently re-issue the mutation against the new location.
3. Only for a `GET` request, after step 1 passes and the old slug maps to
   a known tab id (both are static per-entry facts from the fixed map,
   so this never fails at runtime): `wp_safe_redirect( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . $new_tab_id ), 302 ); exit;`
   — a **temporary (302)** redirect, not permanent (301). The IA change is
   intended to be durable, but a 302 keeps the old URL itself
   revalidated by the browser/cache on every visit rather than
   permanently cached client-side, which is the safer default for an
   internal `wp-admin` URL that a future milestone could still need to
   reuse or further adjust.

All seven old-slug entry points are `GET`-only compatibility redirects.
No old-slug route performs, forwards, or implies a mutation; the
`admin-post.php` mutation routes (`BotManagementController`,
`RuleBuilderRequestHandler`, `VisitorTrackingPage::handle_request()`) are
untouched by `LegacyUrlRedirector` entirely — they are a different URL
space (`admin-post.php`, not `admin.php?page=`) and keep working exactly
as today throughout every work package, including before their owning
tab exists, so no legacy `POST` handler or form route is ever left
without a functioning target (§7 item 4).

`PluginActionLinks` (`src/Administration/PluginActionLinks.php`) changes
its one line from `DiagnosticsPage::SLUG` to `HubPage::SLUG . '&tab=settings'`,
satisfying the product decision that the plugin-row link opens Settings
directly. `DiagnosticsPage::render_admin_notice()`'s banner link updates
from `self::SLUG` to `HubPage::SLUG . '&tab=diagnostics'`.

## 6. Settings-tab scope

New **`SettingsPage`** (`src/Administration/Hub/SettingsPage.php`), built
on the exact existing `VisitorTrackingPage` pattern (own
`ADMIN_POST_ACTION`/`NONCE_ACTION` constants, `MANAGE` capability,
`Settings::sanitize( array_merge( $this->settings->get(), $input ) )`,
`update_option`, `wp_safe_redirect` back to `tab=settings`). Exposes the
plugin-wide `Core\Configuration\Settings` fields that currently have **no
admin UI at all** (confirmed by direct read of `Settings::defaults()` and a
repository-wide search — only `visitor_*` and the implicit
`remove_data_on_uninstall`/retention fields exist, none rendered anywhere
except `VisitorTrackingPage`'s own `visitor_*` subset):
`remove_data_on_uninstall`, `telegram_message_retention_days`,
`telegram_delivery_log_retention_days`, `telegram_max_pending_seconds`,
`telegram_webhook_max_body_bytes`, `telegram_stale_pending_alert_seconds`,
`telegram_rate_limit_fallback_wait_seconds`,
`telegram_webhook_rotation_max_pending_hours`, `event_retention_days`,
`dispatch_log_retention_days`, `fatal_marker_retention_days`. This is new
*UI* over existing, already-sanitized fields — no new option, no new
schema, no new business rule. `visitor_*` fields are explicitly **not**
duplicated here: Visitor Tracking keeps its own dedicated tab and its own
existing `handle_request()` unchanged, per the product decision. Operational
Bot configuration (tokens, destinations, webhook actions) is **not** moved
into Settings — it stays exactly as `BotManagementPage`'s own tab content,
per the explicit exclusion.

## 7. Migration pattern for the six existing pages

For each of `BotManagementPage`, `EventCatalogPage`, `RuleBuilderPage`,
`RuleSimulatorPage`, `EventHistoryPage`, `VisitorTrackingPage`, and
`DiagnosticsPage`:

1. Remove the class's own `register_menu()` `add_submenu_page()`/
   `add_menu_page()` call (superseded by `HubPage::register_tabs()` +
   `LegacyUrlRedirector`).
2. Rename `render(): void` to `render_tab_content(): void`; strip the
   outer `echo '<div class="wrap">'` / `<h1>...</h1>` / closing `</div>`
   (now owned once by `HubPage::render()`); the existing
   `current_user_can()` defense-in-depth check at the top of the method is
   kept unchanged.
3. `RuleSimulatorPage` and `EventHistoryPage` each self-submit a filter
   form containing `<input type="hidden" name="page" value="<?php self::SLUG ?>">`
   (confirmed by direct read of both files) — each gains one additional
   hidden field, `<input type="hidden" name="tab" value="<tab-id>">`, and
   its `page` value changes to `HubPage::SLUG`, so filter submission stays
   on the correct tab.
4. `admin_post_*` handlers (`BotManagementController`,
   `RuleBuilderRequestHandler`, `VisitorTrackingPage::handle_request()`)
   are **entirely unchanged** — they are wired independently of
   `admin_menu`/tab rendering and already redirect back to their own
   page's URL; each redirect target updates from the old
   `admin.php?page=<old-slug>` to `admin.php?page=universal-telegram&tab=<id>`.
5. `Core\Plugin::init()`: replace each page's
   `add_action( 'admin_menu', [ $page, 'register_menu' ] )` with one
   `HubPage::register_tabs()` call that registers all nine `Tab` objects
   (each wrapping the already-constructed page instance's
   `render_tab_content` callable), plus one
   `add_action( 'admin_menu', [ $legacy_redirector, 'register' ] )`. No
   service construction order changes — the same instances already built
   for the old `add_action` calls are reused.

## 8. Accessibility and WordPress-admin integration

Tab markup follows WordPress core's own `nav-tab-wrapper`/`nav-tab`
convention (used throughout core screens, e.g. Settings > General with
subtabs) — no custom CSS framework, no JS-driven tab switching (each tab
is a real, separate GET request, so browser back/forward, reload, and
bookmarking all work natively). Active tab: `nav-tab-active` class +
`aria-current="page"`. The wrapper carries `aria-label` (e.g. "Telegram
Hub sections") for screen-reader landmark navigation. Every tab link is a
real `<a href>` — full keyboard reachability (Tab/Shift+Tab, Enter to
activate) with no custom `tabindex`/`role` needed, since native anchors
already have correct semantics. No animation, no framework, no new
enqueued asset.

## 9. Diagnostics and error behavior

Two cases are deliberately kept distinct and must never be conflated:

- **Absent or unknown `tab` value** (no `tab` param, or a value not
  present in the `TabRegistry`): treated as if no tab were requested at
  all. `resolve_tab_id()` falls back to `overview`, and `overview` then
  renders normally, subject to its own (the requesting user's own,
  ordinary `MANAGE`) capability check like any other tab. No error is
  shown, no restricted content is ever considered, computed, or
  disclosed as part of resolving an unknown id — the fallback happens
  before any tab-specific data access. This matches the uniform,
  non-differential posture the rest of this plugin favors (e.g.
  ADR-0019's ingestion endpoint), and is not itself a capability
  decision — a user with no capabilities at all still cannot reach
  `overview`, since `HubPage::register_menu()`'s own `MANAGE` gate on
  the parent menu applies first.
- **Known tab, insufficient capability**: `current_user_can( $tab->capability() )`
  fails → `wp_die()` with the plugin's existing, unchanged "You do not
  have permission to access this page." message and its existing HTTP
  403 status — the same wording and status every current page already
  uses. This is a hard denial, never a silent redirect or fallback to
  `overview`: the tab is never rendered, no fragment of its content or
  data reaches the response, and the user is told plainly that
  permission, not a routing error, is the reason. Known, documented,
  accepted limitation carried over unchanged from today: because
  `HubPage::register_menu()` gates the entire parent menu on `MANAGE`, a
  hypothetical role holding only `MANAGE_AUTOMATIONS` (never granted
  independently by this plugin today — both capabilities are always
  co-granted to the administrator role, per `CapabilityRegistrar::grant()`)
  would not see the `Telegram Hub` menu entry at all, and so could not
  reach an Automations tab even though that tab's own capability check
  would pass it. This is unchanged risk exposure versus today's status
  quo (no such role exists in the product), documented rather than
  solved, per the exclusion against capability-model changes.
- **Direct old submenu URL**: for a `GET` request, a 302 (temporary)
  redirect to the equivalent tab (§5) — after the same capability check,
  in the same order as above: a user without the required capability
  hits the identical `wp_die()` inside `LegacyUrlRedirector::redirect()`
  before any tab mapping or redirect target is computed. For a non-`GET`
  request, no redirect is issued at all (§5 item 2).
- Diagnostics tab itself remains fully read-only, unchanged from today.

## 10. Work packages

| WP | Objective | Files | Tests | Commit message |
|---|---|---|---|---|
| WP0 | Freeze ADR-0020 | `docs/adr/0020-*.md` | none (docs) | `Add ADR-0020: administration hub navigation shell` |
| WP1 | Tab registry + hub shell | `src/Administration/Hub/{Tab,TabRegistry,HubPage}.php` | `TabRegistryTest` (register/get/default), `HubPageTest` (unknown-tab fallback, capability-denied 403, active-tab markup, default tab is `overview`) | `Add tab registry and administration hub shell` |
| WP2 | Overview + Diagnostics migration | `Administration/Hub/OverviewPage.php` (new), `Administration/Diagnostics/DiagnosticsPage.php` (migrate per §7), `Core/Plugin.php` | `OverviewPageTest`, updated `DiagnosticsPageTest` | `Migrate Diagnostics into the hub and add the Overview tab` |
| WP3 | Bots/Events/Rules/Simulator/Event History migration | the five page classes (per §7), `Core/Plugin.php` | updated tests per class (render-content + capability), `RuleSimulatorPage`/`EventHistoryPage` filter-form `tab` field test | `Migrate Bots, Events, Rules, Simulator, and Event History into hub tabs` |
| WP4 | Visitor Tracking migration | `Administration/Visitor/VisitorTrackingPage.php`, `Core/Plugin.php` | updated `VisitorTrackingPageTest` (still asserts consent-copy text, per M04 closure precedent) | `Migrate Visitor Tracking into its own hub tab` |
| WP5 | Settings tab + plugin-row link + banner link | `Administration/Hub/SettingsPage.php` (new), `Administration/PluginActionLinks.php`, `Administration/Diagnostics/DiagnosticsPage.php` (banner URL only), `Core/Plugin.php` | `SettingsPageTest` (each field round-trips through existing `Settings::sanitize()`), `PluginActionLinksTest` (points at `tab=settings`) | `Add the Settings tab for plugin-wide configuration` |
| WP6 | Legacy URL compatibility + full regression + docs | `Administration/Hub/LegacyUrlRedirector.php` (new), `Core/Plugin.php`, `docs/ARCHITECTURE.md`, `readme.txt` | `LegacyUrlRedirectorTest`: each of the 7 old slugs → correct `302` target for a `GET` request; capability-denied → `wp_die()` 403, no redirect issued, no tab content disclosed; a simulated `POST` to an old slug → no redirect issued (moved-notice rendered instead); direct-bookmark case (old slug with no other query args) resolves to the right tab | `Add legacy URL redirects and complete the administration hub (M04.1)` |

Tests are written with each WP but executed only once, after WP6, in the
repository's established CI order (phpcs → phpstan → unit matrix →
integration WP-only → integration WC-present → build → package
acceptance); no test execution occurs during WP0–WP5 themselves. GitHub
Actions remains the independent gate on PR and merge.

## 11. Testing strategy

- **Unit**: `TabRegistry` resolution logic; `HubPage::resolve_tab_id()`
  (known id, unknown id, missing `$_GET['tab']`, non-string/array
  injection attempt — all fall back to `overview`, never fatal, never
  disclosing another tab's content); capability-gated `render()` denial
  for each of the two capabilities, distinguishing the unknown-tab
  fallback from the known-tab-denied case (§9) as two separate test
  cases, not one.
- **Integration (WP-only)**: every migrated tab's content renders
  identically to its pre-migration output (existing assertions on
  form fields, nonces, table contents carried over, adjusted only for the
  removed outer `.wrap`/`<h1>`); explicit coverage for: each of the 7
  legacy URLs redirecting (302, `GET`) to its correct tab; each legacy
  URL denying an unauthorized user (403, no redirect); an unknown `tab=`
  value falling back to `overview` rather than erroring; a direct
  bookmark to an old slug with no other query args resolving correctly;
  a simulated non-`GET` request to a legacy slug producing no redirect;
  `PluginActionLinks` target; admin-post handlers still succeed and
  redirect to the new tab URL (their own nonce/capability behavior is
  untouched, so existing assertions largely carry over verbatim), proving
  the `admin-post.php` mutation routes were never affected by the
  redirector.
- **Integration (WC-present)**: unaffected tabs (Events/Rules/Simulator/
  Event History/Visitor Tracking's commerce family/Diagnostics'
  WooCommerce section) render unchanged inside their new tab shell.
- **Package acceptance**: unchanged assertions (`db_version` still 10, no
  new table) plus a smoke check that `admin.php?page=universal-telegram`
  loads and the nine tabs are all present in menu markup.
- No test execution during WP0–WP5; one full validation gate after WP6;
  GitHub Actions on PR and merge, per the repository's standing convention.

## 12. Version/DB recommendation and ADR-0020 justification

**DB**: unchanged, `db_version` stays `10` — no new table, no new option
beyond what `Settings::defaults()` already declares (Settings tab exposes
existing fields only). **Version**: `0.3.0 → 0.3.1` (patch), not a minor
bump — unlike M01–M04, this milestone introduces no new functional
capability class (no new event type, no new Telegram feature, no new
persisted concept); it restructures how nine already-shipped screens are
reached. Per `docs/ARCHITECTURE.md`'s own convention ("each a minor bump,
since each milestone is a genuine new functional-capability class"), the
converse — a pure IA/navigation change — reads as a patch. The Master
Architect may reasonably prefer a minor bump instead, given the scale of
the change to every admin URL; this plan flags the choice rather than
insisting.

**ADR-0020 required**: yes, under governance's own test ("architecture,
a security boundary, a persistence model, a public contract, a milestone
boundary, or a previously accepted decision"), but narrowly scoped to two
things only: (a) establishing the `Hub`/tab-navigation contract itself —
the `Tab`/`TabRegistry`/`HubPage` shape and the capability-per-tab model
— as the Administration boundary's new pattern; (b) the legacy-URL
compatibility contract (§5/§9) — 302-only, `GET`-only redirects, with
`admin-post.php` mutation routes explicitly out of its scope, since they
are untouched. It deliberately does **not** bind every future milestone's
admin screen unconditionally: a later milestone (M05 onward) is expected
to join the Hub by registering its own `Tab`, but may instead propose a
documented architectural exception through its own frozen plan and
Master Architect review, if a genuine, distinct requirement warrants a
different surface (e.g. a screen with a legitimate reason not to be a
peer tab of the other nine) — this ADR sets the default, not an absolute
rule, consistent with ADR-0005's own precedent of assigning a default
placement while still allowing a later, superseding decision when
genuinely warranted. Full proposed text below (§14), materializable
verbatim alongside the frozen plan.

## 13. Documentation/closure updates

`docs/ARCHITECTURE.md`: no boundary-table change (Administration row
already covers Diagnostics/Telegram/Automations/Visitor subdomains; this
adds one line noting the `Hub` subdomain and that it is now the sole
menu-registration pattern for Administration screens going forward).
`readme.txt`: version bump line only. A new closure record,
`docs/closure/m04-1-administration-settings-hub-closure.md`, following the
M04 closure record's exact shape (frozen-plan SHA, WP commit list, PR,
security/reliability confirmations, final status). The closure record
must state explicitly, as this document's own instructions require:
**M05 remains unstarted** — this milestone touches only administration
navigation for already-shipped M00–M04 functionality, introduces no
Conversations-boundary code, and does not advance the roadmap sequence.

Self-check before implementation: the nine-row mapping in §3 accounts for
every one of the six existing `add_submenu_page()`/`add_menu_page()` call
sites found by direct search of `src/Administration/**/*.php`, each
exactly once; no new event type, rule condition, persisted field, queue
job, or Telegram/WooCommerce/AI behavior appears anywhere in §4–§10; every
existing nonce/capability check named in §4–§7 is the same check already
present today, only relocated, never removed or weakened.

## 14. Proposed ADR text

### ADR-0020: Administration Hub — Single Menu Entry with URL-Driven Tabs

#### Status

Proposed

#### Context

By M04 close, the plugin registers one top-level admin menu
(`DiagnosticsPage`, doubling as landing page and diagnostics report) plus
six submenu pages (`BotManagementPage`, `EventCatalogPage`,
`RuleBuilderPage`, `RuleSimulatorPage`, `EventHistoryPage`,
`VisitorTrackingPage`), each independently calling `add_submenu_page()`
with its own capability parameter (`CapabilityRegistrar::MANAGE` or
`::MANAGE_AUTOMATIONS`). The Product Owner has determined this pattern
does not scale to the remaining roadmap (M05 Conversations, M06
ChatWidget, M07 Operator workflow, M09 AI each plausibly add their own
screens) and has decided the WordPress left-hand menu should show exactly
one entry, `Telegram Hub`, with every current and future screen reached
through URL-driven horizontal tabs on one shared page shell
(`admin.php?page=universal-telegram&tab=<id>`), preserving every existing
capability, nonce, and form behavior unchanged.

#### Decision

1. A new `Administration\Hub` subdomain (of the existing `Administration`
   top-level boundary, per ADR-0005 — no new boundary) owns a `Tab` value
   object, a `TabRegistry`, and one `HubPage` shell registered via a
   single `add_menu_page()` call under the existing `SLUG =
   'universal-telegram'`, gated on `CapabilityRegistrar::MANAGE`.
2. Every existing admin screen's `render()` method is renamed to
   `render_tab_content()`, stripped of its own outer `.wrap`/`<h1>`
   markup (now owned once by `HubPage`), and registered as one `Tab`
   against the shared registry instead of calling `add_submenu_page()`
   itself. Each tab keeps its own existing capability check
   (`MANAGE` or `MANAGE_AUTOMATIONS`) as its own `Tab::capability()`,
   re-verified independently inside `HubPage::render()` before that
   tab's content method ever runs — the same defense-in-depth posture
   every page already had, now centralized rather than duplicated per
   page.
3. Every retired submenu slug is preserved permanently as a hidden,
   capability-gated compatibility entry point
   (`add_submenu_page(null, ...)`). Capability is checked first, before
   any redirect target is computed or any content is disclosed. Only a
   `GET` request to that slug, from an authorized user, is redirected —
   temporarily (302) — to its equivalent `tab=` URL; a non-`GET` request
   never triggers a redirect, so no future mutation aimed at an old slug
   can be silently carried forward to a new location. This preserves
   every existing bookmark, saved link, or external reference without
   ever implying the old URL is a stable, cacheable canonical target.
4. This pattern is the Administration boundary's new default for an
   administration screen: a later milestone introducing a new
   administration surface is expected to register a `Tab` against the
   existing `TabRegistry` rather than calling
   `add_submenu_page()`/`add_menu_page()` directly. This is a default,
   not an unconditional requirement — a later milestone may instead
   adopt a documented architectural exception, justified in its own
   frozen plan and accepted through the same Master Architect review
   every plan already requires, if a genuine, distinct requirement makes
   a peer tab unsuitable. This decision does not itself enumerate what
   would qualify as such an exception; that judgment is left to the
   later milestone's own plan and review.
5. No new WordPress capability is introduced; the existing `MANAGE`/
   `MANAGE_AUTOMATIONS` two-capability model (ADR-0010) is reused
   unchanged, including its known asymmetry (a hypothetical
   `MANAGE_AUTOMATIONS`-only role could not reach the parent menu at all,
   since the menu itself is gated on `MANAGE`) — accepted as unchanged
   risk versus the status quo, not solved by this decision.

#### Alternatives

- Leave the growing submenu hierarchy as-is and simply keep adding
  entries per future milestone — rejected by explicit Product Owner
  decision as the motivating problem this ADR resolves.
- A JavaScript single-page tab switcher (client-side show/hide, one
  initial page load) — rejected: breaks native browser back/forward and
  bookmarking of individual tabs, and this plugin's charter (master plan
  §10, §7.10) already commits to standard WordPress-admin conventions and
  accessibility without a new frontend framework; a real per-tab GET
  request is simpler, more accessible by default, and consistent with
  how WordPress core itself implements admin subtabs.
- Gate the parent menu on the narrower of the two capabilities, or on a
  new third "can see the hub at all" capability — rejected: the charter
  excludes capability-model changes unless strictly necessary, and no
  role in this product currently holds one existing capability without
  the other, so the narrower-gating case has no live scenario to justify
  the added complexity.
- Use permanent (301) redirects for retired URLs — rejected: a 301 tells
  browsers and any caching layer to stop revalidating the old URL and
  treat the new one as canonical going forward, which forecloses this
  plugin's own ability to further adjust the tab mapping in a later
  milestone without depending on every client's cache having expired; a
  302 keeps the redirect itself revalidated on every visit while still
  fully preserving the old URL's reachability, which is what
  compatibility requires here — durability of the *entry point*, not of
  the specific redirect response.
- Redirect a legacy URL regardless of HTTP method — rejected: a redirect
  issued in response to a `POST` (or any non-`GET` mutation) can cause
  some clients to silently re-issue that request against the new
  location, which is an unacceptable outcome for any future integration
  that might still target an old slug, even though no current mutation
  route is tied to `admin.php?page=` at all (every mutation already goes
  through the separate `admin-post.php` surface, untouched by this
  decision). Restricting the redirect to `GET` closes this off entirely
  rather than relying on it never mattering in practice.

#### Consequences

Every future milestone's own admin screen defaults to being written
against `Administration\Hub\TabRegistry` rather than calling
`add_submenu_page()` itself, unless that milestone's own plan documents
and justifies an exception through Master Architect review — a default
this decision sets for M05 onward, not an unconditional constraint. The
plugin-row "Settings" link and every previously bookmarked admin URL now
redirect (temporarily, `GET`-only) rather than load directly; this is a
one-time URL-shape change administrators and any external documentation
should adopt, while the old URLs themselves remain permanently reachable
as compatibility entry points. No security boundary, persistence model,
or event/rule/Telegram/WooCommerce/AI contract changes. No mutation route
is affected: `admin-post.php` handlers are outside this decision's scope
entirely.

#### Security and privacy impact

None beyond centralizing (not weakening) the existing per-page capability
checks described in Decision item 2. No new capability, no new data
collection, no new public/unauthenticated endpoint (unlike ADR-0019, this
plan's redirects and tabs are all within the existing capability-gated
`wp-admin` surface).

#### Affected Documents/Milestones

`docs/ARCHITECTURE.md` (Administration boundary description gains the
`Hub` subdomain and its default tab-registration convention); every
future milestone from M05 onward that introduces its own administration
screen, which defaults to registering a `Tab` against this decision's
registry rather than inventing its own `add_submenu_page()` call, unless
that milestone's own frozen plan documents and justifies a specific
exception.

#### Compatibility/Migration Impact

No database schema change; `db_version` remains `10`. Every existing
admin URL keeps working, as a permanent compatibility entry point issuing
a temporary (302), `GET`-only redirect (§ Decision item 3); no existing
nonce, capability check, `admin-post.php` route, or form field name
changes. Plugin version moves `0.3.0 → 0.3.1` per this plan's own
recommendation (§12) — a patch bump, since this milestone introduces no
new end-user functional capability and no persistence change, only a
navigation restructuring — subject to Master Architect confirmation.
