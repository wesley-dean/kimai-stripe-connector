# Containerized Kimai / Stripe Sandbox End-to-End Testing

Date: 2026-09-03

## Status and Purpose

This document defines the intended true end-to-end (E2E) validation strategy for the Kimai Stripe Connector.

The project already has a historical pre-implementation mock exercise documented in [`preimplementation-mock-exercise.md`](preimplementation-mock-exercise.md).  Those mocks were useful architectural falsification work, but they were synthetic and are not reproducible repository CI evidence.

The purpose of the E2E layer described here is different:

> Run a real Kimai container, load the real connector plugin through Kimai's supported plugin mechanism, communicate with the real Stripe API in an isolated Stripe sandbox/test environment, complete a payment through Stripe-hosted Checkout, run the real reconciliation command, and verify the real Kimai invoice state.

The intended progression is:

```text
historical architecture mocks
        |
        v
repository unit tests
        |
        v
Kimai integration tests with controlled/fake Stripe
        |
        v
containerized Kimai + real Stripe sandbox E2E
        |
        v
release/compatibility evidence
```

The E2E suite SHOULD remain deliberately small.  It exists to prove that the real systems and extension points work together, not to reproduce every adversarial condition against Stripe's infrastructure.

## Why Containerized Kimai Is Appropriate for E2E

Kimai publishes official Docker images and documents Docker Compose deployment.

Current Kimai documentation shows:

- Kimai listening on port `8001` in the documented container setup;
- plugin storage at `/opt/kimai/var/plugins`;
- plugin directories mounted either through a Docker volume or a host bind mount;
- plugin installation/activation operations performed through Kimai's console after the container starts.

A disposable containerized Kimai instance therefore provides a realistic application runtime without requiring tests to modify a persistent production installation.

The test environment can exercise the real:

- Kimai application kernel;
- Symfony dependency injection container;
- plugin discovery;
- plugin bundle lifecycle;
- event subscriber registration;
- `InvoicePreRenderEvent` behavior;
- `InvoiceModelHydrator` behavior;
- Twig invoice rendering;
- invoice persistence;
- paid-state update;
- command registration;
- plugin disable/remove behavior.

This is substantially stronger evidence than invoking connector classes in isolation.

## Why a Stripe Sandbox/Test Environment Is Appropriate

Stripe documents sandboxes as isolated testing environments that allow transactions to be simulated without moving real funds.

The E2E environment SHALL use Stripe test/sandbox API credentials exclusively.

No E2E test SHALL use a live Stripe secret key or real payment method details.

Stripe provides interactive test-card values for hosted forms.  At the time this document was written, the standard successful Visa test number is:

```text
4242 4242 4242 4242
```

with a future expiration date and any valid test CVC.

The test suite SHOULD use Stripe's documented current test values rather than hard-coding assumptions indefinitely.  If Stripe changes its testing recommendations, the E2E implementation and this document should be updated together.

## Architectural Property Exercised by the E2E Environment

The E2E environment SHOULD preserve one of the connector's central architectural constraints:

> Stripe does not need inbound access to Kimai.

The network shape is:

```text
+-------------------------+
| containerized Kimai     |
| + connector plugin      |
+------------+------------+
             |
             | outbound HTTPS
             v
      +-------------+
      | Stripe API  |
      +-------------+

+-------------------------+
| E2E browser / runner    |
+------------+------------+
             |
             | outbound HTTPS
             v
 +-------------------------+
 | Stripe-hosted Checkout  |
 +-------------------------+

Stripe ----X----> Kimai
         no webhook
         no callback
         no public ingress required
```

The test MAY expose Kimai to the local CI runner or Docker network.  It SHOULD NOT require Kimai to be Internet-accessible merely to satisfy Stripe.

Passing E2E tests under this topology is executable evidence for the project's no-webhook/no-public-ingress architecture.

## Proposed E2E Topology

A representative environment is:

```text
Docker Compose / CI job
|
+-- database
|    +-- MySQL/MariaDB version supported by targeted Kimai release
|
+-- kimai
|    +-- pinned real Kimai image
|    +-- connector source/release mounted under /opt/kimai/var/plugins
|    +-- Stripe sandbox credential
|    +-- test-only Kimai instance UUID
|    +-- no public Internet ingress route
|
+-- e2e-runner
     +-- test orchestration
     +-- HTTP/API client as needed
     +-- Playwright or equivalent browser automation
     +-- access to Kimai on Docker/local test network
     +-- access to Stripe-hosted Checkout
```

The database SHOULD be disposable for each independent E2E run unless a particular upgrade/migration test explicitly requires persistence.

## Pin Exact Versions for Compatibility Evidence

Normal deployments may follow Kimai's recommended stable/major image tags.  E2E compatibility evidence SHOULD use exact Kimai versions.

Conceptually:

```text
kimai/kimai2:<exact-supported-version>
```

rather than allowing a floating tag to change underneath a supposedly reproducible test result.

The CI suite can later use a version matrix such as:

```text
minimum supported Kimai
current primary supported Kimai
new Kimai candidate under evaluation
```

The exact matrix is a release-policy decision and remains open.

A test result against one Kimai version SHALL NOT be generalized automatically to every Kimai version.

## Mount the Real Plugin

Kimai's Docker documentation supports bind-mounted plugin directories under:

```text
/opt/kimai/var/plugins
```

The E2E environment SHOULD mount either:

1. the repository source tree in the plugin directory during development; or
2. the exact packaged release artifact being tested during release validation.

Testing source and testing the packaged release serve different purposes.

### Development E2E

Development E2E answers:

> Does the current source work in a real Kimai runtime?

A conceptual mount may resemble:

```yaml
services:
  kimai:
    image: kimai/kimai2:${KIMAI_VERSION}
    volumes:
      - ../../:/opt/kimai/var/plugins/KimaiStripeConnectorBundle:ro
```

The actual bundle directory/name must match the final Kimai plugin structure and SHALL be defined only after the plugin packaging decision is implemented.

### Release-artifact E2E

Release E2E answers:

> Does the artifact users will actually install work correctly?

The test SHOULD unpack/mount the release artifact exactly as installation documentation instructs.

A release should not be considered validated merely because a source bind mount worked while the packaged artifact is malformed.

## Plugin Installation and Cache Lifecycle

Kimai documents that mounted plugins are installed/managed through Kimai's console and that application cache clearing is part of plugin lifecycle operations.

The E2E setup SHOULD therefore exercise the same supported lifecycle expected of an operator.

The test setup SHALL NOT modify Kimai core files or manually patch the Symfony application merely to make the plugin load.

The exact installation command depends on the final connector bundle name and Kimai plugin conventions and remains an implementation detail.

The E2E suite SHOULD verify at least:

```text
container starts
-> plugin is discovered
-> service container compiles
-> plugin routes/commands/services load as expected
-> cache lifecycle completes
-> Kimai remains healthy
```

## Secrets and Credential Handling

The E2E job requires a Stripe sandbox/test secret or restricted key.

That key is sensitive even though it does not move live funds.

The CI design SHALL:

- store the key in the CI platform's secret store;
- never commit the key;
- never write it into a checked-in `.env` file;
- avoid echoing it in shell tracing;
- prevent it from appearing in command-line diagnostics intentionally;
- mask it in CI logs;
- prevent untrusted fork pull requests from receiving the secret;
- avoid uploading it in test artifacts;
- use the narrowest Stripe test credential practical for the required API operations.

This is especially important because the reference Kimai container deployment previously revealed that entrypoint shell tracing (`set -x`) can expose environment values in logs.

The E2E environment must therefore verify actual log behavior rather than assuming an environment variable is automatically secret-safe.

## Test Isolation and Unique Identity

Each E2E run SHOULD use a unique run identifier.

That identifier MAY be incorporated into:

- Kimai customer/project names;
- invoice prefixes/numbers where practical;
- Stripe metadata;
- connector test metadata;
- test artifact/log labels.

The connector's production metadata contract SHALL remain production-compatible.  Test correlation metadata should be additive and clearly namespaced if used.

The goal is to distinguish objects created by parallel or historical E2E runs without weakening the production identity model.

## Canonical Happy-Path E2E Test

The foundational E2E scenario SHOULD exercise the entire intended business flow.

### Setup

1. Start a disposable supported database.
2. Start a pinned Kimai container.
3. Mount/install the real connector plugin.
4. Wait for Kimai health/readiness.
5. Configure Stripe sandbox/test credentials.
6. Configure a stable test Kimai instance UUID for that run/environment.
7. Seed a customer, project/activity as required, and billable time entries.

### Invoice generation

8. Generate a real Kimai invoice.
9. Verify that the connector creates or recovers exactly one intended Stripe Payment Link.
10. Verify Stripe-side metadata matches the intended connector identity contract.
11. Verify amount and currency are exact.
12. Verify the generated Kimai invoice rendering contains the intended Stripe-hosted payment URL.

### Customer payment

13. Launch a browser automation context.
14. Navigate to the exact Stripe Payment Link rendered by Kimai.
15. Complete Stripe-hosted Checkout with a documented successful test payment method.
16. Verify Stripe records the expected successful payment state.

### Reconciliation

17. Verify Kimai is still unpaid before reconciliation if reconciliation has not yet run.
18. Execute the real connector reconciliation command.
19. Verify the exact Kimai invoice is marked paid.
20. Verify no unrelated invoice is changed.
21. Verify payment date according to the project's eventual payment-date policy.

### Idempotence

22. Execute reconciliation a second time.
23. Verify no duplicate side effect occurs.
24. Verify invoice state remains correct.

### Teardown

25. Preserve diagnostic artifacts needed for a failed test.
26. Deactivate/delete test-created Stripe Payment Links where appropriate to avoid unnecessary active test surfaces.
27. Remove disposable Kimai/database containers and volumes.

This single flow validates a large fraction of the architecture with real systems.

## Browser Automation

A headless browser such as Playwright is a reasonable customer simulator for the hosted Checkout portion.

The browser should interact with Stripe the same way a customer does:

```text
invoice URL
-> Stripe-hosted Payment Link
-> hosted Checkout form
-> test payment
```

This gives stronger evidence than bypassing Checkout and creating a successful PaymentIntent directly through a server-side test helper.

However, Stripe owns the hosted Checkout HTML.

The project SHOULD therefore keep UI-driven Stripe tests few in number and resilient to presentation changes.

Avoid coupling dozens of tests to fragile CSS selectors or page layout that the connector does not control.

The browser layer should answer only high-value questions such as:

- does the URL actually open;
- is the expected amount/currency/customer-facing purpose shown;
- can a documented test payment complete;
- does the resulting Stripe state reconcile correctly.

Detailed connector decision logic belongs in unit/integration tests where the project controls the fixtures.

## Proposed True E2E Scenarios

The E2E suite SHOULD remain small enough that each scenario proves an integration property unavailable from lower-level tests.

Initial candidates include:

### E2E-001: Successful invoice, payment, and reconciliation

The canonical happy path described above.

### E2E-002: Invoice preview produces no Payment Link

Exercise Kimai's real preview mechanism and confirm the Stripe sandbox contains no mutation attributable to that preview.

This proves that the connector is using the real Kimai preview distinction correctly.

### E2E-003: Exact amount and currency

Generate a real Kimai invoice with a value chosen to expose common conversion mistakes and verify the real Stripe Payment Link amount/currency exactly.

### E2E-004: Declined card leaves Kimai unpaid

Complete or attempt Checkout using a current Stripe documented decline test case.

Verify reconciliation does not mark Kimai paid.

### E2E-005: Reconciliation is idempotent

Pay successfully, reconcile, and reconcile again.

Verify the second run is a no-op with respect to accounting state.

### E2E-006: Correct invoice among multiple invoices

Create at least two invoices, pay exactly one, run reconciliation, and prove only the correct invoice becomes paid.

### E2E-007: Plugin disabled/manual fallback

Disable or remove the connector according to supported Kimai procedures.

Verify Kimai can still generate an ordinary invoice and the application remains usable.

This proves the connector is optional rather than infrastructure required for billing.

### E2E-008: Exact orphan recovery

Induce a controlled failure after Stripe Payment Link creation but before successful invoice completion if this can be made deterministic without patching Kimai.

Retry the unchanged invoice and verify the connector reuses the exact compatible Stripe Payment Link.

If deterministic induction is too invasive for true E2E, keep the detailed failure injection in the fake-Stripe/Kimai integration layer and use a more limited sandbox verification of recovery behavior.

### E2E-009: Amount-changing retry fails closed

If E2E setup can create a realistic same-identity amount conflict safely, verify that the connector refuses silent reuse/mutation.

This scenario may be easier and more deterministic in integration tests than through full browser E2E; inclusion should be based on implementation feasibility.

### E2E-010: Refund after reconciliation does not reverse automatically

Pay and reconcile an invoice, create a Stripe sandbox refund through a controlled test helper/API, rerun reconciliation/audit behavior, and verify Kimai is not silently changed from paid to unpaid/canceled.

The connector SHOULD instead report the post-payment accounting exception according to the implemented observability policy.

### E2E-011: 3D Secure, if supported

If the first release claims support for payment methods/cards requiring authentication, use Stripe's current documented 3DS test values and verify the hosted flow and reconciliation.

### E2E-012+: Asynchronous methods only when explicitly supported

Each asynchronous payment method included in the supported product policy should receive at least one real sandbox lifecycle test.

No asynchronous method should be considered supported merely because Stripe enables it automatically on an account.

## What Does Not Belong in the Real Stripe E2E Suite

The real Stripe sandbox is not the correct place for every test.

Stripe explicitly warns that testing environments should not be used for load testing because rate limits may be encountered.

The project SHOULD keep the following in controlled fake-Stripe/integration tests:

- large concurrency storms;
- 20, 100, or more simultaneous create requests as a routine test;
- connection drop precisely after server commit;
- arbitrary injected HTTP 500 sequences;
- deterministic rate-limit injection;
- malformed Stripe JSON payloads;
- impossible Stripe state combinations;
- 10,000-object distractor scans as a routine external API test;
- large fuzz/property runs;
- exhaustive currency/money conversion sweeps;
- intentionally corrupted Stripe object fixtures.

The historical mock exercise already demonstrated why these cases matter.  The repository should recreate them against a controlled fake/server abstraction where failure timing and state can be deterministic.

The Stripe sandbox E2E layer exists to validate **real contract compatibility**, not to abuse Stripe as a fault-injection framework.

## Testing Pyramid for This Project

A useful target shape is:

```text
                         few
                 +------------------+
                 | Stripe sandbox   |
                 | true E2E tests   |
                 +------------------+
                         ||
                         ||
                 +------------------+
                 | real Kimai +     |
                 | controlled/fake  |
                 | Stripe tests     |
                 +------------------+
                         ||
                         ||
                 +------------------+
                 | service/unit/    |
                 | property tests   |
                 +------------------+
                        many
```

### Unit/property layer

Best for:

- money normalization;
- metadata parsing/versioning;
- exact-match rules;
- decision matrices;
- conflict detection;
- cleanup logic;
- reconciliation state logic;
- large generated input sets.

### Real Kimai + controlled Stripe layer

Best for:

- actual plugin loading;
- Kimai events;
- hydrators;
- rendering;
- database persistence;
- failure injection;
- network ambiguity;
- concurrency;
- malformed remote responses.

### Real Kimai + Stripe sandbox E2E

Best for:

- actual Stripe request compatibility;
- real Payment Link creation;
- actual hosted Checkout URL;
- documented test payment lifecycle;
- real Stripe object relationships;
- exact reconciliation against real Stripe objects;
- compatibility claims across Kimai versions.

## Kimai Compatibility Matrix

One major value of the containerized E2E approach is that compatibility can become executable evidence.

A future CI matrix may resemble:

```yaml
strategy:
  matrix:
    kimai:
      - <minimum-supported-version>
      - <primary-supported-version>
      - <candidate-next-version>
```

The exact list SHALL be maintained as part of release/compatibility policy.

The project MAY distinguish between:

- **supported**: covered by release E2E/CI;
- **expected to work**: compatible by design but not part of the current test matrix;
- **unsupported**: known incompatible or outside declared range.

Do not claim support for a Kimai release solely because the plugin happens to install once.

Compatibility evidence should include at least plugin loading, invoice generation, Payment Link creation, and reconciliation.

## Stripe API/SDK Compatibility Evidence

The E2E suite is also the best place to catch real incompatibility among:

- connector code;
- `stripe/stripe-php` version;
- requested/default Stripe API version;
- Payment Link request shape;
- current Stripe sandbox behavior.

If the plugin relies on newer Payment Link features such as inline/ad-hoc `price_data`, the E2E suite SHOULD positively exercise that request rather than trusting only documentation or mocks.

A Stripe API-version policy still requires a separate design decision.

## CI Trigger Policy

Because true E2E tests require a Stripe sandbox secret and make external API calls, they need more careful triggering than ordinary unit tests.

Potential policy:

- run unit/integration/fake-Stripe tests on every pull request;
- run sandbox E2E on trusted same-repository pull requests after secret-safe conditions are satisfied;
- never expose Stripe secrets to untrusted forks;
- run the full supported-Kimai matrix before release;
- optionally run a scheduled compatibility job against the newest candidate Kimai version;
- allow trusted manual `workflow_dispatch` execution for compatibility investigations.

The final trigger policy should balance coverage, secret safety, Stripe API usage, and CI cost.

## Sandbox Object Cleanup

E2E tests will create real Stripe sandbox objects.

The suite SHOULD tag every managed object with unique test metadata so it can identify its own artifacts.

Teardown MAY deactivate unused test Payment Links and clean other test artifacts where Stripe APIs and auditability make that sensible.

Cleanup SHALL be conservative:

- do not touch objects not positively identified as belonging to the test run/project;
- retain enough information to diagnose failed tests where practical;
- avoid making teardown failure hide the actual test result;
- provide a manual cleanup command/report if abandoned sandbox objects accumulate.

Sandbox tidiness is secondary to test correctness and evidence preservation.

## Failure Artifacts and Diagnostics

When an E2E test fails, CI SHOULD preserve enough non-secret evidence to answer where the failure occurred.

Useful artifacts may include:

- Kimai container logs with secrets redacted/masked;
- connector logs;
- reconciliation/cleanup command output;
- invoice number and safe internal IDs;
- Stripe test object IDs;
- HTTP status/error classifications without secret headers;
- rendered invoice document or extracted payment URL where safe;
- browser screenshot on hosted Checkout failure where it does not expose sensitive test secrets;
- Playwright trace for test-only values where safe;
- Docker Compose service status/health output.

The suite SHOULD distinguish among:

```text
Kimai/plugin failure
Stripe API contract failure
Stripe hosted-UI/browser failure
reconciliation-policy failure
test-fixture/setup failure
CI/network/transient failure
```

A red test should provide enough evidence to avoid treating every external failure as a connector regression.

## Retry Policy for E2E Tests

External E2E tests can encounter transient network/service failures.

The test harness MAY retry narrowly classified transient infrastructure failures.

It SHOULD NOT automatically retry assertion failures or financial-state conflicts until they disappear, because doing so can hide nondeterminism or a real connector defect.

A retry policy should distinguish:

- transport failure;
- temporary Stripe 5xx/rate-limit response;
- browser network timeout;
- deterministic connector assertion failure.

Retries must preserve idempotency assumptions.  Retrying a test that creates Payment Links must not create uncontrolled duplicate payment surfaces.

## Parallelism

The E2E suite MAY run independent scenarios in parallel if every scenario uses independent Kimai/database state and uniquely namespaced Stripe metadata.

However, parallelism should be introduced cautiously because:

- Stripe sandbox rate limits are external constraints;
- browser-hosted Checkout tests are comparatively expensive;
- accidental identity collisions could invalidate reconciliation tests;
- cleanup is more complicated.

There is no requirement that a small E2E suite be highly parallel.

Predictability is more valuable than saving a few minutes.

## Local Developer Execution

The E2E environment SHOULD eventually be runnable locally with the same orchestration used in CI.

A future workflow might resemble:

```text
export STRIPE_TEST_SECRET_KEY=...
docker compose -f tests/e2e/compose.yaml up --build --abort-on-container-exit
```

The actual command is not yet defined.

The project SHALL NOT commit a real `.env` file.  The repository's existing pre-commit configuration intentionally rejects `.env` files and scans for secrets.

A checked-in example configuration may use placeholders such as:

```text
STRIPE_TEST_SECRET_KEY=<provided-at-runtime>
KIMAI_TEST_INSTANCE_UUID=<generated-or-provided-at-runtime>
```

## Relationship to ADR-001 Acceptance

ADR-001 intentionally remains `Proposed` until a real implementation demonstrates the architecture.

The E2E suite is the natural mechanism for satisfying many of ADR-001's validation requirements.

In particular, E2E evidence should eventually demonstrate:

1. the plugin installs through supported Kimai mechanisms;
2. the real Kimai runtime starts cleanly with it enabled;
3. invoice preview does not create Stripe objects;
4. a real invoice creates or recovers the intended Payment Link;
5. the rendered invoice contains the real Stripe-hosted URL;
6. a customer can complete a Stripe sandbox payment without Kimai public ingress;
7. reconciliation identifies the exact payment;
8. the correct Kimai invoice is marked paid;
9. rerunning reconciliation is idempotent;
10. plugin disablement preserves the manual workflow.

Some ADR acceptance cases, especially deterministic network-loss and partial-failure injection, belong more naturally in controlled integration tests than true Stripe E2E.

ADR acceptance should therefore consider evidence from the entire test stack rather than requiring every failure mode to be reproduced against Stripe's sandbox.

## What Passing E2E Means

A passing E2E suite provides evidence that:

> For the exact tested Kimai version, connector version, Stripe client/API configuration, and supported payment scenario, the real systems successfully completed the intended workflow.

It does **not** prove:

- every Kimai version is compatible;
- every Stripe payment method is supported;
- every currency is handled correctly;
- all concurrency/failure races are safe;
- future Stripe/Kimai releases will remain compatible;
- the plugin is free of security defects;
- every accounting edge case is handled.

Those require other test layers and ongoing compatibility work.

## Recommended Initial Implementation Order

When implementation begins, the testing work SHOULD proceed roughly as follows:

1. Create the PHP/unit test framework.
2. Reify the most important historical mock cases as executable unit tests.
3. Create a controlled Stripe client/fake HTTP boundary.
4. Create a disposable real-Kimai container test environment.
5. Prove plugin discovery/loading and preview suppression with fake Stripe.
6. Prove real invoice rendering/hydration with fake Stripe.
7. Add Stripe sandbox credential handling.
8. Add one happy-path real Payment Link E2E.
9. Add hosted browser payment.
10. Add real reconciliation and idempotence.
11. Add a second Kimai version to prove the matrix mechanism.
12. Expand the true E2E scenarios only when they prove a real integration property not already covered more deterministically below.

This sequence makes the first true E2E failure diagnosable because lower layers already establish whether Kimai/plugin mechanics and connector decision logic work independently.

## Open Questions

The following implementation questions remain unresolved:

- Which Docker Compose layout should live in the repository?
- Which database image/version should be used for each Kimai matrix entry?
- Should the browser runner use Playwright directly, a PHP browser tool, or another harness?
- Should E2E orchestration be Bash, Make, Composer scripts, a test framework, or a combination?
- How should Kimai fixtures be seeded most cleanly: API, console commands, database fixtures, or browser/UI actions?
- How should a real invoice document be retrieved and inspected in E2E?
- Which Stripe restricted-key permissions are sufficient for E2E?
- How should Stripe test objects be tagged for cleanup?
- Should Stripe E2E run on every trusted PR, only on main, on demand, nightly, or before release?
- What exact Kimai versions constitute the first compatibility matrix?
- Should card payments be the only initially supported E2E payment method?
- If ACH or another asynchronous method is desired, what lifecycle behavior must be proven before declaring it supported?
- How should refunds/disputes be generated safely and deterministically in the Stripe sandbox?
- Which failures should trigger automatic E2E retry?
- What artifacts are safe to retain in a public CI environment?

These questions should be resolved during implementation rather than guessed prematurely.

## Upstream References

### Kimai

- Docker Compose: <https://www.kimai.org/documentation/docker-compose.html>
- Create plugins: <https://www.kimai.org/documentation/plugins.html>
- Plugin management: <https://www.kimai.org/documentation/plugin-management.html>

Relevant current documented properties include port `8001` for the Docker setup and plugin storage/mounting under `/opt/kimai/var/plugins`.

### Stripe

- Testing and sandboxes: <https://docs.stripe.com/testing>

Stripe currently documents:

- isolated sandboxes/test environments that do not move funds;
- use of test API keys;
- interactive use of documented test cards in payment forms;
- successful Visa test value `4242 4242 4242 4242`;
- decline, refund, dispute, 3D Secure, and non-card testing scenarios;
- a warning not to use testing environments for load testing because of rate limits.

## Related Project Documents

- [`README.md`](../../README.md)
- [`AGENTS.md`](../../AGENTS.md)
- [`doc/project-handoff.md`](../project-handoff.md)
- [`ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation`](../adr/ADR-001-single-plugin-architecture.md)
- [`Pre-Implementation Kimai / Stripe Mock Exercise`](preimplementation-mock-exercise.md)
