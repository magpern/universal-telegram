# M00 — Product Foundation

## Status

Planned

Depends on: none (first milestone)

## Objective

Establish the plugin architecture, development standards, persistence foundations, security boundaries, privacy model, and testing infrastructure required by all later milestones.

## Product value

Nothing user-facing ships in M00. Its value is risk reduction: every later milestone depends on foundations that are far more expensive to retrofit than to build first.

## Dependencies

None. First milestone; establishes the repository itself.

## Included scope

- WordPress plugin bootstrap and lifecycle: activate, deactivate, upgrade, uninstall.
- Namespace, autoloading, directory structure, using the identifiers fixed in ADR-0002.
- Module boundaries for all thirteen product modules; later modules represented only as inert boundaries, not functional code.
- Dependency composition and service registration.
- Database schema migration framework, with only the schema M00 itself genuinely needs.
- Durable queue abstraction and failure boundary, distinguishing dispatch-path isolation from worker-failure reporting.
- Capability and authorization model.
- Audit logging model.
- Privacy classification and redaction model.
- Secret-handling policy, fail-closed in production.
- Configuration storage conventions.
- Error and diagnostics foundations.
- Coding standards, static analysis, unit and integration test foundations in both WordPress-only and WooCommerce-present configurations, CI foundation.
- ADRs, developer documentation, versioning and release conventions.
- A WooCommerce-presence detection surface implementing the optional-integration posture already decided in ADR-0003 — M00 implements this decision, it does not introduce it.

## Explicit exclusions

- Any Telegram Bot API connectivity (M01).
- Any concrete event capture or rule engine (M02).
- Any WooCommerce event hooks beyond bare compatibility posture (M03).
- Any conversation, chat widget, or AI functionality (M05, M06, M09, M10).
- Placeholder administration screens or speculative extension surfaces not necessary to prove an accepted M00 requirement.

## Architectural constraints

- Telegram and AI requests must never run synchronously in critical frontend, cart, checkout, or order paths.
- External failures must never break WordPress or WooCommerce operation.
- Provider credentials must never be exposed to frontend code, logs, diagnostics exports, or repository history.
- Privacy classification and redaction must exist before any later milestone begins collecting visitor, order, or conversation data.
- HPOS, Classic Checkout, Cart and Checkout blocks, full-page caching, and async processing must not be obstructed.
- Public extension points must be deliberately versioned; no speculative APIs that cannot yet be validated.
- The queue failure policy must distinguish dispatch-path isolation from worker-failure reporting: a background job that throws must be recorded as failed and remain eligible for the defined retry policy, never silently swallowed and reported as successful.
- Production credential handling must fail closed when secure key material is unavailable; a deterministic fallback key may be injected only by tests.
- Credential key rotation, and the effect of WordPress salt rotation on previously encrypted secrets, must be explicitly designed, not left implicit.
- Capability cleanup and retained operational data are separate uninstall decisions; orphaned capabilities must not remain without explicit justification.

## Deliverables

Plugin bootstrap and module boundaries; coding standards and automated tests; database migration framework; capability model; queue abstraction; audit logging; privacy classification model; architecture decisions; developer documentation.

## Acceptance criteria

Clean install, upgrade, and uninstall in both WordPress-only and WooCommerce-present configurations; queue failure does not affect frontend requests, and worker failures are recorded and retried rather than silently lost; secrets excluded from logs; unit and integration test foundations pass. Exact mechanized tests are defined in M00's own frozen implementation plan.

## Vlad's independent test focus

- Install, activate, deactivate, uninstall on a clean WordPress instance, with and without WooCommerce present.
- Trigger a queued job failure and confirm the site remains unaffected and the failure is recorded, not silently marked successful.
- Attempt to locate any secret-shaped value in logs, diagnostics export, or database dumps after configuring a fake credential.
- Attempt privileged actions as a non-privileged user and confirm they are blocked.
- Rotate WordPress salts and confirm the credential-vault behaviour matches its documented design.

## Required evidence

Automated unit and integration CI results in both test configurations; a completed vlad-acceptance-template instance; the frozen M00 plan's commit SHA; every ADR M00 itself introduces (composition root, queue strategy, migration framework, secret storage, privacy classification, capability model) accepted before implementation begins.

## Entry criteria

docs/governance.md and ADR-0001 through ADR-0004 accepted by the Product Owner; M00's own implementation plan drafted, reviewed, and frozen.

## Exit criteria

All acceptance criteria met or explicitly accepted as limitations; Vlad acceptance obtained; closure document committed with a final status.
