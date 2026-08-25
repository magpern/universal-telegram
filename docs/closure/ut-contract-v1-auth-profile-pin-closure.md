# Closure Record — UT Contract v1 Authentication Profile Pin Documentation Freeze

## Final status

**PASS** (documentation-only freeze; no runtime code).

## What this closes

This record closes **only** the documentation freeze that pins Support Chat ADR-0007 and scopes Universal Telegram's signed Contract client follow-up. It does **not** implement, verify, or close any runtime code, and it does not itself close the UT Adapter M1 signed-client follow-up slice.

## Why this freeze exists

UT Adapter M1 (this repository, PR #32, merged `01f18075d77e1ff1174b0656b70f3531e670ae9b`) implemented adapter persistence, discovery, and fail-closed wiring, but deliberately stubbed every adapter → Support Chat Contract call (`SupportChatContractClient`) to fail closed (`sc_authenticated_contract_unavailable`), because Contract v1 (Support Chat ADR-0005) required authenticated calls without specifying a mechanism. Support Chat has since frozen and merged that mechanism as ADR-0007 (`magpern/universal-support-chat` PR #5). This freeze pins ADR-0007 in this repository and precisely scopes the follow-up implementation, per `docs/governance.md`'s freeze model ("No implementation code may precede the ADRs it relies on").

## Preconditions confirmed

- Support Chat PR #5 (`docs(adr): SC Contract v1 mutual signed adapter authentication profile`) — **MERGED**.
- Support Chat `main` merge SHA: `8ee396d8b8edcbf526797c0a1f5741f3842df57a`.
- ADR-0007 content confirmed present at that exact SHA: `docs/adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md` (198 lines, `git show 8ee396d8b8edcbf526797c0a1f5741f3842df57a:docs/adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md`).

## Baseline

- Repository: `magpern/universal-telegram`
- Starting commit (`origin/main` at freeze start): `01f18075d77e1ff1174b0656b70f3531e670ae9b` (UT Adapter M1 merge, PR #32)
- Branch: `docs/ut-contract-v1-auth-profile-pin`
- No plugin version, `db_version`, release, or tag was created or changed

## Documents introduced or amended

### New ADR (Accepted)

- `docs/adr/0038-support-chat-adr-0007-pin-and-signed-contract-client-follow-up.md` — pins Support Chat ADR-0007 exactly; confirms UT Adapter M1's current fail-closed stubs are intentional legacy, not a defect; scopes the signed-client follow-up (key generation/custody, pairing, outbound signing, inbound verification, rotation/revocation/expiry/replay/uniform-denial); locks the implementation sequence.

### Amended (additive; no ADR text rewritten)

- `docs/adr/README.md` — reserved-number line extended with ADR-0038; next available number is now 0039.
- `docs/ARCHITECTURE.md` — "Support Chat extraction (ADR-0037)" section gains the ADR-0007/ADR-0038 pin and updated cross-repo sequence.
- `docs/master-plan.md` — "Support Chat extraction and optional adapter (ADR-0037)" section gains the same pin and sequence update.
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` — additive §0 Signed Contract client follow-up (`docs/governance.md` "Changing a frozen milestone charter"); Status and Dependencies updated; Frozen-plan link retargeted to v2.
- `docs/milestones/README.md` — UT Adapter M1 registry row corrected to reflect its actual merged status (PR #32) and the new ADR-0038 pin; cross-repo sequence updated.
- `docs/plans/README.md` — UT Adapter M1 plan entry updated to point at v2; v1 marked superseded, link retained.

### New plan (supersedes, does not edit, v1 — `docs/plans/README.md` / `docs/governance.md` immutability rule)

- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md` — supersedes `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md` (retained unedited). Scopes the signed-client follow-up as work packages 1–7 (key generation, pairing, outbound signing, inbound verification, nonce-replay store, joint interoperability tests as an external hard gate, docs/closure), all unimplemented as of this freeze.

### Unchanged (explicitly, per instruction)

- `docs/adr/0037-support-chat-extraction-supersession-and-optional-adapter-consumer.md` — Decision text and all immutable sections untouched. ADR-0038 supplements it; it does not supersede or rewrite it.
- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md` — retained verbatim.
- `docs/closure/ut-adapter-m1-universal-support-chat-adapter-closure.md` — retained verbatim as the historical closure record of the already-implemented UT Adapter M1 work; not reopened or edited.
- `src/SupportChatAdapter/**` and all other runtime code — no changes. `SupportChatContractClient`'s stubs remain exactly as merged.
- M08.1, M08.2, ADR-0032, Automations, digests, and non-chat Telegram bot functionality — untouched.

## Support Chat ADR-0007 pin — summary

- Support Chat ADR-0007 SHA: `8ee396d8b8edcbf526797c0a1f5741f3842df57a`
- Support Chat ADR-0007 URL: `https://github.com/magpern/universal-support-chat/blob/8ee396d8b8edcbf526797c0a1f5741f3842df57a/docs/adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md`
- Auth profile identifier: `support-channel-contract-auth/v1`
- Support Chat Contract v1 (ADR-0005) pin: unchanged, `dff2730e24b7d3f70f15f706305e12e14fdcc6c8`

## Explicit non-implementation confirmation

- **No** PHP, JavaScript, CSS, REST routes, database tables, migrations, queues, plugin headers, Composer project files, test code, release artifacts, tags, or deployments.
- **No** changes to `SupportChatContractClient`, `OutboundContractController`, or any other file under `src/`.
- **No** changes to plugin version (`0.16.0` unchanged) or `db_version` (`31` unchanged).
- **No** changes to M08.1, M08.2, ADR-0032, Automations, digests, or non-chat Telegram bot functionality.
- **No** removal of the legacy Conversations tab, AI tab, chat widget, or chat settings — that decommission remains a separate future task, explicitly out of scope here and gated on SC-M03 acceptance.
- No edits to ADR-0037's Decision text or the existing ADR-0005 pin.
- All internal Markdown links introduced or changed in this freeze were checked and resolve.

## Validation

- Confirmed Support Chat PR #5 state is `MERGED` via `gh pr view 5 --json state,mergedAt,mergeCommit` before making any change.
- Confirmed ADR-0007's exact text is present at commit `8ee396d8b8edcbf526797c0a1f5741f3842df57a` via `git show <sha>:docs/adr/0007-...md` against the Support Chat repository.
- Scanned all changed and pre-existing documentation for references to a local-editor working draft outside this repository, and for any unrelated-organization/hosting reference: none found.
- Confirmed diff scope is documentation-only: no changes under `src/`, `assets/`, `tests/`, `composer.json`, `composer.lock`, or any plugin bootstrap/version file.
- All relative Markdown links added or edited in this freeze (`docs/adr/0038-*.md`, `docs/adr/README.md`, `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/milestones/ut-adapter-m1-*.md`, `docs/milestones/README.md`, `docs/plans/README.md`, `docs/plans/ut-adapter-m1-*-plan-v2.md`, this file) were resolved against the working tree and point at files that exist in this branch.

## Next task

**Implementation, in order:**

1. Support Chat SC-M03 work package 0 — authenticated Contract server, pairing authority, peer-key/nonce-replay stores, signature verification (Support Chat repository).
2. This repository's signed Contract client follow-up (`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md` work packages 1–5): replace `SupportChatContractClient`'s stubs, add pairing, add inbound signature verification.
3. Joint end-to-end authenticated interoperability tests across both plugins (work package 6) — a hard gate before Support Chat's SC-M03 migration/cutover work packages proceed.
4. Only then, SC-M03 migration and controlled cutover.
5. Only after SC-M03 acceptance: this plugin's legacy Conversations tab, AI tab, chat widget, and chat settings decommission — a separate future task.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.
