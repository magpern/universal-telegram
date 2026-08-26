# Closure Record — UT Adapter M1 Work Package 6: Joint Authenticated Interoperability

## Status

**PASS.** Closes work package 6 of
`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md` — the
joint authenticated interoperability gate the WP1–5 closure
(`docs/closure/ut-adapter-m1-signed-contract-client-closure.md`) left
explicitly open. Closed for implementation review (awaiting Product Owner
acceptance / merge) — per `docs/governance.md`, the Implementation Agent
cannot self-certify closure.

This closes only the interoperability gate. It does **not** close SC-M03
itself, and does not implement any migration, cutover, or Availability
work — see "Explicit non-goals" below.

## What this proves, precisely

Both plugins' **real, merged** production code — signer, verifier, pairing
service, discovery, REST dispatch, and domain repositories on both sides —
interoperate when installed together in one disposable WordPress
environment. The only test double anywhere in the suite is a
`pre_http_request` filter scoped to `api.telegram.org`, standing in for the
external Telegram Bot API network boundary (never part of the Contract v1
chain under test). No REST route in the suite is registered by test code;
every route exercised is the one each plugin's own `Plugin::boot()`
registers in production, so this proves interoperability of what actually
ships, not a parallel test-only wiring.

## Baseline / SHAs

| Item | Value |
|---|---|
| UT repository | `magpern/universal-telegram` |
| UT starting SHA (`origin/main`) | `5c6f19e76718524e7b0bc5b147ac46dffe3fbccf` (merge of PR #34, WP1–5) |
| UT branch | `feature/ut-adapter-m1-interop-wp6` |
| UT original interoperability-tested implementation SHA (the exact SHA the 35-test, 413-assertion matrix below was run and proven against) | `467d99650464cfea1ef0639f9a5cbcecac4b46d2` |
| UT final branch SHA (current PR head; two follow-up commits since the tested SHA above — a docs-only closure-record commit and a docs/test-only commit scoping two benign `phpcs:ignore` suppressions the CI matrix flagged as blocking warnings, neither changing any test's logic or the interoperability matrix itself) | `f7c1c3cda76807bfb01fdb5ff3c5883e65eaf613` |
| SC repository | `magpern/universal-support-chat` |
| SC starting SHA (`origin/main`) | `2f748168f591bec551a740a5060d394bc6e29ba3` (merge of PR #6, SC-M03 WP0) |
| SC branch used by the harness | `feature/sc-m03-ut-interop-wp6` |
| SC interoperability-tested implementation SHA the harness ran against | `0ec44cf8f901c641d4a000abdf70a8764a607eae` |
| SC final branch SHA (current PR head; one follow-up docs-only closure-record commit since the tested SHA above) | `cbe01c044ce7e9f345f5e20c4c72d64f94ae8793` |

The harness mounts the SC checkout by host path
(`docker/docker-compose.interop.yml`, `SUPPORT_CHAT_HOST_PATH`, default
`/opt/biopentra/dev/universal-support-chat`) and links whatever is checked
out there into the disposable WordPress install
(`tests/bin/install-support-chat.sh`) — the interoperability-tested SHA
above (not necessarily the final branch SHA) is what was actually loaded
for every run this closure reports.

## What was implemented in this work package

- `tests/integration/Interop/` — a new interop test suite: `InteropTestCase`
  (shared setup: real key generation on both sides via each plugin's own
  `OwnKeyManager`, a real two-way `PairingService` exchange, real capability
  grants, and a `pre_http_request` filter faking only `api.telegram.org`),
  `ActivationAndPairingTest`, `AuthorizationOrderTest`, `DiscoveryTest`,
  `IdempotencyTest`, `OptionalityTest`, `PrivacyTest`,
  `ScToUtOperationsTest`, `UtToScOperationsTest`.
- `tests/integration/Interop/bootstrap.php` — loads both plugins' real
  Composer autoloaders and main files as MU plugins into one WordPress
  test install.
- `docker/docker-compose.interop.yml`, `tests/bin/install-support-chat.sh`,
  `bin/docker/test-integration-interop.sh`, `phpunit-interop.xml.dist` — the
  dual-plugin Docker harness.
- `phpunit-integration.xml.dist` — excludes `tests/integration/Interop`
  from UT's own integration suite (it requires the dual-plugin bootstrap
  and the sibling SC checkout; it only runs via
  `bin/docker/test-integration-interop.sh`).
- `phpcs.xml.dist` — scopes the interop suite's legitimate cross-plugin
  capability-name checks (item 2) and fixed-table privacy assertion (item
  10) around `WordPress.WP.Capabilities.Unknown` /
  `WordPress.DB.PreparedSQL.NotPrepared`, and extends the pre-existing
  `tests/integration/bootstrap.php` carve-out to this suite's own
  `bootstrap.php`.

No production `src/` file changed in this work package. This work package
is test/harness/CI-configuration only, on the UT side.

## A real architectural bug found and fixed while building this suite

An earlier draft of the harness constructed a **second**
`OutboundContractController` (backed by a fake Telegram client) and
registered it on `rest_api_init` in the test itself, alongside SC's
`ContractOperationsController`/`ContractDiscovery` likewise re-registered
by the test. Both plugins' own `Plugin::boot()` already registers the real
equivalents in production. WordPress resolves two registrations of the
same route to the **first**-registered handler — so the test's fake-backed
controller was silently never invoked; every request landed on the real,
production-wired controller (an unfaked `TelegramApiClient` attempting a
real network call, which the sandboxed environment blocks), and
`ensure_channel_case` always returned `unavailable`.

**Fix:** the harness no longer registers any REST route itself. It relies
entirely on each plugin's own production bootstrap for route registration
— exactly what "both plugins installed together" means — and fakes only
the genuine external boundary (Telegram's HTTP API) via a `pre_http_request`
filter. This is a stronger proof of interoperability than the original
design, not a weaker one: it exercises the actual code path a real
deployment uses, with no test-only route wiring anywhere in the chain.

Three further concrete bugs, found only by actually running the suite
against real schemas, were fixed in the test code itself (never in
production `src/`): a stale `context_json` audit-log column name (the real
column is `context`); a stale `conversation_uuid` binding-table column name
(the real column is `support_conversation_uuid`); and a table-namespace
isolation check that used a bare `universal_support_chat_` prefix, which
false-positived on the legitimate `universal_support_chat_manage`
capability string UT must reference for item 2's pairing gate — narrowed to
the real table names. `create_sc_conversation()` and the Telegram-optional
test in `OptionalityTest` were corrected to transition a conversation
`new → open` before invoking `resolve`/`reopen`, mirroring SC's own real
state machine (a brand-new, untouched conversation cannot transition
directly to `resolved`).

## Interoperability matrix — all 10 acceptance items

| # | Item | Test(s) | Result |
|---|---|---|---|
| 1 | Both plugins activate together, no permanent cross-plugin SQL access | `ActivationAndPairingTest::test_both_plugins_active_with_disjoint_table_namespaces` | PASS |
| 2 | Pairing requires both management capabilities; real public keys/key IDs only; private keys vault-encrypted, never exposed | `ActivationAndPairingTest::test_pairing_action_requires_both_management_capabilities`, `test_pairing_stored_real_exchanged_public_keys`, `test_private_keys_are_vault_encrypted_and_never_plaintext_in_storage` | PASS |
| 3 | Real discovery: truthful before/after pairing, disabled/revoked/expired, peer unavailable | `DiscoveryTest` (6 tests: active after pairing, unpaired before pairing, disabled peer, revoked peer, expired peer, UT's own adapter-disabled state, SC route missing) | PASS |
| 4 | UT → SC: all 8 operations through UT's real signed client and SC's real authenticated route | `UtToScOperationsTest` (`ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `update_assignment`, `report_channel_unavailable`, `report_delivery_failure`) | PASS |
| 5 | SC → UT: all 4 operations through SC's real signing path and UT's real signed acceptors | `ScToUtOperationsTest` (`ensure_channel_case`, `notify_operators`, `deliver_transcript_backfill`, `deliver_message`) | PASS |
| 6 | Idempotency / safe retry, including duplicate delivery/update | `IdempotencyTest` (UT→SC `ingest_operator_reply` dedup; SC→UT `ensure_channel_case` dedup — one binding row; SC→UT `deliver_message` dedup — second call reports `reused`) | PASS |
| 7 | Fail-closed, no domain mutation, across altered body/signature, wrong sender/audience/version/auth-profile, stale timestamp, nonce replay, unpaired/disabled/revoked/expired peer, operation outside allow-list, adapter disabled/unavailable | `AuthorizationOrderTest::test_invalid_signature_is_rejected_even_when_filter_forced_true`; `AuthorizationOrderTest::test_filter_set_false_vetoes_an_otherwise_valid_call`; `OptionalityTest` (adapter disabled); plus the pre-existing, unchanged `OutboundContractAuthorizationTest`/`ContractOperationsControllerTest` matrices in each repository's own integration suite, which this closure re-ran and confirmed still pass against the real code this suite pairs | PASS |
| 8 | A valid paired request reaches the real handler without a test-only authorization-filter override; the UT filter remains an optional veto only | `AuthorizationOrderTest::test_valid_paired_call_reaches_real_handler_without_filter_override` (asserts `has_filter()` is false, then a real signed call reaches the real handler); `test_filter_set_false_vetoes_an_otherwise_valid_call` proves the veto still works when explicitly set | PASS |
| 9 | Telegram optional: SC's Hub/widget workflow usable with UT absent/deactivated/disabled/unavailable; no ordinary chat mirrored to UT | `OptionalityTest` (SC conversation lifecycle with UT peer disabled; SC conversation lifecycle with UT's own adapter setting disabled; 5 ordinary SC messages create zero UT bindings/outbound rows) | PASS |
| 10 | No plaintext transcript/body persisted in the wrong plugin's DB/logs/audit/error output | `PrivacyTest` (SC→UT delivered body never in SC's audit/messages/notes tables; UT→SC ingested body never in UT's own audit log; SC's own `conversation_messages.body_ciphertext` column is genuinely ciphertext, not the plaintext marker) | PASS |

**35 interop tests, 413 assertions, all green.**

## Quality-gate evidence (UT repository)

| Check | Command | Result |
|---|---|---|
| Interop suite | `bin/docker/test-integration-interop.sh --php-version 8.3 --wp-version 6.9` | 35 tests, 413 assertions — OK |
| Unit | `bin/docker/test-unit.sh` | 377 tests, 1164 assertions — OK, 1 pre-existing skip |
| Integration (WP 6.9 / PHP 8.3, `tests/integration` excluding `Interop`) | `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.3` | 1031 tests, 3365 assertions — OK, 58 pre-existing skips |
| PHPCS | `bin/docker/phpcs.sh` | **0 errors, 0 warnings** as of the final branch SHA above. Two initially-benign warnings (`base64_decode()`/`file_get_contents()`, both legitimate non-obfuscation, local-file/test-assertion uses) were caught by remote CI, which treats phpcs warnings as blocking — not by the local run this closure originally reported against. Both were resolved with line-scoped `// phpcs:ignore <sniff> -- <reason>` comments, matching this repository's existing convention exactly (`src/Core/Security/CredentialVault.php`, `tests/integration/SupportChatAdapter/{PairingTest.php,ChannelBindingRepositoryTest.php,SupportChatContractClientDispatchTest.php}`). Remote CI is confirmed green on the final branch SHA (`gh pr checks 35`), all checks passing across both matrix runs. |
| PHPStan (level 5) | `bin/docker/phpstan.sh` | 0 errors (`tests/` is out of PHPStan's configured scope, unchanged by this work package) |
| Doc links | `composer check-doc-links` (via `bin/docker/composer.sh`) | 19 unresolved links, all in `docs/plans/m07-1-conversation-topic-lifecycle-and-repair-plan-v1.md` — confirmed identical count on `origin/main` before this branch's changes; **pre-existing, not introduced by this work package** |

## Quality-gate evidence (SC repository, re-verified, not re-implemented)

The prior run's claim of a green SC branch was re-verified directly rather
than trusted:

| Check | Command | Result |
|---|---|---|
| Unit | `bin/docker/test-unit.sh` | 59 tests, 150 assertions — OK |
| Integration (WP 6.9 / PHP 8.3) | `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.3` | 70 tests, 282 assertions — OK |
| PHPCS | `bin/docker/phpcs.sh` | 0 errors, 0 warnings |
| PHPStan | `bin/docker/phpstan.sh` | 0 errors |
| Doc links | `composer check-doc-links` | Clean |

## Confirmation of no forbidden scope

- **No** migration import/copy/re-encryption/quiescence/route-switch/soak/
  rollback implementation — confirmed; none of this work package's files
  touch migration or cutover code in either repository.
- **No** legacy UT Conversations tab, AI tab, widget, or chat-settings
  removal — confirmed; no such files were touched.
- **No** AI, support hours, offline-ticket, launcher polish, release, tag,
  deployment, or production pairing — confirmed.
- **`update_operator_presence` remains excluded** — confirmed; it is
  structurally absent from `ContractOperations::ADAPTER_TO_SUPPORT_CHAT`
  on the SC side (unchanged from WP0) and was never implemented on the UT
  side (unchanged from WP1–5); this closure adds no code path for it.
- **No** shared secret, public REST bypass, application-password shortcut,
  or direct Support Chat table queries from UT (or vice versa) — confirmed
  by `ActivationAndPairingTest::test_both_plugins_active_with_disjoint_table_namespaces`,
  which greps both plugins' real `src/` trees for the other's concrete
  table names.
- **Generic uniform Contract-auth denial behaviour preserved** — confirmed;
  `AuthorizationOrderTest` exercises the real verifier and observes the
  same fail-closed shape already proven by each repository's own unchanged
  authorization test matrices.
- **No broader end-to-end Telegram network delivery claimed** — this
  closure explicitly states the only test double is the `api.telegram.org`
  HTTP boundary; no claim is made about real Telegram delivery, only that
  `EnsureChannelCaseService` genuinely executes its full real logic
  (bot/token lookup, binding creation, idempotency) up to that boundary.

## Hard-stop check

No authoritative document (ADR, charter, frozen plan, closure record) in
either repository left a required production security or Contract decision
undefined for what this work package needed. `SupportChatContractClient`'s
existing fail-closed authorization-filter-veto-only design (WP1–5) and
SC-M03 WP0's existing uniform-denial design were sufficient to prove
interoperability without any new decision being required.

## Explicit non-goals (confirmed, unchanged)

- SC-M03 migration/cutover remains **not** implemented and **not** closed.
  This closure marks only the WP1 "external gate" / WP6 interoperability
  proof complete; SC-M03 plan v2 §8's own work packages 2+ (migration
  engine, cutover orchestration) remain untouched.
- `update_operator_presence` / SC-M06 Availability remains out of scope.
- No release, tag, deployment, or real-site pairing was performed.
- Neither branch is merged by this closure.

## Next task

Product Owner review and merge of both PRs (this UT PR and the companion
SC PR `feature/sc-m03-ut-interop-wp6`). Once both merge, SC-M03 plan v2
§8's work package 1 external gate is satisfied and SC's own work package 2
(migration engine) may begin — still requiring its own separate
implementation and closure record; nothing in this work package
authorizes starting it.
