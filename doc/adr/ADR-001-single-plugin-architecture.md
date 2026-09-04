# ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation

Date: 2026-09-03

## Status

Accepted

## Decision

The connector SHALL be implemented and distributed as one Kimai plugin containing two independently disableable capabilities:

1. Stripe Payment Link generation/recovery with invoice-rendering support; and
2. asynchronous Stripe-to-Kimai payment reconciliation.

The responsibilities SHALL remain internally separated.  Kimai remains authoritative for invoice identity, amount, currency, document generation, and Kimai invoice state.  Stripe remains authoritative for Payment Links, Checkout Sessions, PaymentIntents, Charges, refunds, disputes, and actual payment movement.  The connector owns only the correlation contract and the conservative decision procedure that determines whether Stripe evidence is sufficient to update Kimai.

The connector SHALL preserve the permanent manual fallback, SHALL NOT require public Internet ingress to Kimai, SHALL NOT require Stripe webhooks, SHALL fail closed on financial ambiguity, SHALL prefer false negatives to false-positive paid-state transitions, SHALL treat paid Stripe orphans as accounting exceptions rather than garbage, and SHALL NOT automatically reverse a paid Kimai invoice merely because Stripe later reports a refund or dispute.

The plugin SHALL use supported Kimai extension mechanisms rather than Kimai core patches, forks, or vendor modifications.  Payment Link generation and reconciliation SHALL be independently disableable.  The implementation SHOULD remain small, auditable, recoverable, and free of a connector-owned payment ledger unless implementation evidence later demonstrates a need.

Two separately packaged plugins remain a valid future alternative if lifecycle, dependency, security, user, or maintenance evidence eventually justifies the additional packaging surface.  Such a split would require a later ADR.

## Acceptance Note

The project owner accepted this architectural direction on 2026-09-04 before production implementation began.  Acceptance records the intended architecture; it does not claim that implementation or end-to-end validation has already occurred.  The implementation and release gates documented elsewhere remain mandatory.

The complete original proposed ADR, including the detailed context, Kimai source findings, Stripe research, alternatives, failure analysis, synthetic modeling, cleanup/reconciliation rules, and acceptance-test design, is preserved verbatim at:

[`rationale/ADR-001-proposed-rationale.md`](rationale/ADR-001-proposed-rationale.md)

That preserved rationale remains part of this decision record.  If this concise accepted ADR appears ambiguous, consult the preserved rationale before inferring intent.

## Related Decisions

- Related to: ADR-000
- Related to: ADR-002
