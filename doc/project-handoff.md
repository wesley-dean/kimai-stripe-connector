# Project Handoff

Date: 2026-09-04

## Purpose

This document gives a new human or AI/LLM engineering session enough current
context to resume `kimai-stripe-connector` without the conversation that produced
the project.

For implementation work, read this document, `implementation-playbook.md`,
`decisions.md`, and the relevant Accepted ADRs.  For routine operation and incident
response, use `operator-runbook.md`.

Do not merge, release, make the repository public, use live Stripe credentials, or
perform live financial mutation without explicit owner authorization.

## Current State

Completed and merged:

```text
Phase 1 -> PR #5 foundation / production-shaped Kimai packaging
Phase 2 -> PR #6 configuration, identity, money, metadata, result primitives
Phase 3 -> PR #8 Payment Link capability + real Stripe sandbox contract probe
Phase 4 -> PR #9 conservative reconciliation + Kimai paid-state persistence
Phase 5 -> PR #10 conservative orphan cleanup
```

Phase 5 was owner-reviewed, accepted, and merged on 2026-09-04.  Its merge commit
is:

```text
fc62c2f3db0187b4b0be5b20f08da66ebf203378
```

Current work is **Phase 6: Operator Runbook** on:

```text
docs/phase-6-operator-runbook
```

with draft PR #11:

```text
docs: add phase 6 operator runbook
```

PR #11 adds operator guidance for already merged behavior.  It must not broaden
Stripe permissions, connector mutation authority, supported compatibility, or
accounting automation.

All numbered ADRs 000 through 013 are Accepted.  ADR-009 governs reconciliation,
ADR-010 governs cleanup, ADR-011 governs partial-failure/idempotency behavior, and
ADR-013 supplies the shared operator result taxonomy and process exit codes.

## Architecture Boundaries

Unless a later Accepted ADR changes them:

- one Kimai plugin contains Payment Link and reconciliation capabilities with
  shared common primitives;
- cleanup is an operator-invoked maintenance action on the reconciliation side
  rather than a third independently configurable capability;
- Kimai is authoritative for invoice/business state;
- Stripe is authoritative for payment movement and Stripe objects;
- there is no connector-owned payment ledger/database;
- Kimai core and root Composer state are not patched;
- no public Kimai ingress or Stripe webhook is required;
- Stripe Payment Links remain the customer payment surface;
- Payment Link generation and reconciliation can be enabled independently;
- manual Payment Link creation and manual reconciliation remain permanent
  fallbacks;
- ambiguity causes no automatic financial mutation;
- false negatives are preferred to false-positive financial state changes;
- paid orphans are retained for accounting review;
- refunds/disputes never automatically reverse historical Kimai paid state; and
- cleanup retains uncertainty rather than treating age alone as permission to
  mutate.

## Compatibility Baseline

The current validated baseline remains:

```text
Kimai:             2.65.0
PHP:               >=8.2 <8.6
Stripe PHP SDK:    ^21.3.1
Payment methods:   card only
Money:             integer minor units
Currency scope:    ordinary two-decimal currencies
Metadata version:  1
Stripe credential: one least-privilege restricted key
```

Do not broaden compatibility claims without executable evidence.

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

Payment Link deactivation in Phase 5 uses the already-required Payment Links Write
permission.  No Phase 5 permission expansion was required.

The sandbox key is stored in GitHub Actions environment `stripe-sandbox` as secret
`STRIPE_SANDBOX_RESTRICTED_KEY`.  Never paste it into chat, source, docs, PR
comments, committed `.env`, Compose files, or test fixtures.

## Merged Reconciliation Behavior

Phase 4 provides:

```text
php bin/console kimai:stripe:reconcile
php bin/console kimai:stripe:reconcile --dry-run
```

Reconciliation:

- discovers connector-managed Payment Links for the configured Kimai instance;
- reads Checkout Session -> PaymentIntent -> Charge evidence through Stripe;
- requires exact metadata, invoice identity, amount, currency, and object
  relationships;
- changes Kimai toward paid only with strong mutually consistent evidence;
- never accepts a caller-supplied invoice number or desired state;
- re-reads Kimai immediately before persistence;
- uses Stripe Charge `created` as the source payment timestamp;
- persists through Kimai `InvoiceService::saveInvoice()`;
- treats already-paid state as an idempotent no-op; and
- never automatically reverses historical paid state for later refund/dispute.

There is no internal distributed reconciliation lock.  Schedulers SHOULD avoid
overlapping runs.

## Merged Cleanup Behavior

Phase 5 provides:

```text
php bin/console kimai:stripe:cleanup
php bin/console kimai:stripe:cleanup --dry-run
```

ADR-010 permits automatic deactivation only for a **proven stale unpaid orphan**.

Cleanup must prove:

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

Automatic cleanup currently recognizes only:

- a managed Payment Link with no Checkout Sessions; or
- an `expired` + `unpaid` Checkout Session with no PaymentIntent/Charge; or
- an `expired` + `unpaid` Checkout Session whose PaymentIntent is `canceled` or
  `requires_payment_method`, has received zero money, has no latest Charge, and has
  no Charge evidence.

Open, processing, unknown, contradictory, paid, refunded, disputed, or otherwise
ambiguous evidence is retained.

Before a live deactivation, cleanup repeats:

1. Kimai invoice lookup;
2. current-instance managed Payment Link discovery;
3. exact-one-link proof;
4. Stripe child payment-evidence discovery; and
5. pure cleanup planning.

The Stripe writer then retrieves the selected target again and verifies exact ID,
metadata, creation timestamp, and active state before setting:

```text
active=false
```

It cannot delete Payment Links or perform generalized Stripe updates.

## Operator Result Contract

Reconciliation and cleanup share ADR-013 process semantics:

```text
SUCCESS / NO_CHANGE       0
RETRYABLE                10
CONFIGURATION_ERROR      20
CONFLICT                 30
MANUAL_REVIEW_REQUIRED   40
INTERNAL_ERROR           50
```

For independent invoice groups, a batch may continue when safe and returns the
highest mapped code.

Phase 6 explicitly documents scheduler/operator handling for these categories.
In particular, `MANUAL_REVIEW_REQUIRED` is an accounting/operator work item rather
than a condition that should be retried indefinitely.

## Phase 5 Final Validation

The final Phase 5 head before merge was:

```text
e1ef10b08f66e56297a6ec84a70721b9983c6c6b
```

Its exact-head validation completed successfully:

- PHP 8.2, 8.3, 8.4, and 8.5: success;
- PHPUnit: 206 tests / 481 assertions;
- PHPStan: no errors;
- PHPCS: clean;
- PHP-CS-Fixer: zero fixable files;
- Documentation: success;
- disposable Kimai E2E: success; and
- MegaLinter: success with Grype enabled and every active linter at zero errors
  and zero warnings.

The final MegaLinter run included Grype, Checkov, Trivy, Trivy-SBOM, Syft,
TruffleHog, secretlint, PHPStan, PHPCS, markdownlint, cspell, action/workflow
linters, and the remaining configured active linters.

## Phase 6 Operator Runbook

`doc/operator-runbook.md` is the current Phase 6 artifact.

It covers:

- routine reconciliation and cleanup command use;
- ADR-013 scheduler handling;
- Stripe paid / Kimai unpaid;
- Kimai failure after Payment Link creation;
- conflicting or multiple Payment Links;
- Stripe restricted-key permission failure;
- restricted-key rotation;
- paid Stripe orphans;
- cleanup uncertainty;
- reconciliation outages;
- Kimai compatibility failures after upgrade;
- disabling connector behavior;
- removing the plugin;
- return to manual billing;
- post-incident recovery; and
- safe evidence preservation.

The runbook deliberately treats manual fallback as a supported operating mode, not
an emergency-only exception.

## Manual Billing Fallback

The connector is optional.  Billing can continue as:

```text
track time in Kimai
-> generate invoice
-> create Stripe Payment Link manually
-> include URL in customer-facing invoice workflow
-> send invoice
-> verify payment directly in Stripe
-> mark exact Kimai invoice paid manually
```

Do not infer payment merely from link/session existence.  Preserve actual Stripe
payment evidence and resolve refunds/disputes as accounting events.

## Production-Shaped Packaging Rule

The disposable Kimai E2E stages a production-shaped plugin from the committed
lockfile using:

```text
composer install --no-dev
```

Do not copy the repository-root development `vendor/` tree into Kimai.  Production
packages carry only plugin production dependencies under ADR-012.  Test-only
`App\...` Kimai mirrors under `tests/stubs/` are development autoload only and must
never appear in the production-shaped artifact.

A separate Phase 7 follow-up remains: verify that release automation actually
produces the self-contained production dependency shape required by ADR-012.  Do
not silently release or rewrite release semantics during Phase 6.

## Real Stripe E2E Gap

The canonical full payment proof remains future Phase 7 evidence:

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

## Phase 7 Preview

After Phase 6 is accepted, the implementation playbook moves to compatibility and
release evidence.  Important known work includes:

- full hosted sandbox payment -> reconciliation evidence;
- verifying release automation emits an ADR-012 self-contained production
  artifact rather than only a source release;
- supported compatibility evidence beyond the current Kimai 2.65.0 baseline if
  desired;
- install/disable/remove evidence;
- secret/log review;
- full clean quality/security gates; and
- resolving the public software-license boundary before any public release.

Do not publish a release without explicit owner authorization.

## Resume Checklist

A new engineering session should:

1. inspect draft PR #11, its current head, CI, comments, and review threads;
2. read this handoff, implementation playbook, operator runbook, decision index,
   ADR-009, ADR-010, ADR-011, ADR-012, ADR-013, and test matrix;
3. treat merge commit `fc62c2f3db0187b4b0be5b20f08da66ebf203378`
   as the Phase 6 baseline;
4. preserve the verified Stripe permission set and stable Kimai instance UUID;
5. preserve shared exact money and version-1 metadata semantics;
6. keep cleanup stricter than ordinary reconciliation `NO_CHANGE`;
7. preserve repeated cleanup revalidation and the narrow `active=false` mutation
   seam;
8. keep operator guidance fail-closed and manual-fallback capable;
9. treat full hosted paid E2E as unproven until actually executed; and
10. never merge, release, make the repository public, use live Stripe credentials,
    or perform live financial mutation without explicit owner authorization.
