# Project Handoff

Date: 2026-09-04

## Purpose

This document gives a new human or AI/LLM engineering session enough current context to resume `kimai-stripe-connector` without the conversation that produced the project.

For autonomous work, begin with [`implementation-playbook.md`](implementation-playbook.md), then use [`decisions.md`](decisions.md) to locate the relevant Accepted ADRs.

## Current State

The project has moved from pure architecture into implementation preparation.

As of this handoff:

- ADRs 000 through 013 are Accepted;
- the original large ADR-001 rationale is preserved under `doc/adr/rationale/`, while its independent decisions are split into smaller ADRs;
- an inert Kimai plugin bundle skeleton exists;
- PHP/Composer package metadata exists;
- PHPUnit and PHPStan configuration exists;
- MegaLinter uses the PHP flavor and remains a zero-error/zero-warning gate with auto-fixes disabled;
- PHP CI targets PHP 8.2, 8.3, 8.4, and 8.5;
- a disposable Kimai 2.65.0 + MySQL E2E lifecycle harness exists under `tests/e2e/`;
- the E2E harness constructs fresh containers/volumes, installs the plugin, reloads Kimai, verifies it, removes it, reloads again, and destroys all local test state;
- Doxygen is configured to generate PHP reference documentation under ignored `doc/reference/`;
- no production Stripe mutation behavior has been implemented yet;
- no real Stripe credential is committed or expected in the repository; and
- no production/live Stripe payment has been performed by this project.

The current implementation-preparation branch is:

```text
chore/implementation-prep
```

Do not assume that branch remains open forever; inspect current repository/PR state when beginning a new session.

## Product Goal

Build a small self-hosted Kimai plugin that connects Kimai invoices to Stripe Payment Links while preserving clear authority boundaries.

Desired flow:

```text
track time in Kimai
-> generate Kimai invoice
-> connector creates or recovers exact Stripe Payment Link
-> invoice exposes Stripe-hosted payment URL
-> customer pays Stripe
-> asynchronous reconciliation verifies exact payment evidence
-> Kimai invoice becomes paid
```

The connector is an optimization over a permanently valid manual workflow, not a prerequisite for billing.

## Core Authority Boundaries

Kimai is authoritative for:

- invoice identity;
- amount and currency as invoiced;
- time/business records;
- invoice documents; and
- Kimai invoice state.

Stripe is authoritative for:

- Payment Links;
- Checkout Sessions;
- PaymentIntents;
- Charges;
- refunds/disputes; and
- actual payment movement.

The connector owns:

- its versioned correlation contract;
- exact mapping between Kimai invoices and connector-managed Stripe objects; and
- conservative rules deciding whether Stripe evidence is sufficient for a Kimai mutation.

The connector does not own a third payment ledger.

## Accepted Architecture

Use one Kimai plugin with two independently disableable capabilities:

```text
KimaiStripeConnectorBundle
|
+-- Common
|
+-- PaymentLink
|
+-- Reconciliation
```

See ADR-001 for package-level architecture.

The original large ADR-001 was intentionally broken into focused decisions:

- ADR-005: private Kimai / outbound-only Stripe integration;
- ADR-006: supported Kimai plugin extension seams;
- ADR-007: Stripe Payment Links as the customer payment surface;
- ADR-008: versioned Stripe metadata correlation;
- ADR-009: conservative payment reconciliation;
- ADR-010: conservative orphan cleanup; and
- ADR-011: idempotent partial-failure recovery.

The full original research/rationale remains at:

```text
doc/adr/rationale/ADR-001-proposed-rationale.md
```

Use it for historical detail, not as a replacement for the focused current ADRs.

## Compatibility Contract

ADR-004 currently establishes the initial implementation target:

```text
Kimai:             2.65.0 baseline
PHP:               >=8.2 <8.6
Stripe PHP SDK:    ^21.3.1
Payment methods:   card only initially
Money:             integer Stripe minor units
Currencies:        ordinary two-decimal currencies initially
Metadata version:  1
Credential model:  one least-privilege Stripe restricted key initially
```

The connector initially follows the Stripe API version associated with the exact installed Stripe PHP SDK rather than independently overriding `Stripe-Version`.

Dependency updates are compatibility-relevant behavioral changes and must receive the applicable tests under ADR-003.

## Metadata Contract

Version 1 requires:

```text
integration=kimai-stripe-connector
integration_version=1
kimai_instance=<stable instance UUID>
invoice_number=<Kimai invoice number>
invoice_amount=<integer minor currency units>
invoice_currency=<lowercase currency code>
```

The contract is stored on the Payment Link and supplied as PaymentIntent metadata so correlation can be checked across the Stripe object chain without a connector-owned database.

Unknown metadata versions fail closed.

The instance UUID must remain stable across container recreation, restarts, plugin upgrades, and scheduler changes.

## Network / Exposure Contract

Kimai remains private.

The connector does not require:

- a public Kimai route;
- a Stripe webhook endpoint;
- public NAT/firewall exposure; or
- a customer payment portal hosted by Kimai.

Stripe-hosted Payment Links/Checkout are the public payment surface.

Reconciliation is an externally schedulable repair operation.  Missing a run may temporarily leave Kimai stale, but later reconciliation should repair it.

## Payment Reconciliation Policy

False negatives are preferable to false positives.

The initial automatic state direction is:

```text
Kimai not paid
+ exact identity
+ exact amount
+ exact currency
+ strong mutually consistent Stripe payment evidence
-> Kimai paid
```

Ambiguity means no automatic mutation.

A completed Checkout Session alone is not universal proof of final successful payment.

Later refunds or disputes do not automatically rewrite a historically paid Kimai invoice to unpaid/canceled.  They are surfaced for human/accounting review.

## Partial Failure / Recovery

Stripe and Kimai cannot participate in one atomic transaction.

Expected failures include:

```text
Stripe creates link
-> response is lost
-> connector sees timeout
```

and:

```text
Stripe creates link
-> Kimai rendering/persistence fails
-> Stripe orphan remains
```

The design therefore relies on:

- Stripe idempotency for immediate retries where appropriate;
- versioned metadata for durable rediscovery;
- exact compatible-object reuse;
- financial conflict detection;
- fail-closed behavior on multiple/ambiguous candidates; and
- conservative orphan cleanup.

Do not introduce a connector-owned transaction ledger unless evidence demonstrates that metadata + Kimai state are insufficient and a later ADR approves the change.

## Cleanup Policy

Cleanup is not part of payment correctness.

A link is eligible for automatic deactivation only when the connector can prove it is:

- connector-managed;
- in the current instance namespace;
- unmatched to a Kimai invoice after a successful Kimai query;
- outside the configured grace period;
- unpaid; and
- unambiguous.

Paid orphans are accounting exceptions, not garbage.

Old valid invoice links remain valid merely because they are old.

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

CLI commands, structured logs, tests, and runbooks should share these meanings.

## Testing Contract

ADR-003 requires appropriate automated tests for executable behavior changes.

Examples requiring test changes include:

- Stripe API calls or request parameters;
- metadata changes;
- money conversion;
- Kimai event integration;
- configuration semantics;
- reconciliation/cleanup rules;
- retry/exception behavior; and
- bug fixes.

Documentation-only, comment-only, typo, or formatting changes do not require artificial unit tests.

Use [`testing/test-matrix.md`](testing/test-matrix.md) to assign documented `CFG-*`, `PL-*`, `MNY-*`, `REC-*`, `CLN-*`, `NET-*`, `CON-*`, `KIM-*`, `STR-*`, and `THR-*` scenarios to the correct execution layer.

## Disposable Kimai E2E

The executable harness is:

```text
tests/e2e/compose.yaml
tests/e2e/run.sh
```

It is based closely on Kimai's official Docker Compose model and pins:

```text
kimai/kimai2:2.65.0
```

Every run gets a unique Compose project and fresh named volumes.  The shell harness installs the checked-out plugin into the disposable plugin volume, reloads Kimai, verifies the bundle, removes it, reloads again, and always executes cleanup via an EXIT/INT/TERM trap.

Cleanup includes:

```text
docker compose down --volumes --remove-orphans
```

No Kimai database/application state is intentionally reused between runs.

See [`../tests/e2e/README.md`](../tests/e2e/README.md) and [`testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md).

Real Stripe sandbox E2E will reuse this disposable Kimai environment.  Stripe-side test objects need separate run-specific identification and safe cleanup because Stripe is external to Docker.

## Plugin Packaging

ADR-012 establishes a self-contained Kimai plugin model.

Install path:

```text
var/plugins/KimaiStripeConnectorBundle/
```

Namespace:

```text
KimaiPlugin\KimaiStripeConnectorBundle\
```

Kimai does not automatically install third-party Composer dependencies declared by an in-repository plugin.  The connector therefore carries a plugin-local `vendor/` tree in release artifacts and loads its own Composer autoloader from the bundle entrypoint.

Do not modify Kimai's root `composer.json`, `composer.lock`, `vendor/`, or `config/bundles.php` as part of normal installation.

The source repository does not commit `vendor/`.

`composer.lock` is intended to be committed for reproducible development/release dependency resolution.  If it is absent in the current branch, generating and committing it is implementation-preparation work rather than a change to product architecture.

## Documentation Contract

ADR-002 and [`documentation.md`](documentation.md) establish PHP Doxygen/PHPDoc standards.

Generated reference documentation is:

```text
doc/reference/
```

and is intentionally ignored by Git.

Normal documentation commands:

```text
make docs
make docs-clean
```

Doxygen warnings are failures.

## Configuration Contract

See [`configuration.md`](configuration.md).

Primary namespace:

```text
KIMAI_STRIPE_CONNECTOR_*
```

Environment variables are primary.  File-backed secret injection is preferred for production/container secrets.  `.env` support is tertiary development/test convenience, not an independent configuration system.

## Reference Hosting Context

See [`reference-hosting-environment.md`](reference-hosting-environment.md).

The dogfooding environment includes container orchestration, Traefik, external SQL services, network-backed storage, scheduling, and private LAN/VPN access.  These are operational context, not hard dependencies of the OSS/plugin design.

Do not hard-code site-specific hostnames, addresses, domains, Swarm services, NFS paths, Jenkins job names, or Traefik labels into connector logic.

## Repository Quality Gates

MegaLinter is a blocking quality gate.

Required policy:

```text
DISABLE_ERRORS: false
APPLY_FIXES: false
```

A green workflow status is not sufficient if the report contains warnings.  Completion requires zero reported MegaLinter errors and zero reported MegaLinter warnings.

The PHP workflow runs unit/static-analysis checks across the initial PHP compatibility range.

The Kimai E2E workflow exercises the real disposable plugin lifecycle.

The Documentation workflow exercises Doxygen generation.

The repository retains Dependabot, semver/release automation, CONTRIBUTING, SUPPORT, and the broad `.gitignore`.  Dependabot is extended for the Composer ecosystem.  The cspell configuration file is `.cspell.json`.

## License Status

The repository is currently private.  The inherited CC0 files remain present and the public/open-source release license is not considered an immediate implementation blocker.

Do not make a public release or invite public contributions while assuming the final software-license question has disappeared.  Resolve it deliberately before the repository becomes public or release/contribution circumstances make the distinction material.

## Manual Fallback

The connector must never become necessary to continue billing:

```text
track time in Kimai
-> generate invoice
-> create Stripe Payment Link manually
-> include URL
-> send invoice
-> verify payment in Stripe
-> mark invoice paid manually
```

This is a permanent supported operational fallback.

## Immediate Next Work

After the implementation-preparation branch is accepted and clean, the next session should:

1. ensure `composer.lock` is generated and committed;
2. verify all PHP, MegaLinter, Documentation, and Kimai E2E workflows complete cleanly;
3. implement configuration parsing and validation;
4. implement stable instance identity;
5. implement exact decimal-to-minor-unit money handling for the accepted initial currency scope;
6. implement metadata value objects/validation;
7. implement safe Stripe-client construction and app identification;
8. add the corresponding `CFG-*`, `MNY-*`, metadata, and threat-model tests;
9. only then begin real Payment Link mutation behavior; and
10. add real Stripe sandbox E2E only when an authorized sandbox key is available.

Do not skip directly from the inert bundle skeleton to invoice paid-state mutation.

## Handoff Rule

Update this document whenever a new session would otherwise need conversational history to understand current implementation state, important failed experiments, compatibility findings, active PRs/branches, or the next safe work sequence.

Keep it current rather than using it as an append-only diary.  Historical rationale belongs in ADRs, Git history, and focused research/test documents.
