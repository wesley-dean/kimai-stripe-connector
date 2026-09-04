# Containerized Kimai / Stripe Sandbox End-to-End Testing

Date: 2026-09-04

## Status and Purpose

This document defines the real-system E2E strategy for the Kimai Stripe Connector
and records the subset that is currently executable and the subset that has been
actually proven against Stripe.

The repository contains three related real-system layers:

1. a disposable Kimai 2.65.0 + MySQL plugin-lifecycle harness under `tests/e2e/`;
2. a manual real-Stripe sandbox contract probe that exercises the locked Stripe
   PHP SDK with a restricted test credential; and
3. the Phase 7b hosted-payment harness that constructs a real disposable Kimai
   invoice, waits for one human-completed Stripe-hosted test-card Checkout, runs
   real reconciliation, and verifies the exact Kimai paid-state transition and
   idempotent rerun.

The Phase 3 restricted-key contract probe has already passed against real Stripe.
The Phase 7b hosted-payment harness is implemented on the Phase 7b branch, but the
stronger hosted-payment claim remains **pending an actual successful manual sandbox
workflow run**.  Repository code alone is not evidence that a browser Checkout was
completed.

The canonical E2E goal is:

> Run the real connector inside a fresh real Kimai container, communicate outbound
> with the real Stripe API in sandbox/test mode, complete Stripe-hosted card
> Checkout, prove the exact Stripe object chain, run real connector reconciliation,
> verify the exact Kimai invoice state, preserve useful failure evidence, and
> destroy the test-owned Kimai/database environment before the run ends.

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

## Disposable Kimai Lifecycle Harness

The ordinary non-credentialed disposable Kimai harness is:

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
- the exact self-contained plugin artifact shape produced by `tools/package.sh`.

Current lifecycle flow:

```text
fresh Compose project
-> fresh MySQL/Kimai/plugin volumes
-> build self-contained plugin ZIP
-> start Kimai + MySQL
-> install packaged plugin
-> kimai:reload
-> verify plugin bundle resolves
-> exercise disabled reconciliation and cleanup commands
-> verify Kimai CLI remains healthy
-> remove plugin
-> kimai:reload
-> verify plugin no longer resolves
-> destroy containers/network/volumes
```

The harness proves that the connector remains an ordinary removable Kimai plugin
and does not require Kimai core modification.

## Existing Real Stripe Contract Probe

The durable manual workflow is:

```text
.github/workflows/stripe-sandbox.yml
```

The Phase 3 contract probe is:

```text
tests/e2e/stripe-sandbox-smoke.php
```

The workflow is manual-only through `workflow_dispatch` and references GitHub
Actions environment:

```text
stripe-sandbox
```

with environment secret:

```text
STRIPE_SANDBOX_RESTRICTED_KEY
```

Checkout, PHP setup, and dependency installation do not receive the Stripe secret.
Only steps that actually need Stripe access receive it through environment-secret
injection.

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

See [`../stripe-restricted-key.md`](../stripe-restricted-key.md) for operator-facing
details.

The sandbox contract probe demonstrated that inline one-time
`price_data` / `product_data` creation succeeds through Payment Links `Write`
without separate Products or Prices permissions.

## Real Stripe Evidence Already Proven

The Phase 3 sandbox probe has verified:

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

The initial run created and deactivated the link successfully but exposed an
order-sensitive local metadata assertion.  The assertion was corrected to compare
canonical metadata key/value pairs.  Subsequent probes passed.

The probe refuses live-mode credentials and redacts the known key from exception
text before diagnostics are written.

## Phase 7b Hosted-Payment Harness

The hosted-payment implementation consists of:

```text
tests/e2e/compose.stripe.yaml
tests/e2e/run-stripe-hosted.sh
tests/e2e/stripe-hosted-evidence.php
.github/workflows/stripe-sandbox.yml
```

`compose.stripe.yaml` activates Payment Link generation and reconciliation only for
the credentialed disposable run.  It references runtime environment variables; it
does not contain a Stripe key.

`run-stripe-hosted.sh` creates a fresh disposable Kimai deployment and a fresh
`KIMAI_STRIPE_CONNECTOR_INSTANCE_ID` for every run.  A new UUID is correct for this
fixture because each run is a genuinely new Kimai installation.  It also prevents
old deactivated sandbox Payment Links from becoming same-invoice evidence in a
later disposable deployment.

The harness creates a deterministic `$12.34` USD invoice fixture.  Customer,
project, activity, and timesheet data are created through Kimai's API.  A narrow
run-owned bearer token and invoice-template row are test setup seams.  The invoice
itself is created through Kimai's supported:

```text
kimai:invoice:create
```

command so the production `InvoicePreRenderEvent` subscriber creates or recovers
the Stripe Payment Link exactly as it does for a real Kimai invoice.

The resulting canonical flow is:

```text
fresh disposable Kimai + MySQL
-> fresh Kimai instance UUID
-> build and install self-contained plugin ZIP
-> create exact $12.34 USD billable fixture
-> create real Kimai invoice
-> production subscriber creates exactly one Payment Link
-> verify Payment Link metadata / amount / currency / card-only restrictions
-> print Stripe-hosted Checkout URL
-> human completes Stripe public success-card payment
-> wait for real Checkout Session / PaymentIntent / Charge
-> prove exact metadata / money / relationships / successful captured state
-> verify Kimai remains unpaid before reconciliation
-> kimai:stripe:reconcile --dry-run => SUCCESS would_change=yes changed=no
-> kimai:stripe:reconcile => SUCCESS would_change=yes changed=yes
-> verify exact Kimai invoice status=paid and paymentDate exists
-> kimai:stripe:reconcile again => NO_CHANGE would_change=no changed=no
-> deactivate run-owned Payment Link(s)
-> destroy all disposable Kimai/MySQL state
```

The evidence helper deliberately checks the same success predicates that govern
production reconciliation.  It requires:

- exact connector metadata on the Payment Link, Checkout Session, and
  PaymentIntent;
- exact Kimai instance, invoice number, amount, and currency;
- exactly one compatible active Payment Link;
- exactly one line item with quantity one;
- card-only payment method and one-completed-session restriction;
- Checkout Session `complete` + `paid`;
- PaymentIntent `succeeded` with exact amount received;
- matching Session -> PaymentIntent -> Charge relationships;
- Charge `paid=true` and `captured=true`;
- exact captured amount;
- zero refunded amount;
- `refunded=false`; and
- `disputed=false`.

The helper also requires a real Charge creation timestamp.  Production
reconciliation remains responsible for applying that timestamp to Kimai's payment
date; the helper does not mutate Kimai accounting state.

## Human Interaction Boundary

The hosted proof intentionally does not automate the Stripe-hosted browser page.
The manual workflow prints an `ACTION REQUIRED` block containing the run-owned
Payment Link and Stripe's public successful test-card data.  The job then waits up
to 15 minutes by default for the real payment object chain.

This keeps the credential-bearing workflow small and avoids adding browser
automation solely to enter public test-card data.  It also makes the human action
explicit: the workflow cannot silently manufacture the payment state that it is
supposed to prove.

The browser visits Stripe only.  Kimai remains private and receives no Stripe
callback or webhook.

## Ephemeral Kimai State

Kimai/database E2E state must remain test-owned and ephemeral.

Both disposable harnesses use run-specific Compose projects and EXIT/INT/TERM
traps that ultimately perform:

```text
docker compose down --volumes --remove-orphans
```

Normal E2E runs must not reuse the long-lived dogfooding/reference Kimai instance.

## Stripe-Side Isolation and Cleanup

Stripe objects cannot be destroyed by Docker teardown.

Each hosted run's fresh Kimai instance UUID is the Stripe-side isolation boundary.
The exit trap invokes the evidence helper before local teardown and deactivates
only connector-managed active Payment Links whose metadata contains that exact
run-owned instance UUID.

This cleanup is deliberately narrow:

- it never searches by broad invoice-number pattern;
- it never deactivates another instance's links;
- it never performs global sandbox cleanup; and
- it refuses live-mode credentials.

A failed cleanup makes an otherwise successful E2E run fail rather than silently
leaving an active customer payment surface behind.

Paid test evidence can remain visible in Stripe's sandbox history; deactivation
only prevents further completed Checkout Sessions through the test Payment Link.

## Network Property

The accepted topology remains outbound-only:

```text
Kimai + connector ----outbound HTTPS----> Stripe API
browser/operator ----outbound HTTPS----> Stripe-hosted Checkout
Stripe ----------------X----------------> Kimai
                      no webhook
```

Kimai may be reachable from the local/CI runner for testing.  It must not become
Internet-facing merely for Stripe integration.

## Production-Shaped Plugin Rule

Development E2E must install the plugin as users receive it, including the
plugin-local production dependency graph from the committed lockfile.

Do not copy the repository-root development `vendor/` tree into Kimai.  Both
lifecycle and hosted-payment E2E delegate packaging to the same ADR-012 packager
that creates the installable release artifact.

This avoids development Symfony/PSR packages shadowing Kimai's own runtime
dependencies and prevents E2E from proving a package shape different from the one
intended for release.

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

No live Stripe key may be used without explicit owner authorization.  The hosted
harness independently rejects a credential identified as live mode.

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

Use real Stripe only for contracts that Stripe itself must prove.  Keep timeout
injection, malformed responses, races, and other adversarial conditions in
deterministic lower layers.

## Current and Future E2E Scenarios

| Scenario                                           | Status                                                     | Purpose                                                    |
|----------------------------------------------------|------------------------------------------------------------|------------------------------------------------------------|
| E2E-001 plugin lifecycle                           | Implemented and CI-exercised                               | Install/reload/remove/reload with healthy disposable Kimai |
| E2E-002 preview causes no Stripe mutation          | Lower-layer implemented; full real-system proof optional   | Preserve preview safety                                    |
| E2E-003 successful invoice/card/reconciliation     | Phase 7b harness implemented; real manual run pending      | Canonical business flow                                    |
| E2E-004 exact amount/currency/metadata             | Payment Link subset proven; hosted harness adds paid chain | Prove exact Stripe representation                          |
| E2E-005 declined card leaves Kimai unpaid          | Lower-layer policy covered; real hosted decline optional   | False-positive prevention                                  |
| E2E-006 reconciliation idempotence                 | Phase 7b harness implemented; real manual run pending      | Safe rerun behavior                                        |
| E2E-007 correct invoice among multiple invoices    | Lower-layer implemented; real-system expansion optional    | Exact target selection                                     |
| E2E-008 plugin disabled/manual fallback            | Lower-layer/lifecycle evidence exists                      | Preserve manual operation                                  |
| E2E-009 orphan recovery                            | Controlled layer implemented; real-system subset optional  | Partial-failure recovery                                   |
| E2E-010 refund/dispute does not reverse paid state | Lower-layer implemented; real paid audit scenario optional | Historical-state conservatism                              |
| E2E-011 3DS card behavior                          | Deferred unless explicitly claimed                         | Authentication-required card support                       |

## Phase 7b Acceptance Boundary

Phase 7b is not complete merely because the harness exists or ordinary PR CI is
green.  Acceptance requires one successful manual `Stripe Sandbox` workflow run on
the exact candidate head that demonstrates:

1. contract probe success with the restricted key;
2. real disposable Kimai invoice creation;
3. exactly one connector-created Payment Link;
4. human-completed Stripe-hosted test-card Checkout;
5. exact real Session / PaymentIntent / Charge evidence;
6. Kimai still unpaid immediately before reconciliation;
7. dry-run predicts the paid transition without mutating;
8. real reconciliation marks the exact invoice paid;
9. the payment date is populated;
10. the immediate rerun returns `NO_CHANGE`; and
11. run-owned Stripe Payment Link deactivation succeeds.

Until that workflow succeeds, documentation and release claims must say
"implemented, real sandbox proof pending" rather than "proven" or "complete."

## Completion Criteria for Full Real Stripe E2E

The complete canonical E2E layer is operational when the repository has:

1. secret-safe configuration and logs;
2. stable production instance identity and fresh disposable test identity;
3. exact integer money conversion;
4. version-1 metadata;
5. Payment Link creation/recovery;
6. reconciliation command and strong-evidence decision logic;
7. verified restricted-key permissions;
8. run-specific Stripe object isolation;
9. successful canonical hosted card flow;
10. exact Kimai paid-state verification;
11. idempotent reconciliation rerun; and
12. trigger/secret rules that prevent credential disclosure to untrusted code.

Items 1 through 8 and the executable mechanisms for 9 through 12 are represented
in the repository.  Items 9 through 12 become real-system evidence only after the
manual hosted workflow succeeds on the candidate implementation.

## Related Documentation

- [`test-matrix.md`](test-matrix.md)
- [`preimplementation-mock-exercise.md`](preimplementation-mock-exercise.md)
- [`../../tests/e2e/README.md`](../../tests/e2e/README.md)
- [`../stripe-restricted-key.md`](../stripe-restricted-key.md)
- [`../configuration.md`](../configuration.md)
- [`../threat-model.md`](../threat-model.md)
- [`../implementation-playbook.md`](../implementation-playbook.md)
