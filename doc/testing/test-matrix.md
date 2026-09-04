# Test Architecture and Coverage Matrix

Date: 2026-09-04

## Purpose

This document maps documented connector behavior and historical synthetic scenario IDs to the cheapest deterministic test layer capable of proving them.

The goal is not to duplicate every behavior at every layer.  The goal is to make it difficult for an implementation session to leave important failure paths as prose-only assumptions.

ADR-003 governs when behavior changes require tests.

## Test Layers

### Unit

Pure PHP with no real Kimai or Stripe runtime.

Use for configuration parsing, money/currency normalization, metadata, identity matching, Payment Link planning, reconciliation decisions/orchestration, cleanup eligibility, command behavior, operator-result taxonomy, and redaction.

### Kimai integration

A real or faithfully mirrored Kimai application boundary with the plugin loaded, without requiring real Stripe.

Use for plugin registration, dependency injection, event subscribers, preview detection, InvoiceModel/options/hydrator behavior, invoice rendering integration, paid-state persistence, and Kimai-version compatibility.

### Controlled Stripe/network integration

A deterministic fake HTTP/Stripe boundary under project control while using the real locked Stripe SDK.

Use for timeout-before/after-commit, malformed responses, retry/idempotency behavior, pagination, rate limits, network interruption, races, and hostile/unexpected object graphs.

### Real Stripe sandbox E2E

A deliberately small set of high-value proofs against the official Stripe sandbox/test environment.

Use for real Payment Link request compatibility, real Stripe object behavior, metadata round-trip, hosted Checkout, Session/PaymentIntent/Charge relationships, reconciliation, and safe sandbox cleanup.

The real Stripe layer is evidence for contracts mocks cannot prove.  It is not the project's fault-injection or load-testing layer.

## Phase 2 Primitive Coverage

| Area                  | Repository tests                                           | Current evidence                                                                          |
|-----------------------|------------------------------------------------------------|-------------------------------------------------------------------------------------------|
| Configuration         | `ConfigurationTest`                                        | CFG-001..010 and CFG-013 parser semantics; safe-context support for CFG-011/012           |
| Stable identity       | `KimaiInstanceIdTest`, `ConfigurationTest`, `MetadataTest` | Canonical non-nil UUID validation and lowercase metadata identity                         |
| Money                 | `MoneyTest`                                                | Exact decimal and framework-float conversion, fail-closed precision/currency/range policy |
| Metadata              | `MetadataTest`                                             | Version-1 write/parse validation, unknown-version rejection, Stripe value bounds          |
| Secret safety         | `SecretRedactorTest`, `ConfigurationTest`                  | Known-secret redaction and credential-free safe configuration context                     |
| Operator results      | `OperatorResultTest`                                       | ADR-013 category identifiers and success/no-change semantics                              |
| Stripe client factory | `StripeClientFactoryTest`                                  | Restricted credential reaches SDK client; SDK API version is not overridden               |

## Phase 3 Payment Link Coverage

| Area                     | Repository tests                     | Current evidence                                                                                                            |
|--------------------------|--------------------------------------|-----------------------------------------------------------------------------------------------------------------------------|
| Planning                 | `PaymentLinkPlannerTest`             | Preview, reuse, create, financial conflict, ambiguity, malformed same-invoice metadata, inactive exact link                 |
| Request construction     | `PaymentLinkCreateRequestTest`       | Card-only, one-completed-session limit, exact amount/currency, metadata propagation request, deterministic idempotency key  |
| Orchestration            | `PaymentLinkServiceTest`             | Discovery, reuse, create, definite failure, uncertain commit rediscovery, no blind second create                            |
| Stripe SDK adapter       | `StripePaymentLinkGatewayTest`       | Pagination, response validation, create failure classification                                                              |
| Controlled HTTP boundary | `StripePaymentLinkHttpBoundaryTest`  | Real locked stripe-php request shape, idempotency header, timeout-after-remote-commit recovery                              |
| Kimai money boundary     | `KimaiInvoiceMetadataFactoryTest`    | Kimai float conversion through shared exact money policy; binary-artifact precision fails closed                            |
| Kimai pre-render seam    | `InvoicePaymentLinkSubscriberTest`   | Preview before config/Stripe access, disabled no-op, safe URL hydration, automatic failure stops rendering                  |
| Real Stripe sandbox      | `tests/e2e/stripe-sandbox-smoke.php` | Real create/read/list/update, exact line item, restrictions, metadata round-trip, configured read permissions, deactivation |

## Phase 4 Reconciliation Coverage

| Area                         | Repository tests                       | Current evidence                                                                                                                                                                                                      |
|------------------------------|----------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Pure financial decision      | `ReconciliationPlannerTest`            | Exact successful chain, missing/unsupported states, amount/currency/metadata/relationship contradictions, partial capture, refunds/disputes, repeated sessions, competing Payment Links                               |
| Batch orchestration          | `ReconciliationServiceTest`            | No-link no-op, exact paid transition, dry-run, per-invoice failure isolation, persistence failure, paid orphan, unpaid orphan, already-paid idempotence                                                               |
| Read-only Stripe SDK adapter | `StripeReconciliationHttpBoundaryTest` | Real locked SDK GET request paths, pagination parameters, Payment Link -> Checkout Session -> PaymentIntent -> Charge traversal, instance scoping, malformed evidence, retryable connection failure, Charge timestamp |
| Kimai persistence boundary   | `KimaiReconciliationInvoiceStoreTest`  | Exact Kimai snapshot, shared fail-closed float conversion, last-moment re-read, TOCTOU drift conflict, evidence mismatch, concurrent already-paid no-op, Stripe payment-date application                              |
| External command             | `ReconcileCommandTest`                 | Disabled no-op, configuration failure, `--dry-run`, material manual-review exit, retryable run-level failure, stable human result category                                                                            |
| DI / production packaging    | disposable Kimai E2E                   | Production-shaped plugin installation/reload/removal verifies real Kimai can construct registered Phase 4 services                                                                                                    |
| Real paid sandbox E2E        | future extension of sandbox workflow   | Hosted test-card Checkout and exact Kimai paid mutation remain intentionally unclaimed until implemented and run                                                                                                      |

## Phase 5 Cleanup Coverage

| Area                         | Repository tests                       | Current evidence                                                                                                                                                                                                                                                 |
|------------------------------|----------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Pure cleanup decision        | `CleanupPlannerTest`                   | Existing Kimai invoice, exact grace boundary, in-grace object, missing/future age, missing/inactive state, multiple links, paid orphan, open Session, terminal unpaid Session/PaymentIntent, processing/unknown ambiguity, empty evidence                        |
| Cleanup orchestration        | `CleanupServiceTest`                   | `--dry-run`-equivalent no-write path, actual deactivation, second Kimai lookup, managed-link rediscovery, child-evidence rediscovery, competing link appearing before mutation, payment appearing before mutation, retryable writer failure                      |
| Stripe read evidence         | `StripeReconciliationHttpBoundaryTest` | Locked SDK Payment Link discovery retains Stripe `created` and `active` evidence while preserving Phase 4 read-only Session/PaymentIntent/Charge traversal                                                                                                       |
| Stripe mutation boundary     | `StripeCleanupHttpBoundaryTest`        | Locked SDK target retrieve before update, exact metadata check, idempotent already-inactive result, `active=false` request encoding, update confirmation, retryable uncertain update outcome                                                                     |
| External cleanup command     | `CleanupCommandTest`                   | Disabled no-op, missing credential, `--dry-run`, eligible deactivation, conflict, paid orphan/manual review, retryable discovery, retryable deactivation, generic internal-error output, shared ADR-013 exit-code mapping                                        |
| DI / production packaging    | disposable Kimai E2E                   | Production-shaped Kimai 2.65 plugin installation constructs both reconciliation and cleanup commands; disabled `--dry-run` invocations prove registration/autowiring without Stripe credential or mutation                                                       |
| Real Stripe cleanup behavior | existing sandbox contract probe        | Real sandbox has proven connector-created Payment Link deactivation with the existing restricted-key permission; the Phase 5 eligibility/revalidation algorithm itself remains deterministic lower-layer evidence rather than an active real-account cleanup run |

The Phase 5 destructive decision is deliberately stricter than an ordinary reconciliation `NO_CHANGE`.  Lack of successful payment proof is insufficient: automatic cleanup requires positively supported terminal/unpaid evidence.  Open, processing, unknown, contradictory, partially financial, or otherwise ambiguous Stripe state is retained.

The PHPUnit suite explicitly includes both `tests/Unit` and `tests/Integration`.  This prevents controlled-SDK tests from existing in the repository without actually executing under `composer check`.

## Historical Payment-Link Scenario Status

The authoritative historical scenario descriptions remain in `preimplementation-mock-exercise.md`.

| IDs         | Primary layer                            | Status                                                                                                       |
|-------------|------------------------------------------|--------------------------------------------------------------------------------------------------------------|
| PL-001..004 | Unit/Kimai integration                   | Implemented through planner/service/subscriber coverage                                                      |
| PL-005..008 | Controlled Stripe                        | Implemented for API failure, idempotency, and uncertain-commit recovery represented by current Phase 3 scope |
| PL-009..012 | Unit/controlled Stripe/Kimai integration | Implemented for financial conflict, ambiguity, recovery, and safe rendering boundary                         |

## Historical Reconciliation Scenario Status

| IDs          | Primary layer                         | Status / notes                                                                                           |
|--------------|---------------------------------------|----------------------------------------------------------------------------------------------------------|
| REC-001..012 | Unit/controlled Stripe                | Implemented through planner and read-only gateway evidence validation                                    |
| REC-013..016 | Unit/controlled Stripe                | Implemented for refunds/disputes/capture/ambiguity with no unsafe reversal or paid transition            |
| REC-017      | Unit/Kimai boundary                   | Persistence failure returns a non-success operator result and never reports false mutation               |
| REC-018..020 | Unit/controlled Stripe/Kimai boundary | Implemented for exact successful evidence, repeated-session behavior, and idempotent paid-state handling |
| REC-021      | Unit orchestration                    | Paid Stripe orphan requires manual review and is never discarded or auto-applied                         |
| REC-022      | Unit/controlled Stripe                | Implemented conservative anomaly behavior                                                                |

Real hosted payment remains a separate evidence layer.  Lower-layer implementation of the scenario matrix does not claim that Stripe-hosted Checkout has been exercised end to end.

## Real Stripe Sandbox Contract Probe

The manual workflow is:

```text
.github/workflows/stripe-sandbox.yml
```

The probe is:

```text
tests/e2e/stripe-sandbox-smoke.php
```

Current verified Stripe sandbox permissions are:

```text
Payment Links       Write
Checkout Sessions   Read
Payment Intents     Read
Charges             Read
Products            None
Prices              None
all other resources None
```

The probe proved that inline one-time `price_data` / `product_data` works with Payment Links `Write` and does not require separate Products or Prices permissions.

The probe also proves:

- real Payment Link creation with the locked Stripe PHP SDK;
- exact `$12.34` USD to `1234` minor units;
- one line item and quantity one;
- card-only payment method;
- one-completed-session limit;
- exact version-1 metadata after Stripe round-trip;
- Payment Link retrieve/list access;
- Checkout Session read access;
- PaymentIntent read access;
- Charge read access; and
- successful sandbox link deactivation.

The first real run exposed an order-sensitive local metadata assertion.  Stripe creation and cleanup succeeded.  The test was corrected to canonical metadata comparison and subsequent runs passed.

The current probe does **not** prove hosted card Checkout, a real paid PaymentIntent/Charge chain, Kimai reconciliation, or the complete Phase 5 cleanup eligibility/revalidation loop against a real Stripe account.  Those remain future real-E2E evidence and must not be implied by lower-layer green tests.

## Configuration Backlog

| IDs          | Primary layer    | Status / notes                                                                                 |
|--------------|------------------|------------------------------------------------------------------------------------------------|
| CFG-001..010 | Unit             | Implemented in Phase 2 unit tests                                                              |
| CFG-011..012 | Unit/integration | Pure redaction/safe-context coverage exists; real Kimai startup/log inspection remains pending |
| CFG-013      | Unit/integration | Process-environment values use the same parser as explicit environment maps                    |

A `.env` file remains only one possible mechanism for populating process environment.  The connector deliberately does not implement a second dotenv parser or precedence model.

## Money Coverage

| IDs          | Primary layer | Status / notes                                                                                                                 |
|--------------|---------------|--------------------------------------------------------------------------------------------------------------------------------|
| MNY-001..008 | Unit          | Implemented with exact text parsing, shared framework-float conversion, no rounding, fail-closed currencies, and Stripe bounds |

## Cleanup Scenario Status

| IDs          | Primary layer                            | Status / notes                                                                                                                                                                                       |
|--------------|------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| CLN-001..009 | Unit/controlled Stripe/Kimai integration | Implemented through Phase 5 planner/service/command/locked-SDK coverage for conservative eligibility, dry-run, stale unpaid deactivation, paid/ambiguous retention, failures, and revalidation races |

Paid, open, processing, unknown, or uncertain objects remain conservative.  Sandbox deactivation evidence proves the Stripe permission/API operation, while the destructive eligibility policy remains deterministic repository evidence.

## Network and Concurrency Backlog

| IDs          | Primary layer                         | Status / notes                                                                                                                                                                                                                                                        |
|--------------|---------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| NET-001..008 | Controlled Stripe/network             | Payment Link, reconciliation, and cleanup adapters cover key API failure/retry paths; remaining synthetic fault cases stay lower-layer work                                                                                                                           |
| CON-001..004 | Controlled Stripe/concurrency harness | Kimai paid persistence re-reads before mutation; cleanup now re-queries Kimai, managed links, child payment evidence, and the target before deactivation.  Full simultaneous-process locking/load scenarios remain intentionally outside the connector-owned contract |

These scenarios shall not be implemented solely as real Stripe E2E tests because timing/failure induction would be nondeterministic and inappropriate for Stripe's sandbox.

## Kimai Integration Status

| IDs          | Primary layer                    | Status / notes                                                                                                                                                                                                                                                                                                |
|--------------|----------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| KIM-001..012 | Disposable Kimai integration/E2E | Plugin lifecycle and real service-container compatibility exist; event/render boundary has Phase 3 coverage; paid persistence is modeled against verified Kimai 2.65 APIs; both scheduler commands construct in real Kimai; canonical full paid E2E remains required before that stronger release-level claim |

The disposable Kimai lifecycle harness remains the canonical ephemeral host fixture.

## Stripe Contract Status

| IDs          | Primary layer                                        | Status / notes                                                                                                                                                                                                                                                            |
|--------------|------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| STR-001..011 | Controlled Stripe plus representative sandbox subset | Payment Link create/read/deactivate/money/metadata/permission subset is real-sandbox proven; reconciliation GET and cleanup target-update request behavior are controlled-SDK proven; hosted paid object chain and complete active cleanup loop remain future sandbox E2E |

Real sandbox tests should prove only assumptions that require Stripe.  Hostile response shapes and fault injection remain deterministic controlled tests.

## Threat-Model Tests

Executable evidence supports:

```text
THR-001 direct Stripe secret redaction
THR-002 file-backed Stripe secret redaction
THR-017 contradictory direct + file-backed credentials fail configuration
```

The real Stripe workflow additionally demonstrates environment-secret injection where the credential is provided only to the final probe step, while checkout/setup/dependency-install steps do not receive it.

The reconciliation and cleanup commands accept no arbitrary target invoice, Payment Link, or desired state.  This keeps their mutation surfaces evidence-derived.  Real deployment-boundary startup/log inspection remains necessary before all secret-leakage scenarios are considered fully proven.

## Pull Request Coverage Rule

A PR that changes executable behavior should identify affected documented scenario IDs when such IDs exist and add/update the appropriate tests in the same coherent change.

A PR need not invent a scenario ID for every implementation detail.  IDs exist to make consequential behavior and failure contracts discoverable.

## Completion Evidence

For behavior to be described as implemented, the repository should be able to point to:

```text
documented contract
+ implementation
+ appropriate executable test
+ required CI/lint/documentation checks
```

Historical synthetic results remain useful design evidence, but they do not satisfy this completion rule until represented by executable repository tests.
