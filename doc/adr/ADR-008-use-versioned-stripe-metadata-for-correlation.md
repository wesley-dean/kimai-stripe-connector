# ADR-008: Use Versioned Stripe Metadata for Correlation

Date: 2026-09-04

## Status

Accepted

## Context

The connector must reliably rediscover Stripe objects after retries, application restarts, container recreation, scheduler delays, and partial failures.  Invoice number alone is insufficient because multiple Kimai instances may share one Stripe account and may generate the same invoice number.

A connector-owned database could store mappings, but that would create a third authoritative state store and additional persistence/backup/migration responsibilities.  Stripe already provides metadata on the objects the connector creates and uses.

## Decision

The connector SHALL use a versioned Stripe metadata contract as its durable correlation mechanism.

The version-1 contract is defined in ADR-004 and SHALL include the connector namespace, metadata version, stable Kimai instance UUID, Kimai invoice number, integer minor-unit amount, and normalized currency.

The stable Kimai instance identifier SHALL survive container recreation, application restarts, plugin upgrades, and scheduler changes.

The connector SHALL treat unknown metadata versions conservatively.  It SHALL NOT infer semantics for an unknown version and SHALL NOT perform an automatic financial-state mutation based on an unknown contract.

The connector SHALL NOT introduce a private durable payment-link mapping table unless implementation evidence demonstrates that Stripe metadata plus Kimai state are insufficient.  Introducing such a ledger requires a later ADR.

Correlation SHALL use exact identity semantics.  Amount, customer identity, date proximity, or invoice number alone SHALL NOT be sufficient evidence.

## Consequences

- Remote objects remain recoverable without connector-local persistence.
- Changing the stable instance UUID after production use becomes a metadata namespace migration.
- Metadata becomes part of the compatibility surface and requires explicit versioning.
- Reconciliation can cross-check identity across multiple Stripe object layers.

## Considered Alternatives

### Connector-owned mapping database

Rejected initially because it adds a third state store without evidence that one is necessary.

### Invoice number only

Rejected because invoice numbers are not globally unique across Kimai instances.

### Search by amount/date/customer

Rejected because such heuristics are too weak for financial-state mutation and become unsafe when unrelated Stripe activity exists.

## Related Decisions

- Related to: ADR-004
- Related to: ADR-007
- Related to: ADR-009
- Related to: ADR-011
