# M05.2 — Escalation-Aware Conversation Routing

## Status

Superseded for UT implementation (ADR-0037). Documentation retained; **no M05.2 code was ever implemented**


## Supersession note (ADR-0037)

**Superseded for Universal Telegram implementation.** This charter remains as historical documentation from the PR #30 chat-experience freeze. Future product work for these requirements lives in Universal Support Chat (and UT Adapter M1 where channel-specific). See `docs/adr/0037-support-chat-extraction-supersession-and-optional-adapter-consumer.md`. Do not implement this milestone in Universal Telegram.

## Dependencies

M05 (closed), documentation architecture amendment (ADR-0033). Soft dependency notes: M07.2 consumes escalation for offline handoff; M10 enables `ai-first` mode.

## Objective

Introduce site routing policy modes and escalation-gated Telegram topic/outbound behaviour without regressing live human support before M10.

## Product value

Makes WordPress the durable conversation store for future AI-first chat while preserving today's Telegram human-support path until direct AI is enabled.

## Included scope

- Site routing modes: `human-first` (default while M10 AI disabled) and `ai-first` (M10 only).
- Escalation metadata (additive; do not replace `ConversationStatus` map).
- Mode-gated topic creation and outbound routing; ordered history backfill on escalation under `ai-first`.
- Retry-safe at-most-once topic CAS; no Telegram internals exposed to visitors.

## Explicit exclusions

Widget visual redesign (M06.4); support hours / `/support` / availability sweep (M07.2); approve-and-send (M09.1); direct AI replies (M10); M08.1/M08.2 Automations work; changing ADR-0032.

## Architectural constraints

ADR-0033 governs. Human-first must preserve current visitor→Telegram behaviour. Shipping `ai-first` silence as live default before M10 is forbidden.

## Deliverables

Routing policy resolver; escalation service; mode-gated topic/outbound integration; escalation metadata persistence; automated tests for both modes; documentation updates at closure.

## Acceptance criteria

- Under `human-first`, visitor messages still reach Telegram as today (no message void).
- Under `ai-first`, non-escalated conversations produce zero Telegram traffic.
- Escalation creates at most one topic and backfills history in order; retries are idempotent.
- Visitors never receive Telegram IDs, tokens, or operator internals.

## Entry criteria

- Architecture amendment ADRs accepted and freeze committed.
- Implementation plan frozen.
- Branch from freshly fetched `origin/main`.

## Exit criteria

- Acceptance criteria met; automated verification green; closure record committed; Product Owner acceptance.
