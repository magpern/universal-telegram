# Closure Record — Support Chat ADR-0008 Legacy Export Boundary Pin Documentation Freeze

## Final status

**PASS** (documentation-only freeze; no runtime code).

## What this closes

This record closes **only** the documentation freeze that pins Support Chat ADR-0008 and scopes Universal Telegram's `LegacyExportServiceV1` follow-up. It does **not** implement, verify, or close any runtime code, and it does not itself close the `LegacyExportServiceV1` follow-up slice (plan v3 work package 8).

## Why this freeze exists

Support Chat's SC-M03 legacy migration engine (work packages 3–4) depends on a mechanism this plugin must supply: a narrow, versioned, in-process, WP-CLI-only export interface for legacy conversation data, because Contract v1's closed operation allow-list (ADR-0007 §4) does not and will not cover bulk legacy-data export. Support Chat has frozen that mechanism as ADR-0008 (`magpern/universal-support-chat` PR #8, merged). This freeze pins ADR-0008 in this repository and precisely scopes the follow-up implementation obligation, per `docs/governance.md`'s freeze model ("No implementation code may precede the ADRs it relies on").

## Preconditions confirmed

- Support Chat PR #8 (`docs(adr): ADR-0008 legacy export boundary and migration authority model`) — **MERGED**, including its follow-up authority-model correction commit.
- Support Chat `main` merge SHA: `7546d43be66f8e3b2f179f03a1c81c9aadef59db`.
- ADR-0008 content confirmed present at that exact SHA: `docs/adr/0008-legacy-export-boundary-and-migration-authority-model.md`, via `git show 7546d43be66f8e3b2f179f03a1c81c9aadef59db:docs/adr/0008-legacy-export-boundary-and-migration-authority-model.md` against the Support Chat repository.
- This repository's own signed Contract client follow-up (ADR-0038) confirmed complete: work packages 1–5 (closure `docs/closure/ut-adapter-m1-signed-contract-client-closure.md`) and work package 6, the joint interoperability gate (closure `docs/closure/ut-adapter-m1-wp6-interop-gate-closure.md`), both merged (PR #34, PR #35).

## Baseline

- Repository: `magpern/universal-telegram`
- Starting commit (`origin/main` at freeze start): `c737204` (UT Adapter M1 WP6 interop-gate merge, PR #35)
- Branch: `docs/support-chat-adr-0008-legacy-export-boundary-pin`
- No plugin version, `db_version`, release, or tag was created or changed

## Documents introduced or amended

### New ADR (Accepted)

- `docs/adr/0039-support-chat-adr-0008-pin-and-legacy-export-boundary-follow-up.md` — pins Support Chat ADR-0008 exactly; scopes `LegacyExportServiceV1` (ownership split, versioning, WP-CLI-context self-check, redaction-at-source, batch limits, error behaviour, the precise security-boundary framing correcting any overstatement of what a flag or in-process check can guarantee); cross-references the frozen `QuiescenceStateProvider` interface as a forward commitment only; states an explicit, itemized scope-exclusion list; locks the two-repository implementation gate.

### Amended (additive; no ADR text rewritten)

- `docs/adr/README.md` — reserved-number line extended with ADR-0039; next available number is now 0040.
- `docs/ARCHITECTURE.md` — Support Chat extraction section gains the ADR-0008/ADR-0039 pin, confirms ADR-0038 is now implemented (not merely pinned), and updates the cross-repo sequence.
- `docs/master-plan.md` — the same section gains the same pin and sequence update.
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` — Status line corrected to reflect work package 6's completed closure (pre-existing staleness, corrected here for internal consistency with the new gate); additive §0b legacy export boundary follow-up (`docs/governance.md` "Changing a frozen milestone charter"); Dependencies and Frozen-plan sections updated to reference ADR-0039 and plan v3.
- `docs/milestones/README.md` — UT Adapter M1 registry row corrected to reflect ADR-0038's completed implementation and the new ADR-0039 pin; cross-repo sequence updated.
- `docs/plans/README.md` — UT Adapter M1 plan entry updated to point at v3; v2 marked superseded, link retained.

### New plan (supersedes, does not edit, v2 — `docs/plans/README.md` / `docs/governance.md` immutability rule)

- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md` — supersedes `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md` (retained unedited; its work packages 1–7 are complete and unaffected). Adds work package 8: `LegacyExportServiceV1`, unimplemented as of this freeze.

## Unchanged (explicitly, per instruction)

- `docs/adr/0037-support-chat-extraction-supersession-and-optional-adapter-consumer.md` and `docs/adr/0038-support-chat-adr-0007-pin-and-signed-contract-client-follow-up.md` — Decision text and all immutable sections untouched. ADR-0039 supplements them; it does not supersede or rewrite either.
- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md` and `-plan-v2.md` — retained verbatim.
- `docs/closure/ut-adapter-m1-universal-support-chat-adapter-closure.md`, `docs/closure/ut-adapter-m1-signed-contract-client-closure.md`, `docs/closure/ut-contract-v1-auth-profile-pin-closure.md`, `docs/closure/ut-adapter-m1-wp6-interop-gate-closure.md` — retained verbatim as historical closure records; none reopened or edited.
- `src/SupportChatAdapter/**` and all other runtime code — no changes.
- No changes to plugin version (`0.16.0` unchanged) or `db_version` (`32` unchanged).
- No changes to M08.1, M08.2, ADR-0032, Automations, digests, or non-chat Telegram bot functionality.
- No removal of the legacy Conversations tab, AI tab, chat widget, or chat settings — that decommission remains a separate future task, out of scope here and gated on SC-M03 acceptance.

## Support Chat ADR-0008 pin — summary

- Support Chat ADR-0008 SHA: `7546d43be66f8e3b2f179f03a1c81c9aadef59db`
- Support Chat ADR-0008 URL: `https://github.com/magpern/universal-support-chat/blob/7546d43be66f8e3b2f179f03a1c81c9aadef59db/docs/adr/0008-legacy-export-boundary-and-migration-authority-model.md`
- Support Chat ADR-0007 pin (unchanged, ADR-0038): `8ee396d8b8edcbf526797c0a1f5741f3842df57a`
- Support Chat Contract v1 (ADR-0005) pin (unchanged, ADR-0037): `dff2730e24b7d3f70f15f706305e12e14fdcc6c8`

## Explicit non-implementation confirmation

- **No** PHP, JavaScript, CSS, REST routes, database tables, migrations, queues, plugin headers, Composer project files, test code, release artifacts, tags, or deployments.
- **No** changes to `SupportChatContractClient`, `OutboundContractController`, or any other file under `src/`.
- **No** changes to plugin version (`0.16.0` unchanged) or `db_version` (`32` unchanged).
- **No** changes to M08.1, M08.2, ADR-0032, Automations, digests, or non-chat Telegram bot functionality.
- **No** removal of the legacy Conversations tab, AI tab, chat widget, or chat settings.
- No edits to ADR-0037's or ADR-0038's Decision text or the existing ADR-0005/ADR-0007 pins.
- All internal Markdown links introduced or changed in this freeze were checked and resolve.

## Validation

- Confirmed Support Chat PR #8 state is `MERGED` via `gh pr view 8 --json state,mergedAt,mergeCommit` against the Support Chat repository before making any change.
- Confirmed ADR-0008's exact text is present at commit `7546d43be66f8e3b2f179f03a1c81c9aadef59db` via `git show <sha>:docs/adr/0008-legacy-export-boundary-and-migration-authority-model.md` against the Support Chat repository.
- Confirmed diff scope is documentation-only: no changes under `src/`, `assets/`, `tests/`, `composer.json`, `composer.lock`, or any plugin bootstrap/version file.
- Confirmed plugin version (`0.16.0`) and `db_version` (`32`) unchanged, via `git diff` against the affected version/migrator files (none touched).
- Scanned all changed and pre-existing documentation for references to a local-editor working draft outside this repository, and for any unrelated-organization/hosting reference: none found.
- All relative Markdown links added or edited in this freeze (`docs/adr/0039-*.md`, `docs/adr/README.md`, `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/milestones/ut-adapter-m1-*.md`, `docs/milestones/README.md`, `docs/plans/README.md`, `docs/plans/ut-adapter-m1-*-plan-v3.md`, this file) were resolved against the working tree and point at files that exist in this branch.
- `bin/check-doc-links.php` (or this repository's equivalent doc-link CI check) run against `docs/` — exit 0, no unresolved links.

## Next task

**Implementation, in order:**

1. This repository's `LegacyExportServiceV1` and its own boundary tests (`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md` work package 8) — this repository, gated on this ADR merging.
2. Support Chat's SC-M03 work packages 3–4 (batch migrator/backfill, validators), consuming `LegacyExportServiceV1` — Support Chat repository, gated on both this ADR and Support Chat's own ADR-0008 being merged.
3. Support Chat's SC-M03 work packages 5–8 (binding creator, atomic route switch, soak/rollback, matrix automation) — later, separately scoped, unauthorised by this freeze.
4. Only after SC-M03 acceptance: this plugin's legacy Conversations tab, AI tab, chat widget, and chat settings decommission — a separate future task.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.
