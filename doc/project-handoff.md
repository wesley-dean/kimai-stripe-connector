# Project Handoff

Date: 2026-09-04

## Purpose

This document gives a new human or AI/LLM engineering session enough current context to resume `kimai-stripe-connector` without the conversation that produced the project.

For implementation work, read this document, [`implementation-playbook.md`](implementation-playbook.md), and [`decisions.md`](decisions.md).  Accepted ADRs and explicit source contracts remain authoritative over this handoff.

Do not merge, release, make the repository public, use live Stripe credentials, or perform live financial mutation without explicit owner authorization.

## Current State

Completed and merged:

```text
Phase 1 -> PR #5 foundation / production-shaped Kimai packaging
Phase 2 -> PR #6 configuration, identity, money, metadata, result primitives
Phase 3 -> PR #8 Payment Link capability + real Stripe sandbox contract probe
Phase 4 -> PR #9 conservative reconciliation + Kimai paid-state persistence
```

Phase 4 was owner-reviewed, accepted, and merged on 2026-09-04.  Its merge commit is:

```text
f8420fdcebcd4a3e9c69dd0f2bbafa457b71a927
```

Current work is **Phase 5: Conservative Orphan Cleanup** on:

```text
feat/phase-5-cleanup
```

with draft PR #10:

```text
feat: implement phase 5 conservative cleanup
```

PR #10 was intentionally opened early to obtain CI feedback while implementation and documentation are still changing.  Inspect its current tip, CI, review threads, and MegaLinter report before resuming.  Never merge it without explicit owner approval.

All numbered ADRs 000 through 013 are Accepted.  ADR-010 governs cleanup.  ADR-013 supplies the shared operator result taxonomy and process exit-code vocabulary.

## Architecture Boundaries

Unless a later Accepted ADR changes them:

- one Kimai plugin contains Payment Link and reconciliation capabilities with shared common primitives;
- cleanup is an operator-invoked maintenance action on the reconciliation side rather than a third independently configurable capability;
- Kimai is authoritative for invoice/business state;
- Stripe is authoritative for payment movement and Stripe objects;
- there is no connector-owned payment ledger/database;
- Kimai core and root Composer state are not patched;
- no public Kimai ingress or Stripe webhook is required;
- Stripe Payment Links remain the customer payment surface;
- Payment Link generation and reconciliation can be enabled independently;
- manual Payment Link creation and manual reconciliation remain permanent fallbacks;
- ambiguity causes no automatic financial mutation;
- false negatives are preferred to false-positive financial state changes;
- paid orphans are retained for accounting review;
- refunds/disputes never automatically reverse historical Kimai paid state; and
- cleanup retains uncertainty rather than treating age alone as permission to mutate.

## Phase 4 Baseline

Phase 4 is now the merged baseline, not pending work.

It provides:

- read-only Stripe discovery of connector-managed Payment Links, Checkout Sessions, PaymentIntents, and Charges;
- pure fail-closed reconciliation planning;
- exact amount/currency/metadata/object-relationship validation;
- paid-orphan and refund/dispute reporting without automatic reversal;
- shared exact conversion of Kimai framework floats through `Money::fromFloat()`;
- last-moment Kimai invoice revalidation before a paid-state write;
- payment dates derived from the proven Stripe Charge `created` timestamp;
- persistence through Kimai 2.65 `InvoiceService::saveInvoice()`;
- `kimai:stripe:reconcile` and `--dry-run`; and
- stable ADR-013 process exit codes.

The merged Phase 4 validation checkpoint contained 171 PHPUnit tests / 380 assertions across PHP 8.2 through 8.5, PHPStan level 8, PHPCS, PHP-CS-Fixer check mode, Documentation, disposable Kimai E2E, and MegaLinter with every enabled linter at zero errors and zero warnings.

A full hosted Stripe test-card Checkout through real reconciliation remains deliberately unproven.  Do not imply that lower-layer green tests prove it.

## Stripe Permission Baseline

The verified restricted sandbox-key permissions remain:

```text
Payment Links:      Write
Checkout Sessions: Read
Payment Intents:   Read
Charges:            Read
Products:           None
Prices:             None
Everything else:   None
```

Phase 5 expects no permission expansion.  Payment Link deactivation uses the already-required Payment Links Write permission.

The sandbox key is stored in GitHub Actions environment `stripe-sandbox` as secret `STRIPE_SANDBOX_RESTRICTED_KEY`.  Never paste it into chat, source, docs, PR comments, committed `.env`, Compose files, or test fixtures.  Do not broaden permissions without executable evidence that the current least-privilege set is insufficient.

## Phase 5 Cleanup Contract

ADR-010 allows automatic deactivation only for a **proven stale unpaid orphan**.

Cleanup must prove all of these facts before mutation:

```text
connector-managed Payment Link
+ current Kimai instance namespace
+ successful Kimai lookup
+ no matching Kimai invoice
+ exactly one managed Payment Link for the logical invoice
+ Payment Link at or beyond configured grace age
+ current Payment Link active state
+ no successful payment evidence
+ no open/in-progress/unsupported payment ambiguity
+ conclusively unpaid observed Stripe child evidence
+ successful revalidation immediately before the write
```

If any fact cannot be proven, cleanup retains the Payment Link.

Paid orphans are `MANUAL_REVIEW_REQUIRED`.  Multiple competing managed links are `CONFLICT`.  Kimai/Stripe read failures never authorize cleanup.

### Conclusively unpaid evidence

The current Phase 5 planner is intentionally narrower than reconciliation's ordinary `NO_CHANGE` result.  Automatic cleanup currently recognizes only:

- a managed Payment Link with no Checkout Sessions; or
- an `expired` + `unpaid` Checkout Session with no PaymentIntent/Charge; or
- an `expired` + `unpaid` Checkout Session whose PaymentIntent is `canceled` or `requires_payment_method`, has received zero money, has no latest Charge, and has no Charge evidence.

An `open` Checkout Session, a processing PaymentIntent, an unknown future Stripe status, partial financial evidence, a Charge, or other unsupported state is retained for review rather than inferred to be unpaid.

## Phase 5 Production Shape

New source lives under `Cleanup/`.

Important classes currently include:

```text
CleanupPlan
CleanupPlanner
CleanupItemOutcome
CleanupGatewayException
CleanupStripeGateway
StripeCleanupGateway
CleanupService
CleanupServiceFactory
StripeCleanupServiceFactory
CleanupCommand
```

Phase 5 deliberately reuses these Phase 4 read boundaries:

```text
ReconciliationStripeGateway / StripeReconciliationGateway
ReconciliationInvoiceStore / KimaiReconciliationInvoiceStore
ManagedPaymentLinkEvidence
ReconciliationCandidate and child evidence objects
ReconciliationPlanner
```

`ManagedPaymentLinkEvidence` now optionally retains Stripe Payment Link `created` and `active` fields.  Production Stripe discovery populates them when available; cleanup fails closed if the age or active-state evidence needed for mutation is unavailable.

## Cleanup Stripe Mutation Boundary

`StripeCleanupGateway` is intentionally narrow.  Its only mutation is:

```text
Payment Link active=true -> active=false
```

Before that update it retrieves the target Payment Link and verifies:

- exact Payment Link ID;
- exact connector metadata;
- unchanged Stripe creation timestamp; and
- current active state.

It cannot delete Payment Links or perform generalized Stripe updates.

The update is monotonic and idempotent.  If an API timeout occurs after Stripe may have committed the update, the operation reports `RETRYABLE`; a later run can retrieve the link and observe that it is already inactive.

## Cleanup Revalidation / Concurrency

There is no transaction spanning Kimai and Stripe and no connector-owned distributed lock.

For a planned non-dry-run cleanup, `CleanupService` currently performs:

1. successful Kimai lookup proving no invoice;
2. Stripe payment-evidence discovery;
3. pure cleanup planning;
4. a second Kimai lookup immediately before mutation;
5. a second Stripe child-evidence discovery;
6. a second pure cleanup plan; and
7. `StripeCleanupGateway` retrieval/revalidation immediately before `active=false`.

This closes preventable TOCTOU windows without adding a connector database or distributed locking service.  A very small distributed race remains between the final Stripe read and Stripe's update.  The project does not claim impossible cross-system atomicity.

## Cleanup Command

Phase 5 adds:

```text
php bin/console kimai:stripe:cleanup
php bin/console kimai:stripe:cleanup --dry-run
```

The command accepts no invoice number, Payment Link ID, or desired state.  Callers cannot use it as a generic Stripe mutation endpoint.

Cleanup is available only when both of these existing switches are enabled:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED=true
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true
```

There is intentionally no `KIMAI_STRIPE_CONNECTOR_CLEANUP_ENABLED` variable in the current two-capability architecture.

The grace period remains:

```text
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS
```

with default 7, minimum 1, and maximum 3650 days.

The cleanup command uses the ADR-013 process codes already established by reconciliation:

```text
SUCCESS / NO_CHANGE       0
RETRYABLE                10
CONFIGURATION_ERROR      20
CONFLICT                 30
MANUAL_REVIEW_REQUIRED   40
INTERNAL_ERROR           50
```

For independent invoice groups, the batch continues when safe and returns the highest mapped code.

## Phase 5 Executable Coverage

Current Phase 5 tests include:

- old vs in-grace vs exact-grace-boundary orphan age;
- existing Kimai invoice retention;
- missing/future Payment Link creation evidence;
- missing/already-inactive Payment Link state;
- multiple managed links for one invoice;
- paid orphan retention;
- open Checkout and processing PaymentIntent retention;
- conclusively failed/expired unpaid attempts;
- dry-run with no Stripe mutation;
- actual cleanup with repeated Kimai/Stripe revalidation;
- Kimai invoice appearing between planning and mutation;
- successful Stripe payment appearing between planning and mutation;
- retryable Stripe deactivation failure;
- locked Stripe SDK GET-then-POST request construction;
- exact Payment Link metadata revalidation; and
- cleanup command result/exit-code behavior.

The disposable Kimai E2E now invokes both `kimai:stripe:reconcile --dry-run` and `kimai:stripe:cleanup --dry-run` with the connector disabled.  This proves production-shape command registration/autowiring without requiring a Stripe credential or allowing a remote mutation.

## Current CI Note

The first draft-PR CI pass exposed one test-contract mismatch: the locked Stripe SDK form-encodes the update parameter as `active="false"`, while the integration assertion initially expected a PHP boolean.  Production code was correct; the test was corrected to assert the actual SDK wire representation.

Treat current CI as moving evidence while the branch is still changing.  Before recommending PR #10 for owner review, validate the exact final head across PHP 8.2-8.5, Documentation, disposable Kimai E2E, and every enabled MegaLinter linter.

`REPOSITORY_GRYPE` is temporarily disabled during the current rapid-development loop at owner direction because it adds substantial latency per commit.  This is a development-speed exception, not a policy change.  Re-enable it after the development flurry before relying on later full security-scan evidence.

## Production-Shaped Packaging Rule

The disposable Kimai E2E stages a production-shaped plugin from the committed lockfile using:

```text
composer install --no-dev
```

Do not copy the repository-root development `vendor/` tree into Kimai.  Production packages carry only plugin production dependencies under ADR-012.  Test-only `App\...` Kimai mirrors under `tests/stubs/` are development autoload only and must never appear in the production-shaped artifact.

A separate follow-up remains: verify that release automation actually produces the self-contained production dependency shape required by ADR-012.  Do not silently release or rewrite release semantics as part of Phase 5 without owner review.

## Real Stripe E2E Gap

The canonical full payment proof remains future work:

```text
real disposable Kimai invoice
-> connector-created Payment Link
-> Stripe-hosted sandbox card Checkout
-> real Session / PaymentIntent / Charge evidence
-> reconciliation command
-> exact Kimai paid-state transition
-> idempotent rerun
```

Do not use live credentials or real money to close this gap.

## Resume Checklist

A new engineering session should:

1. inspect draft PR #10, current head, CI, comments, and review threads;
2. read this handoff, implementation playbook, decision index, ADR-009, ADR-010, ADR-011, ADR-013, and test matrix;
3. treat merged Phase 4 commit `f8420fdcebcd4a3e9c69dd0f2bbafa457b71a927` as the Phase 5 baseline;
4. preserve the verified Stripe permission set;
5. preserve the shared exact money policy and Phase 4 reconciliation behavior;
6. keep cleanup stricter than ordinary reconciliation `NO_CHANGE` and retain ambiguous states;
7. preserve repeated Kimai/Stripe revalidation and the narrow `active=false` mutation seam;
8. maintain production-shaped Kimai packaging and dev-only test stubs;
9. treat full hosted paid E2E as unproven until actually executed; and
10. never merge, release, make the repository public, use live Stripe credentials, or perform live financial mutation without explicit owner authorization.
