# M07.1 Closure — Conversation Topic Lifecycle and Repair

## Status

**Implementation and plan freeze are complete.**

Validation, PR creation, GitHub Actions / CI wait, merge to `main`, release/tag/deployment, and Product Owner acceptance are **deferred to the combined M11 validation gate** on `feature/m11a-visitor-activity-digests`. This slice does not open a PR and does not claim M11 closure.

## Branch and SHAs

| Item | Value |
|------|-------|
| Feature branch | `feature/m11a-visitor-activity-digests` |
| Starting SHA (pre-M07.1) | `dee2135e1eeef4576d2d67da92a6d6e92ce49ad6` |
| Plan freeze SHA | `305c8d1d2a098e7d5fef9a43cefb16fe3f642c2b` |
| Final SHA (this closure commit) | *(recorded at commit time; see `git rev-parse HEAD`)* |
| Plugin version | `0.14.0` |
| `db_version` target | `29` |
| ADR | ADR-0031 |

## Work-package commits

1. `305c8d1` — `docs(conversations): freeze M07.1 topic lifecycle plan`
2. `e7e5525` — `feat(conversations): add topic lifecycle columns and archive transitions` (WP1)
3. `3a8d128` — `feat(telegram): add deleteForumTopic and topic-specific terminal codes` (WP2)
4. `da433fb` — `feat(conversations): queue confirmation-gated Telegram topic deletion` (WP3)
5. `20850f6` — `feat(conversations): retention purge waits for remote topic deletion` (WP4)
6. `6340b3a` — `feat(conversations): add archive and permanent-delete operator UI` (WP5)
7. `9a11a22` — `test(conversations): cover detail archive and confirm-delete forms` (WP5 follow-up)
8. `2c965a2` — `feat(conversations): mark missing topics unavailable without leaking Telegram errors` (WP6)
9. `d046f41` — `test(conversations): cover topic lifecycle upgrade and uninstall` (WP7)
10. `cf84402` — `docs(conversations): freeze M07.1 topic lifecycle (ADR-0031)` (WP8)
11. *(this commit)* — closure record

## Critical safety invariants

1. **Exact tuple lookup:** inbound topic identity is always `(bot_id, chat_id, message_thread_id)`. A thread id alone never locates a conversation.
2. **No `chat not found` purge:** on `deleteForumTopic`, `chat not found` is `delete_failed` + `telegram_topic_delete_chat_not_found`; local rows are retained. Only explicit missing-topic/missing-thread responses are idempotent remote success that may purge.
3. **Exclusive destination ownership:** remote delete and destination-row deletion require exactly one conversation referencing `destination_id`. Shared ownership → no remote call and no dest-row delete.

## What was not done in this task

- No local test, linter, build, package-acceptance, or CI run.
- No PR opened; no CI wait; no merge to `main`.
- No tag, release ZIP, or deployment.
- No live Telegram, provider, bot, webhook, or destination API call.
- Combined M11 remains unvalidated and unmerged.
- M12 has not started.

## Frozen plan

`docs/plans/m07-1-conversation-topic-lifecycle-and-repair-plan-v1.md`
