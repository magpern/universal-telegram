# Architecture Reference

This document records the plugin's thirteen authoritative product boundaries and the versioning conventions established by the M00 plan (`docs/plans/m00-product-foundation-plan-v1.md`, section 4.1 and section 9). It is the canonical source of truth for boundary ownership; a structural guard test (`tests/unit/Core/StructuralBoundariesTest.php`, added if and when a later milestone needs one — see below) enforces the continued absence of an unimplemented boundary's directory until its own owning milestone's frozen plan authorizes creating it.

## The thirteen product boundaries

| # | Boundary | Namespace | Status at M00 |
|---|---|---|---|
| 1 | Core | `UniversalTelegram\Core` | Implemented (composition root, configuration, lifecycle, security, capabilities) |
| 2 | Persistence | `UniversalTelegram\Persistence` | Implemented (migration framework, atomic lock, schema health) |
| 3 | Queue | `UniversalTelegram\Queue` | Implemented (job envelope, dispatcher, worker runner, retry policy) |
| 4 | Audit | `UniversalTelegram\Audit` | Implemented (audit logger and repository) |
| 5 | Privacy | `UniversalTelegram\Privacy` | Implemented (classification and redaction) |
| 6 | Events | `UniversalTelegram\Events` | Not implemented — owned by M02; WooCommerce-specific event coverage (M03) and visitor/browser events (M04) are subdomains of this boundary, not separate boundaries |
| 7 | Automations | `UniversalTelegram\Automations` | Not implemented — owned by M02; digests and operational intelligence (M11) are a subdomain of this boundary |
| 8 | Telegram | `UniversalTelegram\Telegram` | Not implemented — owned by M01; the administrative Telegram bot (M08) is a subdomain of this boundary |
| 9 | Conversations | `UniversalTelegram\Conversations` | Not implemented — owned by M05; operator workflow (M07) is a subdomain of this boundary |
| 10 | ChatWidget | `UniversalTelegram\ChatWidget` | Not implemented — owned by M06 |
| 11 | AI | `UniversalTelegram\AI` | Not implemented — owned by M09 |
| 12 | Administration | `UniversalTelegram\Administration` | Implemented (the `Diagnostics` subdomain only, at M00) |
| 13 | Integrations | `UniversalTelegram\Integrations` | Implemented (the `WooCommerce` subdomain, presence detection only) |

`Security`, `Configuration`, and `Lifecycle` are internal components of the `Core` boundary, not boundaries of their own. `Diagnostics` is an internal subdomain of `Administration`. No later milestone should introduce a new top-level boundary beyond these thirteen; a genuinely new product concern is placed as a subdomain of the closest-fitting existing boundary, or requires its own architecture decision superseding this one if none fits.

## Versioning conventions

The plugin's initial development version is `0.0.1`, held in the `UNIVERSAL_TELEGRAM_VERSION` constant and mirrored in `readme.txt`'s own stable-tag field. Versioning follows Semantic Versioning: while the plugin remains below `1.0.0`, any of the minor or patch positions may introduce a breaking change without a major-version bump. Version `1.0.0` is reserved for the release completing milestones M00 through M07 together with M12; major-version bumps after that point are reserved for breaking changes to the plugin's own public contracts, once any exist.

The database schema version is an entirely independent, monotonically increasing integer, unrelated to the plugin's own Semantic Versioning string, stored in its own dedicated option (`universal_telegram_db_version`), starting at `1` for M00's own single migration step.

A future Git tag, whenever one is first created by whichever milestone first produces a public release, follows the format `vX.Y.Z`. A built distributable package is named `universal-telegram-{version}.zip`, built via `bin/docker/build-zip.sh`. No Git tag and no GitHub Release exist at M00.

## Where to look

- Governance and milestone lifecycle: `docs/governance.md`
- Milestone charters: `docs/milestones/`
- Architecture decision records: `docs/adr/`
- The frozen M00 implementation plan: `docs/plans/m00-product-foundation-plan-v1.md`
- Test strategy: `docs/testing/`
