# Stripe Restricted Key Permissions

Date: 2026-09-04

## Purpose

This document records the least-privilege Stripe restricted-key permissions verified against the real Stripe sandbox for `kimai-stripe-connector`.

ADR-004 remains authoritative for the credential model.  This document records the dashboard permission labels and executable evidence that ADR intentionally deferred until a real restricted sandbox key was available.

## Verified Sandbox Permission Set

Create one Stripe restricted **sandbox/test-mode** key for the connector with these permissions:

| Stripe resource     | Permission |
|---------------------|------------|
| Payment Links       | Write      |
| Checkout Sessions   | Read       |
| Payment Intents     | Read       |
| Charges             | Read       |
| Products            | None       |
| Prices              | None       |
| All other resources | None       |

`Payment Links: Write` supplies the Payment Link create, retrieve/list, and update/deactivation access needed by the connector.  The sandbox probe demonstrated that Stripe also accepts the connector's inline one-time `price_data` / `product_data` Payment Link request without granting separate Products or Prices permissions.

Phase 4 reconciliation uses the already verified read permissions for Checkout Sessions, PaymentIntents, and Charges.  Reconciliation performs no Stripe mutation and requires no permission expansion beyond the Phase 3 sandbox set.

Phase 5 conservative cleanup also requires no permission expansion.  It reuses the same read permissions to inspect payment evidence and the already-granted Payment Links `Write` permission for the single permitted Stripe mutation, setting an already-proven orphan Payment Link to `active=false`.

No payout, subscription, customer-management, refund, account-administration, or unrelated billing permission is required by the currently implemented Payment Link, reconciliation, and cleanup behavior.

## Executable Evidence

The manual GitHub Actions workflow is:

```text
.github/workflows/stripe-sandbox.yml
```

The real-Stripe probe is:

```text
tests/e2e/stripe-sandbox-smoke.php
```

The successful sandbox run verified:

- restricted test credential accepted by the locked Stripe PHP SDK;
- real Payment Link creation;
- exact `$12.34` USD line-item representation as `1234` minor units;
- exactly one line item with quantity one;
- card-only payment-method restriction;
- one-completed-session limit;
- exact version-1 connector metadata after Stripe round-trip;
- Payment Link retrieve and list access;
- Checkout Session read access;
- PaymentIntent read access;
- Charge read access; and
- Payment Link deactivation during cleanup.

The initial sandbox attempt also demonstrated that Payment Link creation succeeds while Products and Prices remain `None`.  Its failure was an order-sensitive local metadata comparison, not a Stripe permission failure.  The comparison was corrected to canonical key/value comparison and the subsequent probe passed.

Phase 4's controlled-SDK tests additionally prove the exact read-only request path used for Payment Link -> Checkout Session -> PaymentIntent -> Charge reconciliation.  Phase 5 controlled-SDK tests prove the guarded retrieve-then-update request used for cleanup and confirm that the SDK sends only the `active=false` Payment Link mutation.  These deterministic fake-network tests do not replace the real sandbox permission evidence above.

The existing real sandbox probe proves that the permission can deactivate a connector-created Payment Link.  It does not claim to execute the complete Phase 5 stale-orphan eligibility and repeated-revalidation algorithm against a real Stripe account; that safety logic remains deterministic repository evidence unless a later narrowly scoped sandbox exercise is deliberately added.

## GitHub Actions Secret Boundary

The sandbox key is stored in the GitHub Actions environment:

```text
stripe-sandbox
```

under the environment secret:

```text
STRIPE_SANDBOX_RESTRICTED_KEY
```

The workflow exposes that secret only to the probe step and maps it to the connector's existing runtime configuration name:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
```

Checkout, PHP setup, and Composer installation execute before the Stripe secret is added to the step environment.

The durable sandbox workflow is manual-only through `workflow_dispatch`.  Ordinary pull-request CI remains credential-free.

## Secret Handling

Never commit the restricted key to source, fixtures, `.env` files, Compose files, documentation, issue/PR comments, test artifacts, or chat messages.

The sandbox key is still a secret even though it cannot move live money.  Rotate or revoke it if exposure is suspected.

The connector accepts restricted Stripe credential shapes only and refuses broad Stripe secret-key forms.  The sandbox probe additionally refuses a live-mode restricted credential.

## Production Boundary

This sandbox evidence does **not** authorize creation or use of a live-mode credential.  A production key should use the same least-privilege shape only after the owner explicitly authorizes live deployment and the relevant production behavior has been reviewed.

Later connector capabilities may require additional Stripe permissions.  Any expansion should be driven by implemented behavior and executable evidence, then reflected here.  Do not broaden the key preemptively.

## Related Documentation

- [`adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md`](adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md)
- [`configuration.md`](configuration.md)
- [`testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md)
- [`threat-model.md`](threat-model.md)
