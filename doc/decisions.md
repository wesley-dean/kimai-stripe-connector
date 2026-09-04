# Decision Index

This document is a compact index of the project's Architecture Decision Record (ADR) decisions.  It exists to help humans and AI/LLM-based tools quickly discover what has been decided, what is still proposed, and which ADR contains the authoritative rationale.

This file is **not a substitute for the ADRs**.  When implementing, reviewing, or interpreting a decision, read the referenced ADR in full.  If this summary conflicts with an ADR, the ADR is authoritative.

Update this index whenever an ADR is added, accepted, superseded, deprecated, or materially revised.

## ADR-000: Capability Scope, Epistemic Honesty, and Separation of Concerns

**Status:** Accepted

**Decision:** Project reasoning and AI/LLM-assisted work SHALL prioritize capability honesty, truth over user satisfaction, explicit separation of concerns, non-anthropomorphic framing, evidence-oriented reasoning, refusal of over-commitment, and resistance to sycophantic or appeasement-oriented language.  These principles are project-wide constraints on how analysis and assistance are performed, not merely writing preferences.  Accuracy, explicit uncertainty, and clear boundaries take precedence over appearing agreeable, reassuring, or maximally helpful.

**Source:** [`doc/adr/ADR-000-capability-scope-and-epistemic-honesty.md`](adr/ADR-000-capability-scope-and-epistemic-honesty.md)

## ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation

**Status:** Proposed

**Decision:** The connector SHALL initially be implemented and distributed as one Kimai plugin containing two independently disableable capabilities: Stripe Payment Link generation/recovery with invoice-rendering support, and asynchronous Stripe-to-Kimai payment reconciliation.  The responsibilities remain internally separated, Kimai remains authoritative for invoices, Stripe remains authoritative for payment movement, and ambiguous financial evidence must fail closed rather than change Kimai automatically.  The design preserves manual billing/reconciliation as a permanent fallback, avoids mandatory public Kimai ingress or Stripe webhooks, and favors recoverable metadata/idempotency over pretending Stripe and Kimai can participate in one atomic transaction.  Splitting the capabilities into two plugins remains a valid future option if lifecycle, dependency, security, user, or maintenance evidence eventually justifies the additional packaging surface.

**Source:** [`doc/adr/ADR-001-single-plugin-architecture.md`](adr/ADR-001-single-plugin-architecture.md)
