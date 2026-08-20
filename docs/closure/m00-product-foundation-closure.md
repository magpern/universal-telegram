# Milestone Closure Record — M00 Product Foundation

- **Frozen plan commit SHA:** `704bd55` (`docs(m00): freeze product-foundation plan and ADRs`), materializing `docs/plans/m00-product-foundation-plan-v1.md` and ADR-0005 through ADR-0011.
- **Superseding plan commit SHA(s):** None. The plan was not revised during implementation.
- **Implementation commits** (branch `feature/m00-product-foundation`, merged to `main` via merge commit `ebbabda`):
  - `9c9841c` — WP1: bootstrap, Docker toolchain, and CI foundation
  - `606b9c4` — WP2: configuration storage and lifecycle scaffolding; refuses network activation
  - `55118d8` — WP3: migration framework, atomic lock, and safe degraded mode
  - `963e646` — WP4: privacy classification, fail-closed redaction, and audit logging
  - `e3a9904` — WP5: WooCommerce-presence detection; stands up the WooCommerce-present test configuration
  - `3a7afca` — WP6: AES-256-GCM credential vault with fail-closed key resolution
  - `b333e52` — WP7: capability model (`universal_telegram_manage`)
  - `9586042` — WP8: job envelope, dispatcher, and schema-aware worker runner with corrected degraded-mode handling
  - `ed030a9` — WP9: diagnostics page and bounded self-test; registers the queue's first handler
  - `01caf6e` — WP10: uninstall with unconditional pending-action cleanup and retention-gated data removal
  - `4625d55` — WP11: distributable ZIP finalized; packaged-plugin acceptance testing across three configurations
  - `1a67b89` — WP12: architecture reference, developer README, and doc-link validator
- **PR:** [magpern/universal-telegram#1](https://github.com/magpern/universal-telegram/pull/1), merged via merge commit `ebbabda` (all twelve work-package commits preserved individually, not squashed).
- **Requirements-to-evidence mapping:** `docs/plans/m00-product-foundation-plan-v1.md`, section 12 ("Requirements traceability") and section 13 ("Complete Definition of Done"), both satisfied item-by-item by the work packages and validation commands listed above.
- **Automated test results summary** (full matrix, run clean end to end immediately before opening the PR, and independently reproduced by GitHub Actions on every one of the twelve implementation commits plus the PR and post-merge `main` runs — all green):
  - `bin/docker/composer.sh install --no-interaction` — clean, 42 packages.
  - `bin/docker/phpcs.sh` — 64 files, 0 errors.
  - `bin/docker/phpstan.sh` (level 5, no baseline) — 35 files, 0 errors.
  - `bin/docker/test-unit.sh` — 34 tests, 57 assertions.
  - `bin/docker/test-integration-wp-only.sh --wp-version=6.9` — 38 tests, 98 assertions.
  - `bin/docker/test-integration-wp-only.sh --wp-version=7.1` — 38 tests, 98 assertions.
  - `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1` — 38 tests, 98 assertions.
  - `bin/docker/build-zip.sh` — builds `universal-telegram-0.0.1.zip`.
  - `bin/docker/test-package.sh --wp-version=6.9 --php-version=8.1` — PASSED.
  - `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3` — PASSED.
  - `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3 --woocommerce=11.0.1` — PASSED.
  - `bin/docker/composer.sh run-script check-doc-links` — clean.
- **Vlad's independent acceptance results:** Not applicable for M00 per ADR-0011 — formal, independent manual acceptance is deferred until M10. Required quality evidence for M00 is the frozen plan, code review, mandatory automated validation, and green CI, all listed above.
- **ADRs introduced or superseded in this milestone:** ADR-0005 (composition root and product module boundaries), ADR-0006 (queue implementation and failure semantics), ADR-0007 (persistence and migration framework), ADR-0008 (secret storage and fail-closed key handling), ADR-0009 (privacy classification and redaction model), ADR-0010 (capability model), ADR-0011 (deferred formal acceptance testing until M10) — all accepted in the freeze commit. None superseded.

## Implementation notes (not unresolved limitations — each was fully resolved before its own commit)

- The structural guard test required by the plan's section 7 (asserting the six not-yet-implemented product boundaries remain absent from `src/`) was not written during WP1 as the plan intended. The gap was caught while drafting `docs/ARCHITECTURE.md` in WP12 and added there (`tests/unit/Core/StructuralBoundariesTest.php`) before the milestone closed; the requirement is fully satisfied at closure.
- Two files the plan assigns to WP5 (`bin/docker/test-integration-wc-present.sh`) and the `before_woocommerce_init` HPOS-compatibility hook, and one file the plan assigns to WP11's "finalized" step (`bin/build-zip.sh`'s exclusion list and Action Scheduler presence assertion) were substantively written during WP1's own tooling setup, ahead of their planned work package, for practical convenience. No functional gap resulted; each was exercised and validated in full at its originally planned work package.
- `Queue\HandlerRegistry`/`Queue\WorkerRunner`'s handler contract was widened during WP9 from "receives only the job's payload" to "receives the job's full action-args array" (job_id, job_type, attempt, payload), since the self-test handler genuinely needs its own attempt number to implement the plan's documented retry contract. Recorded in the WP9 commit message; covered by WP8's and WP9's own tests.
- `Core\Configuration\Settings::sanitize()` was corrected during WP10 from an unvalidated array passthrough to an allowlist-based sanitizer, once the option gained its one real M00 field (`remove_data_on_uninstall`, consulted by `Uninstaller`). The WP2-era unit test was updated to match the corrected, more defensible behaviour.
- Two implementation-time defects were caught by the project's own validation gates before ever reaching a commit: an accidental deletion of a `namespace` declaration (caught by the same turn's PHPCS run) and an incorrect `WP_Roles`-level capability-revocation call that did not sync already-instantiated `WP_Role` objects (caught by the WP7 integration test failure, root-caused against WordPress core's own source, and fixed).
- `tests/integration/Core/Lifecycle/UninstallTest.php` documents and works around a WordPress core test-framework artifact: PHPUnit's own transactional test isolation rewrites `CREATE`/`DROP TABLE` into `CREATE`/`DROP TEMPORARY TABLE`, which silently no-ops against the plugin's real (non-temporary) table created during test bootstrap. This is a test-only artifact with no production impact — `uninstall.php` runs in an ordinary request with no such rewriting present — and is explained in code comments at the point it is worked around.

## Final status

**PASS**. All Definition of Done items (plan section 13) and all requirements-traceability entries (plan section 12) are met with the automated evidence listed above; no known defect or scope gap remains open.

- **Product Owner acceptance:** Magnus Pernemark — PASS.
