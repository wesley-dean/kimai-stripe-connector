# ADR-007: Use Stripe Payment Links as the Customer Payment Surface

Date: 2026-09-04

## Status

Accepted

## Context

Kimai remains the invoice system, while Stripe remains the payment processor.  The connector needs a durable hosted payment URL that can be placed on or with a Kimai invoice without making Stripe the invoice authority.

Stripe Payment Links provide a durable Stripe-hosted URL, metadata, Checkout Session correlation, and deactivation support.  Using Stripe Invoices would duplicate invoice authority and introduce product semantics this connector does not need.  Raw one-off Checkout Session URLs are less suitable as the stable customer-facing artifact.

## Decision

The connector SHALL use Stripe Payment Links as the customer-facing payment surface.

Kimai SHALL remain authoritative for invoice identity, document generation, amount, currency, and invoice state.

Stripe SHALL remain authoritative for actual payment movement and Stripe payment objects.

A connector-created Payment Link SHALL represent one fixed Kimai invoice payment and SHALL follow the compatibility/payment-method constraints defined in ADR-004.

The Payment Link URL may be rendered into the Kimai invoice through the supported extension seam defined in ADR-006.

A completed Checkout Session SHALL NOT, by itself, be treated as proof that the invoice is paid.  Reconciliation evidence is governed separately by ADR-009.

## Consequences

- Customers interact with Stripe-hosted payment pages rather than Kimai for payment collection.
- Existing issued Payment Links can remain usable while Kimai is unavailable.
- The connector must correlate Payment Links and downstream Stripe payment objects safely.
- Payment Link creation can succeed even if later Kimai persistence fails, requiring recovery logic under ADR-011.

## Considered Alternatives

### Stripe Invoices

Rejected because Kimai is already the invoice authority and the connector should not create a second invoice system merely to collect payment.

### Raw Checkout Session URLs

Rejected as the primary durable customer-facing object because the project benefits from a persistent Payment Link that can be rediscovered and managed independently of individual Checkout Sessions.

### Host a payment page inside Kimai

Rejected because it would unnecessarily expand Kimai's public/security surface and conflict with ADR-005.

## Related Decisions

- Related to: ADR-004
- Related to: ADR-005
- Related to: ADR-006
- Related to: ADR-008
- Related to: ADR-009
