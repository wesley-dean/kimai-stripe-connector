# ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation

Date: 2026-09-03

## Status

Accepted

## Context

The connector has two distinct responsibilities:

1. create or recover a Stripe-hosted payment surface while Kimai generates an invoice; and
2. reconcile later Stripe payment evidence back into Kimai invoice state.

The responsibilities have different timing and risk profiles, but they share configuration, instance identity, money normalization, metadata/correlation rules, Stripe client construction, compatibility requirements, and release lifecycle.

Packaging them separately from the beginning would duplicate integration surface and create pressure for a third shared library before implementation evidence demonstrates that the isolation is worth the cost.

## Decision

The connector SHALL initially be implemented and distributed as one Kimai plugin containing two independently disableable capabilities:

```text
KimaiStripeConnector
|
+-- Common
|
+-- PaymentLink
|
+-- Reconciliation
```

`PaymentLink` and `Reconciliation` MAY depend on common connector services but SHALL remain internally separated from each other.

Disabling one capability SHALL NOT require disabling the other when the remaining capability has sufficient configuration and correlation data to operate safely.

The connector SHALL remain removable.  Ordinary Kimai invoicing and the manual Kimai/Stripe billing workflow SHALL remain usable without the plugin.

Two separately packaged plugins remain a valid future alternative if lifecycle, dependency, security, user, or maintenance evidence demonstrates that separate packaging is worth the duplicated release and compatibility surface.  Such a split requires a later ADR.

## Decisions Split from This ADR

The original proposed ADR contained several independent architectural decisions.  They are now maintained as focused Accepted ADRs:

- [ADR-005](ADR-005-private-kimai-outbound-stripe-integration.md): private Kimai, outbound-only Stripe integration, polling rather than required webhooks;
- [ADR-006](ADR-006-use-supported-kimai-plugin-extension-seams.md): supported Kimai plugin and invoice-rendering extension seams;
- [ADR-007](ADR-007-use-stripe-payment-links-as-customer-payment-surface.md): Stripe Payment Links as the public customer payment surface;
- [ADR-008](ADR-008-use-versioned-stripe-metadata-for-correlation.md): versioned Stripe metadata and stable Kimai instance identity for correlation;
- [ADR-009](ADR-009-reconcile-only-on-strong-stripe-payment-evidence.md): conservative reconciliation and financial-state transition rules;
- [ADR-010](ADR-010-clean-up-only-proven-unpaid-orphan-payment-links.md): conservative orphan cleanup rules; and
- [ADR-011](ADR-011-design-for-idempotent-recovery-from-partial-failure.md): idempotency, partial-failure recovery, and conflict handling.

Compatibility, money, payment-method, SDK/API, and credential choices are maintained separately in [ADR-004](ADR-004-freeze-initial-compatibility-and-stripe-contract.md).

This ADR therefore governs package-level architecture and responsibility boundaries rather than acting as the container for every connector decision.

## Acceptance Note

The project owner accepted this architectural direction on 2026-09-04 before production implementation began.  Acceptance records the intended architecture; it does not claim that implementation or end-to-end validation has already occurred.  The implementation and release gates documented elsewhere remain mandatory.

The complete original proposed ADR is preserved verbatim at:

[`rationale/ADR-001-proposed-rationale.md`](rationale/ADR-001-proposed-rationale.md)

That historical document preserves the original Kimai source findings, Stripe research, alternatives, failure analysis, synthetic modeling, and reasoning that led to ADR-001 and the focused ADRs above.  It is supporting historical rationale rather than the current normative location for those individual decisions.

## Consequences

- Installation and release lifecycle remain one plugin/package initially.
- Shared primitives have one implementation and compatibility surface.
- Payment Link and reconciliation behavior remain independently testable and disableable.
- Future architectural changes can supersede one focused decision without reopening an enormous all-in-one ADR.

## Related Decisions

- Related to: ADR-000
- Related to: ADR-002
- Related to: ADR-003
- Related to: ADR-004
- Related to: ADR-005
- Related to: ADR-006
- Related to: ADR-007
- Related to: ADR-008
- Related to: ADR-009
- Related to: ADR-010
- Related to: ADR-011
