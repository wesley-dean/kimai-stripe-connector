# AI / Agent Project Guidance

This repository is intentionally designed so a new human or AI/LLM engineering session can work from repository context rather than prior conversation history.

## Primary Execution Guide

Start with:

```text
doc/implementation-playbook.md
```

A suitable minimal instruction is:

> Inspect this repository, read `doc/implementation-playbook.md`, and follow it.  Work as a trusted engineering peer, make ordinary reversible decisions autonomously, keep tests and documentation synchronized, and do not merge or release without explicit authorization.

## Collaboration Posture

Work as a trusted engineering colleague and peer, not as a deferential assistant waiting for step-by-step instructions.

Expected behavior:

- exercise independent technical judgment;
- challenge assumptions, including the project owner's, when evidence supports it;
- surface contradictions, weak evidence, risks, and better alternatives directly;
- make ordinary reversible engineering decisions without repeatedly asking permission;
- distinguish verified facts from inference and uncertainty;
- prefer boring, auditable, recoverable designs;
- keep implementation, tests, documentation, and operational behavior consistent; and
- respect the explicit stop conditions in the implementation playbook.

Peer posture does not authorize destructive production actions, live charges, unapproved merges/releases, or silent architectural reversals.

## Read These First

Before substantive work:

1. `doc/implementation-playbook.md`
2. `doc/decisions.md`
3. `doc/project-handoff.md`
4. `doc/INSTALL.md`
5. `doc/operator-runbook.md`
6. `doc/configuration.md`
7. `doc/threat-model.md`
8. `doc/documentation.md`
9. `doc/testing/test-matrix.md`
10. relevant ADRs discovered through `doc/decisions.md`
11. `doc/testing/preimplementation-mock-exercise.md`
12. `doc/testing/e2e-stripe-sandbox.md`
13. `doc/reference-hosting-environment.md`
14. `README.md`
15. relevant CI/tooling/contribution files

`doc/decisions.md` is an index, not a substitute for ADRs.  If an index summary conflicts with an ADR, the ADR controls.

`doc/INSTALL.md` is the installation/upgrade/removal guide for the self-contained plugin artifact.  `doc/operator-runbook.md` is the operational guide for routine commands, incident response, credential rotation, disablement/removal, and manual fallback.  Neither overrides an Accepted ADR or the explicit configuration/security contracts.

All current numbered ADRs (000–013) are Accepted as of 2026-09-04.

The original large proposed ADR-001 is preserved under `doc/adr/rationale/`; its independent decisions now live in ADRs 005–011.

## Primary Goal

Build a small self-hosted Kimai plugin that connects Kimai invoices to Stripe Payment Links while keeping Kimai as invoice authority and Stripe as payment authority.

Intended workflow:

```text
Kimai time/invoice
-> connector creates or recovers exact Stripe Payment Link
-> Kimai invoice exposes Stripe-hosted URL
-> customer pays Stripe
-> externally scheduled reconciliation proves exact payment
-> Kimai invoice becomes paid
```

The connector is optional.  Manual Payment Link creation and manual Kimai reconciliation remain permanent fallbacks.

## Hard Architecture

Unless a later Accepted ADR changes them:

- one plugin package contains two independently disableable capabilities;
- use supported Kimai plugin mechanisms;
- do not patch/fork/modify Kimai core or its root Composer state;
- do not require public Internet ingress to Kimai;
- do not require Stripe webhooks;
- use Stripe Payment Links as the public customer payment surface;
- Kimai remains authoritative for invoice/time/business state;
- Stripe remains authoritative for payment movement and Stripe objects;
- connector metadata/correlation is versioned and exact;
- no connector-owned payment ledger/database without new evidence and an ADR;
- ambiguity causes no automatic financial mutation;
- false negatives are preferable to false-positive paid-state transitions;
- refunds/disputes do not automatically rewrite historical Kimai paid state;
- paid orphans are accounting exceptions, not cleanup garbage;
- cleanup retains uncertainty;
- design for retries/partial failures/container replacement; and
- keep Payment Link and reconciliation responsibilities internally separate.

## Compatibility Baseline

ADR-004 establishes:

```text
Kimai:            2.65.0
PHP:              >=8.2 <8.6
Stripe PHP SDK:   ^21.3.1
Payment methods:  card only initially
Money:            integer minor units
Currency scope:   ordinary two-decimal currencies initially
Metadata:         version 1
Credential:       one least-privilege restricted key initially
```

Do not broaden compatibility claims without executable evidence.

## Package / Plugin Model

ADR-012 establishes a self-contained plugin:

```text
var/plugins/KimaiStripeConnectorBundle/
```

Namespace:

```text
KimaiPlugin\KimaiStripeConnectorBundle\
```

Production artifacts carry plugin-local Composer dependencies under `vendor/` and load that autoloader without modifying Kimai's root Composer files.

The deterministic artifact build is:

```text
make package VERSION=<version>
```

which creates the installable ZIP and matching SHA-256 file under `dist/`.
`make package-check` verifies package contents and byte-for-byte reproducibility.
Disposable Kimai E2E installs the generated ZIP itself.  GitHub-generated source
archives are not ADR-012 installable artifacts.

Source control does not commit generated `vendor/` or `dist/` output.

## Configuration

Follow `doc/configuration.md`.

Environment namespace:

```text
KIMAI_STRIPE_CONNECTOR_*
```

Environment variables are primary.  File-backed secret injection is preferred for sensitive container values.  `.env` is tertiary local/dev/test convenience.

Never log or commit Stripe API keys.

## Testing Rule

ADR-003 requires automated tests for executable behavior additions/changes/fixes/patches when reasonably testable.

Behavioral examples requiring tests:

- Stripe API request changes;
- metadata changes;
- configuration semantics;
- money conversion;
- Kimai event integration;
- reconciliation/cleanup rules;
- retry/error handling;
- package/release behavior;
- compatibility changes; and
- bug fixes.

Documentation-only, comment-only, typo, or formatting changes do not require artificial unit tests.

Bug fixes SHOULD include regression tests.

Use `doc/testing/test-matrix.md` to select the cheapest deterministic test layer.

Historical “103 passing” scenarios are design evidence, not repository test results.  Do not claim they are executable tests until implemented.

## Disposable E2E

Use `tests/e2e/` for real Kimai integration/E2E.

The Kimai environment is ephemeral by design:

```text
construct fresh Compose project and volumes
-> start Kimai 2.65.0 + MySQL
-> build and extract self-contained plugin ZIP
-> install packaged plugin
-> reload/verify
-> exercise disabled reconciliation/cleanup commands
-> remove plugin
-> reload/verify
-> docker compose down --volumes --remove-orphans
```

Do not repurpose the long-lived dogfooding Kimai environment as the test fixture.

The manual real Stripe sandbox workflow remains a separate credentialed contract probe; ordinary pull-request CI remains credential-free.

## Financial-State Rules

Automatic initial reconciliation direction:

```text
Kimai not paid
+ exact versioned identity
+ exact amount
+ exact currency
+ strong mutually consistent Stripe evidence
-> Kimai paid
```

A completed Checkout Session alone is insufficient universal proof.

Ambiguous evidence => no mutation.

Post-payment refund/dispute => manual/accounting review, not automatic paid-state reversal.

## Failure Philosophy

Stripe and Kimai do not share one transaction.

Expected conditions include Stripe commit followed by lost response and Stripe creation followed by Kimai persistence failure.

Use:

- Stripe idempotency where appropriate;
- durable versioned metadata for rediscovery;
- exact compatible-link reuse;
- conflict detection;
- fail-closed ambiguity; and
- conservative cleanup.

Do not solve expected partial failures by adding a speculative ledger or queue.

## Operator Result Vocabulary

ADR-013 defines:

```text
SUCCESS
NO_CHANGE
RETRYABLE
CONFIGURATION_ERROR
CONFLICT
MANUAL_REVIEW_REQUIRED
INTERNAL_ERROR
```

Use these meanings consistently across CLI output, logs, tests, and runbooks.  Operational handling for each category is documented in `doc/operator-runbook.md`.

## Documentation

ADR-002 and `doc/documentation.md` govern source comments.

PHP uses Doxygen-compatible PHPDoc.  Document intent, invariants, assumptions, security boundaries, side effects, failures, and ownership where relevant rather than restating syntax.

Generated Doxygen output lives under ignored `doc/reference/`.

Doxygen warnings are failures.

When changing architecture/config/security/testing/package/current-state behavior, update the corresponding repository documentation in the same coherent change.

## Repository Quality Gates

MegaLinter is blocking.

Required policy:

```text
DISABLE_ERRORS: false
APPLY_FIXES: false
```

A green workflow is not enough if its report contains warnings.  Required completion state is zero MegaLinter errors and zero MegaLinter warnings.

The repository also includes PHP matrix CI, deterministic Package CI, disposable Kimai E2E, documentation generation, pre-commit checks, Dependabot, and semver/release automation.

Do not weaken checks merely to obtain green status.

## Scope Warning

Stop and reconsider before introducing:

- subscriptions/recurring billing;
- tax computation/filing;
- dunning/late fees;
- generalized bookkeeping;
- customer accounting ledgers;
- automatic refund initiation/accounting;
- automated dispute handling;
- a Stripe dashboard inside Kimai;
- payment credential collection inside Kimai;
- mandatory public ingress/webhooks; or
- arbitrary payment-processor abstraction without a demonstrated requirement.

Material expansion normally requires a new ADR.

## License Boundary

The repository is currently private.  Final public OSS licensing is not an immediate implementation blocker.

Do not make the repository public, publish a public release, or invite public contributions while silently assuming the inherited template license is the final deliberate software-license decision.

## Handoff Rule

Before ending substantial work, update `doc/project-handoff.md` whenever the next session would otherwise need chat history to understand current branch/PR state, implementation progress, failed experiments, version-sensitive findings, verification, uncertainty, or next work.

Update `doc/decisions.md` with every ADR status/material change.
