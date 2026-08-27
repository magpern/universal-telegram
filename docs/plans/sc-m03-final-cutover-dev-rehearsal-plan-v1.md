# SC-M03 Final-Cutover — Disposable DEV Rehearsal Plan v1 (primary operator runbook)

**Status: planning-only. No rehearsal has run. Product Owner execution approval is
outstanding.** This document is a documentation-only materialization of the reviewed
rehearsal plan. It authorizes nothing. It changes no code, schema, plugin version,
configuration, test, tag, release, or deployment, and it does not create infrastructure,
containers, Telegram bots, groups, topics, DNS records, certificates, or credentials.

## 1. Charter, ADRs, and pinned baselines

- Charter: [`docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md`](../milestones/ut-adapter-m1-universal-support-chat-adapter.md) §0d.
- This repository: [ADR-0042](../adr/0042-support-chat-adr-0010-pin-and-final-cutover-state-machine.md) (cutover state machine, activation/compensation saga, cohort-aware replay, incident record, `maybe_mark_topic_unavailable()` cross-talk fix).
- Support Chat: ADR-0010 (`https://github.com/magpern/universal-support-chat/blob/be7461544a39c7ad074164d21e3c1b04c71f2fc2/docs/adr/0010-final-cutover-handoff-contract-and-cohort-activation.md`) and its Product Owner decision record.
- Prior work packages relied on: WP2 quiescence (ADR-0040), WP3–4 legacy export boundary (ADR-0039), WP5 legacy binding preparation (ADR-0041); Support Chat WP2/WP3–4/WP5 closure records.
- Companion (Support Chat): [`sc-m03-final-cutover-dev-rehearsal-plan-v1.md`](https://github.com/magpern/universal-support-chat/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md) and [`sc-m03-final-cutover-dev-rehearsal-po-decisions.md`](https://github.com/magpern/universal-support-chat/blob/main/docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md).

**Accepted baselines this runbook pins (freshly fetched `origin/main`, both HEAD):**

| Repository | Accepted SHA | Notes |
|---|---|---|
| `magpern/universal-telegram` | `31519ee3ae297369118bf2deda6eae05d13a3d8b` | "Merge PR #47: Product Owner acceptance of SC-M03 final-cutover implementation". Plugin version `0.19.0`; schema `target_version()` `36` (cutover tables = Migrator steps 35–36). |
| `magpern/universal-support-chat` | `ce4691241eb843485117b323516899df916fdaf7` | "Merge PR #20: …". Plugin version `0.6.0`; `universal_support_chat_db_version` `11` (handoff-map = Migrator step 11). |

Both final-cutover closure records ("Product Owner acceptance (final)") state verbatim that
acceptance "does not authorize a DEV or production quiescence window, migration, cohort
activation, route switch, cutover, deployment, soak, rollback, deletion, release, or tag. The
next possible activity is a separately planned, disposable DEV rehearsal."

## 2. Repository findings at plan-drafting time (source-accurate command semantics)

Verified against `origin/main` at the pinned SHAs. Every `--assume-*` flag is an
operator-confirmation guard, **not** authentication — the real boundary is host shell authority
to run WP-CLI (ADR-0039 §2 pattern).

| Command | Source | Mutating? | Authority flag | Dry-run |
|---|---|---|---|---|
| `wp universal-support-chat legacy-migrate <run\|status\|validate>` `[--phase=backfill\|reconcile]` `[--batch-size=<n>]` | SC `src/Migration/Cli/LegacyMigrateCommand.php` | `run` (non-dry-run) only | `--assume-migration-authority` | `--dry-run` (default for `run`); zero writes to any table incl. run/map/batch-log |
| `wp universal-support-chat legacy-bind <run\|status\|validate>` `[--limit=<n>]` | SC `src/Migration/Cli/LegacyBindCommand.php` | `run` (non-dry-run) only | `--assume-binding-authority` | `--dry-run`; runs full pipeline incl. the real in-process quiescence lock + live re-check, commits nothing on either side |
| `wp universal-telegram quiescence <enter\|status\|confirm\|exit\|replay-deferred-updates>` | `src/Migration/Cli/QuiescenceCommand.php` | `enter`, `confirm`, `exit`, `replay-deferred-updates` | `--assume-quiescence-authority` (required by `enter` and `exit` only) | none — `status` is the only read-only action |
| `wp universal-telegram cutover <status\|begin\|activate\|confirm-complete\|incident-acknowledge\|recover>` | `src/Migration/Cli/CutoverCommand.php` | **`begin`, `activate`, `confirm-complete`, `incident-acknowledge`** | `--assume-cutover-authority` (required by `activate`, `confirm-complete`, `incident-acknowledge`; **NOT** by `begin`) | **none for `begin` or `activate`** — ADR-0042 §1's `[--dry-run]` for `activate` was not implemented |
| `wp universal-telegram support-chat-bindings import [--dry-run\|--apply]` | `src/SupportChatAdapter/Cli/BindingImportCommand.php` | `--apply` | none | `--dry-run` (default) |

Load-bearing details:

- **`cutover begin --cohort-file=<path>` is MUTATING.** The method's source docblock calls it a
  "read-only whole-cohort preflight," but that describes only the preflight portion: on a passing
  preflight `begin()` calls `CutoverRunRepository::create_prepared( count )`, **inserting a
  `cutover_runs` row** (`state=prepared`). It writes no binding. It has no authority flag and no
  dry-run. Its gates: `quiescence.state === quiescent`; no other open run
  (`CutoverRunRepository::find_open()`); `DeferredUpdateRepository::unresolved_backlog_count() === 0`;
  cohort file readable and non-empty. On any gate failure it prints a `WP_CLI::error` and creates
  nothing.
- **`cutover status` and `cutover recover` are the only read-only `cutover` actions** (verified
  against `CutoverCommand.php`): both only `WP_CLI::log` over `CutoverRunRepository::find_open()`,
  `QuiescenceGate::state()`, `DeferredUpdateRepository::unresolved_backlog_count()`, and (for
  `recover`, only when the run is `activating`) `CutoverRunRepository::activation_audit_for_run()`.
  `recover` is a diagnosis command — "never a state-forcing command."
- **`cutover activate --run=<uuid> --cohort-file=<path> --assume-cutover-authority`** is the only
  command that writes binding status. It re-runs preflight immediately; then a per-candidate,
  `with_quiescence_lock()`-scoped transaction: `activate_prepared( $binding_uuid, $expected_cas )`
  (`prepared → active`, `cas_version + 1`). On any commit-time failure it halts and compensates
  every already-activated candidate via `revert_activation()` (`active → prepared`, `cas_version + 1`
  again). **`cas_version` is strictly monotonic**: a successfully activated candidate ends
  pre-run `+1`; a compensated candidate ends pre-run `+2`, never restored to its pre-run value.
  Idempotent resume: re-run `activate` with the identical cohort file. No force/abandon command.
- **`quiescence replay-deferred-updates`** is the single authoritative, cohort-aware drain — there
  is no separate "final handoff scan," and `CutoverCommand` deliberately has no
  `handoff-deferred-updates` action. Per row, grouped by bot, `(update_id, id)` ascending: decrypt;
  if `ChannelBindingRepository::find_by_bot_topic()` returns an **active** binding →
  `CutoverReplayDispatcher::dispatch()` (outcome handed-off / incident / retry); otherwise legacy
  `WebhookController::process_update()` then `DeferredUpdateRepository::mark_replayed()`. Then it
  attempts the locked `replaying → idle` CAS against the widened backlog predicate
  `replayed_at IS NULL AND handed_off_at IS NULL AND incident_resolved_at IS NULL`. Safe to re-run.
- **Deferred-update capture** (ADR-0040 §3): webhook arrivals in any non-`idle` state are
  `CredentialVault`-encrypted (AAD `"quiescence-deferred-update:{bot_id}:{update_id}"`) into
  `universal_telegram_quiescence_deferred_updates`, `UNIQUE (bot_id, update_id)` (duplicate
  buffering is idempotent, returns 200). Unreplayed rows are never auto-deleted; the 30-day
  cleanup only touches rows with `replayed_at` or `handed_off_at` set.
- **Incident model** (ADR-0042 §4): pre-dispatch failures inside `CutoverReplayDispatcher` —
  `decrypt_failed`, `parse_failed`, `unsupported_command`, `unmapped_sender` — plus
  `handoff_provenance_conflict` (Support Chat `409`). Recorded only on this plugin's
  `quiescence_deferred_updates` incident columns (`incident_reason`, `incident_recorded_at`,
  `incident_resolved_at`, `incident_resolution`, `incident_po_decision_ref`); **never** a Support
  Chat `legacy_handoff_map` write. A transient transport failure is **not** an incident. An
  unresolved incident blocks both `replaying → idle` and `confirm-complete`. A successful retry
  stamps `incident_resolution = 'retried_success'` automatically.
- **Terminal-acknowledgement exception** (ADR-0042 §4; Support Chat ADR-0010 §5 + PO decision
  record item 2, approved 2026-08-27): `wp universal-telegram cutover incident-acknowledge
  --id=<row> --po-decision-ref=<opaque> --assume-cutover-authority`. `--po-decision-ref` must
  match `/^[A-Za-z0-9._\/-]{1,191}$/` (never free-form). Stamps `incident_resolved_at` /
  `incident_resolution = 'po_acknowledged_terminal'` / `incident_po_decision_ref` — **never**
  `replayed_at` or `handed_off_at`. The row's ciphertext and full audit trail are retained
  forever.
- **Quiescence** (ADR-0040 §6): `idle → draining → quiescent → replaying → idle`, CAS-only, **no
  TTL/staleness/auto-expiry**. `enter` blocks/buffers 8 synchronous legacy entry points and pauses
  3 recurring sweeps the instant `state != 'idle'`. `confirm` requires every async drain proof to
  hold (topic create/delete + zero leases, outbound routing, `telegram_send_message` joined
  against `conversations.destination_id` so Support Chat binding deliveries are never counted, AI
  draft generation + zero leases). `Core\Plugin::quiescence_status()` returns
  `is_quiescent = ( state === 'quiescent' AND deferred_update_backlog_count() === 0 )`, and
  `is_quiescent` can flip back to `false` with **no** state transition when a webhook is buffered.
- **Phase B continuous re-check** (Support Chat WP2): `PhaseBReconciliationService::run()`
  re-checks `is_quiescent()` at the top of every loop iteration and before each promotion write;
  on loss it stops immediately, rolls back the in-progress row, promotes nothing further, returns
  `REFUSED_NOT_QUIESCENT`; rows already promoted stay promoted.
- **Routing gate** (ADR-0042 §"Required source verification" item 1): `InboundAdapterBridge::try_handle()`'s
  sole gate is `$binding->is_active()`. A `prepared` binding is structurally unreachable from live
  routing. `try_handle()`, `DeliverMessageService`, and `process_update()` routing order are
  unchanged by ADR-0042.
- **Live lifecycle cross-talk fix** (ADR-0042 §5): for `forum_topic_closed` / `forum_topic_deleted`,
  `WebhookController::process_update()` checks for an active binding on
  `(bot_id, message_thread_id)` **before** `maybe_mark_topic_unavailable()`; if active →
  `SupportChatContractClient::report_channel_unavailable( $binding_uuid, $reason_code )` with reason
  `'telegram_topic_closed'` / `'telegram_topic_deleted'`; the legacy conversation row is never
  mutated. Fail-closed even when the Contract call fails.
- **Wire detail** (final-cutover closure addendum): `channel_case_ref` on the wire =
  `$binding->binding_uuid()`, which Support Chat's dispatcher resolves directly as its own
  `conversation_uuid`. A fixture binding's `binding_uuid` must equal the Support Chat conversation
  UUID.
- **Do not use `wp universal-telegram support-chat-bindings import --apply` for cohort
  preparation** — it hardcodes `status = 'active'` with no source-liveness check (ADR-0041 §5).
  Cohort `prepared` bindings come only from Support Chat `legacy-bind run` →
  `LegacyBindingImportServiceV1`.

## 3. Assumptions requiring DEV verification (separated from decisions)

| # | Assumption | Verify (in the disposable env, before it is relied on) |
|---|---|---|
| A1 | The rehearsal env's checked-out plugin SHAs equal the accepted baselines and running schema is UT `36` / SC `11`. | `git -C … rev-parse HEAD`; `wp eval` on `Migrator::target_version()` / `get_option('universal_support_chat_db_version')`. |
| A2 | Both plugins can be mutually paired and Contract V1 discovery reports `channel_available:true` with the six cutover ops on the peer allow-list. | admin pairing actions (or the interop harness's real two-way pairing); `GET /universal-support-chat/v1/channel-contract`. |
| A3 | Whether `cutover begin` preflight enforces "mapping-complete on Support Chat's side" and "no blocking incident," or only the local `prepared` binding. UT `CutoverActivationService::preflight()` at the pinned SHA checks only for a `prepared`-status binding per candidate. | Read `CutoverActivationService::preflight()`; drive a candidate whose SC map row is not `migrated` and observe whether `begin` refuses it. |
| A4 | The exact Support Chat CLI used to confirm `status = 'migrated'` for a cohort member is `wp universal-support-chat legacy-migrate status` / `validate`. | Confirm against the pinned CLI; cross-check a known map row via `wp eval`. |
| A5 | Support Chat's message-retention policy for migrated/handed-off conversations vs this plugin's 30-day replayed/handed-off deferred-row retention are mutually compatible. | Read `Settings` defaults + retention sweeps in both repos at the pinned SHAs; document, do not change. |
| A6 | Synthetic deferred-update payloads injected via `DeferredUpdateRepository::buffer(...)` decrypt and drive `process_update()` / `CutoverReplayDispatcher` identically to a real webhook arrival. | Compare an injected-row replay against a real authenticated webhook arrival (Tier 2) or against the interop suite's own fixtures (Tier 1). |
| A7 | `docker compose … down -v` fully removes the disposable DB volume; no named volume survives. | `docker volume ls` before/after; `docker compose ps`. |
| A8 | No cohort/binding/quiescence/cutover-run state pre-exists in the rehearsal DB. | `cutover status`; `quiescence status`; `legacy-bind status`; direct `SELECT` on the `cutover_*` / `quiescence_*` / `legacy_migration_*` / `legacy_handoff_map` tables. |

## 4. Rehearsal objective and boundary

**Objective**: prove the complete operational sequence, not merely that tests pass:

> Phase A migration evidence → UT quiescence → Phase B reconciliation → prepared-binding cohort
> activation → cohort-aware deferred-update handling → validation → safe return to ordinary DEV
> operation.

**"Safe return" in DEV** means: the rehearsal environment is torn down — `docker compose … down -v`
plus explicit volume removal (Tier 1); additionally, for Tier 2, deletion of the throwaway
Telegram bot via `@BotFather` + `deleteWebhook` and removal of the isolated vhost configuration.
It is **not** a production rollback, and it is never described as one. Production remains
forward-only (ADR-0042 §2; Support Chat ADR-0010 §1; PO decision record item 3).

**This rehearsal does not authorize** production cutover, retention or deletion, release,
deployment, route switch, soak, or removal of Universal Telegram legacy UI or data. Every such
step remains separately gated.

### 4.1 Tier boundary — Tier 1 is not the DEV rehearsal

| Tier | What it is | Status |
|---|---|---|
| **Tier 1** | A **required disposable automated operational-sequence / integration validation**. Runs in the existing container/PHPUnit interop harness (`docker/docker-compose.yml` + `docker/docker-compose.interop.yml`, `down -v` before and after) with **zero Telegram traffic**. Proves the **data effects, state-machine sequencing, and CLI-equivalent service ordering** of Runs 1, 2, and 3 (§7) in an isolated harness. | **Required prerequisite. Unexecuted.** |
| **Tier 2** | The **first actual disposable DEV rehearsal**: an isolated full-WordPress instance (real WP-Cron / Action Scheduler, Redis object cache, SWAG vhost, LE cert) plus a **dedicated non-production Telegram bot + test forum supergroup + test topics**, exercising the same sequences with a real authenticated webhook round-trip and real forum-topic lifecycle service messages. This is what the final-cutover Product Owner acceptance means by "a separately planned, disposable DEV rehearsal." | **Required. Blocked on B1 and B2 (§6).** |

**Tier 1 does NOT satisfy the accepted requirement for a disposable DEV rehearsal.** It lacks
real WP-Cron / Action Scheduler drain, the Redis object cache, authenticated Telegram webhook
ingress, real chat-widget traffic, and the actual DEV VPS runtime surface — all of which the
accepted requirement's "exercising a real cohort end-to-end" implies. **B1 and B2 therefore block
execution of the DEV rehearsal itself**, not merely optional extra realism. Tier 1 must be
completed first, green, as a precondition to Tier 2.

## 5. Isolation and data safety

### 5.1 Required disposable environment topology

**Tier 1** (no new infrastructure):

- **Where**: a fresh clone pair under a throwaway path, each checked out to the exact accepted SHA, driven only through `bin/docker/*.sh` against `docker/docker-compose.yml` + `docker/docker-compose.interop.yml`. Never `/opt/biopentra/dev/*` and never `dev.biopentra.eu`.
- **WordPress / databases**: per-run disposable — one `mariadb` container, database `wordpress_test`, WordPress core fetched fresh to `/tmp`, `docker compose … down -v` before and after every run. No Redis, no Apache, no persistent site.
- **Plugin versions**: pinned to the accepted SHAs; verified by `git rev-parse` + a schema assertion (A1). No plugin-version, schema, or config change is made.
- **Credentials**: none real. Database credentials are the harness defaults. No `CredentialVault` production key — the harness derives its own from throwaway WordPress salts.
- **Action Scheduler / WP-Cron**: not present; Tier 1 drives every step synchronously via the same service calls the existing `Cutover*Test` / `Quiescence*Test` / `Interop` suites use. Documented consequence: Tier 1 does not exercise real async-drain timing of `quiescence confirm`, webhook-ingress buffering, or Redis-cache interaction — those are Tier 2.
- **Logs**: PHPUnit stdout plus a captured `docker compose logs` bundle per run.
- **Telegram resources**: **none**. Deferred updates are synthetic payload arrays injected via `DeferredUpdateRepository::buffer( bot_id, update_id, update_type, $raw_payload )`. The Telegram Bot API boundary is the harness's existing `pre_http_request` filter scoped to `api.telegram.org` (no token, no network). No `setWebhook` call is ever made.

**Tier 2** (requires B1 + B2 resolved first):

- A second, fully independent compose project — distinct container names, network, host volume paths, database name + credentials, Redis, `CredentialVault` key, SWAG vhost (e.g. `rehearsal.dev.biopentra.eu`) and LE certificate — built as a sibling of `apps/wordpress/` and never sharing a volume, network alias, or database with `dev.biopentra.eu`.
- A **dedicated non-production Telegram bot** (its own `@BotFather` token), a **dedicated test forum supergroup**, and **dedicated test topics**, configured only in that isolated instance's database. The bot's webhook points only at the isolated vhost. The production/dev support group and its bot are never referenced.
- Non-production secrets throughout (`.env` mode 600, never committed, never echoed).

### 5.2 Non-production secrets and dedicated Telegram resources

If Telegram is exercised at all (Tier 2 only), it uses a dedicated test bot, a dedicated test
group, and dedicated test topics. No production bot token, production webhook URL, production
group, or production topic is referenced. Tier 1 exercises no Telegram resource at all.

### 5.3 Synthetic fixture data only

- All chat fixture data is **synthetic**, created in-process via the plugins' own repository classes with `self::factory()->user->create()` WordPress users — the pattern the interop suites already use. No customer or operator content, no production transcript, ever enters a fixture.
- No production database is attached, mounted, dumped into, or restored from.
- No production credential (`apps/wordpress/.env`, `.admin-credentials`, `proxy/config/dns-conf/cloudflare.ini`) is read by the rehearsal.
- No production webhook is registered; no production bot token is decrypted.

### 5.4 Setup and cleanup evidence

- **Setup evidence**: `git rev-parse` of both checkouts; `docker compose config`; `docker volume ls` before; a fresh-DB assertion (`SHOW TABLES` empty of plugin tables before install); post-install schema assertion (UT `36` / SC `11`); pairing + discovery result.
- **Cleanup evidence**: `docker compose … down -v` output; `docker volume ls` showing the run's volume gone (A7); `docker compose ps` empty; for Tier 2, `@BotFather` deletion confirmation + `getWebhookInfo` showing no URL.

### 5.5 Irreversible external effects and their prevention

The only irreversible external effect possible is a real Telegram Bot API call (`setWebhook`,
`sendMessage`, forum-topic creation/close). Tier 1 prevents it structurally (the `pre_http_request`
filter; no token; no network). Tier 2 confines it to the throwaway bot and test supergroup, all
deleted at teardown. **No message is ever sent to a real user or a real support group.**

### 5.6 Redaction rules for retained artefacts

- Retain only: ids, UUIDs, fixed-vocabulary strings (`kind`, `incident_reason`, `incident_resolution`, quiescence/cutover state names), counts, timestamps, CLI stdout, `SHOW COLUMNS` output, before/after `cas_version`.
- **Never retain**: any `payload_ciphertext` value, any decrypted payload, any `body_ciphertext`, any message text, any bot token / webhook secret, any WordPress admin credential, any `CredentialVault` key material.
- `SELECT *` dumps are filtered to drop ciphertext/body columns before saving. Fixture message text is synthetic and non-sensitive, but is still elided to keep the redaction rule uniform.

## 6. Blockers

| ID | Blocker | Blocks | Owner |
|---|---|---|---|
| **B1** | No isolated full-WordPress rehearsal environment exists (the DEV VPS is a single shared WordPress + MariaDB + Redis stack with no multi-instance capability). | **Execution of the DEV rehearsal (Tier 2).** Tier 1 is not blocked. | Infrastructure — build a sibling compose project per §5.1. |
| **B2** | No dedicated non-production Telegram bot / test supergroup / test topics are provisioned or documented. | **Execution of the DEV rehearsal (Tier 2)** — the accepted "real cohort end-to-end" needs authenticated webhook ingress and real topic-lifecycle service messages. | Product Owner / infrastructure — create a throwaway bot + supergroup. |
| **B3** | Neither `cutover begin` nor `cutover activate` has a dry-run (ADR-0042 §1's `[--dry-run]` for `activate` unimplemented). `begin` inserts a `cutover_runs` row; `activate` writes binding status. Both are real, teardown-only-reversible writes and sit in the mutating-command runbook behind a hard stop-condition review (§8). Only `cutover status` and `cutover recover` are read-only. | Confidence that `begin` / `activate` can be "previewed." | Documented limitation; `status` + `recover` are the read-only pre-`begin` / pre-`activate` checks. |
| **B4** | Assumption A3 unresolved — the cohort could pass UT `begin` preflight while its Support Chat map row is not `migrated`. | Trusting `begin` alone as the migration-evidence gate. | The rehearsal additionally asserts `status = 'migrated'` via Support Chat CLI (A4) **before** running `begin`. |
| **B5 (governance)** | The Product Owner has not approved executing any rehearsal. The final-cutover PO acceptance explicitly excludes it. | The entire rehearsal (both tiers). | Product Owner — see Approval A / Approval B (§10). |

## 7. Test matrix and sequencing

The first rehearsal is deliberately small: **one synthetic conversation / one-member cohort**,
and only the minimum Telegram fixtures needed to prove the authoritative path. Three separate
disposable runs. Every mutating step is gated by its §8 stop-condition review.

### 7.1 Run 1 — authoritative happy path (REQUIRED)

Fixtures, all with **distinct `(bot_id, update_id)`**: one deferred operator reply (→ handed-off,
one Support Chat message + one handoff-map row + `handed_off_at`); the same `(bot_id, update_id)`
re-presented once (→ idempotent pre-`handed_off_at` retry convergence, no duplicate); one supported
command `/claim` (→ correct Support Chat op with provenance); one `forum_topic_closed` lifecycle
event (→ `report_channel_unavailable`, no legacy mutation); one transient-transport-failure row
that clears on the next `replay-deferred-updates` pass (→ non-incident retry, `retried_success`).
**No unrecoverable incident fixture in Run 1** — Run 1 is expected to reach `confirm-complete`.

| # | Step | Mutating? | Command / action |
|---|---|---|---|
| 1 | Preflight | read-only | §8.1 checklist (Approval A signed, SHAs, schema, fresh env, pairing, discovery, `cutover status`, `quiescence status`, `legacy-bind status`) |
| 2 | Seed synthetic fixtures | writes to the disposable DB only | test bot profile + supergroup destination + topic; one legacy UT conversation `topic_creation_state='created'` + 2 messages + 1 note, owner = factory user; the one-line cohort file (a synthetic `support_conversation_uuid`) |
| 3 | Phase A dry-run | no | `wp universal-support-chat legacy-migrate run --phase=backfill --dry-run` |
| 4 | Phase A real | **yes** | `wp universal-support-chat legacy-migrate run --phase=backfill --assume-migration-authority` |
| 5 | Phase A validation | no | `wp universal-support-chat legacy-migrate validate`; `… status`; assert source UT rows unmutated; a second `--dry-run` backfill shows a stable high-water mark |
| 6 | Quiescence enter | **yes** | `wp universal-telegram quiescence enter --assume-quiescence-authority` |
| 7 | Drain observation | no | `wp universal-telegram quiescence status` (record the full drain breakdown); attempt one synthetic legacy write → expect `409 quiescence_active` |
| 8 | Quiescence confirm | **yes** | `wp universal-telegram quiescence confirm` → `quiescent`; `status` shows `is_quiescent(): true`, backlog 0 |
| 9 | Phase B dry-run | no | `wp universal-support-chat legacy-migrate run --phase=reconcile --dry-run` |
| 10 | Phase B real | **yes** | `wp universal-support-chat legacy-migrate run --phase=reconcile --assume-migration-authority` → cohort member `status='migrated'` |
| 11 | Migration-evidence gate | no | Support Chat CLI + `wp eval` prove `status='migrated'` for every cohort member (resolves B4 / A4) **before** any `begin` |
| 12 | Binding prep dry-run | no | `wp universal-support-chat legacy-bind run --dry-run` |
| 13 | Binding prep real | **yes** | `wp universal-support-chat legacy-bind run --assume-binding-authority` → UT binding `status='prepared'`; `legacy-bind status` shows `is_quiescent true` |
| 14 | Cutover status | no | `wp universal-telegram cutover status` (no open run) |
| 15 | **Cutover begin** | **YES — inserts a `cutover_runs` row** | `wp universal-telegram cutover begin --cohort-file=<path>` → prints run uuid, `state=prepared`. Gated on `quiescent` + no open run + backlog 0 + non-empty file |
| 16 | Inject deferred updates | writes buffered rows to the disposable DB | `DeferredUpdateRepository::buffer(...)` for the reply, its duplicate, `/claim`, `forum_topic_closed`, and the transient-failure row — all distinct `(bot_id, update_id)` |
| 17 | Cutover recover (diagnosis) | no | `wp universal-telegram cutover recover` (read-only; confirms run state + backlog + quiescence) |
| 18 | Cutover activate | **yes** | `wp universal-telegram cutover activate --run=<uuid> --cohort-file=<path> --assume-cutover-authority` → cohort binding `prepared → active`, `cas_version` pre-run+1; run `state=activated` |
| 19 | Post-activation binding check | no | `SELECT binding_uuid, status, cas_version` before/after; `cutover_activation_audit` + `cutover_transitions` share one `cutover_run_id` |
| 20 | Quiescence exit | **yes** | `wp universal-telegram quiescence exit --assume-quiescence-authority` → `replaying` |
| 21 | Replay (repeat until settled) | **yes** | `wp universal-telegram quiescence replay-deferred-updates` — expect handed-off (reply), converged (duplicate), handed-off (`/claim`), handed-off (`forum_topic_closed` → `report_channel_unavailable`), transient row retried then `Replayed` / `retried_success`; re-run until `State is now: idle` |
| 22 | Backlog / idle assertion | no | `wp universal-telegram quiescence status` → `idle`, backlog 0; `cutover status` backlog 0 |
| 23 | Confirm-complete | **yes** | `wp universal-telegram cutover confirm-complete --run=<uuid> --assume-cutover-authority` → `state=complete` |
| 24 | Post-activation routing checks | no | fresh post-idle synthetic inbound for the cohort topic → routed via the Support Chat adapter (`try_handle` claims it, binding `active`); fresh inbound for a non-cohort legacy topic → still legacy |
| 25 | No-leak audit | no | `SHOW COLUMNS` + filtered `SELECT *` on `legacy_handoff_map`, `quiescence_deferred_updates` incident columns, `cutover_runs` / `cutover_transitions` / `cutover_activation_audit` |
| 26 | Controlled exit / return to idle | — | confirm quiescence `idle`, no open mutating run, `cutover status` `state=complete` — "safe return" in DEV: teardown, not a production rollback |
| 27 | Cleanup + evidence capture | **yes (teardown)** | `docker compose -f docker/docker-compose.yml -f docker/docker-compose.interop.yml down -v`; `docker volume ls` diff; `docker compose ps` empty; (Tier 2) delete throwaway bot + `deleteWebhook`; assemble the §9 evidence bundle |

### 7.2 Run 2 — Phase-B quiescence-loss recovery (REQUIRED, separate disposable run)

Steps 1–9 as Run 1. Then:

| # | Step | Mutating? | Command / action |
|---|---|---|---|
| 10 | Phase B real, with a mid-run injection | **yes** (reconcile) + buffered-row write | begin `wp universal-support-chat legacy-migrate run --phase=reconcile --assume-migration-authority`; while it runs, inject **one** synthetic deferred update via `DeferredUpdateRepository::buffer(...)` targeting a topic that has **no active binding** (correct disposition = legacy replay) |
| 11 | Phase B refuses | — | reconcile returns `REFUSED_NOT_QUIESCENT`; assert the in-progress row rolled back, any earlier promotions intact |
| 12 | **HARD GATE** | — | **do NOT run `legacy-bind`, `cutover begin`, or `cutover activate`** (§8 stop condition) |
| 13 | Exit to replaying | **yes** | `wp universal-telegram quiescence exit --assume-quiescence-authority` → `quiescent → replaying` |
| 14 | Drain the injected update via its legacy path | **yes** | `wp universal-telegram quiescence replay-deferred-updates` (repeat) — assert the injected row is `Replayed` through legacy `process_update()` (**not** handed off, **not** an incident); no `legacy_handoff_map` row created |
| 15 | Verify backlog empty + idle | no | `wp universal-telegram quiescence status` → `idle`, backlog 0; `SELECT replayed_at` on the injected row is set |
| 16 | Re-establish quiescence | **yes** | `wp universal-telegram quiescence enter --assume-quiescence-authority`; `… confirm` → `quiescent`; `status` shows `is_quiescent(): true`, backlog 0 |
| 17 | Re-run Phase B successfully | **yes** | `wp universal-support-chat legacy-migrate run --phase=reconcile --assume-migration-authority` → cohort member `status='migrated'` |
| 18 | Only now proceed | — | continue with binding preparation (Run 1 step 13) and cutover preflight / `begin` (Run 1 step 15) |
| … | remainder | — | as Run 1 steps 13–27 |

### 7.3 Run 3 — incident detection and safe blocking (REQUIRED, separate disposable run)

Steps 1–18 as Run 1 (through `cutover begin` + `activate`), then:

| # | Step | Mutating? | Command / action |
|---|---|---|---|
| A | Inject one **permanently-undecryptable** synthetic row | buffered-row write | `DeferredUpdateRepository::buffer(...)` with ciphertext that cannot decrypt under its own AAD, `(bot_id, update_id)` distinct from every other fixture |
| B | Exit + replay | **yes** | `quiescence exit`; `quiescence replay-deferred-updates` → reports `1 incident(s)`, `incident_reason='decrypt_failed'` |
| C | Assert blocking | no | `replaying → idle` refused; `wp universal-telegram cutover confirm-complete …` refused; `cutover status` backlog > 0 |
| D | Assert evidence preserved | no | the incident row's ciphertext + `incident_recorded_at` + every `cutover_*` audit row **unchanged**; **no mutation of the row is attempted** |
| E | Documented "blocked-as-designed" outcome | — | Run 3 does **not** reach `confirm-complete`; that is the correct result, recorded in `NOTES.md`, not a failure |
| F | Teardown | **yes** | `docker compose … down -v` (the incident row is destroyed with the disposable DB — the permanent-evidence rule holds within the run's lifetime, and teardown is total, not selective) |

**The incident row is permanent evidence for the lifetime of the run. This runbook never
instructs mutating, overwriting, or replacing an incident row's ciphertext or any of its columns
to make replay or completion succeed.** To also exercise a *remediable* path, use exactly one of:
(a) separate disposable runs — incident-blocking in Run 3, a genuinely-remediable transient-failure
retry in Run 1; or (b) distinct synthetic rows with different `(bot_id, update_id)` values within
one run. The original incident row is never modified.

### 7.4 Optional later scenarios (each its own separately-approved run, under Approval B)

1. **Compensation**: two-member cohort, one member externally forced to `active` before `activate`; prove full compensation (`cas_version` pre-run+2, `state=activation_failed`, provably zero net binding change).
2. **`incident-acknowledge` interface** (§7.5, synthetic fixture only).
3. **`unsupported_command` / `unmapped_sender` / `parse_failed` incidents** — one fixture each.
4. **`handoff_provenance_conflict`** — pre-seed a mismatched `legacy_handoff_map` row; prove `409` + UT incident + no Support Chat write.
5. **Crash-and-resume**: interrupt `activate` mid-saga; re-run with the identical cohort file; prove idempotent resume.
6. **Tier 2 realism run** (only after B1/B2 resolved): real Action Scheduler drain during `quiescence confirm`, real authenticated webhook round-trip, real forum-topic close.

### 7.5 Incident and terminal-acknowledgement handling

- Run 3 **must** test incident detection and safe blocking, and end blocked-as-designed.
- **`incident-acknowledge` is NOT used to make a run pass.** Its interface is rehearsed **only** as an explicitly separate, optional scenario (§7.4 item 2), and only with:
  - a **synthetic** deferred-update fixture that is genuinely unrecoverable by construction;
  - an **opaque** `--po-decision-ref` matching `/^[A-Za-z0-9._\/-]{1,191}$/` and pointing at a **synthetic, pre-created rehearsal decision-record file** (e.g. `rehearsal/incident-ack-fixture-1`) — never free-form text, never a real Product Owner decision reference;
  - an **explicit Product-Owner-authority simulation** note in `NOTES.md` (the operator records that they are standing in for Product Owner authority for this fixture only);
  - proof afterward that the row carries `incident_resolved_at` + `incident_resolution='po_acknowledged_terminal'` + `incident_po_decision_ref`, that `replayed_at` and `handed_off_at` are **still NULL**, that ciphertext + `cutover_*` / audit rows are retained, and that **no** Support Chat `legacy_handoff_map` row and **no** false `handed_off_at` / provenance stamp was produced anywhere.

### 7.6 Cases that stay in automated integration coverage (not repeated operationally)

Already proven, CI-enforced at every commit, by `CutoverActivationServiceTest`,
`CutoverReplayDispatcherTest`, `CutoverWidenedBacklogPredicateTest`,
`WebhookControllerActiveBindingCrossTalkTest`, `InboundAdapterBridgeActivationTest`,
`QuiescenceRaceInterleavingTest`, Support Chat `ContractOperationsControllerTest`, and
[`tests/integration/Interop/CutoverHandoffIntegrationTest.php`](../../tests/integration/Interop/CutoverHandoffIntegrationTest.php)
(7 cases, 42 tests / 580 assertions green on both WP/PHP variants):

- Every individual CAS transition and the two-transaction webhook-vs-final-idle interleaving race.
- Each incident reason code in isolation; the structural "UT incident never writes a Support Chat map row" negative.
- Handoff-map column-shape / no-plaintext structural assertions.
- Fake-provider Phase B success/refusal permutations; effective-batch-size regressions.
- The `prepared` binding never routes / `active` binding never skipped permanent regression.

They are deterministic and fast. The rehearsal's job is the end-to-end operational sequence and
its evidence, plus resolving the §3 assumptions that only a running environment resolves — not
re-litigating unit-level correctness.

## 8. Preconditions and hard stop conditions

### 8.1 Preconditions that must ALL hold before any mutating rehearsal command

1. The applicable authorization is signed — **Approval A** for a Tier 1 run; **Approval B** (which itself requires B1 + B2 resolved and Tier 1 PASS) for a Tier 2 run. (B5)
2. Both checkouts `git rev-parse HEAD` == the accepted SHAs; `git status` clean; the checkouts are the throwaway pair, never `/opt/biopentra/dev/*`. (A1)
3. Disposable env verified fresh: no plugin tables before install; after install, schema UT `36` / SC `11`; `cutover status` / `quiescence status` / `legacy-bind status` all report no open run / `idle` / no prepared bindings. (A8)
4. Both plugins mutually paired; discovery `channel_available:true`. (A2)
5. `docker volume ls` snapshot captured; `docker compose config` reviewed; for Tier 2, isolation of the instance and bot demonstrated (no shared volume/network/DB with `dev.biopentra.eu`; `getWebhookInfo` shows only the isolated vhost). (A7)
6. The synthetic cohort file exists, is readable, and every line is a synthetic UUID created by this rehearsal's own fixtures.
7. Evidence-capture directory created (§9); redaction rules understood (§5.6).
8. `cutover recover` re-confirmed read-only against source at the pinned SHA; `cutover begin` and `cutover activate` understood as mutating with no dry-run (B3).

### 8.2 Hard stop conditions — evaluated before each irreversible / state-changing step (including `cutover begin`, which inserts a `cutover_runs` row)

For every stop condition: **halt immediately, do not run the next mutating command, capture the
listed evidence, and escalate to the Product Owner.** No `force-idle`, no `discard`, no
`silent-abandon`, no hand-editing of an incident row. There is no force-idle, discard, or
silent-abandon path in the code, and none is to be invented.

| Stop condition | Detected by | Safe immediate action | Evidence to retain |
|---|---|---|---|
| Deployed plugin SHA/version ≠ accepted baseline | `git rev-parse` / schema-assertion mismatch (A1) | Halt before any command; rebuild the checkout at the exact SHA | `git rev-parse` output, `git status`, schema query result |
| Environment not provably isolated (shared volume/network/DB/bot, or a real production table visible) | `docker compose config`, `docker volume ls`, `SHOW TABLES`, `getWebhookInfo` | Halt; `docker compose down -v`; do not proceed on this host | compose config, volume list, table list |
| Unexpected source counts / field-validation failure in Phase A or Phase B | `legacy-migrate validate` reports failed count/correspondence; `run` reports `failed > 0`; `status` shows unexpected `failed` / `ownerless_conversation_unsupported` / `note_operator_user_id_null_unsupported` | Halt before quiescence; do not run reconcile / bind / begin | `legacy-migrate status` + `validate` output, `SELECT status, error_reason, COUNT(*)` on the map table |
| `cutover begin` preflight fails for any cohort member | `begin` prints `Cohort preflight failed — the whole cohort is refused; no run was created` | Halt; inspect the per-candidate `ineligible: <reason>` lines; fix the fixture, not the gate | `begin` full stdout |
| `cutover begin` refused by its gates (not `quiescent`; an open run already exists; unresolved backlog > 0; empty cohort file) | `begin` prints the corresponding `WP_CLI::error` | Halt; resolve the named gate (re-establish quiescence, close/complete the stray run, drain the backlog, fix the file) — never bypass | `begin` full stdout, `cutover status`, `quiescence status` |
| Quiescence failure or stale/false provider response | `quiescence confirm` prints `Still draining: …`; or `is_quiescent()` true while a deferred row exists; or the provider returns `true` when UT is not `quiescent` | Halt; do not run reconcile / begin / activate; re-run `quiescence status` | `quiescence status` (state + drain breakdown + backlog + oldest-age), `legacy-bind status` `is_quiescent` line |
| Phase B refused `REFUSED_NOT_QUIESCENT` (whether from the intentional Run 2 injection or unexpectedly) | `legacy-migrate run --phase=reconcile` stdout | **Do NOT run `legacy-bind`, `cutover begin`, or `cutover activate`.** Execute the full quiescence-loss recovery sequence (§7.2 steps 13–18): `exit → replaying`, drain the injected row via its legacy path, verify backlog 0 + `idle`, `enter` + `confirm` again, then re-run Phase B successfully. Only then continue. | reconcile refusal stdout, `quiescence status` at each recovery step, `SELECT replayed_at` on the injected row, backlog-0 + `idle` confirmation, second successful reconcile stdout |
| Recovery sequence (§7.2) not fully completed before a downstream mutating step | operator checklist: any of `exit` / drain / `idle` / `enter` / `confirm` / re-reconcile missing an evidence artefact | Halt; do not run `legacy-bind` / `cutover begin` / `cutover activate` until every §7.2 artefact exists | the §7.2 evidence set, `NOTES.md` checklist |
| Non-empty deferred backlog when a final state requires empty | `cutover status` "Unresolved deferred-update backlog … : N>0" before `confirm-complete`; or `quiescence status` backlog > 0 before expecting `idle` | Halt; re-run `replay-deferred-updates`; if it will not drain, treat as an incident | `cutover status`, `quiescence status`, `SELECT` on `quiescence_deferred_updates` unresolved rows |
| Unresolved incident | `replay-deferred-updates` reports `N incident(s)`; `cutover status` backlog > 0; `SELECT` shows `incident_reason IS NOT NULL AND incident_resolved_at IS NULL` | Halt; either remediate with a genuine retry + re-replay, or (only in the §7.5 scenario) `incident-acknowledge` with a synthetic ref; **never** `confirm-complete` over it; **never** hand-edit the row | incident row (`id, bot_id, update_id, incident_reason, incident_recorded_at` — no ciphertext), `replay-deferred-updates` stdout |
| Non-prepared or mismatched binding | `preflight` reason `not_prepared_status_<status>`; or `activate` prints `activation refused at candidate <uuid>`; or a `409 handoff_provenance_conflict` appears | Halt; if `activate` already ran, the compensation path leaves no net binding change — verify that, then stop | `cutover status` / `recover`, `SELECT binding_uuid, status, cas_version`, activation-audit rows |
| Pairing / authentication failure | Contract call returns `401 {"ok":false,"reason":"contract_auth_failed"}`; discovery `channel_available:false` | Halt; do not inject deferred updates or run replay; re-pair in the disposable env | discovery response, the failing Contract response body, pairing-page state |
| Unexpected external Telegram traffic | Tier 1: any `api.telegram.org` request not absorbed by the `pre_http_request` filter. Tier 2: any update in `getWebhookInfo` / bot logs not originating from the rehearsal | Halt; in Tier 2 `deleteWebhook` immediately; investigate before any further step | request/response capture, `getWebhookInfo`, bot update log |
| `cas_version` not strictly monotonic after a run (e.g. restored to its pre-run value) | `SELECT cas_version` before/after ≠ pre-run+1 (activated) or pre-run+2 (compensated) | Halt; this contradicts ADR-0042 §2 — treat as a correctness finding, not a rehearsal nuisance | before/after `cas_version` values, `cutover_activation_audit` rows, `cutover_transitions` rows |
| Any plaintext / content-derived value found in a handoff-map row, incident row, or audit row | direct `SELECT *` on `legacy_handoff_map` / `quiescence_deferred_updates` incident columns / `cutover_*` audit tables | Halt; treat as a security finding | the offending row with the suspect column, `SHOW COLUMNS` for the table |
| Any evidence the environment is not isolated (any of the above isolation checks) | as above | Halt; teardown; do not continue on this host | the failing check output |

## 9. Success criteria and acceptance evidence

Each disposable run PASSES only if **all** of its applicable criteria hold, each with its
retained artefact. Run 3 legitimately ends "blocked-as-designed" at criterion 10 and does not
reach 11–14.

| # | Criterion | Evidence artefact |
|---|---|---|
| 1 | **Phase A migration evidence**: every synthetic cohort conversation `status='migrated'`; message/note counts correspond; `body_ciphertext` ≠ source plaintext (re-encrypted through Support Chat's own vault); source UT rows unmutated; ownerless / null-operator fixtures excluded with the correct durable reason; high-water mark stable across a second Phase A pass. | `legacy-migrate status` / `validate` stdout, `SELECT` proving `body_ciphertext` differs, before/after `SELECT` on UT `conversations` / `conversation_messages`, second-pass high-water-mark check |
| 2 | **UT quiescence**: `idle→draining→quiescent` with drain proofs recorded; `is_quiescent()` true + backlog 0; a synthetic legacy write returns `409 quiescence_active`. | `quiescence status` per state + full drain breakdown, the `409` body, `quiescence_transitions` audit rows |
| 3 | **Phase B under continuous quiescence**: promotes only while `is_quiescent()`; the intentional mid-run buffered update (Run 2) forces immediate `REFUSED_NOT_QUIESCENT` with the in-progress row rolled back and earlier promotions intact. | reconcile stdout (success + Run 2 refusal), `SELECT status` before/after partial-progress |
| 4 | **Quiescence-loss recovery (Run 2)**: after the refusal, **no `legacy-bind` / `cutover begin` / `cutover activate` runs**; `exit → replaying`; the injected update drains via **legacy `process_update()`** (not handed off, not an incident, no `legacy_handoff_map` row); backlog 0 + `idle` reached; `enter` + `confirm` re-establish `quiescent`; Phase B re-run then promotes the cohort member to `migrated`; only then do binding preparation and `cutover begin` proceed. | `quiescence exit` stdout, per-pass `replay-deferred-updates` stdout showing the row `Replayed`, `SELECT replayed_at, handed_off_at, incident_reason` on the injected row, `idle` + backlog-0 confirmation, `enter` / `confirm` stdout + `is_quiescent(): true`, second successful reconcile stdout, `status='migrated'` select |
| 5 | **Migration-evidence gate**: `status='migrated'` for every cohort member asserted via Support Chat CLI + `wp eval` **before** `cutover begin` (B4 / A4). | Support Chat CLI + `wp eval` output |
| 6 | **Cohort begin (mutating)**: `cutover begin` executed as a mutating step (gated on `quiescent` + no open run + backlog 0 + non-empty file); it inserts one `cutover_runs` row `state=prepared`; any gate refusal is a stop condition, never bypassed. | `cutover status` pre-`begin` (no open run), `begin` stdout, the new `cutover_runs` row |
| 7 | **Activation**: cohort `prepared→active`, `cas_version` = pre-run+1 each, run `state=activated` — **or** (§7.4) forced-failure compensation to `prepared`, `cas_version` = pre-run+2, `state=activation_failed`, provably zero net binding change; all audit rows share one `cutover_run_id`. | `activate` stdout, `SELECT binding_uuid, status, cas_version` before/after, `cutover_transitions` + `cutover_activation_audit` |
| 8 | **Cohort-aware deferred handling**: every active-binding cohort row dispatched via `CutoverReplayDispatcher`; non-cohort / compensated rows fall to legacy replay; `(update_id, id)` order preserved. | `replay-deferred-updates` stdout, per-row `SELECT replayed_at, handed_off_at, incident_reason`, an ordering assertion |
| 9 | **UT→SC provenance handoff + idempotent retry**: one Support Chat domain effect + one `legacy_handoff_map` row per handed-off row (`kind` server-derived, `channel_case_ref` = the binding UUID, `target_message_uuid` only for `kind='message'`); `handed_off_at` stamped only after `{ok:true}`; a second `replay-deferred-updates` pass adds no duplicate; a matching `(bot_id,update_id)` retry converges silently; a mismatched one → `409 handoff_provenance_conflict`, nothing written. | `SELECT *` on `legacy_handoff_map` (filtered), the corresponding Support Chat message / assignment / channel-status row, `quiescence_deferred_updates.handed_off_at`, two consecutive replay passes' stdout, the `409` capture |
| 10 | **Incident detection + safe blocking (Run 3)**: a `decrypt_failed` fixture detected (`1 incident(s)`), blocks `replaying→idle` and `confirm-complete`; **the incident row's ciphertext and every audit row are unchanged, verified again at teardown — the row is never mutated to drain the backlog**; Run 3 ends here, blocked-as-designed. | `replay-deferred-updates` stdout, the two refusal captures, incident-row metadata (no ciphertext) before and at teardown, `NOTES.md` entry |
| 11 | **No legacy mutation for an active SC-bound topic lifecycle event**: an active-binding topic's `forum_topic_closed` reaches `report_channel_unavailable`; the legacy UT conversation row is unmutated. | before/after `SELECT` on the UT legacy conversation row, Support Chat `ChannelStatusRepository` row, handoff-map row `kind='channel_unavailable'` with no `target_message_uuid` |
| 12 | **No plaintext leakage**: `SHOW COLUMNS` + filtered `SELECT *` on every cutover/handoff/incident/audit table shows only ids/uuids/fixed-vocabulary/timestamps; `Migrator::verify_step_11` passes. | `SHOW COLUMNS` output, filtered dumps, verify-step result |
| 13 | **Return to normal DEV operation (Run 1 / Run 2)**: after `confirm-complete`, quiescence `idle` + backlog 0, `cutover status` `state=complete`; a fresh post-idle inbound for the cohort topic routes through the Support Chat adapter; a fresh non-cohort legacy-topic inbound still routes to legacy. | `quiescence status`, `cutover status`, routing assertion outputs |
| 14 | **Teardown proof**: `docker compose … down -v` completes, the run's DB volume is gone, `docker compose ps` empty; (Tier 2) throwaway bot deleted + `getWebhookInfo` empty. | `down -v` stdout, `docker volume ls` diff, `docker compose ps`, `@BotFather` / `getWebhookInfo` confirmation |

### 9.1 Evidence bundle layout

One directory per disposable run (Run 1 / Run 2 / Run 3 / any §7.4 run):

```
rehearsal-evidence/<run-id>/
  00-preconditions/       approval ref, git-rev-parse, docker-compose-config, docker-volume-ls-before, schema-assertions, pairing+discovery, initial cutover/quiescence/legacy-bind status
  01-phaseA/              legacy-migrate {run --dry-run, run, validate, status} stdout, map-table selects, source-unchanged selects, high-water-mark stability
  02-quiescence-enter/    quiescence status per state, drain breakdown, 409 quiescence_active capture, quiescence_transitions audit
  03-phaseB/              reconcile {dry-run, real} stdout; (Run 2) the REFUSED_NOT_QUIESCENT capture + partial-progress selects
  03a-recovery/           (Run 2 only) quiescence exit stdout, replay passes showing the injected row Replayed via legacy path, replayed_at select, idle+backlog-0 confirmation, re-enter/re-confirm stdout, second successful reconcile stdout, status='migrated' select
  04-migration-evidence/  Support Chat CLI + wp eval proving status='migrated' for the cohort member (B4/A4)
  05-bind/                legacy-bind {run --dry-run, run, status} stdout, prepared-binding select (status='prepared', cas_version)
  06-cutover-begin/       cutover status (no open run) + begin stdout + the new cutover_runs row  [MUTATING STEP — begin inserts this row]
  07-deferred-inject/     synthetic payload metadata only (bot_id/update_id/update_type, NO ciphertext), buffer confirmations, cutover recover stdout
  08-activate/            cutover activate stdout, before/after binding selects, cas_version delta, cutover_activation_audit + cutover_transitions sharing one cutover_run_id
  09-replay/              quiescence exit stdout, replay-deferred-updates stdout (each pass), per-row disposition selects, ordering assertion
  10-incident/            (Run 3 only) incident-row metadata (no ciphertext), replaying→idle refusal, confirm-complete refusal, evidence-unchanged proof, "blocked-as-designed" NOTES entry
  11-confirm-complete/    quiescence status idle + backlog 0, cutover confirm-complete stdout, cutover_transitions state=complete   (Run 1 / Run 2 only)
  12-routing-checks/      post-idle routing assertions (Support Chat adapter route for the cohort topic, legacy route for a non-cohort topic)
  13-no-leak/             SHOW COLUMNS + filtered SELECT * for legacy_handoff_map, quiescence_deferred_updates incident columns, cutover_runs/cutover_transitions/cutover_activation_audit; verify_step_11 result
  14-teardown/            down -v stdout, docker volume ls-after (run volume gone), docker compose ps empty, (Tier 2) @BotFather deletion + getWebhookInfo empty
  NOTES.md               deviations, every stop condition hit + action taken, assumptions (§3) resolved, Product-Owner-authority-simulation notes (if the §7.5 scenario)
```

## 10. Approval texts required before execution

Two separate authorizations are needed, in order. **Neither exists yet.**

### Approval A — Tier 1 prerequisite (automated operational-sequence validation)

> **Product Owner authorization — SC-M03 final-cutover Tier 1 prerequisite validation**
>
> I authorize execution of the Tier 1 disposable automated operational-sequence / integration
> validation exactly as described in this runbook and its Support Chat companion, pinned to
> universal-telegram `31519ee3ae297369118bf2deda6eae05d13a3d8b` and universal-support-chat
> `ce4691241eb843485117b323516899df916fdaf7`.
>
> I acknowledge that **Tier 1 does NOT satisfy the accepted requirement for a disposable DEV
> rehearsal** — it lacks real WP-Cron / Action Scheduler, Redis, authenticated Telegram webhook
> ingress, chat-widget traffic, and the DEV VPS runtime surface. Tier 1 is a required
> prerequisite whose purpose is to prove the data effects, state-machine sequencing, and
> CLI-equivalent service ordering of Runs 1, 2 (quiescence-loss recovery), and 3 (incident
> detection + safe blocking) in §7.
>
> This authorization is limited to:
> - the container/PHPUnit interop harness only (`docker/docker-compose.yml` +
>   `docker/docker-compose.interop.yml`, `down -v` before and after each run);
> - entirely synthetic fixture data created by the rehearsal's own code;
> - **zero** Telegram network traffic (no bot token, no `setWebhook`, no message send);
> - fresh throwaway checkouts at the pinned SHAs, never `/opt/biopentra/dev/*` or
>   `dev.biopentra.eu`.
>
> It does **not** authorize: Tier 2 or any DEV rehearsal; any dedicated or existing Telegram
> bot; any use of the `dev.biopentra.eu` site, its database, its bot(s), its webhook, or any
> existing conversation; any production quiescence window, migration, cohort activation, route
> switch, cutover, soak, deployment, release, tag, or rollback; any retention change or deletion
> of Universal Telegram legacy data, UI, or any audit/incident/handoff record; mutation of an
> incident row to drain a backlog; or use of `cutover incident-acknowledge` to make a run pass.
>
> The operator must halt on any §8.2 hard stop condition and escalate to me. A run is PASS only
> when every applicable §9 criterion has its evidence artefact and teardown is proven; Run 3
> legitimately ends "blocked-as-designed" without reaching `confirm-complete`.
>
> Signed: __________________________  Date: __________

### Approval B — Tier 2, the actual disposable DEV rehearsal (cannot be signed until B1 and B2 are resolved)

> **Product Owner authorization — SC-M03 final-cutover disposable DEV rehearsal (Tier 2)**
>
> Prerequisites, all confirmed complete before this authorization takes effect:
> - Tier 1 (Approval A) executed, all runs PASS, evidence bundle reviewed;
> - B1 resolved: an isolated full-WordPress instance exists — its own containers, network, host
>   volumes, database + credentials, Redis, `CredentialVault` key, SWAG vhost and LE cert —
>   sharing no volume, network alias, or database with `dev.biopentra.eu`, isolation demonstrated
>   not asserted;
> - B2 resolved: a dedicated non-production Telegram bot, a dedicated test forum supergroup, and
>   dedicated test topics exist, configured only in that isolated instance; the production and dev
>   support groups and their bots are never referenced.
>
> I authorize execution of the Tier 2 disposable DEV rehearsal (Runs 1, 2, 3 of §7) against that
> isolated instance and dedicated bot only, pinned to universal-telegram
> `31519ee3ae297369118bf2deda6eae05d13a3d8b` and universal-support-chat
> `ce4691241eb843485117b323516899df916fdaf7`, with entirely synthetic fixture data.
>
> It does **not** authorize: any production quiescence window, migration, cohort activation,
> route switch, cutover, soak, deployment, release, tag, or rollback; any retention change or
> deletion of Universal Telegram legacy data, UI, or any audit/incident/handoff record; mutation
> of an incident row to drain a backlog; use of `cutover incident-acknowledge` to make a run
> pass; or any action against `dev.biopentra.eu` or its bot.
>
> "Safe return" after each run is teardown of the isolated instance (`docker compose … down -v`
> + explicit volume removal; delete the throwaway bot via `@BotFather` + `deleteWebhook`; remove
> the isolated vhost conf) — it is **not** a production rollback; production remains forward-only.
> The operator must halt on any §8.2 stop condition and escalate to me.
>
> Signed: __________________________  Date: __________

### Further separate authorizations

Each §7.4 optional scenario (compensation run, the `incident-acknowledge` interface scenario,
each additional incident-reason scenario, the `handoff_provenance_conflict` scenario, the
crash-and-resume run) requires its own authorization referencing that scenario, and — for the
realism-dependent ones — runs only under Approval B.

## 11. Explicit non-authorizations

This document authorizes nothing. It does not authorize, and its existence must not be read as
authorizing:

- execution of Tier 1 or Tier 2;
- any production or DEV quiescence window, migration, binding preparation, cohort activation,
  deferred-update replay, Telegram webhook registration, or any operational command against
  `dev.biopentra.eu` or production;
- creation of infrastructure, containers, Telegram bots, groups, topics, DNS records,
  certificates, or credentials;
- any schema, plugin-version, configuration, test, tag, release, or deployment change;
- production cutover, route switch, soak, rollback, retention change, deletion, or removal of
  Universal Telegram legacy UI or data.

Separate Product Owner approval (Approval A, then Approval B) is required before the DEV
rehearsal — even the Tier 1 prerequisite — may be executed.

## 12. Definition of done (for this documentation-freeze stage only)

- This runbook and its Support Chat companion plan and decision record are committed on
  documentation-only branches, reviewed, CI-green, and merged.
- Registries, plan indexes, milestone §0d pages, and cross-links are updated **planning-only** —
  every touched line states that no rehearsal has run and Product Owner execution approval is
  outstanding.
- No acceptance record is added; that is a later Product Owner action.
- No code, schema, version, configuration, test, tag, release, deployment, or infrastructure
  change is made by the freeze.

---

## Amendment A — 2026-08-27 — Tier 1 halt (finding F1) and correction gate (non-design status note)

This is a post-freeze **status amendment**. It changes no design section above; the design
revision is a new file, `sc-m03-final-cutover-dev-rehearsal-plan-v2.md` (not yet written).

- **Tier 1 was executed and HALTED** at the UT→SC deferred-update handoff phase by **finding
  F1**, recorded in `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` (merge
  `98c602543bd67bc471e2a88468d175fb6e659b46`; Support Chat closure merge
  `fcbfaa773ef63661b6d8ce42962f10bb174588f8`) and pinned by
  `tests/integration/Interop/CutoverTier1HandoffResolutionTest.php`.
- **F1**: Contract v1 `channel_case_ref` was sent as the UT `binding_uuid`, but Support Chat
  resolves it as its own `conversation_uuid`; every real binding mints an independent
  `binding_uuid`. Secondary defect: `CutoverReplayDispatcher::finish()` classified the
  resulting `404 not_found` as an unbounded `OUTCOME_RETRY_TRANSIENT`, blocking replay
  completion with no classified outcome.
- **Correction frozen** (documentation-only, Proposed): **ADR-0043** (this repo) + **Support
  Chat ADR-0011**, and `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`.
  `channel_case_ref` = Support Chat conversation/case UUID; UT emits
  `ChannelBinding::support_conversation_uuid()`; `binding_uuid` stays UT-local, off the wire;
  new closed incidents `unresolved_case_reference` / `handoff_rejected` make every Contract
  outcome after active-binding selection a named retryable outcome or a named incident. No
  schema/`db_version` change. Identifier collapse (option (c)) and an SC-side
  binding→conversation resolver are rejected.
- **Tier 1 acceptance gate**: Tier 1 **cannot be accepted** until the correction is implemented
  in both repositories and its real-binding handoff path (bindings from
  `LegacyBindingImportServiceV1` / `EnsureChannelCaseService`, not equality fixtures) passes
  green in the interop harness. A Tier 1 re-attempt requires a **separate Approval A addendum**
  and runs only under runbook **v2**.
- **Tier 2** retains its **B1** and **B2** blockers and its **unexecuted** status, and is
  **additionally blocked on F1**. Approval B is unchanged and cannot take effect early.
- Product Owner implementation acceptance for F1 is **not** recorded by this amendment — see the
  remediation plan §15.
