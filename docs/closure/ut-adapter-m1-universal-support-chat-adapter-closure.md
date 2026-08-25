# Closure Record — UT Adapter M1 Universal Support Chat Adapter

## Status

Closed for implementation review (awaiting Product Owner acceptance / merge).

## Scope closed

Implements Universal Telegram as an optional Support Chat Contract v1 channel adapter:

- Contract discovery against pinned Support Chat ADR-0005
- UT-owned binding + delivery-idempotency tables (`db_version` 30→31)
- Outbound REST acceptors: `ensure_channel_case`, `notify_operators`, `deliver_transcript_backfill`, `deliver_message`
- Inbound webhook bridge for bound topics → Support Chat Contract client (ingest + claim/release/resolve/reopen)
- Fail-closed deactivation reporting (`report_channel_unavailable`)
- WP-CLI binding import readiness for SC-M03
- Hub “Support Chat adapter” status/settings tab

## Explicit non-goals (unchanged)

- No SC-M03 cutover / dual-write / legacy chat deletion
- No plugin SemVer bump (remains `0.16.0`)
- No Support Chat SQL
- No widget/Hub SoR ownership in UT

## Pins recorded

| Item | Value |
|---|---|
| Contract v1 SHA | `dff2730e24b7d3f70f15f706305e12e14fdcc6c8` |
| Contract v1 URL | https://github.com/magpern/universal-support-chat/blob/dff2730e24b7d3f70f15f706305e12e14fdcc6c8/docs/adr/0005-canonical-support-channel-contract-v1.md |
| Support Chat `main` at Adapter M1 implementation (SC-M02 merged) | `653bc4020ef3ffd1233fd1951bf3bc2bccd5c659` |
| UT `origin/main` branch point | `7ff563eb218447c77fbd559e04599c06ae303c98` |
| UT `db_version` target | `31` |
| Plugin SemVer | `0.16.0` (unchanged) |

## Verification

- Unit: `DiscoveryClientTest`
- Integration: binding uniqueness, discovery fail-closed without SC, no SC SQL structural scan, deliver idempotency path, uninstall drops Adapter M1 tables
- Package acceptance expects `db_version` 31 and Adapter M1 tables

## Next

Product Owner merge of this PR, then Support Chat **SC-M03** migration/cutover (creates bindings via CLI / tooling; removes dual SoR).
