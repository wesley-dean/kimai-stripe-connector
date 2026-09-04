# Decision Index

This document is a compact index of the project's Architecture Decision Record (ADR) decisions.  It exists to help humans and AI/LLM-based tools quickly discover what has been decided and which ADR contains the authoritative rationale.

This file is **not a substitute for the ADRs**.  When implementing, reviewing, or interpreting a decision, read the referenced ADR in full.  If this summary conflicts with an ADR, the ADR is authoritative.

Update this index whenever an ADR is added, accepted, superseded, deprecated, or materially revised.

All current numbered ADRs are Accepted as of 2026-09-04.

## ADR-000: Capability Scope, Epistemic Honesty, and Separation of Concerns

**Status:** Accepted

**Decision:** Project reasoning and AI/LLM-assisted work SHALL prioritize capability honesty, evidence, explicit uncertainty, clear boundaries, and resistance to sycophancy or over-commitment.

**Source:** [`ADR-000`](adr/ADR-000-capability-scope-and-epistemic-honesty.md)

## ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation

**Status:** Accepted

**Decision:** Package Payment Link generation and reconciliation in one Kimai plugin with internally separated, independently disableable capabilities.  Keep shared identity/configuration/money/Stripe-client concerns common and preserve the option to split packages later if evidence justifies it.

**Source:** [`ADR-001`](adr/ADR-001-single-plugin-architecture.md)

The complete original large proposed rationale is preserved under [`doc/adr/rationale/`](adr/rationale/ADR-001-proposed-rationale.md); its independent decisions were split into ADRs 005–011.

## ADR-002: Use Documentation-First PHP Source Comments and Generated Reference Docs

**Status:** Accepted

**Decision:** Use Doxygen-compatible PHPDoc source contracts, generated ignored `doc/reference/` output, and distinct roles for README, AGENTS, ADRs, specifications, source comments, testing documentation, and generated reference material.

**Source:** [`ADR-002`](adr/ADR-002-documentation-first-php-comments-and-generated-reference-docs.md)

## ADR-003: Require Tests for Behavioral Changes

**Status:** Accepted

**Decision:** Executable behavior changes, fixes, API changes, and compatibility patches require appropriate automated tests in the same coherent change when reasonably testable.  Documentation/formatting/comment-only changes do not require artificial unit tests.

**Source:** [`ADR-003`](adr/ADR-003-require-tests-for-behavioral-changes.md)

## ADR-004: Freeze the Initial Compatibility and Stripe Contract

**Status:** Accepted

**Decision:** Start with Kimai 2.65.0, PHP 8.2–8.5, Stripe PHP `^21.3.1`, SDK-aligned Stripe API behavior, card-only Payment Links, versioned metadata, integer minor-unit money, initial two-decimal currency support, and one least-privilege restricted Stripe key.

**Source:** [`ADR-004`](adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md)

## ADR-005: Keep Kimai Private and Integrate with Stripe Outbound-Only

**Status:** Accepted

**Decision:** Do not require public Kimai ingress or Stripe webhooks.  Keep Stripe as the hosted public payment surface and use externally scheduled repair-oriented polling/reconciliation with a permanent manual fallback.

**Source:** [`ADR-005`](adr/ADR-005-private-kimai-outbound-stripe-integration.md)

## ADR-006: Use Supported Kimai Plugin Extension Seams

**Status:** Accepted

**Decision:** Use Kimai's supported plugin/bundle lifecycle, `InvoicePreRenderEvent`, preview detection, InvoiceModel data, and model hydration rather than Kimai core patches or forks.

**Source:** [`ADR-006`](adr/ADR-006-use-supported-kimai-plugin-extension-seams.md)

## ADR-007: Use Stripe Payment Links as the Customer Payment Surface

**Status:** Accepted

**Decision:** Use durable Stripe-hosted Payment Links for customer payment while Kimai remains invoice authority; do not use Stripe Invoices, raw Checkout URLs, or a Kimai-hosted payment page as the initial customer surface.

**Source:** [`ADR-007`](adr/ADR-007-use-stripe-payment-links-as-customer-payment-surface.md)

## ADR-008: Use Versioned Stripe Metadata for Correlation

**Status:** Accepted

**Decision:** Use versioned Stripe metadata plus a stable Kimai instance UUID as the durable cross-system correlation contract, avoiding a connector-owned mapping ledger until evidence demonstrates one is necessary.

**Source:** [`ADR-008`](adr/ADR-008-use-versioned-stripe-metadata-for-correlation.md)

## ADR-009: Reconcile Only on Strong Stripe Payment Evidence

**Status:** Accepted

**Decision:** Automatically move Kimai toward paid state only with exact identity, amount, currency, and strong mutually consistent Stripe evidence.  Fail closed on uncertainty, do not automatically reverse historical paid state for later refunds/disputes, and record an automatic payment date from the proven Stripe Charge timestamp rather than the later reconciliation execution time.

**Source:** [`ADR-009`](adr/ADR-009-reconcile-only-on-strong-stripe-payment-evidence.md)

## ADR-010: Clean Up Only Proven Unpaid Orphan Payment Links

**Status:** Accepted

**Decision:** Automatically deactivate only connector-managed orphan Payment Links proven stale, unpaid, unmatched to Kimai, and safe to remove.  Retain uncertainty, valid invoices, recent objects, and paid orphans.

**Source:** [`ADR-010`](adr/ADR-010-clean-up-only-proven-unpaid-orphan-payment-links.md)

## ADR-011: Design for Idempotent Recovery from Partial Failure

**Status:** Accepted

**Decision:** Treat Stripe/Kimai as a distributed system: use idempotency, exact rediscovery, durable metadata correlation, conflict detection, and conservative repair instead of pretending remote creation and Kimai persistence are atomic.  Reconciliation does not claim an internal distributed lock; it revalidates Kimai immediately before mutation, remains monotonic/idempotent, and expects schedulers to avoid overlapping runs when duplicate Kimai update events would be undesirable.

**Source:** [`ADR-011`](adr/ADR-011-design-for-idempotent-recovery-from-partial-failure.md)

## ADR-012: Package as a Self-Contained Kimai Plugin

**Status:** Accepted

**Decision:** Install under `var/plugins/KimaiStripeConnectorBundle`, carry plugin-local Composer production dependencies, load the plugin-local autoloader, avoid modifying Kimai's root Composer state, and ship a self-contained normal Kimai plugin artifact.

**Source:** [`ADR-012`](adr/ADR-012-package-as-self-contained-kimai-plugin.md)

## ADR-013: Use a Stable Operator Result Taxonomy

**Status:** Accepted

**Decision:** Use stable operator-facing categories `SUCCESS`, `NO_CHANGE`, `RETRYABLE`, `CONFIGURATION_ERROR`, `CONFLICT`, `MANUAL_REVIEW_REQUIRED`, and `INTERNAL_ERROR` across CLI behavior, logs, tests, and runbooks.  The reconciliation CLI maps those categories to stable exit codes `0`, `10`, `20`, `30`, `40`, and `50`, with successful categories sharing zero and batch execution returning the highest applicable mapped code.

**Source:** [`ADR-013`](adr/ADR-013-use-stable-operator-result-taxonomy.md)
