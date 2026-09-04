# ADR-010: Clean Up Only Proven Unpaid Orphan Payment Links

Date: 2026-09-04

## Status

Accepted

## Context

A Payment Link can be created before Kimai finishes rendering or persisting the corresponding invoice.  If Kimai subsequently fails, Stripe may contain a connector-managed Payment Link for which no completed Kimai invoice exists.

These objects create clutter and potential customer-facing payment surfaces, but cleanup is destructive.  An old valid Payment Link is not necessarily abandoned: a legitimate unpaid invoice may remain open for a long time.  A paid orphan is an accounting exception and must never be treated as garbage.

## Decision

Orphan cleanup SHALL be conservative and SHALL remain outside the billing correctness path.

A connector-managed Payment Link MAY be automatically deactivated only when the cleanup process can establish all of the following:

- the object is managed by this connector;
- it belongs to the current connector/Kimai instance namespace;
- no matching Kimai invoice exists;
- Kimai was successfully queried, rather than merely unavailable;
- exactly one managed Payment Link represents the logical invoice being considered;
- the object is at least as old as the configured cleanup grace period;
- the Payment Link is currently active;
- no successful payment evidence exists;
- observed payment evidence is conclusively unpaid rather than merely lacking proof of success; and
- no ambiguity exists that requires human review.

The cleanup process SHALL retain a Payment Link when:

- a matching Kimai invoice exists;
- Kimai is unavailable or its state is uncertain;
- the object is inside the grace period;
- successful payment evidence exists;
- a Checkout/payment attempt is open, processing, unsupported, contradictory, or otherwise ambiguous; or
- the connector cannot prove the link is safe to deactivate.

A paid Stripe orphan SHALL be retained and surfaced as an accounting/manual-review exception.

Age alone SHALL NOT make a Payment Link eligible for cleanup.

For the initial implementation, conclusively unpaid Stripe evidence is deliberately narrow.  Automatic cleanup MAY proceed when there are no Checkout Sessions, or when every observed Checkout attempt is expired and unpaid with no Charge and no received money.  If a PaymentIntent exists for such an attempt, its state SHALL be limited to an explicitly failed/cancelled state supported by executable tests.  Unknown future Stripe states SHALL fail closed until deliberately supported.

Cleanup SHALL revalidate Kimai absence and Stripe payment evidence immediately before a destructive update.  The Stripe mutation boundary SHALL then retrieve and validate the target Payment Link before changing `active` from `true` to `false`.  Cleanup does not claim cross-system transaction atomicity or a distributed lock.

Cleanup SHALL provide a non-destructive `--dry-run` mode before operational use.

The initial external command is:

```text
php bin/console kimai:stripe:cleanup
php bin/console kimai:stripe:cleanup --dry-run
```

The command SHALL accept no invoice identifier, Payment Link identifier, or caller-selected desired Stripe state.  Eligibility is derived from configured instance identity and current Kimai/Stripe evidence.

Cleanup is an operator-invoked maintenance action within the reconciliation side of the initial two-capability plugin architecture.  It therefore requires both global connector enablement and reconciliation enablement.  The initial configuration contract does not add a third `KIMAI_STRIPE_CONNECTOR_CLEANUP_ENABLED` capability switch.

If cleanup does not run, the expected failure mode is accumulated Stripe clutter rather than incorrect billing state.

## Consequences

- Some uncertain or abandoned objects may remain indefinitely until reviewed.
- Safety is preferred over aggressive Stripe object hygiene.
- Cleanup can run less frequently than reconciliation and does not participate in payment correctness.
- The grace period becomes operational configuration rather than evidence that an invoice is abandoned.
- A very small race remains between the final remote read and Stripe's update because Kimai and Stripe do not share a transaction; repeated revalidation reduces the preventable window without introducing connector-owned state.
- Payment Link deactivation is monotonic and idempotent, so an uncertain post-update timeout can be retried safely on a later run.

## Considered Alternatives

### Deactivate links after a fixed age

Rejected because legitimate unpaid invoices can remain valid longer than an arbitrary age threshold.

### Delete/deactivate every link without a current Kimai invoice immediately

Rejected because temporary Kimai persistence failure is an expected partial-failure mode and a later retry may need to recover the link.

### Treat paid orphans as cleanup candidates

Rejected because payment has occurred and the missing Kimai invoice is an accounting exception requiring human attention.

### Add a third cleanup capability switch immediately

Rejected for the initial implementation because cleanup is maintenance of reconciliation-discovered state and the accepted architecture currently defines two independently disableable capabilities.  A later independent cleanup capability can be introduced through an explicit decision if operations demonstrate a need for separate lifecycle control.

### Add a connector-owned cleanup ledger or distributed lock

Rejected for the initial implementation because it would add state, deployment coupling, and a new correctness surface to solve a low-frequency hygiene problem.  Conservative repeated reads and an idempotent one-way deactivation provide a smaller failure domain.

## Related Decisions

- Related to: ADR-001
- Related to: ADR-007
- Related to: ADR-008
- Related to: ADR-011
- Related to: ADR-013
- Related to: `doc/configuration.md`
