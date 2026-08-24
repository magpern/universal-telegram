# Combined M11 + M07.1 — CI-Repair and Validation-Gate Closure

## Status

**VALIDATION GATE CLOSED — EXPLORATORY WIDE TESTING AUTHORIZED**

This record formally closes the combined **CI-repair pass** and the deferred **full validation gate** that followed the exploratory merge of M11A + M11B + M07.1 to `main`.

It does **not** claim that M11A, M11B, or M07.1 are PASS, Product-Owner-accepted, tagged, released, or production-ready.

## What was closed

| Gate | Result |
|------|--------|
| Combined local validation | **PASS** |
| CI / GitHub Actions review | **PASS** |
| Defect repair (CI-repair) | **PASS** (landed) |
| Full validation gate (local matrix) | **PASS** |

## SHAs and PR

| Item | Value |
|------|-------|
| Exploratory merge to `main` | `708804b301dab5dda0b6146c9278b4343fdc0f32` (PR #26) |
| Pre-repair `main` tip | `a4db1e7` |
| CI-repair branch | `fix/ci-repair-m11-m07-1` |
| CI-repair commit | `a8dec18` — `fix(ci): repair M11/M07.1 baseline after exploratory merge` |
| CI-repair PR | https://github.com/magpern/universal-telegram/pull/27 |
| Merge to `main` | `aa668721c437b2e498447dafe0804e2acb3d001f` |
| Closure record commit | `b3cf593bdca8f0d0e66a73550a87e2bb44e0b1fc` |
| Plugin version on `main` | `0.14.0` |
| `db_version` target on `main` | `29` |

## Evidence (automated)

Local gate on `main` after merge (all exit 0):

- `bin/docker/phpcs.sh`
- `bin/docker/phpstan.sh` (2G / single-process locally; CI default matrix on GHA)
- `bin/docker/test-unit.sh`
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`
- `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`
- `bin/docker/build-zip.sh`
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3 --woocommerce=11.0.1`

GitHub Actions: PR #27 and the subsequent `main` push of the merge commit both completed successfully (phpcs, static-analysis, unit 8.1/8.3/8.4, integration WP-only floor + current, integration WC-present, js-behavioural, build, package-acceptance matrix).

## Repair themes (non-feature)

1. **MigrationLock under PHPUnit:** DDL implicitly commits the lock INSERT; test tearDown would otherwise roll back the release DELETE and resurrect a held lock / stale `db_version`. Test-bootstrap-only `COMMIT` + `START TRANSACTION` after release under `WP_TESTS_DOMAIN`.
2. **Migrator step 29 presence checks:** `SHOW COLUMNS` / `SHOW INDEX` instead of stale `INFORMATION_SCHEMA` after DROP+recreate in the same PHPUnit process, so `UNIQUE(destination_id)` is not skipped.
3. **PHPCS / PHPStan / test fixtures:** sanitizer clamps, schema target 29, CredentialVault wiring, hook documentation, and related M11/M07.1 expectation fixes.

## Critical safety invariants (unchanged; must not regress in exploratory testing)

1. Topic lookup = exact `(bot_id, chat_id, message_thread_id)`.
2. `chat not found` on delete → retain / `delete_failed`, never purge.
3. Exclusive destination ownership before remote delete / destination-row delete.

## Explicitly not claimed

- M11A / M11B / M07.1 technical PASS or Product Owner acceptance.
- Git tag, GitHub Release, production deployment, or live Telegram/provider configuration change as part of this closure.
- Completion of the M07.1 manual exploratory checklist (archive, confirm permanent delete, reopen+retry, already-removed topic, chat-not-found repair banner, Bots-tab delete control absence).

## Next authorized step

**Exploratory wide testing on `main` (`0.14.0` / `db_version` 29).**

Remaining after exploratory testing:

1. Defect repair and affected-check reruns (only if exploratory finds defects).
2. Formal technical closure and Product Owner acceptance of M11A, M11B, and M07.1.
3. Release decision (tag / ZIP / deploy).

Related records: `docs/closure/m11-combined-exploratory-merge-record.md`, `docs/closure/m07-1-conversation-topic-lifecycle-and-repair-closure.md`.
