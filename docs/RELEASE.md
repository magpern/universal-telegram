# Release process — Telegram Operations Hub (universal-telegram)

## Canonical version source

| Location | Field | Authoritative? |
|---|---|---|
| `universal-telegram.php` | `Version:` plugin header | **yes** |
| `universal-telegram.php` | `UNIVERSAL_TELEGRAM_VERSION` constant | **yes** |
| `readme.txt` | `Stable tag:` | **yes** |
| `readme.txt` | `= <version> =` changelog section | **yes** |
| `CHANGELOG.md` | `## [<version>]` | **no** — currently lags the plugin version; not gated on, fix separately |

`scripts/build-release-package.sh` refuses to build unless header == constant ==
`Stable tag` and `readme.txt` has the matching `= <version> =` section.
`.github/workflows/release.yml` additionally refuses to publish unless the
header, constant and `Stable tag` all equal the pushed Git tag (leading `v`
removed). CI never rewrites version files.

## Package identity

| Item | Value |
|---|---|
| Deployable directory | `universal-telegram/` (sole top-level entry) |
| ZIP | `dist/universal-telegram-<version>.zip` |
| Checksum | `dist/universal-telegram-<version>.zip.sha256` |

**Included:** `universal-telegram.php`, `universal-telegram-functions.php`,
`uninstall.php`, `src/`, `readme.txt`, `composer.json`, `README.md`, `LICENSE`,
`CHANGELOG.md`, and a freshly generated production `vendor/`
(`composer install --no-dev` against the committed `composer.lock`). The
packaging script asserts the bundled runtime dependency
`vendor/woocommerce/action-scheduler/action-scheduler.php` is present.

**Excluded:** `.git/`, `.github/`, `.claude/`, `bin/`, `docker/`, `docs/`,
`tests/`, `dist/`, `composer.lock`, `phpcs.xml.dist`, `phpstan.neon.dist`,
`phpunit*.xml.dist`, `.phpunit.result.cache`, `.gitignore`, and any previous
build output. The script fails if any appear in the ZIP.

## Single packaging implementation

`scripts/build-release-package.sh` is the **only** packaging code. The former
`bin/build-zip.sh` and `bin/docker/build-zip.sh` are removed. The
`tests/package/run.sh` acceptance suite (invoked by
`bin/docker/test-package.sh`) now builds via the same script, so "does the ZIP
install and activate in a real WordPress" is validated against the exact
artifact a release publishes.

## Build and validate locally

```bash
bin/docker/build-release-package.sh            # version from the plugin file
bin/docker/build-release-package.sh 0.19.0     # must match the plugin file

# full "install into real WordPress" acceptance:
bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3

cd dist
sha256sum -c universal-telegram-<version>.zip.sha256
unzip -l universal-telegram-<version>.zip
```

## Cutting a release

1. Bump the `Version:` header, `UNIVERSAL_TELEGRAM_VERSION`, and `readme.txt`
   (`Stable tag` + a `= <version> =` section) in one commit. (Ideally also
   reconcile `CHANGELOG.md`, though it is not gated.)
2. Merge to **`main`** (the only release branch) and wait for the full CI
   matrix to go green on that commit.
3. Push an annotated tag matching `v[0-9]+.[0-9]+.[0-9]+`
   (`-rc.N` / `-beta.N` suffix → GitHub prerelease):
   ```bash
   git tag -a v0.19.0 -m "Telegram Operations Hub 0.19.0"
   git push origin v0.19.0
   ```
4. `release.yml` re-runs PHPCS / PHPStan / unit tests plus one package
   acceptance leg, builds the ZIP, verifies the packaged version == tag,
   generates the SHA-256 checksum, and creates the GitHub Release with the ZIP
   + `.zip.sha256` attached.
5. Both assets appear on the Release page
   (`https://github.com/magpern/universal-telegram/releases/tag/v<version>`).

## Using the artifact for deployment

Normal WordPress plugin archive — `wp plugin install <zip> --activate` or
**Plugins → Add New → Upload Plugin**. Telegram-specific: after deploying a new
version, re-check the plugin's Diagnostics tab and confirm the outbound queue /
Action Scheduler are healthy; no webhook re-registration is required by
packaging alone. Verify before deploying:

```bash
sha256sum -c universal-telegram-<version>.zip.sha256
```

Generated ZIPs/checksums are CI outputs — `dist/` is `.gitignore`d; never
commit them.

## Recovering from a failed release

- Failure before "Create GitHub Release" → nothing published. Fix the version
  declarations on `main`, delete the tag
  (`git push --delete origin v<version>`, `git tag -d v<version>`), re-tag the
  corrected commit.
- Failure during publish → delete the partial GitHub Release in the UI, re-run
  the workflow from the Actions tab.
- Always tag a commit already on `main`; never release from a feature branch.
