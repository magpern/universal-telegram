# SC-M03 Final-Cutover — Tier 2 Disposable DEV Rehearsal Prerequisites Plan v1 (primary)

**Status: planning-only. FROZEN. This document authorizes nothing.** It provisions no
infrastructure; creates no container, network, volume, database, Redis instance, SWAG vhost, DNS
record, or TLS certificate; creates no Telegram bot, supergroup, topic, token, or webhook;
executes no Tier 2 rehearsal; and does not record Approval B. It changes no code, schema, plugin
version, configuration, test, workflow, tag, release, or deployment. It does not alter the
already-consumed Tier 1 Approval A addendum, the executed Tier 1 re-attempt closure, or the
immutable Tier 1 execution baseline SHAs.

Its sole output is this specification — the exact, narrow set of prerequisites that must be
**built and independently verified** before a Tier 2 rehearsal can be authorised, plus a
**proposed, unsigned** Approval B text (§6). Building the environment, recording Approval B, and
executing the one-time Tier 2 rehearsal are three separate later tasks (§7), none of them
authorised here.

## 1. Context and pinned baselines

| Item | Value |
|---|---|
| This plan authored against `origin/main` | universal-telegram `ea06520fdc8998dd2c25b0b5cdd09534c2ded3aa`, universal-support-chat `cc0c879f31bcdee20b7695c599e113449e12480b` |
| Operative rehearsal runbook | [`sc-m03-final-cutover-dev-rehearsal-plan-v2.md`](sc-m03-final-cutover-dev-rehearsal-plan-v2.md) (v2) — all of its §5 isolation/data-safety, §8 hard-stop, and §9 evidence/redaction rules apply to Tier 2 verbatim and are **extended**, never weakened, by this plan |
| Immutable Tier 2 execution baselines | **identical to the immutable Tier 1 execution baselines**: universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e`, universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (operators fetch origin, verify these exact commits exist, check them out; runtime trees byte-identical to the F1 implementation commits `7d4cc4f` / `9144cb1`) |
| Tier 1 status | **COMPLETE — the single authorised re-attempt was executed 2026-08-28 and PASSED** on both supported WP/PHP variants ([Tier 1 re-attempt closure](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md)). The Approval A addendum's one-time authorisation is consumed. |
| Remaining blockers to Tier 2 | **B1** (no isolated full-WordPress rehearsal environment) and **B2** (no dedicated non-production Telegram bot / supergroup / topics) — both defined in runbook v2 §6. This plan resolves the **design and verification** of B1 and B2; it does not resolve them by building anything. |

### 1.1 Why Tier 2 is required and what it must add over Tier 1

The final-cutover Product Owner acceptance names "a separately planned, disposable DEV rehearsal"
as the next possible activity. Tier 1 proved the data effects, state-machine sequencing, and
CLI-equivalent service ordering of Runs 1–3 in the container/PHPUnit interop harness with **zero
Telegram traffic**. It structurally cannot exercise (runbook v2 §4.1):

- real **WP-Cron / Action Scheduler** drain during `quiescence confirm` and `replay-deferred-updates`;
- the **Redis object cache** in the request/CLI path;
- **authenticated Telegram webhook ingress** — a real `POST /wp-json/universal-telegram/v1/webhook/{bot_uuid}` with a real `X-Telegram-Bot-Api-Secret-Token` header, buffered while quiescence is non-idle;
- real **`forum_topic_closed` / `forum_topic_deleted`** service messages from Telegram;
- a real outbound **`sendMessage`** to a Telegram supergroup as a handoff side effect;
- real **chat-widget / front-end HTTP** traffic being `409 quiescence_active`-blocked.

Tier 2 exercises exactly those paths, once, end-to-end, on an isolated instance and a dedicated
throwaway bot — never against `dev.biopentra.eu`, its data, or its bot.

## 2. B1 — isolated full-WordPress rehearsal environment

### 2.1 Topology

A **second, fully independent Docker Compose project**, authored later as a sibling of
`apps/wordpress/` in the `/opt/biopentra` tree (proposed path `/opt/biopentra/rehearsal/`), that
shares **nothing** with the `dev.biopentra.eu` stack:

| Concern | `dev.biopentra.eu` (existing) | Tier 2 rehearsal instance (to be built) |
|---|---|---|
| Compose project name | `wordpress` (dir `apps/wordpress`) | `scm03rehearsal` (dir `rehearsal/`) — explicit `name:` in `compose.yml`, never the default |
| WordPress container | `wordpress` (`biopentra-wordpress:php8.3-redis`) | `scm03rehearsal-wordpress` — official `wordpress:<pinned>-apache` image, **no Biopentra plugin bind-mounts** except the two plugins under test |
| Plugins under test | bind-mount `/opt/biopentra/dev/universal-telegram` (+ 12 others) | **fresh throwaway checkouts** of universal-telegram `6eed022…` and universal-support-chat `4f833c3…`, checked out under `/opt/biopentra/rehearsal/src/{universal-telegram,universal-support-chat}` (detached HEAD, clean tree), bind-mounted read-only; **no other Biopentra plugin, and no theme child, is mounted** |
| Database | `wordpress-db` (`mariadb:11.8.8`), db `wordpress`, user `wordpress`, volume `/opt/biopentra/data/wordpress/db` | `scm03rehearsal-db` (`mariadb:<pinned>`), db `rehearsal`, distinct user + password, **named volume** `scm03rehearsal_db` (not a host bind under `/opt/biopentra/data`) |
| Redis | `wordpress-redis` (`redis:8.8.0`), volume `/opt/biopentra/data/wordpress/redis`, password `${REDIS_PASSWORD}` | `scm03rehearsal-redis` (`redis:<pinned>`), **distinct password**, named volume `scm03rehearsal_redis`, `--maxmemory` set; `WP_REDIS_HOST=scm03rehearsal-redis` in the rehearsal `wp-config` only |
| Object cache | redis-cache plugin, `WP_REDIS_*` → `redis` | redis-cache plugin enabled and its drop-in verified active (`wp redis status`) — this is the point of the rehearsal; distinct Redis, distinct key prefix (`WP_CACHE_KEY_SALT` set to a rehearsal-specific value) |
| Networks | `reverse_proxy` (external, shared), `wordpress_internal` (`internal: true`), `wordpress_bridge-net` | `scm03rehearsal_edge` (**own** external-style bridge for its own SWAG only, or joins `reverse_proxy` as a *distinct alias* — see §2.2), `scm03rehearsal_internal` (`internal: true`) for db + redis. **Never joins `wordpress_internal` or `wordpress_bridge-net`.** |
| Reverse proxy / TLS | shared `swag` container, vhost `dev.biopentra.eu` | **its own SWAG container** `scm03rehearsal-swag` on its own edge network, **or** a dedicated `proxy/config/nginx/proxy-confs/` server block with a distinct `server_name` — either way a **distinct `server_name`, distinct upstream, distinct certificate** (§2.2) |
| WP-Cron | host crontab `*/5 * * * * /opt/biopentra/scripts/wp-cron.sh` | a **separate** rehearsal cron entry (or a foreground `wp cron event run` loop run by the operator during the rehearsal), targeting only `scm03rehearsal-wordpress`; `DISABLE_WP_CRON` true as in dev, so Action Scheduler drains through that real cron — the behaviour Tier 1 could not exercise |
| Secrets file | `apps/wordpress/.env` (mode 600) | `rehearsal/.env` (mode 600) — **new, gitignored, never committed, never echoed**; contains only rehearsal-scoped `MARIADB_*`, `REDIS_PASSWORD`, admin bootstrap; **no value copied from `apps/wordpress/.env`, `.admin-credentials`, or `proxy/config/dns-conf/cloudflare.ini`** |
| Mail / worker | `proton-bridge`, `biopentra-mail-worker` | **absent** — the rehearsal has no mail path |

Pin every image to an exact release tag (no `:latest`), documenting version + date in a
`compose.yml` comment, per the repo image policy.

### 2.2 Domain / TLS — and why a public HTTPS endpoint is unavoidable for B2

Telegram delivers webhook updates only to a **publicly reachable HTTPS URL with a valid
certificate**. Therefore the rehearsal instance needs a real public endpoint for the duration of
the rehearsal. Two acceptable options, decided at provisioning time and recorded in the B1
verification artefact:

- **Option A (recommended) — dedicated subdomain.** A new DNS record
  `rehearsal.dev.biopentra.eu` (or a separate rehearsal-only hostname), Cloudflare-proxied
  (orange cloud) with zone SSL "Full (strict)", served by the rehearsal SWAG/vhost with its own
  DNS-01 Let's Encrypt certificate. The certificate is obtained with a **scoped Cloudflare API
  token limited to that one DNS zone/record**, stored in a **separate** `dns-conf/cloudflare.ini`
  under `rehearsal/` (mode 600, gitignored) — the existing `proxy/config/dns-conf/cloudflare.ini`
  is never read or reused. The record is **deleted at teardown** (§2.5).
- **Option B — temporary reverse tunnel.** A short-lived public HTTPS tunnel (e.g. a Cloudflare
  Tunnel or equivalent) from a rehearsal-only hostname to `127.0.0.1:<rehearsal-port>`, bound
  **only** to loopback, torn down immediately after the rehearsal. No new permanent DNS record.

Either way: **UFW is not modified** (public ingress stays 2222/80/443 via the existing SWAG, or
via the tunnel daemon's own outbound connection); **SSH config is not touched**; the rehearsal
hostname never resolves to, and the rehearsal vhost never proxies to, `dev.biopentra.eu` or the
`wordpress` container.

### 2.3 Secrets boundary

- `rehearsal/.env`, `rehearsal/dns-conf/cloudflare.ini` (Option A), and any tunnel credential are
  **mode 600**, added to `.gitignore` before they are created, and **never committed, echoed
  into chat/logs, or included in an evidence artefact**.
- The rehearsal `CredentialVault` key is **distinct** — derived from rehearsal-specific WordPress
  salts generated at install time; it never equals the `dev.biopentra.eu` key and cannot decrypt
  any `dev.biopentra.eu` ciphertext.
- No production or DEV credential (`apps/wordpress/.env`, `.env.worker`, `.admin-credentials`,
  `proxy/config/dns-conf/cloudflare.ini`, any bot token in the `dev.biopentra.eu` DB) is read by
  provisioning or by the rehearsal.

### 2.4 B1 isolation invariants and verification gate

Provisioning is complete only when an **independent verifier** (not the person who built it)
confirms **all** of the following, capturing the command output as the B1 verification artefact.
Every check is "demonstrated, not asserted":

1. **Distinct compose project.** `docker compose -p scm03rehearsal config` resolves; its
   `name:` is `scm03rehearsal`; no service, network, or volume name collides with the
   `wordpress` project.
2. **No shared volume or bind.** `docker compose -p scm03rehearsal config --format json` shows
   **no** bind mount under `/opt/biopentra/data/wordpress/*` or `/opt/biopentra/dev/*` (except the
   two read-only plugin-under-test checkouts under `/opt/biopentra/rehearsal/src/`), and **no**
   named volume shared with the `wordpress` project (`docker volume ls` — the rehearsal volumes
   are `scm03rehearsal_*` and did not exist before provisioning).
3. **No shared network.** `docker network inspect wordpress_internal` and
   `docker network inspect wordpress_bridge-net` list **no** `scm03rehearsal-*` container.
   The rehearsal db and redis are on `scm03rehearsal_internal` (`internal: true`) only.
4. **No shared database.** From `scm03rehearsal-db`: `SELECT ... ` proves the schema is
   freshly created for this rehearsal (plugin tables absent before install; after install,
   schema universal-telegram `36` / universal-support-chat `11`). The `wordpress-db` container is
   not reachable from `scm03rehearsal-wordpress` (`docker exec scm03rehearsal-wordpress
   getent hosts wordpress-db` fails; a connection attempt to `wordpress-db:3306` times out).
5. **No shared Redis.** `scm03rehearsal-redis` is a distinct container with a distinct password;
   `wp redis status` inside the rehearsal reports connected to `scm03rehearsal-redis`;
   `KEYS *` on `wordpress-redis` shows no rehearsal key prefix and vice versa.
6. **No shared web identity.** `curl -sI https://rehearsal.dev.biopentra.eu` (Option A) or the
   tunnel URL returns the rehearsal site; `curl -sI https://dev.biopentra.eu` is unchanged and
   still served by the original stack; the rehearsal vhost `server_name` and upstream differ.
7. **Public listener surface unchanged.** `ss -tuln` on the host shows public listeners are
   **exactly** 2222/80/443 (no new published port); `docker compose -p scm03rehearsal config`
   has **no `ports:`** on any service except its own SWAG (80/443 on its own edge) or no SWAG at
   all under Option B. UFW rules unchanged.
8. **Pinned, clean checkouts.** `git -C /opt/biopentra/rehearsal/src/universal-telegram rev-parse
   HEAD` == `6eed0228286e84b4e56e0119f242b483f138a58e`; universal-support-chat ==
   `4f833c3344c3cff2adcc0227f93832c0c3a4427a`; both `git status --porcelain` clean; both mounted
   read-only.
9. **Fresh state.** `wp universal-telegram cutover status` → no open run; `wp universal-telegram
   quiescence status` → `idle`; `wp universal-support-chat legacy-bind status` → no prepared
   bindings; no cohort/binding/quiescence/cutover-run rows pre-exist.
10. **Redis object cache and real cron proven live.** `wp redis status` → connected;
    `wp cron event list` shows `action_scheduler_run_queue`; a test Action Scheduler job queued
    and drained by the rehearsal cron within one interval.

**B1 is not "resolved" until this artefact exists and an independent reviewer signs off on it.**

### 2.5 Complete B1 teardown

Run after the rehearsal (and on any hard stop):

1. `docker compose -p scm03rehearsal down -v --remove-orphans`.
2. `docker volume rm scm03rehearsal_db scm03rehearsal_redis` (and any other `scm03rehearsal_*`),
   then `docker volume ls | grep scm03rehearsal` → empty.
3. `docker network rm scm03rehearsal_internal scm03rehearsal_edge` (if not auto-removed) →
   `docker network ls | grep scm03rehearsal` → empty.
4. `docker image rm` the rehearsal-only images if any were built.
5. Option A: delete the `rehearsal.dev.biopentra.eu` DNS record; revoke the scoped Cloudflare
   token; remove the rehearsal SWAG vhost conf and its certificate. Option B: stop and remove the
   tunnel; the tunnel hostname no longer resolves.
6. `rm -rf /opt/biopentra/rehearsal/` including `.env`, `dns-conf/`, and `src/` — after the
   evidence bundle (redacted) has been copied out.
7. Re-run B1 verification checks 6 and 7: `curl -sI https://dev.biopentra.eu` unchanged;
   `ss -tuln` public listeners exactly 2222/80/443; the rehearsal hostname no longer resolves.

## 3. B2 — dedicated non-production Telegram bot, supergroup, and topics

### 3.1 Minimum Telegram resources

- **One** bot, created via `@BotFather` (`/newbot`), used **only** for this rehearsal.
- **One** Telegram group, upgraded to a **forum-enabled supergroup** ("Topics" turned on), with
  the bot added as an administrator with "Manage Topics" permission.
- **The minimum test topics** the runbook Runs need: one cohort topic (Run 1 handoff), one
  non-cohort/legacy topic (routing negative check), and one topic used for the
  `forum_topic_closed` / `forum_topic_deleted` lifecycle checks (Run 1 / Run 3). Three topics.
- **One** non-production Telegram **user account** (the operator's own test account) to post the
  synthetic operator replies and `/claim` commands and to open/close topics. No other human
  participates.

### 3.2 Ownership

- The bot and supergroup are owned by the **operator's dedicated non-production Telegram
  account**, documented by name/handle in the B2 verification artefact.
- The **production and DEV support bot(s) and their supergroup(s) are never referenced** — not
  their tokens, not their chat IDs, not their webhook URLs. The rehearsal DB contains only the
  throwaway bot profile.

### 3.3 Credential storage

- The bot token and the webhook secret are entered **only** through the rehearsal WordPress
  admin UI (the plugin's Bot Management screen), which encrypts them per-bot in the rehearsal DB
  via `CredentialVault`. They are **never** written to `rehearsal/.env`, any file on disk, any
  commit, any log, or any evidence artefact.
- The webhook secret is a fresh, random, high-entropy string generated for this rehearsal.
- At teardown the rehearsal DB volume is destroyed (§2.5), so the encrypted token is destroyed
  with it; the token is **additionally** revoked at the Telegram side (§3.6).

### 3.4 Webhook lifecycle

1. **Register.** Through the plugin's own webhook-registration path (not a manual `curl`):
   `setWebhook` to
   `https://rehearsal.dev.biopentra.eu/wp-json/universal-telegram/v1/webhook/{bot_uuid}` with
   `secret_token` = the generated secret, `allowed_updates` restricted to exactly the update
   types the Runs need (`message`, `edited_message` if used, and the forum-topic service
   messages), and `drop_pending_updates=true`.
2. **Verify.** `getWebhookInfo` shows the rehearsal URL, `pending_update_count` 0, no
   `last_error_message`, and `has_custom_certificate=false`.
3. **Operate.** Real updates flow only from the operator's test account in the test supergroup
   for the duration of the rehearsal.
4. **Deregister.** `deleteWebhook(drop_pending_updates=true)` at teardown or on any hard stop;
   `getWebhookInfo` then shows an empty `url`.

### 3.5 Ingress authentication

- Every inbound `POST` to the webhook route carries `X-Telegram-Bot-Api-Secret-Token`; the
  plugin's `WebhookSecretVerifier` rejects any request whose header does not match the stored
  per-bot secret (verified by a deliberate negative probe with a wrong/absent header → rejected).
- The route is otherwise unauthenticated by design (Telegram cannot sign requests); the secret
  header **is** the ingress authentication. The rehearsal must show it working (accepts correct,
  rejects incorrect) and must record that no update reached a handler without it.
- No IP allow-listing change, no SWAG auth change, no UFW change.

### 3.6 Full post-rehearsal revocation / deletion

1. `deleteWebhook(drop_pending_updates=true)`; confirm `getWebhookInfo.url` empty.
2. Revoke the bot token via `@BotFather` (`/revoke`), then **delete the bot** (`/deletebot`).
3. Delete the test supergroup (remove all members, delete group) — or, if Telegram retains it,
   remove the bot and rename/clear it and record that it is inert and unreferenced.
4. Destroy the rehearsal DB volume (§2.5) — the encrypted token and all bot/binding rows go with
   it.
5. Record in the teardown artefact: `getWebhookInfo` empty; `@BotFather` deletion confirmation;
   the throwaway account holds no active bot for this rehearsal.

### 3.7 B2 verification gate

Independent verifier confirms and captures: the bot handle and owner; the supergroup is a
distinct forum supergroup with only the operator's test account and the bot; the three topics
exist; `getWebhookInfo` points only at the rehearsal URL; a wrong-secret probe is rejected; **no
production/DEV bot token, chat ID, or webhook URL appears anywhere in the rehearsal
configuration**; the deletion procedure (§3.6) is documented and dry-run-checked.

## 4. Tier 2 operator sequence — the real paths Tier 1 did not exercise

Tier 2 runs **Runs 1, 2, and 3 of runbook v2 §7** unchanged in intent, but on the isolated
instance with the dedicated bot, so that each step goes through its real runtime path. The
additions and substitutions relative to Tier 1:

| Runbook step / concern | Tier 1 (executed) | Tier 2 (this plan) |
|---|---|---|
| Fixture seeding | in-process repository calls | in-process repository calls **for the legacy conversation/message/note rows**; the **cohort topic and its Telegram bindings are real** — the operator creates the test topic in the supergroup, the bot receives the `forum_topic_created` update, the plugin records the destination/topic |
| Phase A / Phase B | real service calls | **real `wp universal-support-chat legacy-migrate run --phase=backfill\|reconcile --assume-migration-authority`** via `docker compose -p scm03rehearsal exec wpcli wp …`, with real Action Scheduler batch jobs draining through the rehearsal cron |
| Quiescence enter/confirm | real `QuiescenceGate` calls | **real `wp universal-telegram quiescence enter --assume-quiescence-authority` then `… confirm`**, with the **real async drain proofs** (topic-create/delete queue, outbound routing, `telegram_send_message` jobs, AI-draft leases) settling through real WP-Cron / Action Scheduler — the timing behaviour Tier 1 stubbed |
| `409 quiescence_active` on a live write | synthetic assertion | a **real front-end request** (chat widget or a real REST call) to the rehearsal site while quiescent → real `409` response captured from the wire |
| Deferred-update capture | `DeferredUpdateRepository::buffer(...)` injection | the operator posts a **real message** in the cohort topic → Telegram → **real authenticated `POST /wp-json/universal-telegram/v1/webhook/{bot_uuid}`** with the secret header → the plugin buffers it (`CredentialVault`-encrypted, AAD `quiescence-deferred-update:{bot_id}:{update_id}`) because quiescence is non-idle; a duplicate delivery returns 200 idempotently |
| `legacy-bind` | real `LegacyBindingImportServiceV1` | **real `wp universal-support-chat legacy-bind run --assume-binding-authority`** producing a real `prepared` binding with an independent `binding_uuid` |
| `cutover begin` | real `CutoverRunRepository::create_prepared()` | **real `wp universal-telegram cutover begin --cohort-file=<path>`** (a synthetic one-line `support_conversation_uuid` file), inserting a real `cutover_runs` row after the whole-cohort preflight |
| `cutover activate` | real CAS write | **real `wp universal-telegram cutover activate --run=<uuid> --cohort-file=<path> --assume-cutover-authority`** — real `prepared → active` with `cas_version` pre-run+1; the forced-failure compensation variant stays a §7.4 optional run |
| `replay-deferred-updates` | real dispatch, `pre_http_request` boundary | **real `wp universal-telegram quiescence replay-deferred-updates`** — real in-process Contract v1 calls to the real Support Chat server **and a real outbound `sendMessage` to the test supergroup** for each handed-off row; `handed_off_at` stamped only after `{ok:true}` |
| Topic lifecycle | synthetic `forum_topic_closed` payload | the operator **really closes / deletes** a test topic → real `forum_topic_closed` / `forum_topic_deleted` update → real `report_channel_unavailable` to Support Chat; the legacy conversation row is not mutated |
| `confirm-complete` | real call | **real `wp universal-telegram cutover confirm-complete --run=<uuid> --assume-cutover-authority`** → `state=complete`, with `quiescence status` `idle` + backlog 0 |
| Post-idle routing | synthetic inbound | a **real** post-idle message in the cohort topic routes through the Support Chat adapter (`InboundAdapterBridge::try_handle` claims it); a real message in the non-cohort legacy topic still routes to legacy |
| Redis | absent | present and **used** throughout — `wp redis status` connected; object-cache hit/miss observed across the CLI steps |

Run 3 still ends **blocked-as-designed** for every injected incident
(`unresolved_case_reference` / `handoff_rejected` / `decrypt_failed`); no incident row is mutated;
`incident-acknowledge` is exercised only as the separate runbook v2 §7.5 synthetic scenario.

## 5. Evidence, redaction, hard-stop, incident, retry, rollback, teardown

**All of runbook v2 §5 (isolation/data safety), §8 (hard stop conditions), §9 (success criteria
and the `rehearsal-evidence/<run-id>/` bundle layout), and §7.5 (incident / terminal
acknowledgement) apply to Tier 2 verbatim**, with these Tier 2 additions:

### 5.1 Evidence additions (Tier 2 only)

- `00-preconditions/` also holds: the signed Approval B; the **B1 verification artefact** (§2.4);
  the **B2 verification artefact** (§3.7); `getWebhookInfo` before the rehearsal.
- `07-deferred-inject/` is renamed in intent — it holds the **real webhook ingress** evidence:
  the redacted `POST` request line and headers (secret value **redacted**), the buffered-row
  metadata (`bot_id`, `update_id`, `update_type` — no ciphertext), the wrong-secret negative
  probe result.
- `09-replay/` also holds the redacted outbound `sendMessage` request/response (chat_id and
  message text **redacted**; only the `ok:true`/message_id and the map-row linkage retained).
- `14-teardown/` also holds: `deleteWebhook` result; `getWebhookInfo` empty; `@BotFather`
  deletion confirmation; the B1 teardown checks 6/7 (`curl -sI https://dev.biopentra.eu`
  unchanged; `ss -tuln` public listeners exactly 2222/80/443; rehearsal hostname no longer
  resolves); `docker volume ls` / `docker network ls` showing no `scm03rehearsal_*`.

### 5.2 Redaction additions

Never retain: any bot token, any webhook secret, any `X-Telegram-Bot-Api-Secret-Token` value,
any Telegram `chat_id` or user id, any real message text (fixture text is synthetic but still
elided), any scoped Cloudflare token, any tunnel credential, any `CredentialVault` key material.

### 5.3 Hard-stop additions

Halt immediately, capture evidence, escalate to the Product Owner, and **`deleteWebhook`
immediately** on any of:

- any inbound update in `getWebhookInfo` / the bot update log **not originating from the
  operator's test account in the test supergroup**;
- any `getWebhookInfo.last_error_message`, or the webhook URL ever showing anything other than
  the rehearsal URL;
- any evidence that the rehearsal site resolves to, proxies to, or shares a DB/Redis/volume/
  network with `dev.biopentra.eu` (fails a B1 §2.4 check mid-rehearsal);
- any `sendMessage` target that is not the test supergroup;
- any of the runbook v2 §8.2 conditions (SHA/version mismatch, quiescence failure,
  `REFUSED_NOT_QUIESCENT` without full recovery, unresolved incident, non-prepared/mismatched
  binding, `cas_version` non-monotonic, plaintext in a handoff/incident/audit row, pairing/auth
  failure).

### 5.4 Incident / retry / rollback

- Incident handling is exactly runbook v2 §7.3 / §7.5: detection + safe blocking; the incident
  row is never mutated, acknowledged, or repaired to drain a backlog or reach `confirm-complete`.
- Retry: a genuine transient-transport failure that clears on the next `replay-deferred-updates`
  pass and auto-stamps `incident_resolution='retried_success'` — no other "retry" is permitted.
- **Rollback: there is none in the production sense.** "Safe return" for Tier 2 is **teardown**
  of the isolated instance and the throwaway bot (§2.5, §3.6). Production and `dev.biopentra.eu`
  remain forward-only and are untouched. A failed Tier 2 run does not "roll back" — it tears
  down, the evidence is preserved, and a second attempt requires a **new** Product Owner
  approval.

### 5.5 Teardown

B1 teardown (§2.5) **and** B2 revocation/deletion (§3.6), both fully executed and both evidenced,
are a mandatory part of every Tier 2 run — pass, fail, or hard stop.

## 6. Proposed Approval B text (PROPOSED — unsigned)

> **Product Owner authorization — SC-M03 final-cutover disposable DEV rehearsal (Tier 2)**
>
> Status: **PROPOSED — not signed, not recorded.** This text is reproduced for review only. It
> takes effect only when signed and recorded in a dedicated acceptance record, and only after the
> prerequisites below are independently verified.
>
> **Prerequisites — all confirmed complete and independently verified before this authorization
> takes effect:**
>
> - **Tier 1 complete.** The single authorised Tier 1 re-attempt under DEV rehearsal runbook v2
>   was executed and PASSED on both supported WP/PHP variants (Tier 1 re-attempt closure,
>   universal-telegram / universal-support-chat, 2026-08-28). Its Approval A addendum is
>   consumed and is not reused.
> - **B1 resolved and verified.** An isolated full-WordPress rehearsal instance exists — its own
>   Docker Compose project (`scm03rehearsal`), containers, networks, named volumes, database and
>   credentials, Redis instance and password, `CredentialVault` key, reverse-proxy vhost, and TLS
>   certificate (or temporary tunnel) — sharing **no** volume, bind mount, network, database,
>   Redis, credential, or web identity with `dev.biopentra.eu` or anything under
>   `/opt/biopentra/dev/*` or `/opt/biopentra/data/*`. Isolation is **demonstrated, not
>   asserted**, in a B1 verification artefact signed off by a reviewer who did not build the
>   environment, covering every check in the Tier 2 prerequisites plan §2.4. Public host
>   listeners remain exactly 2222/80/443; UFW and SSH configuration are unchanged.
> - **B2 resolved and verified.** A dedicated non-production Telegram bot, a dedicated
>   forum-enabled test supergroup, and the minimum test topics exist, owned by the operator's
>   non-production Telegram account, configured **only** in the isolated instance. The
>   production and DEV support bots and groups — their tokens, chat IDs, and webhook URLs — are
>   never referenced. The bot token and webhook secret are stored only encrypted in the
>   rehearsal database via `CredentialVault`, never on disk or in any commit or log. A B2
>   verification artefact (plan §3.7) is signed off by an independent reviewer, and the full
>   revocation/deletion procedure (plan §3.6) is documented.
>
> **Authorization.** I authorize execution of the Tier 2 disposable DEV rehearsal — Runs 1, 2,
> and 3 of DEV rehearsal runbook v2 §7 — **exactly once**, against that isolated instance and
> that dedicated bot only, pinned to the immutable execution baselines universal-telegram
> `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
> `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (operators fetch origin, verify these exact commits
> exist, and check them out first), with entirely synthetic fixture data and only the operator's
> test Telegram account as a participant.
>
> **This authorization covers, for that single rehearsal, and only within the isolated instance:**
> real WP-Cron / Action Scheduler drain during `quiescence confirm` and `replay-deferred-updates`;
> the Redis object cache in the request/CLI path; a real authenticated Telegram webhook
> round-trip (`setWebhook` / `getWebhookInfo` / `deleteWebhook` against the dedicated bot, and
> real `POST` ingress with the `X-Telegram-Bot-Api-Secret-Token` header); real
> `forum_topic_closed` / `forum_topic_deleted` service messages from the test supergroup; a real
> outbound `sendMessage` to the test supergroup as a handoff side effect; and a real front-end
> request receiving `409 quiescence_active`.
>
> **It does NOT authorize:** a second Tier 2 rehearsal or any repeat run; any change to the
> immutable execution baseline SHAs; any action against `dev.biopentra.eu`, `/opt/biopentra/dev/*`,
> `/opt/biopentra/data/*`, the DEV or production WordPress, database, Redis, SWAG, bots, webhooks,
> or any existing conversation; any production or DEV quiescence window, migration, binding
> preparation, cohort activation, deferred-update replay, route switch, cutover, soak, deployment,
> release, tag, rollback, deletion, or retention change; any retention change or deletion of
> Universal Telegram legacy data, UI, or any audit/incident/handoff record; mutation of an
> incident row to drain a backlog; use of `cutover incident-acknowledge` to make a run pass; any
> message to a real user or a real support group; any modification of SSH, UFW, or any other
> `/opt/biopentra` service; or any of the §7.4 optional scenarios (each needs its own
> authorization).
>
> **Safe return** after the rehearsal — pass, fail, or hard stop — is full teardown: the isolated
> instance (`docker compose -p scm03rehearsal down -v` + explicit volume and network removal +
> removal of the vhost/certificate or tunnel + deletion of `/opt/biopentra/rehearsal/`) and the
> Telegram side (`deleteWebhook`; `@BotFather` `/revoke` then `/deletebot`; delete the test
> supergroup), both evidenced. It is **not** a production rollback; production remains
> forward-only. The operator must halt on any runbook v2 §8.2 or Tier 2 prerequisites plan §5.3
> hard-stop condition and escalate to me, and must `deleteWebhook` immediately on any unexpected
> inbound update.
>
> A Tier 2 run is PASS only when every applicable runbook v2 §9 criterion and every Tier 2
> evidence addition (plan §5.1) has its artefact, Run 3 ends blocked-as-designed, and both
> teardowns are proven. Any second attempt requires a new Product Owner approval.
>
> Signed: __________________________  Date: __________

## 7. Four-phase separation — what is and is not authorised

| Phase | What it is | Authorised by | Status |
|---|---|---|---|
| **1. Documentation / planning** (this plan) | Specify and freeze the B1/B2 prerequisites and the proposed Approval B text. | Ordinary documentation review + this PR. | **This task.** Authorises nothing operational. |
| **2. Prerequisite provisioning** | Build the isolated instance (B1) and create the dedicated bot/supergroup/topics (B2); produce and independently sign off the B1 and B2 verification artefacts. | A separate later task, on the Product Owner's instruction. Touches `/opt/biopentra` infrastructure and Telegram — **not** covered by this plan or by Approval A. | **Not started. Not authorised here.** |
| **3. Approval B recording** | The Product Owner signs the §6 text (or a revision of it) into a dedicated acceptance record, after reviewing the phase-2 artefacts. | The Product Owner. | **Not done. This plan does not record it.** |
| **4. One-time Tier 2 execution** | Execute Runs 1–3 once on the provisioned instance per runbook v2 §7 + this plan §4/§5; capture the evidence bundle; tear down both sides; record a Tier 2 closure. | Signed Approval B (phase 3) + verified phases 2. | **Not authorised. Blocked on phases 2 and 3.** |

## 8. §9.2 SHA reference — labelled documentation clarification

Runbook v2 §9.2 point 1 pins `git rev-parse HEAD` to universal-telegram
`6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
`4f833c3344c3cff2adcc0227f93832c0c3a4427a`. **These are the immutable, Product-Owner-approved
Tier 1 execution baselines** established by the Approval A addendum and Product Owner decision
Addendum C — not "the current origin/main HEAD". An earlier revision of §9.2 (before the
immutable baselines were pinned) cited universal-telegram `33b042f…` / universal-support-chat
`2000eaf…`, and the Tier 1 re-attempt closure's "Scope boundary" paragraph referred to that
earlier revision; the runbook text itself now carries the correct immutable-baseline SHAs and no
change to it is required. This clarification is additive and changes no historical execution
record, no closure, and no immutable baseline.

## 9. Explicit non-authorizations

This document authorizes nothing and its existence must not be read as authorizing: provisioning
the B1 environment; creating any Telegram bot, supergroup, topic, token, or webhook; recording
Approval B; executing Tier 2 or any part of it; any action against `dev.biopentra.eu`,
`/opt/biopentra/dev/*`, `/opt/biopentra/data/*`, the DEV/production WordPress, database, Redis,
SWAG, bots, webhooks, or conversations; any DNS, TLS, UFW, SSH, or `/opt/biopentra` service
change; any production or DEV quiescence, migration, binding preparation, cohort activation,
route switch, cutover, soak, deployment, release, tag, rollback, deletion, or retention change;
any second Tier 1 attempt or any change to the immutable Tier 1 execution baseline SHAs. Separate
Product Owner authorization (Approval B), preceded by independently verified B1/B2 provisioning,
is required before any Tier 2 activity.

## 10. Registry

- `docs/plans/README.md` — this plan added under the SC-M03 final-cutover rehearsal group,
  marked planning-only / prerequisites-only.
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` §0d — one planning-only line.
- Support Chat companion:
  `universal-support-chat/docs/plans/sc-m03-final-cutover-tier2-dev-rehearsal-prerequisites-plan-v1.md`
  (the B1 database/schema and Support Chat CLI half, and the same §6 proposed Approval B text),
  plus its `docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md` Addendum E
  (labelled — Tier 2 prerequisites specified; Approval B still proposed/unsigned).
