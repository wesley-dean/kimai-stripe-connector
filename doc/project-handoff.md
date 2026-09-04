# Project Handoff

Date: 2026-09-04

## Purpose

This document gives a new human or AI/LLM engineering session enough current context to resume `kimai-stripe-connector` without the conversation that produced the project.

For autonomous work, begin here, then read [`implementation-playbook.md`](implementation-playbook.md) and [`decisions.md`](decisions.md).  Accepted ADRs and explicit contracts remain authoritative over this handoff.

Do not merge, release, use live Stripe credentials, or perform live financial mutation without explicit owner authorization.

## Current State

Phase 1 foundation work was merged through PR #5.  Phase 2 common primitives were reviewed, approved, and merged through PR #6.

Current implementation work is Phase 3 Payment Links on:

```text
feat/phase-3-payment-links
```

with draft PR #8:

```text
feat: implement phase 3 payment link capability
```

Inspect current repository and PR state before resuming.  Do not assume the branch, SHA, CI state, or draft state remains unchanged.

All numbered ADRs 000 through 013 remain Accepted.  Phase 3 implements those decisions without reversing an Accepted architectural boundary.

## What Phase 3 Implements

Phase 3 now contains the first production Stripe Payment Link capability.

The implementation includes:

- deterministic Payment Link discovery, planning, reuse, creation, and recovery;
- fail-closed handling for financial conflicts, ambiguity, malformed same-invoice metadata, and inactive exact links;
- deterministic Stripe idempotency keys;
- card-only Payment Link creation;
- one-completed-session restriction;
- inline one-time `price_data` / `product_data` request construction;
- version-1 correlation metadata on Payment Links and generated PaymentIntents;
- the locked Stripe PHP SDK adapter;
- timeout-after-possible-commit rediscovery without blind duplicate creation;
- Kimai `InvoicePreRenderEvent` integration;
- preview suppression before configuration or Stripe access;
- exact Kimai invoice metadata/money conversion at the integration boundary;
- safe invoice-model hydration exposing only the Stripe-hosted payment URL;
- operator-safe failure that stops invoice rendering when enabled automation cannot complete safely;
- controlled HTTP-boundary tests through the real locked Stripe SDK with a scripted fake network; and
- a manual real-Stripe sandbox contract probe.

No automatic Kimai paid-state reconciliation exists yet.  No cleanup scheduler/command exists yet beyond sandbox probe cleanup.  No live Stripe mutation is authorized.

## Payment Link Architecture

The main production package is:

```text
PaymentLink/
```

Important classes include:

```text
PaymentLinkPlanner
PaymentLinkService
PaymentLinkCreateRequest
StripePaymentLinkGateway
StripePaymentLinkServiceFactory
InvoicePaymentLinkSubscriber
KimaiInvoiceMetadataFactory
PaymentLinkInvoiceModelHydrator
```

The planner is intentionally pure.  It decides among preview, reuse, create, conflict, and manual-review outcomes before remote mutation.

The service rediscoveries before creation.  If a create request may have committed but the response is uncertain, it performs one rediscovery pass and does not issue another create in the same attempt.  This is the executable ADR-011 partial-failure rule.

## Kimai Integration Boundary

Phase 3 uses Kimai's supported `InvoicePreRenderEvent` seam under ADR-006.

Preview behavior returns immediately before configuration parsing, invoice-number preparation, or Stripe access.  Preview therefore causes zero Stripe reads or writes.

For a real invoice:

1. configuration is validated;
2. disabled Payment Link automation returns without changing the invoice;
3. the Kimai invoice number, total, and currency are converted into the connector metadata contract;
4. the Payment Link service proves or creates one exact compatible link; and
5. only the safe Stripe-hosted URL is exposed to the invoice model/template.

If enabled automatic generation cannot complete safely, invoice rendering stops with an operator-safe `PaymentLinkGenerationException`.  The explicit manual fallback is to disable Payment Link automation and handle billing manually.

## Kimai Float Boundary

Kimai 2.65 exposes invoice calculator totals as `float`.

The connector does not round those values.  `KimaiInvoiceMetadataFactory` serializes the float to PHP's shortest JSON decimal representation and passes that text into the exact `Money` parser.  Ordinary values such as `875.1` can therefore become exact cents, while a value carrying binary-artifact precision beyond the supported two-decimal policy fails closed rather than being rounded silently.

## Payment Link Request Contract

A connector-created link represents one fixed invoice payment:

```text
quantity:                 1
payment method:           card only
completed-session limit:  1
price:                    inline one-time price_data
product:                  inline product_data
amount:                   exact integer minor units
currency:                 normalized lowercase currency
```

Version-1 metadata remains:

```text
integration=kimai-stripe-connector
integration_version=1
kimai_instance=<stable UUID>
invoice_number=<Kimai invoice number>
invoice_amount=<integer minor units>
invoice_currency=<lowercase currency>
```

The same six-field contract is written to Payment Link metadata and to `payment_intent_data.metadata`.  Stripe copies Payment Link metadata to Checkout Sessions created through the link.

## Deterministic Recovery and Safety Rules

The following behavior is implemented and tested:

```text
preview -> no Stripe access
exact compatible active link -> reuse
no compatible link -> create once
same logical invoice + wrong amount/currency -> CONFLICT
multiple exact candidates -> fail closed
malformed same-invoice connector metadata -> fail closed
inactive exact link -> manual review / no replacement create
definite create failure -> RETRYABLE
possibly committed create -> rediscover once
possibly committed create + exact rediscovery -> reuse recovered link
possibly committed create + no proof -> RETRYABLE, no second create
```

False positives are treated as more dangerous than false negatives.  Ambiguity never authorizes financial mutation.

## Real Stripe Sandbox Evidence

Phase 3 crossed the real Stripe boundary on 2026-09-04 using a restricted test-mode credential stored in GitHub Actions environment secret storage.

The durable workflow is:

```text
.github/workflows/stripe-sandbox.yml
```

The probe is:

```text
tests/e2e/stripe-sandbox-smoke.php
```

The successful real sandbox probe verified:

- the exact locked Stripe PHP SDK can create the intended Payment Link request;
- the inline Product/Price representation works without separate Product or Price permissions;
- exact `$12.34` USD representation as `1234` minor units;
- exactly one line item with quantity one;
- card-only restriction;
- one-completed-session limit;
- exact six-field version-1 metadata after Stripe round-trip;
- Payment Link retrieval and listing;
- Checkout Session read permission;
- PaymentIntent read permission;
- Charge read permission; and
- safe Payment Link deactivation after the probe.

The first sandbox attempt successfully created and later deactivated a Payment Link but failed a local assertion because PHP array comparison was order-sensitive while Stripe returned metadata keys in its own order.  The test was corrected to canonical key/value comparison.  A subsequent run passed.  This was a test defect, not a Stripe permission failure.

Do not treat the real sandbox probe as proof of hosted card payment or reconciliation.  Those require Phase 4 reconciliation plus the canonical full E2E flow.

## Verified Stripe Restricted-Key Permissions

The verified sandbox permission set is documented in [`stripe-restricted-key.md`](stripe-restricted-key.md).

Current least-privilege set:

| Stripe resource | Permission |
|-----------------|------------|
| Payment Links | Write |
| Checkout Sessions | Read |
| Payment Intents | Read |
| Charges | Read |
| Products | None |
| Prices | None |
| Everything else | None |

The sandbox key lives in GitHub Actions environment:

```text
stripe-sandbox
```

under secret name:

```text
STRIPE_SANDBOX_RESTRICTED_KEY
```

The workflow maps that value only for the probe step to:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
```

Do not paste the credential into source, documentation, `.env`, Compose files, PR comments, test fixtures, artifacts, or ordinary chat messages.

## Sandbox Workflow Security

The temporary PR-only trigger used to prove PR #8 was removed after the successful sandbox run.  The durable workflow is manual-only through `workflow_dispatch`.

The Stripe secret is exposed only to the final probe step.  Checkout, PHP setup, and Composer installation run before the secret is placed in the step environment.

The probe refuses a live-mode restricted credential and redacts the known secret from exception text before writing diagnostics.

Ordinary PR CI remains credential-free.  Fork/Dependabot work must never receive the Stripe environment secret.

## Test Coverage Added in Phase 3

Phase 3 adds unit and integration coverage around:

- Payment Link planning decisions;
- exact reuse and create decisions;
- financial conflicts;
- ambiguity and malformed metadata;
- inactive exact links;
- deterministic idempotency request construction;
- gateway pagination and response validation;
- create failure classification;
- timeout/uncertain-commit recovery;
- real stripe-php HTTP request construction through a scripted fake HTTP client;
- preview-before-config behavior;
- disabled connector behavior;
- safe URL hydration;
- automatic-failure render blocking;
- Kimai float-to-exact-money boundary behavior; and
- the real Stripe sandbox contract probe described above.

See [`testing/test-matrix.md`](testing/test-matrix.md) for current scenario mapping.

## CI / Quality Gate

The project quality bar remains:

- PHP 8.2, 8.3, 8.4, and 8.5 checks green;
- PHPUnit green;
- PHPStan level 8 clean;
- PHPCS clean;
- PHP-CS-Fixer dry run clean;
- Documentation/Doxygen clean with zero warnings;
- disposable Kimai E2E clean; and
- every active MegaLinter linter at exactly zero errors and zero warnings.

Do not weaken, suppress, or exclude a real finding to make the gate green.

Documentation/workflow changes near the end of Phase 3 retrigger ordinary CI.  Before recommending PR #8 for merge, inspect the **final head**, not an earlier green commit, and inspect the MegaLinter summary itself rather than relying only on the workflow conclusion.

## Production-Shaped Packaging Rule to Preserve

The disposable Kimai E2E harness must stage a production-shaped plugin using the committed lockfile and:

```text
composer install --no-dev
```

Do not copy the repository-root development `vendor/` tree into Kimai.  That earlier shape introduced development Symfony/PSR dependencies into Kimai and caused duplicate interface declarations despite a healthy pristine Kimai installation.

The production artifact carries only the plugin's production dependency subset under ADR-012.

## Configuration and Secret Boundary

Configuration remains environment-driven with prefix:

```text
KIMAI_STRIPE_CONNECTOR_
```

Important variables include:

```text
ENABLED
PAYMENT_LINKS_ENABLED
RECONCILIATION_ENABLED
CLEANUP_GRACE_DAYS
INSTANCE_ID
STRIPE_API_KEY
STRIPE_API_KEY_FILE
```

A `.env` file is only one external mechanism for populating environment variables.  The connector does not implement a second dotenv parser or precedence model.

Direct and file-backed Stripe credential forms are mutually exclusive.  Restricted key shapes are enforced.  Broad Stripe secret keys are rejected.

## Architecture Boundaries That Still Apply

Unless a later Accepted ADR changes them:

- one Kimai plugin contains Payment Link and reconciliation capabilities with shared common primitives;
- Kimai remains authoritative for invoice/business state;
- Stripe remains authoritative for payment movement and Stripe objects;
- no connector-owned payment ledger/database exists;
- Kimai core and root Composer state are not patched;
- no public Kimai ingress or Stripe webhook is required;
- Stripe Payment Links remain the customer payment surface;
- manual Payment Link creation and manual reconciliation remain permanent fallbacks;
- ambiguity causes no automatic financial mutation;
- false negatives are preferred to false-positive paid-state transitions;
- paid orphans are retained for accounting review;
- refunds/disputes do not automatically reverse historical Kimai paid state; and
- cleanup retains uncertainty.

## Next Safe Step After Phase 3

Once PR #8 is final-head green, owner-reviewed, and owner-merged, begin **Phase 4: Reconciliation** from [`implementation-playbook.md`](implementation-playbook.md).

Phase 4 should implement a deterministic external command suitable for cron/Jenkins/systemd/manual execution.  Start with pure reconciliation state/evidence logic before wiring Kimai persistence.

The reconciliation path must prove strong evidence before marking an invoice paid, including exact identity, amount, currency, and successful Stripe payment state.  It must remain conservative for refunds, disputes, ambiguity, persistence failures, and reruns.

The real Stripe sandbox key already has the read permissions required for Checkout Sessions, PaymentIntents, and Charges.  Do not add permissions merely because Phase 4 begins.  Expand privilege only if implemented behavior proves the current set insufficient.

After Phase 4 exists, extend the real sandbox E2E from the current Payment Link contract probe to the canonical flow:

```text
real Kimai invoice
-> connector-created Payment Link
-> Stripe-hosted card Checkout
-> Session / PaymentIntent / Charge evidence
-> reconciliation command
-> exact Kimai paid-state transition
-> idempotent reconciliation rerun
```

## Resume Checklist

A new engineering session should:

1. inspect PR #8 and current branch/head state;
2. read this handoff, the implementation playbook, decisions index, and relevant ADRs;
3. verify whether Phase 3 has been merged;
4. if PR #8 is still open, finish only remaining final-head validation/documentation/review items;
5. if PR #8 is merged, begin Phase 4 reconciliation rather than adding more Payment Link surface casually;
6. preserve the verified Stripe permission set unless executable evidence requires change;
7. keep the real Stripe workflow manual-only and secret-safe;
8. maintain production-shaped Kimai packaging; and
9. never merge, release, or use live Stripe credentials without explicit owner authorization.
