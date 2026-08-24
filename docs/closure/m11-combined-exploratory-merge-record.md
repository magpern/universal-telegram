# Combined M11 + M07.1 Exploratory Merge Record

## Status

**MERGED FOR EXPLORATORY TESTING — NOT VALIDATED OR RELEASED**

This record documents an authorized merge of combined implementation work to `main` so the Product Owner can conduct exploratory testing. It does **not** claim that M11A, M11B, or M07.1 are PASS, closed, accepted, or production-ready.

## SHAs and PR

| Item | Value |
|------|-------|
| Baseline (`origin/main` before merge) | `7011163fc645a0f8a9083f7eaa82530192215b16` |
| Feature branch head | `7bcc070c7d9dccc89853f19acb7f696eb2172a93` (`feature/m11a-visitor-activity-digests`) |
| PR | https://github.com/magpern/universal-telegram/pull/26 |
| Merge SHA | `708804b301dab5dda0b6146c9278b4343fdc0f32` |
| Record SHA | `4f24a099c99a37a9944cc56cfb2f622f88de4cac` |

## Included milestones

- **M11A** — Visitor Activity Digests
- **M11B** — Operational Intelligence
- **M07.1** — Conversation Topic Lifecycle (including committed Operator Inbox bulk archive-and-delete follow-up already on the feature branch)

## Versions now on `main`

- Plugin version: **`0.14.0`**
- Database version (`universal_telegram_db_version` target): **`29`**

## Confirmations

- No local validation (tests, PHPCS, PHPStan, builds, package acceptance, migrations, or browser tests) was run for this merge task.
- CI / GitHub Actions was not waited on before or after merging, and is not represented as passing.
- No tag, GitHub Release, production deployment, live Telegram call, live provider call, or configuration change (credentials, bots, destinations, webhooks, Telegram/provider/WordPress settings) occurred as part of this task.

## Remaining gates (explicit)

1. Combined local validation  
2. CI review  
3. Exploratory Product Owner testing  
4. Defect repair and affected-check reruns  
5. Full validation gate  
6. Formal technical closure and Product Owner acceptance  
7. Release decision  

Exploratory testing on `main` is authorized. Formal validation, closure, release, and deployment remain pending.
