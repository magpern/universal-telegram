# M08.2 Addendum — Grouped Hub Navigation: Plan v1

## Context

The Hub top navigation (`HubPage`/`TabRegistry`/`Tab`, ADR-0020) currently
registers 13 flat top-level tabs (Overview, Bots, Events, Notifications
[`rules`], Test notifications, Event History, Visitor Tracking, Settings,
Operator Identities, Conversations [`operator-inbox`], AI, AI Content,
Diagnostics). After M08.2 added "Test notifications," the row is crowded
and the grouping is no longer legible.

**Product Owner decision**: reduce the top-level row to seven areas —
Overview, Bots, Notifications & activity, Conversations, AI, Settings,
Diagnostics — with the remaining screens reachable as an accessible
secondary tab row inside the area they belong to.

**This is a presentation/navigation refinement only.** No feature behavior,
capability model, persistence, event contract, Telegram behavior, AI
behavior, or business logic changes. Every grouped screen is the exact
existing page class, reused unchanged, with its own capability check,
heading, and content untouched — only how it is *reached* changes.

## Verified current tab registration (`src/Core/Plugin.php`)

| Tab id (current) | Label | Capability | Page class |
|---|---|---|---|
| `overview` | Overview | MANAGE | `OverviewPage` |
| `bots` | Bots | MANAGE | `BotManagementPage` |
| `events` | Events | MANAGE_AUTOMATIONS | `EventCatalogPage` |
| `rules` | Notifications | MANAGE_AUTOMATIONS | `RuleBuilderPage` (also composes the M11B Intelligence section — Daily operations summary + Threshold alerts — inline) |
| `test-notifications` | Test notifications | MANAGE_AUTOMATIONS | `NotificationTesterPage` |
| `event-history` | Event History | MANAGE_AUTOMATIONS | `EventHistoryPage` |
| `visitor-tracking` | Visitor Tracking | MANAGE | `VisitorTrackingPage` |
| `settings` | Settings | MANAGE | `SettingsPage` |
| `operator-identities` | Operator Identities | MANAGE | `OperatorIdentityPage` |
| `operator-inbox` | Conversations | MANAGE_CONVERSATIONS | `ConversationInboxPage` |
| `ai` | AI | MANAGE | `AISettingsPage` |
| `ai-content` | AI Content | MANAGE | `ApprovedContentPage` |
| `diagnostics` | Diagnostics | MANAGE | `DiagnosticsPage` |

"Daily operations summary" and "Threshold alerts" are not separate tabs —
they are `<h3>` sections `RuleBuilderPage::render_intelligence_settings()`
already renders unconditionally inside the `rules` tab's own content. They
need no code change: once `rules` is reachable as a child of "Notifications
& activity," they are reachable exactly as before, with no duplication.

## New grouping

| Top-level area (new `tab` id) | Label | Children (new `section` id = old `tab` id) |
|---|---|---|
| `overview` (unchanged) | Overview | — |
| `bots` (unchanged) | Bots | — |
| `notifications-activity` | Notifications & activity | `rules` (Notifications), `test-notifications` (Test notifications), `events` (Events), `event-history` (Event History), `visitor-tracking` (Visitor Tracking) |
| `conversations` | Conversations | `operator-inbox` (Conversations), `operator-identities` (Operator Identities) |
| `ai-hub` | AI | `ai` (AI drafts), `ai-content` (AI content) |
| `settings` (unchanged) | Settings | — |
| `diagnostics` (unchanged) | Diagnostics | — |

Every child id is the exact existing `TAB_ID`/literal tab id already in
use — no id is invented, no page controller is duplicated. Only the AI
children's *nav labels* change ("AI" → "AI drafts", "AI Content" → "AI
content"); each page's own `<h2>` heading ("AI Draft Assistant," "Approved
AI Source Content") is untouched.

## URL shape

`admin.php?page=universal-telegram&tab=<area id>&section=<child id>`

`tab` keeps its existing meaning and query-param name (HubPage already
resolves it); `section` is new, read only by the area's own page object,
exactly the same "GET-only catalog selector" pattern `mode`/`rule_id`/
`event_type` already use on the Test notifications page.

## Compatibility mechanism

`HubPage::resolve_tab_id()` already carries one `LEGACY_TAB_ALIASES` entry
(`simulator` → the old `test-notifications` top-level id, added when that
tab was renamed). It is extended to a richer `old id => [new area id, new
section id]` map covering every moved child, including `simulator` itself
(now two hops: `simulator` → `notifications-activity` / `test-notifications`).
When an old id is matched, `resolve_tab_id()` sets `$_GET['section']` to the
matched child id before returning the area id — so `?tab=rules`,
`?tab=test-notifications`, `?tab=simulator`, `?tab=events`,
`?tab=event-history`, `?tab=visitor-tracking`, `?tab=operator-inbox`,
`?tab=operator-identities`, `?tab=ai`, `?tab=ai-content` all keep landing on
their own exact former content, now inside their new area. `LegacyUrlRedirector`
(the pre-Hub-era slug map) needs no change: it already redirects to
`?tab=<old id>`, which now flows through the same alias resolution.

An unknown/invalid `section` for a known area — or a `section` the current
user cannot access — falls back to the first section that user *can*
access, never an error and never silently landing on the area's nominal
first entry regardless of capability.

## Capability/visibility contract

- Every child page's own `current_user_can()` check inside its
  `render_tab_content()` is untouched — the authoritative, defense-in-depth
  gate, exactly as before.
- A new area is accessible only if at least one of its children's
  capability passes for the current user; `HubPage`'s own render-time gate
  and the top nav's link-rendering both use this same check for the three
  new area tabs specifically (an opt-in `Tab` constructor closure) — solo
  tabs (`overview`, `bots`, `settings`, `diagnostics`) keep their exact
  current behavior (always listed in the nav; gated only at render time),
  since they never supply that closure.
- The secondary nav row lists only sections the current viewer can access.

## Implementation

- `Tab` (`src/Administration/Hub/Tab.php`): add one optional 5th
  constructor param, a niladic `?callable $accessible`. `is_accessible()`
  uses it when present, else falls back to `current_user_can($capability)`
  — byte-identical to today's behavior for every existing call site.
  `has_accessibility_override(): bool` reports whether the closure was
  supplied, so `HubPage`'s nav-listing loop can choose to hide only tabs
  that opt in.
- `HubPage` (`resolve_tab_id()`, `render()`, `render_tab_nav()`): the
  richer alias table (above); `render()`'s gate switches from
  `current_user_can($tab->capability())` to `$tab->is_accessible()`
  (identical value for every existing tab, since none supply the closure
  yet); `render_tab_nav()` skips a tab only when
  `has_accessibility_override() && !is_accessible()`.
- New `AreaPage` (`src/Administration/Hub/AreaPage.php`): takes an area id
  and an ordered list of `Tab` "sections" (the existing `Tab` value object,
  reused rather than duplicated — its id/label/capability/render shape is
  exactly what a section needs). `is_accessible()` = any section
  accessible. `render_tab_content()` resolves the requested `section`
  (falling back to the first accessible one, or `wp_die()` if truly none),
  renders a secondary `<h2 class="nav-tab-wrapper">`-style row (identical
  WP-native classes `HubPage::render_tab_nav()` already uses, so no new
  CSS system), then delegates to that section's own `render()` — no
  content is duplicated or reimplemented.
- `Plugin.php`: remove the nine now-grouped screens' individual
  `hub_tab_registry->register()` calls (their page objects, `add_action()`
  wiring, and all other construction stay exactly where they are — only
  the `register(new Tab(...))` lines move/disappear). After all nine page
  objects exist, construct three `AreaPage`s and register them as the new
  `notifications-activity`, `conversations`, `ai-hub` tabs. Move
  `SettingsPage`'s existing `register()` call to immediately after the new
  AI area's registration, so final registration order is Overview, Bots,
  Notifications & activity, Conversations, AI, Settings, Diagnostics
  (Diagnostics is already registered last and is untouched).
- `OverviewPage::OTHER_TABS`: update entries for the five moved
  Notifications-&-activity links to carry `&section=`; other entries
  unchanged.

## Verification

- Manual: `docker compose run --rm wpcli wp eval` a page render for each
  area/section combination is not run as part of this task (execution
  authorization excludes it) — see the exploratory-testing handoff instead.
- Automated (written, not run): area/child resolution, every moved screen's
  legacy URL, `tab=simulator`, direct deep links, invalid-child fallback,
  capability-gated parent/child visibility, active-state rendering, no
  duplicate registration, Daily operations summary/Threshold alerts still
  reachable, and every existing M08.1/M08.2 URL resolving correctly.
