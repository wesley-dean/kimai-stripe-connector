# ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation

Date: 2026-09-03

## Status

Proposed

## Intent and Documentation Posture

This ADR records the initial architectural direction for the Kimai Stripe Connector.  The decision is intentionally documented before implementation so that the project begins with explicit boundaries around responsibility, failure behavior, operational expectations, and scope.

The project is expected to remain small.  The purpose of documenting this decision in detail is not to justify complexity.  It is to make the limits of the integration durable enough that future implementation work does not gradually turn a narrow connector into a billing platform.

The current decision is proposed rather than accepted because the architecture has been explored through source review and synthetic/adversarial modeling, but a packaged plugin has not yet been installed and exercised against a real Kimai runtime and Stripe test account.

## Context

Kimai provides the time-tracking and invoice-generation capabilities needed by the intended users of this project.  Stripe provides the public payment surface.  The missing capability is a narrow bridge between them:

1. A Kimai invoice should be able to contain a Stripe-hosted payment URL without requiring the user to create and paste that URL manually.
2. When Stripe provides sufficiently strong evidence that the corresponding invoice has been paid, Kimai should be able to reflect that invoice as paid.

The integration should preserve a strong separation of authority:

- Kimai remains authoritative for time records, invoice identity, invoice amount, currency, and Kimai invoice status.
- Stripe remains authoritative for Payment Links, Checkout Sessions, PaymentIntents, Charges, and actual payment movement.
- The connector correlates the two systems and should not attempt to reproduce Stripe accounting behavior inside Kimai.

The project has several non-negotiable operational constraints:

- Kimai must not require public Internet ingress.
- Stripe webhooks are not required.
- Kimai core source code must not be modified or patched.
- The connector must use supported Kimai extension points.
- The connector must be removable without preventing normal Kimai invoicing.
- Manual Stripe Payment Link creation and manual Kimai payment reconciliation remain valid fallback workflows.
- Ambiguous financial state must fail safely and require human review rather than being guessed or silently repaired.

Kimai 2.65.0 exposes the invoice lifecycle hooks and invoice-model extension points needed for this architecture.  A Payment Link can be created or recovered before invoice rendering, and its URL can be exposed to the invoice rendering model without modifying Kimai core.  Reconciliation can occur asynchronously through a command invoked by cron, Jenkins, or another scheduler.

Two packaging approaches were considered seriously:

1. one Kimai plugin containing Payment Link and reconciliation capabilities; or
2. two separate Kimai plugins, one for Payment Links and one for reconciliation.

The responsibilities are meaningfully different.  Payment Link generation is workflow automation performed during invoice generation.  Reconciliation is accounting-related automation performed asynchronously after payment.  This distinction should remain explicit even if both capabilities are shipped in one package.

## Decision Drivers

The following factors most strongly influence this decision:

- Keep the installation, compatibility, and release surface as small as practical.
- Preserve clear separation between synchronous invoice-generation behavior and asynchronous reconciliation behavior.
- Allow either capability to be disabled independently.
- Avoid duplicate Stripe configuration, metadata contracts, versioning, and compatibility management.
- Avoid introducing a third shared library solely to support two very small plugins.
- Keep the connector operationally disposable: if it stops working, Kimai and Stripe must remain usable manually.
- Prefer conservative financial behavior over aggressive automation.
- Preserve the possibility of splitting the plugin later if the two responsibilities evolve independently enough to justify separate packages.

## Decision

The project SHALL initially be implemented and distributed as a single Kimai plugin containing two independently disableable capabilities:

1. Payment Link integration
2. payment reconciliation

These capabilities SHALL share only the common infrastructure required to correlate Kimai invoices with Stripe objects, authenticate to Stripe, normalize invoice identity and money values, and enforce a shared metadata contract.

The internal design SHALL preserve separation between the two capabilities even though they share a package.

Conceptually:

```text
KimaiStripeConnector
|
+-- PaymentLink
|   +-- create or recover Stripe Payment Links
|   +-- expose payment URLs to invoice rendering
|   +-- identify and clean abandoned unpaid links
|
+-- Reconciliation
    +-- inspect Stripe payment evidence
    +-- correlate payment to an exact Kimai invoice
    +-- mark a Kimai invoice paid when evidence is sufficient
    +-- report later disputes, refunds, or contradictions for review
```

### Payment Link capability

The Payment Link capability SHALL operate during real invoice generation and SHALL NOT create Stripe objects for invoice previews.

For each invoice, the connector SHALL create or recover an exact Stripe Payment Link using a stable metadata contract.  The contract is expected to include, at minimum:

```text
integration=kimai-stripe-v1
kimai_instance=<stable instance UUID>
invoice_number=<Kimai invoice number>
amount=<integer minor currency units>
currency=<ISO currency code>
```

The Payment Link SHALL represent a fixed invoice amount and currency.  Quantity adjustment and pay-what-you-want behavior are outside the intended scope.

A previously created link MAY be reused only when identity, amount, currency, and namespace agree exactly.  If a link with the expected identity exists but its financial data conflicts with the current invoice, invoice automation SHALL fail rather than silently mutate or reuse that link.

The Payment Link URL SHALL be injected into the invoice rendering model through supported Kimai extension mechanisms.  Kimai core SHALL NOT be patched to add the field.

### Reconciliation capability

Reconciliation SHALL be asynchronous and SHALL NOT be required for invoice generation or customer payment.

It SHALL be invokable through an explicit command suitable for cron, Jenkins, or another scheduler.  A Stripe outage or reconciliation failure must therefore leave customer payment behavior unaffected; the Kimai invoice may temporarily remain unpaid until reconciliation succeeds or a user marks it paid manually.

The reconciliation capability SHALL mark an invoice paid only when Stripe evidence unambiguously identifies the intended invoice and the relevant financial values agree.

The first implementation is expected to validate, at minimum, the relevant Checkout Session, PaymentIntent, and Charge state before changing Kimai invoice status.

Ambiguous, incomplete, malformed, disputed, refunded, partially paid, mismatched, or contradictory Stripe state SHALL NOT cause an invoice to be marked paid automatically.

The automated state transition SHALL be deliberately one-way:

```text
Kimai unpaid + sufficient Stripe payment evidence -> Kimai paid
```

The connector SHALL NOT automatically reverse a paid Kimai invoice to unpaid, canceled, or another state because Stripe later reports a refund, dispute, reversal, or other subsequent event.  Those conditions SHALL be surfaced for human review.

### Independent operation

Payment Link generation and reconciliation SHALL be independently enableable and disableable.

Disabling reconciliation SHALL leave Payment Link generation usable.

Disabling Payment Link generation SHALL leave existing invoice reconciliation usable when the connector can establish the required Stripe/Kimai correlation.

The connector SHOULD use the narrowest Stripe credentials practical for each operational responsibility.  If Stripe's restricted-key permission model permits useful separation of privileges without undue operational complexity, separate restricted credentials MAY be supported.

### Manual fallback

The connector SHALL NOT become a prerequisite for billing.

If the plugin is unavailable, incompatible with a future Kimai release, intentionally disabled, or otherwise unusable, the supported operational fallback remains:

```text
track time
-> generate Kimai invoice
-> create Stripe Payment Link manually
-> place the URL on or with the invoice
-> send the invoice
-> mark the Kimai invoice paid manually after payment
```

No incident involving the connector should require emergency development merely to continue sending invoices or receiving Stripe payments.

### Orphaned Payment Links

Because a Payment Link must exist before the invoice document is rendered, the following failure is possible:

```text
Stripe creates Payment Link
-> Kimai rendering or persistence fails
-> Stripe Payment Link remains without a completed Kimai invoice
```

This is an accepted consequence of the chosen event ordering.

The connector SHALL make such links recoverable through stable metadata so that a later retry of the same invoice can reuse an exact matching link when the financial data is unchanged.

The connector SHALL provide or support a conservative cleanup process for abandoned links.  Cleanup MAY run weekly or on another low-frequency schedule.

Cleanup SHALL distinguish between unpaid garbage and financial exceptions:

- A managed Payment Link with a matching Kimai invoice SHALL be retained.
- A managed Payment Link with no matching Kimai invoice and no completed payment MAY be deactivated after a grace period.
- A managed Payment Link with no matching Kimai invoice but evidence of payment SHALL NOT be treated as garbage.  It SHALL be reported for manual accounting review.
- If Kimai cannot be queried reliably, cleanup SHALL deactivate nothing.

The cleanup mechanism is maintenance, not part of the correctness path.  Failure to run cleanup for a period of time may leave unused Stripe objects but must not prevent invoicing or payment.

### Scope boundary

This connector SHALL remain a connector rather than becoming an accounting or billing platform.

The following are outside the current architectural scope unless separately proposed and accepted:

- recurring billing
- subscription management
- tax calculation
- Stripe customer synchronization beyond what is required for payment
- automated refund handling
- automated dispute resolution
- automatic reversal of Kimai payment state
- generalized bookkeeping
- a payment dashboard duplicating Stripe
- webhook-based public ingress as a requirement

Features that materially expand these responsibilities require a new ADR or an explicit update to this decision.

## Considered Alternatives

### Two independent Kimai plugins

One plugin would create and embed Stripe Payment Links, while a second plugin would perform payment reconciliation.

This approach has real advantages:

- stronger package-level separation of concerns
- independent installation and removal
- smaller failure blast radius
- potentially stronger least-privilege credential separation
- independent release cadence if the capabilities evolve differently

It was not selected for the initial implementation because both plugins would need to understand the same Kimai instance identity, Stripe metadata convention, invoice identity rules, money normalization, Stripe authentication, and compatibility assumptions.  Maintaining those concepts in two packages would either duplicate code and risk semantic drift or introduce a third shared package and additional version coordination.

At the current expected project size, the operational and maintenance costs of two plugins are not yet justified by the additional isolation.

This alternative remains viable.  The project SHOULD revisit it if Payment Link generation and reconciliation acquire substantially different dependencies, release cadences, maintainers, security requirements, or user populations.

### Payment Link plugin only

A smaller plugin could automate Payment Link creation and invoice rendering while leaving payment reconciliation manual.

This has the smallest financial automation risk and remains a valid fallback design.  It was not selected as the target architecture because keeping paid Stripe invoices visibly unpaid in Kimai reduces the value and accuracy of Kimai's invoice archive when the connector can conservatively establish payment state.

The implementation MAY still be staged so that Payment Link functionality is completed before reconciliation.

### Reconciliation plugin only

Users could continue creating Stripe Payment Links manually and use a plugin only to mark invoices paid.

This retains the clerical Payment Link creation step that originally motivated the connector and therefore does not satisfy the primary workflow goal by itself.

### No plugin

Users can create Stripe Payment Links manually, paste them into invoices, and reconcile payments manually.

This remains operationally valid and is intentionally preserved as the fallback.  It was not selected as the preferred workflow because the repeated handoff is straightforward to automate without requiring public ingress, core Kimai modification, or a new billing authority.

### Core Kimai modifications

Kimai core could be patched or forked to add Stripe behavior directly.

This is rejected.  The project SHALL use supported plugin and extension mechanisms.  Carrying Kimai source modifications would increase upgrade risk, maintenance cost, and coupling without providing a necessary capability.

### Webhook-driven reconciliation

Stripe webhooks could notify Kimai immediately when payments occur.

This is rejected as a requirement because it would introduce inbound public reachability or an additional externally reachable relay component.  The expected invoice volume does not justify that operational exposure.  Outbound polling provides adequate timeliness while preserving an internal-only Kimai deployment.

## Consequences

### Positive

- Users install and maintain one Kimai plugin rather than two.
- Payment Link and reconciliation behavior share one versioned metadata and identity contract.
- The plugin can remain small while preserving internal separation of concerns.
- Payment Link generation and reconciliation can fail independently.
- Kimai does not require public Internet ingress.
- Stripe remains the public payment surface.
- Manual billing remains available if the plugin is disabled or broken.
- Conservative reconciliation can keep Kimai invoice status accurate without attempting to reproduce Stripe accounting.
- Orphaned links are recoverable and can be cleaned asynchronously.
- A future split into two plugins remains possible if real operational experience justifies it.

### Negative

- A defect in the package or Kimai compatibility layer can potentially make both capabilities unavailable until the plugin is disabled or fixed.
- The plugin package has a broader conceptual responsibility than a single-purpose Payment Link plugin.
- Payment Link creation before invoice persistence can leave orphaned Stripe objects after Kimai failures.
- Polling means Kimai payment status is eventually consistent rather than instantaneous.
- Reconciliation introduces financial-state automation and therefore requires stronger testing and more conservative failure behavior than Payment Link generation alone.
- Kimai plugin compatibility becomes an ongoing maintenance responsibility for the project.

### Risks accepted

The project explicitly accepts eventual consistency between Stripe payment and Kimai paid status.

The project explicitly accepts the possibility of abandoned unpaid Payment Links because they can be identified, recovered, or deactivated without affecting customer payments.

The project does not accept guessing about ambiguous payment state.  False negatives, in which a paid invoice remains temporarily unpaid in Kimai, are preferable to false positives that incorrectly mark an invoice paid.

## Open Questions and Follow-Ups

The following items require validation during implementation:

- Verify the final Kimai plugin package structure and compatibility requirements against the supported Kimai release range.
- Verify the complete flow against a real Kimai installation and Stripe test-mode account.
- Determine the narrowest practical Stripe restricted-key permissions for Payment Link creation, reconciliation, and cleanup.
- Decide whether one Stripe credential or separate restricted credentials should be recommended.
- Define the exact metadata contract and its versioning rules before the first public release.
- Define the grace period used before deactivating unpaid orphaned Payment Links.
- Define how reconciliation reports disputes, refunds, and paid-orphan conditions without automatically changing previously reconciled Kimai state.
- Define the explicit operational bypass for Payment Link automation.
- Determine the supported Kimai versions and the project's compatibility-testing policy.
- Revisit the one-plugin decision if either capability grows large enough to justify an independent lifecycle.

## Related Decisions

- Related to: ADR-000: Capability Scope, Epistemic Honesty, and Separation of Concerns
