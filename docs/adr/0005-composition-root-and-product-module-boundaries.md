# ADR-0005 — Composition Root and Product Module Boundaries

## Status

Accepted

## Context

This milestone's own charter requires establishing module boundaries for all thirteen product modules, with later modules represented only as inert boundaries rather than functional code. An earlier draft of this decision proposed twelve empty placeholder classes, one per later milestone, registered at plugin boot purely so that a structural test could confirm they existed; review found this produced code whose only function was satisfying its own test, which this same milestone's charter explicitly excludes as a speculative extension surface with no genuine requirement behind it.

## Decision

The thirteen authoritative top-level product boundaries are, exactly: Core, Persistence, Queue, Audit, Privacy, Events, Automations, Telegram, Conversations, ChatWidget, AI, Administration, and Integrations. Later-milestone concerns are subdomains of these thirteen, never additional top-level boundaries of their own: WooCommerce-specific event coverage and visitor and browser events are both subdomains of Events; operator workflow is a subdomain of Conversations; the administrative Telegram bot is a subdomain of Telegram; digests and operational intelligence are a subdomain of Automations. The plugin's composition root is a single, final `Plugin` class implementing the singleton pattern already proven across this Product Owner's other plugins: an `instance()` accessor and an idempotent `init()` method guarded by a boolean flag, with no dependency-injection container, since every service this milestone constructs is wired by hand, explicitly, inside that one method. This milestone writes real, functional code only for the Core, Persistence, Queue, Audit, Privacy, and Integrations boundaries, together with the Diagnostics subdomain of Administration. The remaining six boundaries — Events, Automations, Telegram, Conversations, ChatWidget, and AI — receive no files of any kind at this milestone; their ownership and eventual layout are recorded only in a reference document, and an automated structural test asserts their continued absence from the source tree until each one's own owning milestone's own frozen plan authorizes its creation.

## Alternatives

Twelve empty placeholder classes, one per later milestone, registered at boot — rejected, since this produces dead code whose sole function is satisfying its own structural test, exactly the kind of speculative extension surface this milestone's charter excludes. No documented boundary map at all, left to emerge organically as each later milestone is planned — rejected, since the charter requires this map as a genuine deliverable of this milestone itself, and an emergent, undocumented map risks exactly the drift a documented one prevents. Treating internal implementation components such as configuration storage, lifecycle orchestration, credential security, and diagnostics as additional top-level boundaries in their own right — rejected, since these are properly understood as components internal to the Core and Administration boundaries respectively, not independent product concerns of their own.

## Consequences

Every later milestone must place its own code under whichever boundary or subdomain this decision assigns it, or must itself propose a superseding architecture decision if it believes a different assignment is warranted. The reference document recording boundary ownership becomes the canonical source of truth for this question, and the structural guard test enforcing the six undocumented boundaries' continued absence must be updated, never simply deleted, as each one's own owning milestone is authorized to begin creating it.

## Security and privacy impact

None directly; this decision governs source-code organization only, not a security or privacy boundary in its own right.

## Affected Documents/Milestones

The architecture reference document this decision requires; every milestone from M01 through M11, each of which must place its own code according to this map; milestone M12, which introduces no new boundary of its own, since its charter is cross-cutting hardening applied to boundaries this and earlier milestones already establish.

## Compatibility/Migration Impact

None. No code of any kind exists in this repository yet.
