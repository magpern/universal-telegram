# ADR-0003 — Optional WooCommerce Integration

## Status

Accepted (effective upon Product Owner approval of the documentation baseline that includes this ADR)

## Context

master-plan.md's own principles imply the plugin should function on a WordPress-only site — an administrator may use Telegram notifications without enabling visitor tracking, chat, or AI, and WordPress-only events are treated separately from WooCommerce-specific events. No section of master-plan.md states explicitly whether WooCommerce is a hard dependency or an optional integration. Left unresolved, this ambiguity would be inherited silently into M00's bootstrap design and into every later milestone's test matrix.

## Decision

- WooCommerce is an optional integration. The plugin does not declare a hard WooCommerce requirement in its bootstrap header and must activate, configure, and operate its core WordPress and Telegram functionality with WooCommerce absent.
- WooCommerce-specific functionality (event coverage in M03, and WooCommerce-dependent commands such as order, stock, and sales queries in M08) activates only when WooCommerce is detected present and compatible.
- CI and integration testing require both a WordPress-only configuration and a WooCommerce-present configuration wherever a milestone's scope could plausibly interact with WooCommerce, per docs/testing/test-strategy.md.

## Alternatives

- A hard dependency on WooCommerce, matching this Product Owner's other sibling plugins — rejected: those plugins' entire premise is WooCommerce-specific; this plugin's premise (Telegram notifications and chat) is not, per master-plan.md's own operational-separation principle.
- Defer the decision to M00's own implementation plan — rejected: the decision changes the bootstrap file's plugin header, the Integrations module's design, and the entire CI test matrix; deferring it would force rework once decided later.

## Consequences

- M00 must build a WooCommerce-presence detection surface. Compatibility is declared when WooCommerce is present; all related integration code remains safely inert when WooCommerce is absent.
- Every milestone from M03 onward that touches WooCommerce must be designed to be skippable and inert when WooCommerce is absent, and every relevant milestone's CI runs both configurations.
- M08's command set spans both WordPress-only and WooCommerce-dependent commands; its charter accounts for this directly through its dependency list.

## Affected Documents/Milestones

docs/testing/test-strategy.md; M00 (Integrations module); M03 (activates only when WooCommerce present); M08 (order, stock, and sales commands depend on M03).

## Compatibility/Migration Impact

None — no code exists yet. A future decision to make WooCommerce a hard dependency requires a new ADR superseding this one.
