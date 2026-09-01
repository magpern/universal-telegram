# Telegram Operations Hub for WordPress

A WordPress plugin bringing WooCommerce and site events into Telegram, with an operator conversation workflow and controlled AI assistance. This repository is at milestone M00 (Product Foundation): no end-user functionality ships yet — see `docs/milestones/m00-product-foundation.md`.

## Requirements

- WordPress 6.9+ (tested up to 7.1)
- PHP 8.1+
- WooCommerce is a genuinely optional integration (`docs/adr/0003-optional-woocommerce-integration.md`)

## Development

This repository is developed entirely through Docker; no host installation of PHP, Composer, or Node is required or expected.

```
bin/docker/composer.sh install --no-interaction   # install dependencies
bin/docker/phpcs.sh                               # coding standards
bin/docker/phpstan.sh                             # static analysis
bin/docker/test-unit.sh                           # unit tests (no WordPress)
bin/docker/test-integration-wp-only.sh --wp-version=6.9
bin/docker/test-integration-wp-only.sh --wp-version=7.1
bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1
bin/docker/build-release-package.sh               # build the deployable ZIP + SHA-256
bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3
bin/docker/composer.sh run-script check-doc-links  # verify documentation links
```

## Releases

Pushing an annotated `vX.Y.Z` tag on `main` runs
`.github/workflows/release.yml`: it re-runs PHPCS / PHPStan / unit tests and one
real "install the built ZIP into WordPress" acceptance leg, builds
`universal-telegram-<version>.zip` + `.zip.sha256` with
`scripts/build-release-package.sh` (Docker wrapper
`bin/docker/build-release-package.sh`), verifies the packaged version equals the
tag (header + `UNIVERSAL_TELEGRAM_VERSION` + `readme.txt` `Stable tag`), and
publishes a GitHub Release with both assets. Nothing generated is committed
(`dist/` is `.gitignore`d). Full procedure and the canonical version source:
[`docs/RELEASE.md`](docs/RELEASE.md).

## Documentation

- Governance and milestone lifecycle: `docs/governance.md`
- Architecture reference (product boundaries, versioning conventions): `docs/ARCHITECTURE.md`
- Milestone charters: `docs/milestones/`
- Architecture decision records: `docs/adr/`
- Implementation plans: `docs/plans/`
- Test strategy: `docs/testing/`
- Milestone closure records: `docs/closure/`
- Release process: [`docs/RELEASE.md`](docs/RELEASE.md)

## License

GPL-2.0-or-later. See `LICENSE`.
