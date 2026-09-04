# Containerized Kimai / Stripe Sandbox End-to-End Testing

Date: 2026-09-04

## Status and Purpose

This document defines the real-system E2E strategy for the Kimai Stripe Connector and records the subset that is currently executable.

The repository contains two real-system layers:

1. a disposable Kimai 2.65.0 + MySQL plugin-lifecycle harness under `tests/e2e/`; and
2. a manual real-Stripe sandbox contract probe that exercises the locked Stripe PHP SDK with a restricted test credential.

The Phase 3 real-Stripe contract probe is operational.  The full hosted-card-payment and Kimai reconciliation flow remains pending Phase 4 because automatic reconciliation does not exist yet.

The eventual canonical E2E goal remains:

> Run the real connector inside a fresh real Kimai container, communicate outbound with the real Stripe API in sandbox/test mode, complete Stripe-hosted card Checkout, run real connector reconciliation, verify the exact Kimai invoice state, preserve useful failure evidence, and destroy the test-owned Kimai/database environment before the run ends.

## Governing Decisions

The E2E design follows ADR-003 through ADR-013, especially:

- ADR-003: executable behavior changes require appropriate tests;
- ADR-004: Kimai/PHP/Stripe/payment/money/credential contract;
- ADR-005: no Stripe-to-Kimai public webhook dependency;
- ADR-006: supported Kimai plugin seams;
- ADR-007: Payment Links as customer payment surface;
- ADR-008: exact versioned metadata correlation;
- ADR-009: strong-evidence-only reconciliation;
- ADR-010: conservative cleanup;
- ADR-011: idempotent partial-failure recovery;
- ADR-012: self-contained plugin packaging; and
- ADR-013: operator result taxonomy.

## Current Executable Kimai Harness

The disposable Kimai harness is:

```text
tests/e2e/compose.yaml
tests/e2e/run.sh
```

It uses:

- official `kimai/kimai2:2.65.0`;
- MySQL 8.3;
- ordinary Kimai environment configuration;
- run-owned named volumes;
- loopback-only host exposure; and
- production-shaped plugin staging with production dependencies only.

Current lifecycle flow:

```text
fresh Compose project
-> fresh MySQL/Kimai/plugin volumes
-> start Kimai + MySQL
-> install production-shaped checked-out plugin
-> kimai:reload
-> verify plugin bundle resolves
-> verify Kimai CLI remains healthy
-> remove plugin
-> kimai:reload
-> verify plugin no longer resolves
-> destroy containers/network/volumes
```

The harness proves that the connector remains an ordinary removable Kimai plugin and does not require Kimai core modification.

## Current Real Stripe Contract Probe

The durable workflow is:

```text
.github/workflows/stripe-sandbox.yml
```

The probe is:

```text
tests/e2e/stripe-sandbox-smoke.php
```

The workflow is manual-only through `workflow_dispatch` and references GitHub Actions environment:

```text
stripe-sandbox
```

with environment secret:

```text
STRIPE_SANDBOX_RESTRICTED_KEY
```

The secret is mapped only for the probe step to:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
```

Checkout, PHP setup, and dependency installation do not receive the Stripe secret.

## Verified Restricted-Key Permissions

The verified sandbox permission set is:

| Stripe resource     | Permission |
|---------------------|------------|
| Payment Links       | Write      |
| Checkout Sessions   | Read       |
| Payment Intents     | Read       |
| Charges             | Read       |
| Products            | None       |
| Prices              | None       |
| All other resources | None       |

See [`../stripe-restricted-key.md`](../stripe-restricted-key.md) for operator-facing details.

The sandbox probe demonstrated that inline one-time `price_data` / `product_data` creation succeeds through Payment Links `Write` without separate Products or Prices permissions.

## Real Stripe Evidence Already Proven

The Phase 3 sandbox probe verifies:

- the restricted test credential works with the exact locked Stripe PHP SDK;
- real Payment Link creation;
- exact `$12.34` USD representation as `1234` minor units;
- exactly one line item and quantity one;
- card-only payment-method restriction;
- one-completed-session limit;
- exact version-1 metadata after Stripe round-trip;
- Payment Link retrieve/list access;
- Checkout Session read access;
- PaymentIntent read access;
- Charge read access; and
- successful Payment Link deactivation during cleanup.

The initial run created and deactivated the link successfully but exposed an order-sensitive local metadata assertion.  The assertion was corrected to compare canonical metadata key/value pairs.  Subsequent probes passed.

The probe refuses live-mode credentials and redacts the known key from exception text before diagnostics are written.

## What Is Not Yet Proven

The Phase 3 probe does **not** prove:

- a real Kimai-generated invoice invoking the Payment Link subscriber inside the disposable Kimai container;
- browser traversal of the Stripe-hosted Payment Link;
- successful test-card Checkout;
- metadata on an actually created Checkout Session;
- metadata on an actually paid PaymentIntent;
- Charge relationship for the paid flow;
- Kimai paid-state reconciliation;
- reconciliation idempotence;
- declined-card behavior; or
- refund/dispute audit behavior.

Those depend on Phase 4 reconciliation or later cleanup/audit implementation.

## Ephemeral Kimai State

Kimai/database E2E state must remain test-owned and ephemeral.

`tests/e2e/run.sh` uses a run-specific Compose project and an EXIT/INT/TERM trap that performs:

```text
docker compose down --volumes --remove-orphans
```

Normal E2E runs must not reuse the long-lived dogfooding/reference Kimai instance.

## Stripe-Side Isolation

Stripe objects cannot be destroyed by Docker teardown.

Every real Stripe run should use a unique run identity in the test invoice number and/or safely additive test metadata while preserving the production six-field correlation contract.

Connector-created test Payment Links should be deactivated after successful evidence collection.  If a future paid or uncertain test object is needed for failure forensics, retain it until the evidence is no longer needed rather than deleting accounting-significant state merely to make teardown look clean.

Parallel tests must never perform global cleanup that could deactivate another run's objects.

## Network Property

The accepted topology remains outbound-only:

```text
Kimai + connector ----outbound HTTPS----> Stripe API
browser/test runner --outbound HTTPS----> Stripe-hosted Checkout
Stripe ----------------X----------------> Kimai
                      no webhook
```

Kimai may be reachable from the local/CI runner for testing.  It must not become Internet-facing merely for Stripe integration.

## Production-Shaped Plugin Rule

Development E2E must install the plugin as users receive it, including the plugin-local production dependency graph from the committed lockfile.

Do not copy the repository-root development `vendor/` tree into Kimai.  The harness stages a production-shaped plugin using:

```text
composer install --no-dev
```

This rule preserves ADR-012 and avoids development Symfony/PSR packages shadowing Kimai's own runtime dependencies.

## Secret Handling

The sandbox key remains a secret even though it cannot move live money.

The CI/test design must:

- store it only in secret storage;
- never commit it;
- never place it in fixture Compose files or `.env` examples;
- avoid shell tracing of the value;
- prevent untrusted fork/Dependabot code from receiving it;
- keep it out of artifacts/screenshots/traces; and
- use only the verified restricted-key permission set.

No live Stripe key may be used without explicit owner authorization.

## Test-Layer Relationship

```text
                         few
                 +------------------+
                 | real Stripe      |
                 | sandbox E2E      |
                 +------------------+
                         ||
                 +------------------+
                 | real Kimai +     |
                 | controlled Stripe|
                 +------------------+
                         ||
                 +------------------+
                 | unit/property    |
                 | tests            |
                 +------------------+
                        many
```

Use real Stripe only for contracts that Stripe itself must prove.  Keep timeout injection, malformed responses, races, and other adversarial conditions in deterministic lower layers.

## Current and Future E2E Scenarios

| Scenario                                           | Status                                                    | Purpose                                                    |
|----------------------------------------------------|-----------------------------------------------------------|------------------------------------------------------------|
| E2E-001 plugin lifecycle                           | Implemented                                               | Install/reload/remove/reload with healthy disposable Kimai |
| E2E-002 preview causes no Stripe mutation          | Lower-layer implemented; full Kimai/Stripe proof pending  | Preserve preview safety                                    |
| E2E-003 successful invoice/card/reconciliation     | Pending Phase 4                                           | Canonical business flow                                    |
| E2E-004 exact amount/currency/metadata             | Payment Link contract subset implemented                  | Prove exact Stripe representation                          |
| E2E-005 declined card leaves Kimai unpaid          | Pending Phase 4                                           | False-positive prevention                                  |
| E2E-006 reconciliation idempotence                 | Pending Phase 4                                           | Safe rerun behavior                                        |
| E2E-007 correct invoice among multiple invoices    | Pending Phase 4                                           | Exact target selection                                     |
| E2E-008 plugin disabled/manual fallback            | Lower-layer/lifecycle evidence exists                     | Preserve manual operation                                  |
| E2E-009 orphan recovery                            | Controlled layer implemented; real-system subset optional | Partial-failure recovery                                   |
| E2E-010 refund/dispute does not reverse paid state | Pending reconciliation/audit implementation               | Historical-state conservatism                              |
| E2E-011 3DS card behavior                          | Deferred unless claimed                                   | Authentication-required card support                       |

## Phase 4 Extension

After Phase 4 reconciliation exists, extend the real sandbox suite to the canonical flow:

```text
fresh disposable Kimai invoice
-> preview proves no Stripe mutation
-> real invoice creates exactly one Payment Link
-> browser opens Stripe-hosted Checkout
-> documented Stripe test card completes payment
-> verify Session / PaymentIntent / Charge evidence
-> verify Kimai remains unpaid before reconciliation
-> run reconciliation command
-> verify exact invoice becomes paid
-> rerun reconciliation
-> verify no duplicate accounting side effect
-> preserve/clean Stripe evidence safely
-> destroy Kimai/MySQL environment
```

The existing restricted key already has the expected read permissions for Checkout Sessions, PaymentIntents, and Charges.  Do not add permissions merely because Phase 4 begins.

## Completion Criteria for Full Real Stripe E2E

The complete E2E layer is operational when the repository has:

1. secret-safe configuration and logs;
2. stable instance identity;
3. exact integer money conversion;
4. version-1 metadata;
5. Payment Link creation/recovery;
6. reconciliation command and strong-evidence decision logic;
7. verified restricted-key permissions;
8. run-specific Stripe object isolation;
9. canonical successful hosted card flow;
10. exact Kimai paid-state verification;
11. idempotent reconciliation rerun; and
12. CI trigger/secret rules that prevent credential disclosure to untrusted code.

Items 1 through 5 and 7 are substantially represented now.  Phase 4 supplies the main missing reconciliation and canonical paid-flow pieces.

## Related Documentation

- [`test-matrix.md`](test-matrix.md)
- [`preimplementation-mock-exercise.md`](preimplementation-mock-exercise.md)
- [`../../tests/e2e/README.md`](../../tests/e2e/README.md)
- [`../stripe-restricted-key.md`](../stripe-restricted-key.md)
- [`../configuration.md`](../configuration.md)
- [`../threat-model.md`](../threat-model.md)
- [`../implementation-playbook.md`](../implementation-playbook.md)
