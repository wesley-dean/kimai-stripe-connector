# Test Architecture and Coverage Matrix

Date: 2026-09-04

## Purpose

This document maps documented connector behavior and historical synthetic scenario IDs to the cheapest deterministic test layer capable of proving them.

The goal is not to duplicate every behavior at every layer.  The goal is to make it difficult for an implementation session to leave important failure paths as prose-only assumptions.

ADR-003 governs when behavior changes require tests.

## Test Layers

### Unit

Pure PHP with no real Kimai or Stripe runtime.

Use for configuration parsing, money/currency normalization, metadata, identity matching, Payment Link planning, reconciliation decisions, cleanup eligibility, operator-result taxonomy, and redaction.

### Kimai integration

A real or faithfully mirrored Kimai application boundary with the plugin loaded, without requiring real Stripe.

Use for plugin registration, dependency injection, event subscribers, preview detection, InvoiceModel/options/hydrator behavior, invoice rendering integration, paid-state persistence, and Kimai-version compatibility.

### Controlled Stripe/network integration

A deterministic fake HTTP/Stripe boundary under project control.

Use for timeout-before/after-commit, malformed responses, retry/idempotency behavior, pagination, rate limits, network interruption, races, and hostile/unexpected object graphs.

### Real Stripe sandbox E2E

A deliberately small set of high-value proofs against the official Stripe sandbox/test environment.

Use for real Payment Link request compatibility, real Stripe object behavior, metadata round-trip, hosted Checkout, Session/PaymentIntent/Charge relationships, reconciliation, and safe sandbox cleanup.

The real Stripe layer is evidence for contracts mocks cannot prove.  It is not the project's fault-injection or load-testing layer.

## Phase 2 Primitive Coverage

| Area | Repository tests | Current evidence |
|------|------------------|------------------|
| Configuration | `ConfigurationTest` | CFG-001..010 and CFG-013 parser semantics; safe-context support for CFG-011/012 |
| Stable identity | `KimaiInstanceIdTest`, `ConfigurationTest`, `MetadataTest` | Canonical non-nil UUID validation and lowercase metadata identity |
| Money | `MoneyTest` | MNY-001..008 exact conversion, fail-closed currency policy, Stripe amount bounds |
| Metadata | `MetadataTest` | Version-1 write/parse validation, unknown-version rejection, Stripe value bounds |
| Secret safety | `SecretRedactorTest`, `ConfigurationTest` | Known-secret redaction and credential-free safe configuration context |
| Operator results | `OperatorResultTest` | ADR-013 category identifiers and success/no-change semantics |
| Stripe client factory | `StripeClientFactoryTest` | Restricted credential reaches SDK client; SDK API version is not overridden |

## Phase 3 Payment Link Coverage

| Area | Repository tests | Current evidence |
|------|------------------|------------------|
| Planning | `PaymentLinkPlannerTest` | Preview, reuse, create, financial conflict, ambiguity, malformed same-invoice metadata, inactive exact link |
| Request construction | `PaymentLinkCreateRequestTest` | Card-only, one-completed-session limit, exact amount/currency, metadata propagation request, deterministic idempotency key |
| Orchestration | `PaymentLinkServiceTest` | Discovery, reuse, create, definite failure, uncertain commit rediscovery, no blind second create |
| Stripe SDK adapter | `StripePaymentLinkGatewayTest` | Pagination, response validation, create failure classification |
| Controlled HTTP boundary | `StripePaymentLinkHttpBoundaryTest` | Real locked stripe-php request shape, idempotency header, timeout-after-remote-commit recovery |
| Kimai money boundary | `KimaiInvoiceMetadataFactoryTest` | Kimai float serialization into exact money policy; binary-artifact precision fails closed |
| Kimai pre-render seam | `InvoicePaymentLinkSubscriberTest` | Preview before config/Stripe access, disabled no-op, safe URL hydration, automatic failure stops rendering |
| Real Stripe sandbox | `tests/e2e/stripe-sandbox-smoke.php` | Real create/read/list/update, exact line item, restrictions, metadata round-trip, configured read permissions, deactivation |

## Historical Payment-Link Scenario Status

The authoritative historical scenario descriptions remain in `preimplementation-mock-exercise.md`.

| IDs | Primary layer | Phase 3 status |
|-----|---------------|----------------|
| PL-001..004 | Unit/Kimai integration | Implemented through planner/service/subscriber coverage |
| PL-005..008 | Controlled Stripe | Implemented for API failure, idempotency, and uncertain-commit recovery represented by current Phase 3 scope |
| PL-009..012 | Unit/controlled Stripe/Kimai integration | Implemented for financial conflict, ambiguity, recovery, and safe rendering boundary |

Where a historical scenario extends into paid-state reconciliation, its Payment Link half is implemented while the accounting transition remains Phase 4 work.

## Real Stripe Sandbox Contract Probe

The manual workflow is:

```text
.github/workflows/stripe-sandbox.yml
```

The probe is:

```text
tests/e2e/stripe-sandbox-smoke.php
```

Current verified Stripe sandbox evidence includes:

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

## Configuration Backlog

| IDs | Primary layer | Status / notes |
|-----|---------------|----------------|
| CFG-001..010 | Unit | Implemented in Phase 2 unit tests |
| CFG-011..012 | Unit/integration | Pure redaction/safe-context coverage exists; real Kimai startup/log inspection remains pending |
| CFG-013 | Unit/integration | Process-environment values use the same parser as explicit environment maps |

A `.env` file remains only one possible mechanism for populating process environment.  The connector deliberately does not implement a second dotenv parser or precedence model.

## Money Backlog

| IDs | Primary layer | Status / notes |
|-----|---------------|----------------|
| MNY-001..008 | Unit | Implemented with exact text parsing, no rounding, fail-closed currencies, and Stripe bounds |

## Reconciliation Backlog

Reconciliation remains Phase 4.

| IDs | Primary layer | Status / notes |
|-----|---------------|----------------|
| REC-001..012 | Unit/controlled Stripe | Pending Phase 4 strong-evidence reconciliation logic |
| REC-013..018 | Controlled Stripe/Kimai integration | Pending refund, dispute, capture, ambiguity, persistence-failure behavior |
| REC-019..022 | Real E2E plus lower layers | Pending successful exact payment, paid mutation, idempotent rerun, anomaly behavior |

The real Stripe key already has the Checkout Session, PaymentIntent, and Charge read permissions expected by Phase 4.  Do not broaden permissions preemptively.

## Cleanup Backlog

| IDs | Primary layer | Status / notes |
|-----|---------------|----------------|
| CLN-001..009 | Unit/controlled Stripe/Kimai integration | General cleanup logic pending; sandbox probe already proves connector-created unpaid link deactivation works |

Paid or uncertain objects must remain conservative.  Sandbox cleanup evidence does not authorize deleting accounting-significant production objects.

## Network and Concurrency Backlog

| IDs | Primary layer | Status / notes |
|-----|---------------|----------------|
| NET-001..008 | Controlled Stripe/network | Phase 3 covers key create/retry/uncertain-commit paths; remaining scenarios stay deterministic lower-layer work |
| CON-001..004 | Controlled Stripe/concurrency harness | Pending |

These scenarios shall not be implemented solely as real Stripe E2E tests because timing/failure induction would be nondeterministic and inappropriate for Stripe's sandbox.

## Kimai Integration Backlog

| IDs | Primary layer | Status / notes |
|-----|---------------|----------------|
| KIM-001..012 | Disposable Kimai integration/E2E | Plugin lifecycle exists; Phase 3 adds pre-render seam coverage; paid-state cases wait for Phase 4 |

The disposable Kimai lifecycle harness remains the canonical ephemeral host fixture.

## Stripe Contract Backlog

| IDs | Primary layer | Status / notes |
|-----|---------------|----------------|
| STR-001..011 | Controlled Stripe plus representative sandbox subset | Payment Link create/read/restriction/money/metadata/permission subset implemented; hosted paid object chain waits for Phase 4 |

Real sandbox tests should prove only assumptions that require Stripe.  Hostile response shapes and fault injection remain deterministic controlled tests.

## Threat-Model Tests

Phase 2/3 executable evidence supports:

```text
THR-001 direct Stripe secret redaction
THR-002 file-backed Stripe secret redaction
THR-017 contradictory direct + file-backed credentials fail configuration
```

The real Stripe workflow additionally demonstrates environment-secret injection where the credential is provided only to the final probe step, while checkout/setup/dependency-install steps do not receive it.

Real Kimai startup/log inspection remains necessary before deployment-boundary secret-leakage scenarios are considered fully proven.

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
