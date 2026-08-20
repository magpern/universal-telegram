# ADR-0002 — Plugin Identity and Naming

## Status

Accepted (effective upon Product Owner approval of the documentation baseline that includes this ADR)

## Context

master-plan.md states a provisional working name and explicitly defers final naming. The repository's own pre-existing directory name implied a different, shorter identifier consistent with this Product Owner's other Universal-X plugins. Every technical identifier that follows from a name is expensive to change once code exists, so this needed resolving before M00 implementation.

## Decision

- Display name, used in the WordPress Plugin Name header, readme.txt title, and all human-facing copy: Telegram Operations Hub for WordPress.
- Slug, GitHub repository name, plugin folder name, and text domain: universal-telegram.
- The PHP namespace root, hook and option prefix, and composer package name are not decided by this ADR; they are an M00 implementation-plan decision that must be consistent with this ADR's slug.

## Alternatives

- Fully align technical identifiers with the master-plan.md working name — rejected: conflicts with the Product Owner's explicit direction that the display name and the slug/repository identity are deliberately different, matching this Product Owner's existing product-line convention.
- Leave naming provisional into M00 — rejected: every M00 deliverable depends on this being fixed first.

## Consequences

- master-plan.md records the finalized identity decided here and removes its obsolete provisional-naming statement ("A final public name can be selected later."), replacing it with a pointer to this ADR.
- Any future rename requires a new ADR that supersedes this one and is treated as a dedicated rename milestone, not an incidental change inside another milestone.

## Affected Documents/Milestones

master-plan.md addendum; M00 (all bootstrap, composer, and text-domain deliverables must use these identifiers).

## Compatibility/Migration Impact

None — no code, package, or repository exists yet under any name.
