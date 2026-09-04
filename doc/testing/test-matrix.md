# Test Architecture and Coverage Matrix

Date: 2026-09-04

## Purpose

This document maps the connector's documented behavior and historical synthetic
scenario IDs to the cheapest deterministic test layer capable of proving them.

The goal is not to duplicate every behavior at every layer.  The goal is to make
it difficult for an implementation session to accidentally leave important
failure paths as prose-only assumptions.

ADR-003 governs when behavior changes require tests.

## Test Layers

### Unit

Pure PHP with no real Kimai or Stripe runtime.

Use for:

- configuration parsing/validation;
- money and currency normalization;
- metadata construction/parsing;
- identity matching;
- reconciliation decision logic represented as pure state;
- cleanup eligibility represented as pure state;
- result/error taxonomy; and
- redaction helpers.

### Kimai integration

A real Kimai application/container with the plugin loaded, without necessarily
calling real Stripe.

Use for:

- plugin registration and removal;
- dependency-injection wiring;
- event subscribers;
- invoice preview detection;
- InvoiceModel/options/hydrator behavior;
- Twig invoice rendering integration;
- Kimai invoice paid-state persistence; and
- Kimai version compatibility.

### Controlled Stripe/network integration

A deterministic fake HTTP/Stripe boundary under project control.

Use for conditions that cannot be reliably induced against the real Stripe
sandbox:

- timeout before commit;
- timeout after remote commit;
- malformed responses;
- retry and idempotency behavior;
- pagination edge cases;
- rate limits;
- network interruption;
- races/concurrent creation attempts; and
- hostile/unexpected object graphs.

### Real Stripe sandbox E2E

A deliberately small set of expensive high-value proofs using the official Stripe
sandbox/test mode and a disposable real Kimai environment.

Use for:

- real Payment Link creation;
- actual metadata propagation among Stripe objects;
- hosted card Checkout;
- successful real sandbox PaymentIntent/Charge relationships;
- exact reconciliation into Kimai;
- idempotent reconciliation reruns; and
- safe connector-managed sandbox cleanup.

The Kimai portion of every E2E run is ephemeral and is constructed/destroyed by
`tests/e2e/run.sh`.

## Phase 2 Primitive Coverage

The Phase 2 implementation adds executable unit coverage before any real Stripe
mutation exists.

| Area                  | Repository tests                                           | Current evidence                                                                 |
|-----------------------|------------------------------------------------------------|----------------------------------------------------------------------------------|
| Configuration         | `ConfigurationTest`                                        | CFG-001..010 and CFG-013 parser semantics; safe-context support for CFG-011/012  |
| Stable identity       | `KimaiInstanceIdTest`, `ConfigurationTest`, `MetadataTest` | Canonical non-nil UUID validation and lowercase metadata identity                |
| Money                 | `MoneyTest`                                                | MNY-001..008 exact conversion, fail-closed currency policy, Stripe amount bounds |
| Metadata              | `MetadataTest`                                             | Version-1 write/parse validation, unknown-version rejection, Stripe value bounds |
| Secret safety         | `SecretRedactorTest`, `ConfigurationTest`                  | Known-secret redaction and credential-free safe configuration context            |
| Operator results      | `OperatorResultTest`                                       | ADR-013 category identifiers and success/no-change semantics                     |
| Stripe client factory | `StripeClientFactoryTest`                                  | Restricted credential reaches SDK client; SDK API version is not overridden      |

The Phase 2 Stripe client test constructs an SDK client only.  It makes no remote
request and therefore requires no real Stripe credential.

## Configuration Backlog

The `CFG-*` scenarios from `doc/configuration.md` are primarily unit tests:

| IDs          | Primary layer    | Status / notes                                                                                 |
|--------------|------------------|------------------------------------------------------------------------------------------------|
| CFG-001..010 | Unit             | Implemented in Phase 2 unit tests                                                              |
| CFG-011..012 | Unit/integration | Pure redaction/safe-context coverage exists; real Kimai startup/log inspection remains pending |
| CFG-013      | Unit/integration | Process-environment values are proven to use the same parser as an explicit environment map    |

A `.env` file remains only one possible mechanism for populating process
environment values.  The connector deliberately does not implement a second dotenv
parser or precedence model.

## Historical Payment-Link Backlog

The original pre-implementation exercise defines `PL-*` scenarios.

| IDs         | Primary layer          | Representative concerns                              |
|-------------|------------------------|------------------------------------------------------|
| PL-001..004 | Unit/Kimai integration | Preview suppression, identity, create/reuse behavior |
| PL-005..008 | Controlled Stripe      | API error, timeout, idempotency, uncertain commit    |
| PL-009..012 | Unit/controlled Stripe | Financial conflict, ambiguous candidates, recovery   |

Exact individual scenario descriptions remain authoritative in
`preimplementation-mock-exercise.md`; this table assigns execution layers rather
than replacing that record.

## Money Backlog

| IDs          | Primary layer | Status / notes                                                                                    |
|--------------|---------------|---------------------------------------------------------------------------------------------------|
| MNY-001..008 | Unit          | Implemented with exact text parsing, no float/rounding, fail-closed currencies, and Stripe bounds |

Money conversion receives broad parameterized coverage because it is cheap and
financially consequential.  The current implementation intentionally rejects zero
and negative invoice amounts, zero-decimal currencies, Stripe special-case
currencies, malformed precision, and amounts greater than Stripe's eight-digit
minor-unit limit.  The upper boundary itself is covered as a valid case.

## Reconciliation Backlog

| IDs          | Primary layer                       | Representative concerns                                                     |
|--------------|-------------------------------------|-----------------------------------------------------------------------------|
| REC-001..012 | Unit/controlled Stripe              | Identity, amount, currency, checkout/payment state                          |
| REC-013..018 | Controlled Stripe/Kimai integration | refund, dispute, capture, multiple candidates, persistence failure          |
| REC-019..022 | Real E2E plus lower layers          | successful exact payment, paid mutation, idempotent rerun, anomaly behavior |

The real Stripe E2E suite should contain only representative high-value cases.
The full adversarial state matrix belongs in deterministic lower layers.

## Cleanup Backlog

| IDs          | Primary layer                            | Notes                                                                                             |
|--------------|------------------------------------------|---------------------------------------------------------------------------------------------------|
| CLN-001..009 | Unit/controlled Stripe/Kimai integration | Prove eligibility logic, uncertainty retention, paid-orphan retention, grace period, deactivation |

At least one real Stripe sandbox test should prove that a connector-created unpaid
sandbox Payment Link can be deactivated safely, but the full cleanup decision
matrix does not need repeated external API calls.

## Network and Concurrency Backlog

| IDs          | Primary layer                         |
|--------------|---------------------------------------|
| NET-001..008 | Controlled Stripe/network             |
| CON-001..004 | Controlled Stripe/concurrency harness |

These scenarios SHALL NOT be implemented solely as real Stripe E2E tests because
network timing and failure induction would be nondeterministic and slow.

## Kimai Integration Backlog

| IDs          | Primary layer                    |
|--------------|----------------------------------|
| KIM-001..012 | Disposable Kimai integration/E2E |

The existing lifecycle harness is the first implementation of this layer.  Future
cases should reuse the same ephemeral environment rather than creating a second
long-lived Kimai fixture.

## Stripe Contract Backlog

| IDs          | Primary layer                                               |
|--------------|-------------------------------------------------------------|
| STR-001..011 | Controlled Stripe plus a representative real sandbox subset |

Real sandbox tests should verify assumptions that only Stripe can prove, including
actual metadata propagation and object relationships.  Response-shape hostility
and failure paths stay deterministic in the controlled layer.

## Threat-Model Tests

`THR-*` scenarios in `doc/threat-model.md` cut across layers.

Phase 2 directly implements pure unit evidence supporting:

```text
THR-001 direct Stripe secret redaction
THR-002 file-backed Stripe secret redaction
THR-017 contradictory direct + file-backed credentials fail configuration
```

THR-001 and THR-002 still require real startup/integration log inspection before
the deployment boundary is considered fully proven.  THR-018 test/live mismatch
remains a later environment/Stripe validation concern; the credential primitive can
identify test versus live restricted keys from their prefixes, but Phase 2 does
not invent an expected-mode configuration knob.

Assign each new threat test to the lowest layer that can genuinely exercise the
threat.  Credential/log leakage and public-ingress assumptions require integration
or deployment inspection in addition to pure unit tests.

## Pull Request Coverage Rule

A PR that changes executable behavior should identify the affected documented
scenario IDs when such IDs exist and add/update the appropriate tests in the same
coherent change.

A PR need not invent a scenario ID for every trivial implementation detail.  IDs
exist to make important behavioral and failure contracts discoverable.

## Completion Evidence

For a behavior to be described as implemented, the repository should be able to
point to:

```text
documented contract
+ implementation
+ appropriate executable test
+ required CI/lint/documentation checks
```

Historical synthetic results are useful design evidence but do not satisfy this
completion rule until represented by executable repository tests.
