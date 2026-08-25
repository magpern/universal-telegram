# Closure Record — UT Adapter M1 Universal Support Chat Adapter

## Status

Closed for implementation review (awaiting Product Owner acceptance / merge).

## Accurate scope statement

**Adapter persistence, UI, and fail-closed wiring are implemented; operational Contract v1 exchange remains unavailable pending SC-M03’s authenticated, capability-advertising Contract server.**

## Scope closed (this milestone)

- Contract discovery against pinned Support Chat ADR-0005, capability-aware:
  - exact `support-channel-contract/v1`
  - every Adapter M1 required operation advertised
  - `channel_available: true`
- Current SC-M02 inert discovery (`channel_available: false`) correctly yields **Unavailable**, not Compatible
- UT-owned binding + delivery-idempotency tables (`db_version` 30→31)
- Outbound REST acceptors registered but mutating calls require Compatible discovery **and** an explicit authenticated Contract assertion filter (default deny). Holding only `universal_support_chat_manage` or UT MANAGE alone cannot create topics, enqueue Telegram sends, or mutate bindings through these routes.
- Inbound UT→SC Contract client fails closed: bare `rest_do_request()` is **not** claimed as authentication; lifecycle calls return unavailable until SC-M03’s authenticated server exists
- Fail-closed deactivation reporting (local binding status; SC report remains unavailable until SC-M03)
- WP-CLI binding import readiness for SC-M03
- Hub “Support Chat adapter” status/settings tab explaining wait-for-SC-M03 when enabled but Unavailable

## SC-M03 dependency (precise)

Support Chat owns the authoritative authenticated Contract server-side authorization mechanism and must advertise `channel_available: true` with the full Adapter M1 operation set. Universal Telegram consumes that mechanism (via the Contract boundary / authorization filter) and must prove end-to-end exchange in a coordinated SC-M03 + adapter soak — not via shared secrets, Support Chat SQL, or a public REST bypass.

## Explicit non-goals (unchanged)

- No SC-M03 cutover / dual-write / legacy chat deletion
- No plugin SemVer bump (remains `0.16.0`)
- No Support Chat SQL
- No widget/Hub SoR ownership in UT
- No operational live Contract exchange until SC-M03 authenticated server

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

- Unit: discovery (version match + unavailable, missing capability, incompatible version, fully compatible); Contract client fail-closed
- Integration: binding uniqueness; unauthorized/unauthenticated/insufficient-capability acceptor rejection; no SC SQL structural scan; uninstall drops Adapter M1 tables
- Package acceptance expects `db_version` 31 and Adapter M1 tables

## Next

Product Owner merge of this PR, then Support Chat **SC-M03** authenticated Contract server + migration/cutover (bindings via CLI / tooling; dual SoR removal after soak).
