# ADR-0020: Administration Hub — Single Menu Entry with URL-Driven Tabs

## Status

Proposed

## Context

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

## Decision

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

## Alternatives

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

## Consequences

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

## Security and privacy impact

None beyond centralizing (not weakening) the existing per-page capability
checks described in Decision item 2. No new capability, no new data
collection, no new public/unauthenticated endpoint (unlike ADR-0019, this
plan's redirects and tabs are all within the existing capability-gated
`wp-admin` surface).

## Affected Documents/Milestones

`docs/ARCHITECTURE.md` (Administration boundary description gains the
`Hub` subdomain and its default tab-registration convention); every
future milestone from M05 onward that introduces its own administration
screen, which defaults to registering a `Tab` against this decision's
registry rather than inventing its own `add_submenu_page()` call, unless
that milestone's own frozen plan documents and justifies a specific
exception.

## Compatibility/Migration Impact

No database schema change; `db_version` remains `10`. Every existing
admin URL keeps working, as a permanent compatibility entry point issuing
a temporary (302), `GET`-only redirect (Decision item 3); no existing
nonce, capability check, `admin-post.php` route, or form field name
changes. Plugin version moves `0.3.0 → 0.3.1` — a patch bump, since this
milestone introduces no new end-user functional capability and no
persistence change, only a navigation restructuring.
