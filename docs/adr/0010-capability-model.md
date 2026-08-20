# ADR-0010 — Capability Model

## Status

Accepted

## Context

The master plan itself names restricting settings by WordPress capability as an explicit security control, and this milestone's own charter requires a capability and authorization model as one of its own deliverables. Review of an earlier draft of this decision found that it had asserted command-line or shell-level access as sufficient proof that capability-based authorization was actually being exercised, when shell access is, in fact, an entirely different authorization boundary from a WordPress capability check, and does not actually demonstrate that the capability model works at all.

## Decision

One WordPress capability is introduced at this milestone, granted to the administrator role on plugin activation and revoked from every role unconditionally on uninstall, independent of whatever data-retention choice an operator has separately made. This capability gates a genuine, rendered administration screen — this milestone's own diagnostics page — through WordPress core's own native capability parameter on the function that registers that screen's own menu entry, so that a user lacking this capability is denied by WordPress itself, not merely by an assertion elsewhere that they would be. That same page's one interactive control independently re-verifies both this capability and a nonce, inside its own request handler, rather than relying solely on the check already performed at the point the menu entry itself was registered — closing the gap between a menu item merely being hidden from an unauthorized user and the underlying action it triggers actually being protected against a direct, forged request from that same user. Later milestones that introduce their own distinct authorization needs — operator identity, and bot-command authorization — are expected to extend this same model by registering their own additional capability constants through the same grant-and-revoke pattern this milestone establishes, rather than inventing a separate mechanism of their own.

## Alternatives

Gating every administrative surface this plugin ever introduces on WordPress's own generic, built-in options-management capability, rather than introducing a capability specific to this plugin — rejected, since the master plan itself names capability-based restriction as an explicit control, and this product's own later milestones need a genuine grant-and-revoke lifecycle already proven at this milestone, not retrofitted afterward. Treating command-line or shell-level access as sufficient demonstration that capability enforcement genuinely works — rejected outright, per the correction described in this decision's own context above; only a check against a real, rendered, capability-gated surface demonstrates this. Introducing multiple capabilities already at this milestone, in anticipation of later milestones' own eventual needs — rejected as premature, since this milestone itself has exactly one thing that genuinely needs gating.

## Consequences

Later milestones extend this same registrar with their own additional capability constants, rather than building a separate mechanism. The single capability this milestone introduces is exercised, automatically, in both directions — an authorized user succeeding, an unauthorized one being denied, and a request missing a valid nonce also being denied — from this milestone onward, against a real, rendered administration surface, not merely in an isolated unit test.

## Security and privacy impact

This decision is itself this plugin's own authorization boundary for its administrative surface, and is directly what a reviewer's own attempt to perform a privileged action as a non-privileged user verifies, against this milestone's own real diagnostics page.

## Affected Documents/Milestones

The later milestone introducing Telegram-operator identity, and the one after it introducing bot-command authorization, each of which is expected to extend this same registrar with its own capability constants.

## Compatibility/Migration Impact

None. No code of any kind exists in this repository yet.
