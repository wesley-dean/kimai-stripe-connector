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
- the object is older than the configured cleanup grace period;
- no successful payment evidence exists; and
- no ambiguity exists that requires human review.

The cleanup process SHALL retain a Payment Link when:

- a matching Kimai invoice exists;
- Kimai is unavailable or its state is uncertain;
- the object is inside the grace period;
- successful payment evidence exists;
- payment state is ambiguous; or
- the connector cannot prove the link is safe to deactivate.

A paid Stripe orphan SHALL be retained and surfaced as an accounting/manual-review exception.

Age alone SHALL NOT make a Payment Link eligible for cleanup.

Cleanup SHALL provide a non-destructive `--dry-run` mode before operational use.

If cleanup does not run, the expected failure mode is accumulated Stripe clutter rather than incorrect billing state.

## Consequences

- Some uncertain or abandoned objects may remain indefinitely until reviewed.
- Safety is preferred over aggressive Stripe object hygiene.
- Cleanup can run less frequently than reconciliation and does not participate in payment correctness.
- The grace period becomes operational configuration rather than evidence that an invoice is abandoned.

## Considered Alternatives

### Deactivate links after a fixed age

Rejected because legitimate unpaid invoices can remain valid longer than an arbitrary age threshold.

### Delete/deactivate every link without a current Kimai invoice immediately

Rejected because temporary Kimai persistence failure is an expected partial-failure mode and a later retry may need to recover the link.

### Treat paid orphans as cleanup candidates

Rejected because payment has occurred and the missing Kimai invoice is an accounting exception requiring human attention.

## Related Decisions

- Related to: ADR-007
- Related to: ADR-008
- Related to: ADR-011
- Related to: `doc/configuration.md`
