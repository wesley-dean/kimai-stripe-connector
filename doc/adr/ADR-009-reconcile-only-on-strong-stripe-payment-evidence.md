# ADR-009: Reconcile Only on Strong Stripe Payment Evidence

Date: 2026-09-04

## Status

Accepted

## Context

The connector can change Kimai invoice paid state based on Stripe evidence.  A false positive is more harmful than a false negative: a genuinely paid invoice can be repaired later if Kimai remains stale, while an unpaid invoice incorrectly marked paid creates an accounting error.

Stripe Checkout completion is not universally equivalent to final successful payment.  Depending on payment method and lifecycle, downstream PaymentIntent/Charge state may still be processing, failed, uncaptured, refunded, disputed, or otherwise inconsistent with a safe automatic paid transition.

## Decision

Automatic reconciliation SHALL be conservative and monotonic in the initial release.

The only automatic Kimai accounting transition initially permitted is:

```text
Kimai invoice not paid
+ exact connector identity match
+ exact amount match
+ exact currency match
+ sufficiently strong and mutually consistent Stripe payment evidence
-> mark the Kimai invoice paid
```

The reconciler SHALL fail closed and make no automatic paid-state change when evidence is missing, ambiguous, contradictory, unsupported, or unavailable.

Automatic reconciliation SHALL NOT rely on any one of the following alone:

- Payment Link existence;
- Checkout Session completion;
- invoice number;
- customer identity;
- amount;
- date proximity; or
- a single loosely correlated Stripe object.

The implementation SHALL validate the exact versioned correlation contract defined in ADR-008 and inspect the Stripe object relationships appropriate to the supported payment-method/API contract.

For the initial card-only scope, reconciliation SHALL require positive evidence that the exact intended payment succeeded for the expected amount and currency and that the relevant Stripe object chain is mutually consistent.

The connector SHALL NOT automatically reverse a Kimai invoice from paid to unpaid or canceled merely because Stripe later reports a refund, partial refund, or dispute.  Those are accounting exceptions requiring visibility and human review.

Reconciliation SHALL be safe to repeat.  Re-running it after an invoice is correctly paid SHALL produce no duplicate mutation or harmful side effect.

A `--dry-run` mode SHALL be provided before operational use so an operator can inspect intended changes without modifying Kimai.

When automatic reconciliation marks an invoice paid, Kimai's payment date SHALL be derived from the proven Stripe Charge `created` timestamp.  The timestamp SHALL be converted to the invoice creation timezone before Kimai persists its date-only payment field.  The reconciliation execution time SHALL NOT be used as the payment date because it records when the connector observed the payment rather than when the payment occurred.

## Consequences

- Paid invoices may remain temporarily unpaid in Kimai when Stripe is unavailable or evidence is ambiguous.
- Later reconciliation runs can repair false negatives.
- Refunds/disputes need operator-facing anomaly reporting rather than automatic historical rewriting.
- Reconciliation latency does not distort the recorded payment date.
- The reconciliation logic requires substantial adversarial test coverage under ADR-003.

## Considered Alternatives

### Mark paid when Checkout completes

Rejected because Checkout completion is not universal proof of successful final payment.

### Reconcile by amount/customer/date heuristics

Rejected because those signals are insufficiently unique for automatic financial mutation.

### Use reconciliation execution time as the payment date

Rejected because a delayed polling run would record when the connector noticed the payment instead of when Stripe recorded the successful Charge.

### Automatically reverse paid invoices after refunds/disputes

Rejected because a later refund/dispute does not erase the historical fact that payment occurred and may require separate accounting treatment.

## Related Decisions

- Related to: ADR-005
- Related to: ADR-008
- Related to: ADR-003
- Related to: `doc/threat-model.md`
