# ADR-0009 — Privacy Classification and Redaction Model

## Status

Accepted

## Context

This milestone's own charter requires a privacy classification and redaction model that must exist before any later milestone begins collecting visitor, order, or conversation data of any kind. Review of an earlier draft of this decision found that its own redaction mechanism relied entirely on each individual caller remembering to invoke it correctly, with no defined behavior at all for a nested data structure or for a field genuinely absent from its own classification map — a gap directly inconsistent with the fail-closed posture this milestone's own charter otherwise requires throughout.

## Decision

Four classification levels exist: public, internal, sensitive, and secret. A redaction operation walks a data structure, including any nested structure within it, matched against an explicit classification map using a path-based key for any nested field, stripping any field classified secret entirely and masking any field classified sensitive. Any field encountered at any depth of nesting that has no corresponding entry at all in the classification map is rejected outright — removed from the resulting output entirely — never passed through unchanged, and never silently treated as though it had been classified public; this fail-closed treatment of both nested structures and of any field missing its own classification is a deliberate, load-bearing property of this model, not an incidental default worth revisiting later. The plugin's own audit-logging component requires this classification map as a mandatory argument on every single call that records anything at all, and performs redaction internally, itself, before ever persisting anything — never leaving redaction as a responsibility any individual caller might forget to discharge correctly. This entire mechanism remains internal to the plugin at this milestone: there is no public hook of any kind through which a third party could register its own classification, since nothing at this milestone yet needs one, and it becomes a genuine public extension point only once a later milestone that actually needs one introduces its own architecture decision specifically for that purpose.

## Alternatives

Exposing the classification map as a public extension point already at this milestone, in anticipation of a later milestone's own eventual need for one — rejected, since this milestone's own charter introduces no public contract of any kind, and a public mechanism with no genuine registrant yet to validate it against is exactly the kind of speculative surface this milestone's charter excludes. Defaulting any field absent from its own classification map to the public classification, allowing it to pass through unchanged — rejected outright, since this is precisely the fail-open behavior this decision exists to prevent; rejection, not a permissive default, is the only classification-consistent choice available for a field with no classification of its own at all. Leaving redaction as an individual caller's own responsibility, rather than enforcing it centrally inside the audit-logging component itself — rejected, since an earlier review already found this insufficient in practice.

## Consequences

Every single call site that records an audit entry anywhere in this plugin must supply its own explicit, complete classification map for whatever context it is recording — there is no implicit, safe-by-default shortcut available anywhere. Whichever later milestone first needs a genuine public extension point for third-party classification registration introduces its own architecture decision for that purpose, rather than assuming this internal mechanism already supports it.

## Security and privacy impact

This decision is itself this plugin's own privacy-redaction foundation. Enforcing redaction centrally, inside the one component that actually persists anything, rather than trusting every individual caller to have already redacted its own data correctly, is itself the primary hardening this revision of the decision introduces.

## Affected Documents/Milestones

Whichever later milestone first introduces a genuine public extension point for third-party classification registration, most likely the milestone that first introduces this plugin's own broader event-registration and rule-engine capability.

## Compatibility/Migration Impact

None. No code of any kind exists in this repository yet.
