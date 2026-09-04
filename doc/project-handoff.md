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
```

The current `main` baseline for Phase 7 is the PR #12 merge commit:

```text
3b1534b2d2c825897dc41e1d6c598922881fc3c6
```

Current work is the ADR-012 release-artifact portion of **Phase 7: Compatibility
and Release Evidence** on:

```text
build/phase-7-release-artifact
```

with draft PR #13:

```text
build: prove self-contained release artifact
```

The separate hosted Stripe Checkout -> reconciliation -> exact Kimai paid-state
proof remains a later focused Phase 7 increment.  Do not silently fold live or
hosted-payment activity into the packaging PR.

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
permission.  No cleanup permission expansion was required.

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

Draft PR #13 implements ADR-012 through a deterministic allowlist-based packager.

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

Disposable Kimai E2E now builds the same ZIP, extracts it, installs that exact
artifact under `var/plugins/KimaiStripeConnectorBundle`, reloads Kimai, verifies
the bundle, exercises disabled reconciliation/cleanup commands, removes it,
reloads again, and verifies the bundle is gone.

The semver workflow now builds the same self-contained artifact and attaches the
ZIP plus checksum to future GitHub releases.  GitHub-generated **Source code**
archives remain source-only and are not treated as ADR-012 installable artifacts.

No release has been published as part of PR #13.

## Phase 7A Evidence So Far

On implementation head:

```text
81ce0aead87e08b97a95904d64de10d89c660a97
```

these checks completed successfully before later documentation-only commits moved
the branch:

- Package workflow: success, including reproducibility/content/checksum checks;
- PHP workflow: success;
- Documentation workflow: success; and
- disposable Kimai E2E: success installing/removing the generated ZIP.

MegaLinter on that head was cancelled because the branch advanced; it was not a
reported lint failure.  Final acceptance requires every relevant workflow,
including MegaLinter, to be green on one exact final head.

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

## Remaining Real Stripe E2E Gap

The canonical full payment proof remains Phase 7 work:

```text
real disposable Kimai invoice
-> connector-created Payment Link
-> Stripe-hosted sandbox card Checkout
-> real Session / PaymentIntent / Charge evidence
-> reconciliation command
-> exact Kimai paid-state transition
-> idempotent rerun
```

Do not use live credentials or real money to close this gap.  The existing
restricted sandbox key and test mode are the only acceptable Stripe boundary for
this proof unless the owner explicitly authorizes something else.

## Remaining Phase 7 Work

After the release-artifact PR is accepted/merged, important remaining evidence is:

- full hosted sandbox payment -> reconciliation -> exact Kimai paid transition;
- idempotent reconciliation rerun after that real payment;
- compatibility evidence beyond the Kimai 2.65.0 baseline only if deliberately
  claimed;
- final secret/log review;
- full clean quality/security gates; and
- resolving the public software-license boundary before any public release or
  public repository transition.

Do not publish a release without explicit owner authorization.

## Resume Checklist

A new engineering session should:

1. inspect draft PR #13, its current head, CI, comments, reviews, and review
   threads;
2. read this handoff, implementation playbook, installation guide, operator
   runbook, decision index, ADR-009 through ADR-013, and test matrix;
3. treat merge commit `3b1534b2d2c825897dc41e1d6c598922881fc3c6`
   as the Phase 7 baseline until PR #13 is merged;
4. preserve the verified Stripe permission set and stable Kimai instance UUID;
5. preserve shared exact money and version-1 metadata semantics;
6. preserve ADR-012's self-contained plugin-local production dependency model;
7. keep GitHub source archives distinct from installable self-contained ZIPs;
8. require final exact-head Package/PHP/Documentation/Kimai E2E/MegaLinter
   evidence before declaring PR #13 complete;
9. treat full hosted paid E2E as unproven until actually executed; and
10. never merge, release, make the repository public, use live Stripe credentials,
    or perform live financial mutation without explicit owner authorization.
