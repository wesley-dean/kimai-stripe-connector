# Project Handoff

Date: 2026-09-04

## Purpose

This document gives a new human or AI/LLM engineering session enough current context to resume `kimai-stripe-connector` without the conversation that produced the project.

For implementation work, read this document, [`implementation-playbook.md`](implementation-playbook.md), and [`decisions.md`](decisions.md).  Accepted ADRs and explicit source contracts remain authoritative over this handoff.

Do not merge, release, use live Stripe credentials, or perform live financial mutation without explicit owner authorization.

## Current State

Completed and merged:

```text
Phase 1 -> PR #5 foundation / production-shaped Kimai packaging
Phase 2 -> PR #6 configuration, identity, money, metadata, result primitives
Phase 3 -> PR #8 Payment Link capability + real Stripe sandbox contract probe
```

Current work is **Phase 4: Reconciliation** on:

```text
feat/phase-4-reconciliation
```

with draft PR #9:

```text
feat: implement phase 4 reconciliation
```

Inspect current PR/head/CI state before resuming.  PR #9 remains draft until implementation, documentation, and final-head validation are complete.  Never merge it without explicit owner approval.

All numbered ADRs 000 through 013 are Accepted.  ADR-009 now also defines automatic payment-date semantics, and ADR-013 defines reconciliation CLI exit codes.

## Architecture Boundaries

Unless a later Accepted ADR changes them:

- one Kimai plugin contains Payment Link and reconciliation capabilities with shared common primitives;
- Kimai is authoritative for invoice/business state;
- Stripe is authoritative for payment movement and Stripe objects;
- there is no connector-owned payment ledger/database;
- Kimai core and root Composer state are not patched;
- no public Kimai ingress or Stripe webhook is required;
- Stripe Payment Links remain the customer payment surface;
- Payment Link generation and reconciliation can be enabled independently;
- manual Payment Link creation and manual reconciliation remain permanent fallbacks;
- ambiguity causes no automatic financial mutation;
- false negatives are preferred to false-positive paid-state transitions;
- paid orphans are retained for accounting review;
- refunds/disputes never automatically reverse historical Kimai paid state; and
- cleanup retains uncertainty.

## Phase 3 Baseline

Phase 3 provides:

- deterministic Payment Link planning/reuse/create/recovery;
- card-only fixed-amount Payment Links;
- one-completed-session restriction;
- version-1 metadata on Payment Links and generated PaymentIntents;
- deterministic idempotency keys;
- no blind second create after an uncertain commit;
- Kimai `InvoicePreRenderEvent` integration;
- preview suppression before configuration or Stripe access;
- safe invoice hydration exposing only the hosted URL; and
- real Stripe sandbox verification of the intended request/permission contract.

The verified restricted sandbox-key permissions remain:

| Stripe resource   | Permission |
|-------------------|------------|
| Payment Links     | Write      |
| Checkout Sessions | Read       |
| Payment Intents   | Read       |
| Charges           | Read       |
| Products          | None       |
| Prices            | None       |
| Everything else   | None       |

The key is stored in GitHub Actions environment `stripe-sandbox` as secret `STRIPE_SANDBOX_RESTRICTED_KEY`.  Never paste it into chat, source, docs, PR comments, committed `.env`, Compose files, or test fixtures.  Do not broaden permissions without executable evidence that the current least-privilege set is insufficient.

## Phase 4 Reconciliation Model

Phase 4 treats reconciliation as an evidence problem before it becomes a persistence problem.

The production path is:

```text
connector-managed Payment Link metadata
-> Checkout Sessions for that Payment Link
-> PaymentIntent for each applicable Session
-> Charge for the successful PaymentIntent
-> pure fail-closed reconciliation planner
-> current Kimai invoice snapshot
-> optional last-moment Kimai persistence
```

Strong successful evidence requires exact and mutually consistent identity, amount, currency, Payment Link, Checkout Session, PaymentIntent, Charge, paid/captured state, and full captured amount.

Unknown, missing, unsupported, contradictory, partially captured, refunded, disputed, or ambiguous evidence never authorizes an automatic paid transition.

Repeated customer visits can create multiple Checkout Sessions for one Payment Link.  Those attempts are not treated as competing Payment Links.  A later successful Session can still prove payment after an abandoned visit.  Multiple successful payments or multiple competing logical Payment Links remain conflicts/accounting anomalies.

## Reconciliation Production Classes

Important files under `Reconciliation/` include:

```text
CheckoutSessionEvidence
PaymentIntentEvidence
ChargeEvidence
ReconciliationCandidate
ReconciliationPlan
ReconciliationPlanner
ReconciliationService
ReconciliationStripeGateway
StripeReconciliationGateway
ReconciliationInvoice
ReconciliationInvoiceStore
KimaiReconciliationInvoiceStore
ReconciliationServiceFactory
StripeReconciliationServiceFactory
ReconciliationItemOutcome
ReconcileCommand
```

The planner is pure.  The Stripe gateway is read-only.  Kimai mutation exists only behind `ReconciliationInvoiceStore`.

## Stripe Discovery Boundary

`StripeReconciliationGateway` uses the locked Stripe PHP SDK and performs GET-only reconciliation discovery:

```text
Payment Links list, paginated
Checkout Sessions list by Payment Link, paginated
PaymentIntent retrieve
Charge retrieve
```

Connector Payment Links are scoped by stable Kimai instance UUID before their remaining metadata is parsed.  Malformed connector metadata for another Kimai instance is ignored; malformed connector metadata for this instance fails closed for operator review.

The gateway retains Stripe Charge `created` as evidence metadata for payment-date persistence.  The timestamp does not participate in heuristic identity matching.

## Shared Money Boundary

Kimai 2.65 exposes invoice amounts as floats in both invoice generation and persisted invoice entities.

`Money::fromFloat()` is the single shared boundary for those framework floats.  It serializes the float to PHP's shortest round-trippable JSON decimal and then uses the exact decimal parser.  It performs no financial arithmetic and no rounding.

Therefore:

```text
875.1 -> exact 87510 minor units
0.1 + 0.2 -> 0.30000000000000004 -> reject / fail closed
```

Payment Link creation and reconciliation now use the same exact conversion policy.

## Kimai Persistence Boundary

`KimaiReconciliationInvoiceStore` uses verified Kimai 2.65 public APIs:

```text
App\Repository\InvoiceRepository
App\Entity\Invoice
App\Invoice\InvoiceService::saveInvoice()
```

The adapter does not manipulate Doctrine directly.

Before a paid transition it:

1. re-fetches the persisted Kimai invoice by invoice number;
2. rebuilds exact connector metadata from current Kimai identity, amount, and currency;
3. compares the current snapshot to the snapshot used during planning;
4. compares the proven Stripe metadata to the planned Kimai metadata;
5. returns an idempotent no-op if another process already marked the invoice paid;
6. requires the proven Charge timestamp;
7. sets Kimai's payment date;
8. calls `setIsPaid()`; and
9. persists through `InvoiceService::saveInvoice()` so Kimai's update pre/post events are preserved.

If Kimai identity/amount/currency changes between planning and persistence, the write stops as `CONFLICT`.  A missing invoice, malformed current invoice, unavailable persistence, or invariant failure also stops without guessing paid state.

## Payment-Date Policy

ADR-009 now states that an automatic paid transition records the payment date from the proven Stripe Charge `created` timestamp.

The timestamp is converted to the invoice creation timezone before Kimai stores its date-only payment field.  The reconciliation execution time is not used because it represents when polling noticed the payment rather than when Stripe recorded it.

## External Command

Phase 4 provides:

```text
php bin/console kimai:stripe:reconcile
php bin/console kimai:stripe:reconcile --dry-run
```

The command is intended for cron, Jenkins, systemd timers, or manual execution.

It accepts no invoice number and no desired financial state.  All mutations must be derived from configured Kimai identity + current Kimai state + Stripe evidence.

Human output includes the ADR-013 category and safe invoice number, planned/applied mutation booleans, and a safe reason.

Exit-code contract:

| Result | Exit code |
| --- | ---: |
| `SUCCESS` / `NO_CHANGE` | 0 |
| `RETRYABLE` | 10 |
| `CONFIGURATION_ERROR` | 20 |
| `CONFLICT` | 30 |
| `MANUAL_REVIEW_REQUIRED` | 40 |
| `INTERNAL_ERROR` | 50 |

Independent invoices may continue after item-level problems when safe.  The final batch process code is the highest mapped code among item results so scheduler-visible attention is never hidden.

Disabled reconciliation and no managed Payment Links are successful `NO_CHANGE` runs.

## Configuration

Configuration remains process-environment driven using:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE
```

Reconciliation can run while automatic Payment Link generation is disabled, provided the global connector switch, reconciliation switch, stable instance UUID, and restricted Stripe credential are present.

Direct and file-backed credential forms are mutually exclusive.  Broad `sk_*` credentials are rejected; restricted `rk_test_*` / `rk_live_*` shapes are required by the configuration primitive.  Never use a live credential during development/test without explicit owner authorization.

## Current Executable Coverage

Phase 4 repository tests cover:

- pure successful/failed/ambiguous reconciliation decisions;
- exact amount/currency/metadata/object relationships;
- partial capture;
- refunds/disputes without reversal;
- repeated Checkout attempts;
- multiple competing Payment Links;
- read-only Stripe SDK request construction and pagination;
- malformed same-instance vs foreign-instance metadata;
- retryable Stripe reads;
- paid and unpaid orphans;
- batch isolation;
- dry-run behavior;
- persistence failure;
- Kimai last-moment financial drift;
- concurrent already-paid idempotence;
- Stripe Charge payment-date application; and
- CLI configuration, dry-run, output categories, and exit codes.

The controlled Stripe tests live under `tests/Integration/` and are explicitly included in `phpunit.xml.dist`; they must actually execute under `composer check`.

See [`testing/test-matrix.md`](testing/test-matrix.md).

## Production-Shaped Packaging Rule

The disposable Kimai E2E harness stages a production-shaped plugin from the committed lockfile using:

```text
composer install --no-dev
```

Do not copy the repository-root development `vendor/` tree into Kimai.  Production packages carry only plugin production dependencies under ADR-012.  Test-only `App\...` Kimai mirrors under `tests/stubs/` are development autoload only and must never appear in the production-shaped artifact.

## Real Stripe Evidence and Remaining E2E Gap

The current real sandbox workflow/probe proves Payment Link creation and required read permissions, but it still does **not** prove:

- browser traversal of the Stripe-hosted link;
- successful test-card Checkout;
- real completed Checkout Session metadata;
- real paid PaymentIntent/Charge relationships;
- invocation of the reconciliation command against a disposable real Kimai invoice;
- exact Kimai paid-state persistence from that real payment; or
- idempotent rerun of that full flow.

Do not imply that lower-layer green tests prove those facts.

The eventual canonical E2E remains:

```text
real disposable Kimai invoice
-> connector-created Payment Link
-> Stripe-hosted sandbox card Checkout
-> Session / PaymentIntent / Charge evidence
-> reconciliation command
-> exact Kimai paid-state transition
-> idempotent reconciliation rerun
```

No additional Stripe permissions are expected for that flow based on the already verified sandbox key, but privilege must not be expanded preemptively.

## CI / Quality Gate

A Phase 4 head is not ready for owner merge review until all of the following pass on that exact head/PR merge ref:

- PHP 8.2, 8.3, 8.4, and 8.5;
- PHPUnit including `tests/Integration`;
- PHPStan level 8;
- PHPCS;
- PHP-CS-Fixer dry run;
- Documentation/Doxygen with zero warnings;
- disposable Kimai E2E; and
- every active MegaLinter linter at exactly zero errors and zero warnings.

Inspect the MegaLinter report itself.  A green workflow badge alone is insufficient.

Do not weaken a quality gate to make the branch pass.

## Next Work

For PR #9 itself:

1. finish any CI findings on the current Phase 4 persistence/command implementation;
2. keep README, ADRs, decision index, handoff, and test matrix synchronized;
3. review concurrency expectations and ensure the last-moment Kimai re-read/idempotent path is documented adequately;
4. refresh the PR #9 body to describe the actual implemented scope rather than its original pure-planner-only draft;
5. run and inspect all final-head quality gates; and
6. only then recommend owner review/approval.

A full hosted Stripe paid E2E may be added when it can be performed deterministically and safely with the existing sandbox credential and disposable Kimai.  Do not use live credentials or real money.

Phase 5 after Phase 4 is conservative orphan cleanup under ADR-010.

## Resume Checklist

A new engineering session should:

1. inspect PR #9, its head SHA, current draft state, and current CI;
2. read this handoff, implementation playbook, decision index, ADR-009, ADR-011, ADR-013, and test matrix;
3. preserve the verified Stripe permission set;
4. preserve the shared exact money policy and TOCTOU re-read before paid mutation;
5. keep reconciliation read-only on Stripe;
6. keep the command evidence-derived rather than accepting arbitrary financial-state instructions;
7. maintain production-shaped Kimai packaging and dev-only test stubs;
8. treat full hosted paid E2E as unproven until actually executed; and
9. never merge, release, make the repository public, use live Stripe credentials, or perform live financial mutation without explicit owner authorization.
