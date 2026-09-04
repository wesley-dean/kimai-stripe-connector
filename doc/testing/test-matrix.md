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

## Configuration Backlog

The `CFG-*` scenarios from `doc/configuration.md` are primarily unit tests:

| IDs           | Primary layer    | Notes                                                                                  |
| ------------- | ---------------- | -------------------------------------------------------------------------------------- |
| CFG-001..010  | Unit             | Configuration validity and contradiction handling                                      |
| CFG-011..012  | Unit/integration | Secret redaction must also be checked in real startup logs                              |
| CFG-013       | Unit/integration | `.env` population must have identical semantics to externally supplied environment variables |

## Historical Payment-Link Backlog

The original pre-implementation exercise defines `PL-*` scenarios.

| IDs          | Primary layer            | Representative concerns                                      |
| ------------ | ------------------------ | ------------------------------------------------------------ |
| PL-001..004  | Unit/Kimai integration   | Preview suppression, identity, create/reuse behavior         |
| PL-005..008  | Controlled Stripe        | API error, timeout, idempotency, uncertain commit            |
| PL-009..012  | Unit/controlled Stripe   | Financial conflict, ambiguous candidates, recovery           |

Exact individual scenario descriptions remain authoritative in
`preimplementation-mock-exercise.md`; this table assigns execution layers rather
than replacing that record.

## Money Backlog

| IDs          | Primary layer | Notes                                                                                       |
| ------------ | ------------- | ------------------------------------------------------------------------------------------- |
| MNY-001..008 | Unit          | Exact decimal/minor-unit behavior, supported/unsupported currency policy, malformed precision |

Money conversion receives broad parameterized/property-style coverage because it
is cheap and financially consequential.

## Reconciliation Backlog

| IDs           | Primary layer                       | Representative concerns                                      |
| ------------- | ----------------------------------- | ------------------------------------------------------------ |
| REC-001..012  | Unit/controlled Stripe              | Identity, amount, currency, checkout/payment state           |
| REC-013..018  | Controlled Stripe/Kimai integration | refund, dispute, capture, multiple candidates, persistence failure |
| REC-019..022  | Real E2E plus lower layers          | successful exact payment, paid mutation, idempotent rerun, anomaly behavior |

The real Stripe E2E suite should contain only representative high-value cases.
The full adversarial state matrix belongs in deterministic lower layers.

## Cleanup Backlog

| IDs           | Primary layer                            | Notes                                                                                   |
| ------------- | ---------------------------------------- | --------------------------------------------------------------------------------------- |
| CLN-001..009  | Unit/controlled Stripe/Kimai integration | Prove eligibility logic, uncertainty retention, paid-orphan retention, grace period, deactivation |

At least one real Stripe sandbox test should prove that a connector-created unpaid
sandbox Payment Link can be deactivated safely, but the full cleanup decision
matrix does not need repeated external API calls.

## Network and Concurrency Backlog

| IDs          | Primary layer                         |
| ------------ | ------------------------------------- |
| NET-001..008 | Controlled Stripe/network             |
| CON-001..004 | Controlled Stripe/concurrency harness |

These scenarios SHALL NOT be implemented solely as real Stripe E2E tests because
network timing and failure induction would be nondeterministic and slow.

## Kimai Integration Backlog

| IDs          | Primary layer                     |
| ------------ | --------------------------------- |
| KIM-001..012 | Disposable Kimai integration/E2E  |

The existing lifecycle harness is the first implementation of this layer.  Future
cases should reuse the same ephemeral environment rather than creating a second
long-lived Kimai fixture.

## Stripe Contract Backlog

| IDs          | Primary layer                                            |
| ------------ | -------------------------------------------------------- |
| STR-001..011 | Controlled Stripe plus a representative real sandbox subset |

Real sandbox tests should verify assumptions that only Stripe can prove, including
actual metadata propagation and object relationships.  Response-shape hostility
and failure paths stay deterministic in the controlled layer.

## Threat-Model Tests

`THR-*` scenarios in `doc/threat-model.md` cut across layers.

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
