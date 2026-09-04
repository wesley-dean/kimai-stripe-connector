# Project Handoff

Date: 2026-09-04

## Purpose

This document gives a new human or AI/LLM engineering session enough current
context to resume `kimai-stripe-connector` without the conversation that produced
the project.

For implementation work, read this document, `implementation-playbook.md`,
`decisions.md`, `INSTALL.md`, and the relevant Accepted ADRs.  For routine
operation and incident response, use `operator-runbook.md`.

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
Phase 6 -> PR #11 operator runbook
Install docs -> PR #12 doc/INSTALL.md + README integration
Phase 7A -> PR #13 self-contained release artifact and release automation
```

The current `main` baseline is the PR #13 merge commit:

```text
2498f5e1fac06e5f59d62dc0bfc89764c75b3d4d
```

Current work is **Phase 7b: hosted Stripe payment proof** on:

```text
test/phase-7b-hosted-payment
```

The Phase 7b branch implements the executable canonical sandbox flow but has not
yet completed the one human Stripe-hosted test-card interaction required to turn
that implementation into real-system evidence.  Until the protected manual
workflow succeeds, describe Phase 7b as **implemented, real sandbox proof pending**.

All numbered ADRs 000 through 013 are Accepted.  ADR-009 governs reconciliation,
ADR-010 governs cleanup, ADR-011 governs partial-failure/idempotency behavior,
ADR-012 governs self-contained packaging, and ADR-013 supplies the shared operator
result taxonomy and process exit codes.

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

Payment Link deactivation uses the already-required Payment Links Write
permission.  No Phase 7b permission expansion is required.

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

Open, processing, unknown, contradictory, paid, refunded, disputed, or otherwise
ambiguous evidence is retained.

Before a live deactivation, cleanup repeats the Kimai lookup, managed-link
cardinality proof, Stripe child evidence, and pure planning.  The Stripe writer
then re-retrieves the target and validates identity/metadata/creation/active state
before the only permitted mutation:

```text
active=false
```

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

`MANUAL_REVIEW_REQUIRED` is an accounting/operator work item rather than a
condition that should be retried indefinitely.

## Merged Operator and Installation Documentation

`doc/operator-runbook.md` covers routine command use, scheduler handling,
incidents, credential rotation, orphans/conflicts, outages, disablement/removal,
and manual fallback.

`doc/INSTALL.md` covers installation, configuration, verification, staged
enablement, scheduling, upgrade, disablement, removal, troubleshooting, and the
manual billing fallback.

Both documents preserve fail-closed financial behavior and least privilege.

## Phase 7A: Self-Contained Release Artifact

PR #13 is merged.  It implements ADR-012 through a deterministic allowlist-based
packager.

The ordinary build is:

```text
make package VERSION=<version>
```

and produces:

```text
dist/KimaiStripeConnectorBundle-<version>.zip
dist/KimaiStripeConnectorBundle-<version>.zip.sha256
```

`tools/package.sh`:

- copies only runtime source directories and required plugin/project metadata;
- includes `doc/INSTALL.md`;
- runs `composer install --no-dev` from the committed lockfile in isolated staging;
- requires plugin-local `vendor/autoload.php`;
- rejects tests, CI/tooling files, and known require-dev dependency trees;
- normalizes permissions and timestamps;
- sorts archive entries deterministically; and
- writes the SHA-256 checksum next to the ZIP.

`make package-check`:

- builds the same version twice;
- requires byte-for-byte identical ZIPs;
- verifies the checksum;
- verifies required and forbidden paths;
- runs strict Composer validation inside the extracted artifact; and
- verifies the packaged Stripe SDK autoloads.

The dedicated Package workflow runs this contract on pull requests and `main`.
It does not publish a release.

Disposable Kimai E2E builds the same ZIP, extracts it, installs that exact artifact
under `var/plugins/KimaiStripeConnectorBundle`, reloads Kimai, verifies the bundle,
exercises disabled reconciliation/cleanup commands, removes it, reloads again, and
verifies the bundle is gone.

The semver workflow builds the same self-contained artifact and attaches the ZIP
plus checksum to future GitHub releases.  GitHub-generated **Source code** archives
remain source-only and are not treated as ADR-012 installable artifacts.

PR #13 reached clean Package, PHP, Documentation, Kimai E2E, and MegaLinter
results before merge, including zero MegaLinter errors and zero warnings.  No
release was published as part of that work.

## Phase 7b: Hosted Stripe Payment Proof

The Phase 7b branch adds:

```text
tests/e2e/compose.stripe.yaml
tests/e2e/run-stripe-hosted.sh
tests/e2e/stripe-hosted-evidence.php
```

and extends the existing manual-only:

```text
.github/workflows/stripe-sandbox.yml
```

rather than creating a second secret-bearing workflow.

The hosted workflow still uses GitHub Actions environment `stripe-sandbox`.  The
Stripe secret is injected only into the contract-probe and hosted-payment steps;
checkout, PHP setup, and dependency installation remain credential-free.

### Disposable fixture and instance identity

Every hosted run creates fresh Compose volumes and generates a new
`KIMAI_STRIPE_CONNECTOR_INSTANCE_ID` from the runner's kernel UUID source.  This is
intentional: each test is a new disposable Kimai deployment, so a fresh instance
namespace is correct and prevents prior deactivated sandbox links from becoming
ambiguous same-invoice evidence.

The fixture creates one exact `$12.34` USD billable invoice.  Customer, project,
activity, and timesheet data are created through Kimai's API.  A run-owned bearer
API token and invoice-template row are narrow test setup seams.  The invoice itself
is created through:

```text
kimai:invoice:create
```

so Kimai's real invoice creation path dispatches the production
`InvoicePreRenderEvent` and lets the connector create/recover the Payment Link.

### Stripe evidence helper

`stripe-hosted-evidence.php` uses the packaged plugin's locked Stripe SDK and
shared connector metadata/money primitives.  It refuses live-mode credentials and
checks the same successful-payment predicates used by production reconciliation:

- exact Payment Link metadata, amount, currency, line item, and restrictions;
- exact Checkout Session metadata, amount, currency, and Payment Link relation;
- Session `complete` + `paid`;
- exact PaymentIntent metadata, amount, amount received, currency, and
  `succeeded` state;
- exact latest Charge relation;
- Charge `paid=true` and `captured=true`;
- exact amount/captured amount/currency;
- zero refunded amount;
- `refunded=false`;
- `disputed=false`; and
- a usable Charge creation timestamp.

It fails if more than one successful payment matches the disposable invoice.

### Human interaction boundary

The workflow prints the run-owned Stripe-hosted Payment Link and Stripe's public
success-card test data, then waits up to 15 minutes by default for the human to
complete Checkout.

Browser automation is deliberately not added merely to enter public test-card
data.  The human action makes the proof boundary explicit: the test does not
manufacture the payment state it is meant to verify.

### Reconciliation assertions

After the real Stripe object chain appears, the harness requires:

```text
Kimai invoice still unpaid
-> kimai:stripe:reconcile --dry-run
   [SUCCESS] would_change=yes changed=no
-> kimai:stripe:reconcile
   [SUCCESS] would_change=yes changed=yes
-> exact invoice status=paid and paymentDate populated
-> kimai:stripe:reconcile again
   [NO_CHANGE] would_change=no changed=no
```

This proves the real paid-state transition only after Stripe has supplied the
strong evidence required by ADR-009, then proves the immediate rerun is idempotent
under ADR-011.

### Run-owned Stripe cleanup

Before Docker teardown, the EXIT/INT/TERM path asks the evidence helper to
deactivate active connector-managed Payment Links for the current run's unique
Kimai instance UUID.  It performs no broad or global sandbox cleanup and cannot
target another run's instance namespace.

A cleanup failure makes the hosted test fail rather than silently leaving an
active run-owned Payment Link behind.

## Current Phase 7b Evidence State

Implementation work exists on:

```text
test/phase-7b-hosted-payment
```

The PHP evidence helper and shell harness were syntax-checked during development.
The environment available to that session did not provide Docker, so the complete
containerized hosted path could not be executed locally.

Ordinary PR CI still needs to prove this branch against the repository's normal
Package/PHP/Documentation/Kimai E2E/MegaLinter gates.  Separately, one successful
manual protected `Stripe Sandbox` workflow run is required before saying the
hosted payment path is proven.

The manual run must demonstrate:

```text
real disposable Kimai invoice
-> connector-created Payment Link
-> human Stripe-hosted sandbox card Checkout
-> real Session / PaymentIntent / Charge evidence
-> Kimai still unpaid before reconciliation
-> reconciliation dry-run predicts paid transition
-> real reconciliation changes exact invoice to paid
-> paymentDate exists
-> immediate idempotent NO_CHANGE rerun
-> run-owned Payment Link deactivation
```

Do not substitute lower-layer green tests for that evidence.

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

## Remaining Phase 7 Work

Before a supported release claim:

- finish Phase 7b ordinary PR CI;
- run the protected real Stripe hosted-payment workflow successfully on the exact
  candidate head;
- update this handoff/test matrix with that exact run evidence;
- perform final secret/log review;
- keep compatibility claims at Kimai 2.65.0 unless deliberately expanded with
  executable evidence;
- require all quality/security gates to be clean; and
- resolve the public software-license boundary before any public release or
  public repository transition.

Do not publish a release without explicit owner authorization.

## Resume Checklist

A new engineering session should:

1. inspect `test/phase-7b-hosted-payment`, its PR if opened, exact head, CI,
   comments, reviews, and review threads;
2. read this handoff, implementation playbook, installation guide, operator
   runbook, decision index, ADR-009 through ADR-013, and test matrix;
3. treat merge commit `2498f5e1fac06e5f59d62dc0bfc89764c75b3d4d`
   as the Phase 7A/main baseline;
4. preserve the verified Stripe permission set;
5. preserve stable production instance identity while allowing a fresh UUID for
   each genuinely disposable Kimai E2E deployment;
6. preserve shared exact money and version-1 metadata semantics;
7. preserve ADR-012's self-contained plugin-local production dependency model;
8. keep GitHub source archives distinct from installable self-contained ZIPs;
9. require exact-head Package/PHP/Documentation/Kimai E2E/MegaLinter evidence for
   the PR;
10. require a successful manual protected Stripe Sandbox hosted-payment run before
    declaring Phase 7b proven; and
11. never merge, release, make the repository public, use live Stripe credentials,
    or perform live financial mutation without explicit owner authorization.
