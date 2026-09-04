# Containerized Kimai / Stripe Sandbox End-to-End Testing

Date: 2026-09-04

## Status and Purpose

This document defines the real-system E2E strategy for the Kimai Stripe Connector.

The repository already contains an executable disposable Kimai 2.65.0 + MySQL plugin-lifecycle harness under `tests/e2e/`.  Real Stripe sandbox payment flows have not yet been implemented because production Stripe behavior is not present in the plugin and ordinary CI does not yet require a Stripe sandbox credential.

The E2E goal is:

> Run the real connector inside a fresh real Kimai container, communicate outbound with the real Stripe API in a sandbox/test environment, complete a Stripe-hosted card Checkout, run the real connector reconciliation behavior, verify the exact Kimai invoice state, preserve useful failure evidence, and destroy the Kimai/database test environment before the run ends.

The progression is:

```text
historical synthetic architecture cases
        |
        v
repository unit tests
        |
        v
real Kimai + controlled/fake Stripe integration tests
        |
        v
real Kimai + real Stripe sandbox E2E
        |
        v
compatibility/release evidence
```

The real Stripe layer remains deliberately small.  It proves contracts that mocks cannot prove; it is not the project's fault-injection or load-testing layer.

## Governing Decisions

The E2E design follows:

- ADR-003: behavior changes require appropriate tests;
- ADR-004: Kimai 2.65.0, PHP 8.2–8.5, Stripe PHP `^21.3.1`, card-only initial payment scope, ordinary two-decimal currencies, version-1 metadata, and one restricted Stripe credential;
- ADR-005: no Stripe-to-Kimai public ingress/webhook dependency;
- ADR-006: supported Kimai plugin/extension seams;
- ADR-007: Payment Links/Stripe-hosted Checkout as the customer payment surface;
- ADR-008: exact versioned metadata correlation;
- ADR-009: strong-evidence-only reconciliation;
- ADR-010: conservative cleanup;
- ADR-011: idempotent partial-failure recovery; and
- ADR-012: self-contained plugin packaging.

## Current Executable Kimai Harness

The executable harness is:

```text
tests/e2e/compose.yaml
tests/e2e/run.sh
```

It intentionally follows Kimai's documented Docker Compose model closely:

- official `kimai/kimai2:2.65.0` image;
- MySQL 8.3 fixture database;
- ordinary Kimai environment variables;
- Kimai data/plugin named volumes; and
- host access bound only to loopback on an ephemeral host port.

The current lifecycle flow is:

```text
fresh unique Compose project
-> fresh MySQL/Kimai/plugin volumes
-> start Kimai 2.65.0 + MySQL
-> copy checked-out self-contained plugin into var/plugins
-> run kimai:reload
-> verify plugin bundle resolves
-> verify Kimai CLI remains healthy
-> remove plugin
-> run kimai:reload
-> verify plugin no longer resolves
-> destroy containers/network/all named volumes
```

The harness already proves an architectural property before financial code exists: the connector is a normal removable Kimai plugin and does not require Kimai core modification.

## Ephemeral by Construction

E2E Kimai/database state SHALL be created by the test and destroyed by the test.

`tests/e2e/run.sh` creates a run-specific Docker Compose project name and installs the checked-out plugin into that project's test-owned plugin volume.

An EXIT/INT/TERM trap executes:

```text
docker compose down --volumes --remove-orphans
```

Cleanup destroys:

- Kimai container;
- MySQL container;
- Compose network;
- MySQL data volume;
- Kimai data volume; and
- installed-plugin volume.

No Kimai database/application state is intentionally reused between normal E2E runs.

The long-lived dogfooding/reference Kimai instance SHALL NOT be used as the E2E fixture.

A future upgrade/migration test may deliberately preserve state inside one test scenario, but that persistence must remain owned by that test run and still be destroyed at final teardown.

## Stripe-Side Isolation

Stripe is external to Docker, so `docker compose down --volumes` cannot clean Stripe objects.

Every Stripe sandbox E2E run SHOULD use a unique run identifier attached through test-only names/labels or safely additive test metadata without changing the production correlation contract.

Stripe-side test objects should be:

- attributable to one E2E run;
- distinguishable from manual sandbox work;
- deactivated/cleaned when safe; and
- retained temporarily when a failed test needs forensic evidence.

Paid or otherwise accounting-significant test objects should never be deleted merely to make teardown look clean if doing so would destroy the evidence needed to diagnose a failure.

## Network Property

The E2E environment SHALL preserve the accepted no-public-ingress architecture.

```text
+----------------------------+
| ephemeral Kimai + plugin   |
+-------------+--------------+
              |
              | outbound HTTPS
              v
        +-----------+
        | Stripe API|
        +-----------+

+----------------------------+
| E2E browser/test runner    |
+-------------+--------------+
              |
              | outbound HTTPS
              v
       Stripe-hosted Checkout

Stripe --------X--------> Kimai
          no webhook
          no callback
          no public route
```

Kimai may be reachable from the local/CI runner for testing.  It does not become an Internet-facing endpoint merely so Stripe can operate.

Passing the real Stripe E2E suite under this topology is executable evidence for ADR-005.

## Versions and Compatibility Evidence

Initial E2E compatibility baseline:

```text
Kimai:          2.65.0
Stripe PHP:     exact version selected by composer.lock within ^21.3.1
Payment method: card
```

The Kimai image is pinned to an exact version.  A result against 2.65.0 is evidence for 2.65.0, not a claim about every future Kimai version.

Later compatibility expansion should add an explicit matrix, for example:

```text
minimum supported Kimai
current primary supported Kimai
new candidate Kimai under evaluation
```

A moving `stable` image tag is useful for exploratory candidate checks but should not replace exact-version evidence for a release claim.

## Plugin Under Test

### Development E2E

Development E2E installs the current repository contents into:

```text
/opt/kimai/var/plugins/KimaiStripeConnectorBundle
```

The plugin is self-contained under ADR-012 and expects its production Composer dependencies under its plugin-local `vendor/` tree.

The harness therefore requires `composer install` before execution.

### Release-artifact E2E

Release validation must test the artifact users actually receive, not only a source checkout.

The future release-artifact test should:

1. build the plugin from the committed lockfile;
2. include production plugin-local dependencies;
3. unpack/install it exactly as the installation documentation specifies;
4. exercise the same plugin lifecycle; and
5. then run representative Stripe behavior.

A source-tree pass does not prove a malformed release archive is installable.

## Secrets and Credential Handling

Real Stripe E2E requires a sandbox restricted key.

The sandbox key is still a secret even though it cannot move live money.

The CI/test design SHALL:

- store it in CI/runtime secret storage;
- never commit it;
- never store it in the fixture Compose file;
- avoid shell tracing of the value;
- prevent untrusted fork PRs from receiving it;
- prevent it from appearing in test artifacts/screenshots/traces;
- use the accepted restricted-key permission model; and
- verify that application/container logs do not expose the credential.

The reference Kimai container has exhibited shell tracing capable of exposing environment values.  E2E should prove actual secret-safe behavior rather than assuming environment-variable injection is automatically safe.

## Restricted-Key Permission Validation

ADR-004 selects one least-privilege restricted key initially.

A sandbox setup exercise should verify the exact Stripe dashboard permissions required for:

- Payment Link create/retrieve/list/deactivate;
- inline one-time product/price creation used by the Payment Link request;
- Checkout Session retrieval/listing;
- PaymentIntent retrieval; and
- Charge retrieval.

The verified dashboard labels should then be recorded in operator/deployment documentation.

At least one test should demonstrate the operator-visible failure when the credential lacks a required permission.

## Canonical Happy Path

The foundational true E2E scenario should exercise the entire intended business flow.

### Setup

1. Start the disposable Kimai/MySQL environment.
2. Install the real connector through the normal plugin path.
3. Wait for Kimai readiness and reload the application.
4. Inject a Stripe sandbox restricted key through the connector configuration contract.
5. Configure a run-specific stable test Kimai instance UUID.
6. Seed the minimum Kimai customer/project/activity/time data needed for a real invoice.

### Invoice creation

7. Generate a real Kimai invoice.
8. Confirm invoice preview, if exercised beforehand, caused no Stripe mutation.
9. Confirm exactly one connector-managed Payment Link exists for the invoice.
10. Confirm link amount/currency match Kimai exactly.
11. Confirm version-1 metadata matches the intended instance/invoice/amount/currency.
12. Confirm the resulting invoice rendering contains that exact Stripe-hosted URL.

### Customer payment

13. Open the rendered Payment Link with a browser runner such as Playwright.
14. Complete Stripe-hosted Checkout using Stripe's current documented successful card test data.
15. Confirm Stripe produced the expected Session/PaymentIntent/Charge relationship and successful payment evidence.

### Reconciliation

16. Confirm Kimai remains unpaid before reconciliation if no reconciliation run has occurred.
17. Run the real connector reconciliation command.
18. Confirm the exact invoice becomes paid.
19. Confirm no unrelated invoice changes.
20. Confirm the operator result/exit behavior matches ADR-013.

### Idempotence

21. Run reconciliation again.
22. Confirm the second run produces no duplicate accounting side effect.
23. Confirm the result is an appropriate no-change/success-equivalent operator outcome.

### Teardown

24. Preserve failure diagnostics when needed.
25. Safely deactivate/clean connector-managed Stripe sandbox links created by the run when evidence no longer needs preservation.
26. Destroy Kimai/MySQL containers, network, and volumes through the harness trap.

## Browser Automation

A headless browser such as Playwright is appropriate for the small number of tests that need to prove the actual customer-facing hosted Checkout path.

Browser tests should answer high-value questions:

- does the rendered URL open a Stripe-hosted page;
- is the intended amount/currency represented;
- can a documented card test payment complete; and
- does the resulting real Stripe object chain reconcile correctly.

Avoid broad coupling to Stripe's HTML/CSS.  Stripe owns that presentation and may change it without changing the API contract.

Most connector behavior belongs below the browser layer.

## Initial True E2E Scenarios

### E2E-001: Plugin lifecycle

Already represented by the current disposable harness.

Prove install/reload/remove/reload and healthy Kimai behavior.

### E2E-002: Invoice preview produces no Stripe mutation

Use Kimai's real preview path and prove no connector-managed Payment Link is created.

### E2E-003: Successful invoice, card payment, and reconciliation

Canonical full flow.

### E2E-004: Exact amount/currency and metadata

Use an amount chosen to expose common decimal conversion mistakes and verify exact Stripe object fields/metadata.

### E2E-005: Declined card leaves Kimai unpaid

Use Stripe's current documented declined-card test behavior and prove no automatic paid transition.

### E2E-006: Reconciliation idempotence

Pay, reconcile, reconcile again, and prove the second run changes no accounting state.

### E2E-007: Correct invoice among multiple invoices

Create multiple invoices, pay exactly one, and prove only the intended invoice is reconciled.

### E2E-008: Plugin disabled/manual fallback

Disable/remove connector and prove ordinary Kimai invoice generation remains usable.

### E2E-009: Exact orphan recovery when practical

If a realistic Kimai-side failure can be induced without patching core, create a link then fail invoice completion and prove an unchanged retry rediscovers the exact link.

If deterministic failure induction would distort the real system, keep precise timeout/commit injection at the controlled Stripe layer and limit the true E2E case to a safely reproducible orphan/recovery observation.

### E2E-010: Refund/dispute does not reverse historical paid state

Pay/reconcile, create a sandbox refund or dispute-like supported test condition, rerun connector audit/reconciliation behavior, and prove Kimai is not automatically rewritten from paid to unpaid/canceled.

### E2E-011: 3DS card behavior if claimed

If the initial card-support statement includes authentication-required cards, add at least one Stripe-documented 3DS sandbox flow.

## What Does Not Belong in Real Stripe E2E

Use controlled/fake Stripe integration tests for deterministic fault injection and scale-sensitive scenarios, including:

- timeout precisely after Stripe commits;
- repeated injected 500 responses;
- rate-limit injection;
- malformed Stripe JSON/object shapes;
- impossible state combinations;
- large concurrency storms;
- 10,000-object distractor scans;
- large property/fuzz runs;
- exhaustive currency conversion sweeps; and
- deliberately corrupted Stripe fixtures.

Do not use Stripe's sandbox as a load-testing target.

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

Use [`test-matrix.md`](test-matrix.md) for scenario-to-layer assignment.

### Unit/property layer

Best for configuration, money, metadata, exact-match rules, reconciliation state decisions, cleanup logic, result taxonomy, and large generated input sets.

### Real Kimai + controlled Stripe layer

Best for real plugin/events/rendering/persistence plus deterministic network ambiguity, concurrency, malformed responses, and fault injection.

### Real Kimai + real Stripe sandbox

Best for actual Stripe request compatibility, Payment Link creation, metadata propagation, hosted Checkout, real Stripe object relationships, and release compatibility evidence.

## Parallelism

Each Kimai E2E run already uses a unique Compose project and isolated volumes.

Stripe-side objects must also be distinguishable by run.  Parallel tests SHALL NOT depend on global cleanup that could deactivate another run's links.

Avoid making invoice numbering or Stripe metadata globally collision-prone across concurrent CI runs.

## Diagnostics

A failed E2E run should preserve enough evidence to answer:

- which Kimai version ran;
- which connector commit/version ran;
- which Stripe object IDs were involved;
- which invoice identity was involved;
- which phase failed;
- what safe operator result occurred; and
- whether Docker/Stripe cleanup completed.

Do not preserve secrets, full payment payloads, or unnecessary customer/payment-method data merely for convenience.

## CI Trigger Model

Kimai lifecycle E2E can run on ordinary trusted PRs because it needs no external secret.

Real Stripe sandbox E2E should run only where repository policy can protect the sandbox credential, such as:

- trusted same-repository PRs;
- protected manual workflow dispatch; or
- release/candidate validation.

Untrusted fork PRs SHALL NOT receive the Stripe sandbox secret.

Lower-layer tests remain the default coverage for untrusted changes.

## Completion Criteria for Real Stripe E2E Enablement

Before calling the Stripe E2E layer operational, the repository should have:

1. configuration/credential parsing with secret-safe logs;
2. stable instance identity;
3. integer money conversion for the accepted currency scope;
4. version-1 metadata implementation;
5. Payment Link creation/recovery;
6. reconciliation command;
7. sandbox restricted-key permissions verified;
8. a run-specific Stripe object namespace/cleanup mechanism;
9. at least the canonical successful card flow; and
10. CI secret/trigger rules that prevent credential disclosure to untrusted code.

## Relationship to Historical Mock Evidence

The historical 103 synthetic cases remain useful architecture/test-backlog evidence, but they are not repository CI results.

The repository should recreate those behaviors at the cheapest deterministic executable layer, then use only a small subset for true Stripe E2E proof.

## Related Documentation

- [`test-matrix.md`](test-matrix.md) — current scenario/layer mapping
- [`preimplementation-mock-exercise.md`](preimplementation-mock-exercise.md) — historical synthetic architecture exercise
- [`../../tests/e2e/README.md`](../../tests/e2e/README.md) — executable disposable Kimai lifecycle harness
- [`../configuration.md`](../configuration.md) — secret/configuration contract
- [`../threat-model.md`](../threat-model.md) — E2E/security threats and controls
- [`../adr/ADR-003-require-tests-for-behavioral-changes.md`](../adr/ADR-003-require-tests-for-behavioral-changes.md) — test requirement
- [`../adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md`](../adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md) — compatibility/payment baseline
- [`../adr/ADR-005-private-kimai-outbound-stripe-integration.md`](../adr/ADR-005-private-kimai-outbound-stripe-integration.md) — network boundary
- [`../adr/ADR-009-reconcile-only-on-strong-stripe-payment-evidence.md`](../adr/ADR-009-reconcile-only-on-strong-stripe-payment-evidence.md) — reconciliation evidence
