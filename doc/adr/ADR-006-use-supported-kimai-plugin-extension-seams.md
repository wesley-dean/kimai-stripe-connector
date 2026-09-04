# ADR-006: Use Supported Kimai Plugin Extension Seams

Date: 2026-09-04

## Status

Accepted

## Context

The connector must insert a Stripe-hosted payment URL into a Kimai-generated invoice while remaining removable and upgradeable.  The design review inspected Kimai 2.65.0 source and identified supported extension seams that can support this workflow without modifying Kimai core.

Important findings include:

- `InvoicePreRenderEvent` occurs before invoice rendering;
- previews are distinguishable through `InvoiceModel::isPreview()`;
- `InvoiceModel` supports options;
- `InvoiceModelHydrator` can add data to `InvoiceModel::toArray()`;
- Twig invoice rendering receives that hydrated invoice data; and
- `InvoiceCreatedEvent` occurs too late to insert a newly created payment URL into an already rendered document.

## Decision

The connector SHALL use Kimai's supported plugin/bundle mechanisms and SHALL NOT require patches, forks, vendor modifications, or maintained changes to Kimai core.

For Payment Link generation and invoice rendering, the initial implementation SHALL prefer this seam:

```text
InvoicePreRenderEvent
-> if preview: no Stripe mutation
-> create/recover exact Payment Link
-> attach connector data to InvoiceModel
-> InvoiceModelHydrator exposes connector data
-> Twig invoice template renders Stripe URL
```

The implementation MAY refine class names or internal service structure while preserving the supported extension boundary.

The plugin SHALL live under Kimai's normal plugin lifecycle and packaging conventions.  It SHALL be disableable/removable without making ordinary Kimai invoicing dependent on connector-owned core changes.

A different Kimai extension seam MAY replace this one when a supported Kimai release provides a materially better mechanism.  Such a change requires compatibility tests and documentation under ADR-003; it requires a new architecture ADR only if it changes the product boundary or requires core modification.

## Consequences

- Plugin lifecycle and Kimai compatibility become explicit test targets.
- Invoice previews must remain side-effect-free.
- Payment Link work occurs before final Kimai invoice persistence, creating an unavoidable partial-failure window addressed by ADR-011.
- Kimai upgrades can break supported extension assumptions even without PHP compile errors, so integration tests are necessary.

## Considered Alternatives

### Modify Kimai core invoice generation

Rejected because it makes upgrades and removal substantially more difficult and violates the project's integration rule.

### Create the link only after `InvoiceCreatedEvent`

Rejected for the initial workflow because the invoice document is already rendered by that point.

### Maintain a custom Kimai image containing patched source

Rejected as an implementation requirement.  Container packaging may carry the plugin, but the connector SHALL remain a normal Kimai plugin rather than a Kimai fork.

## Related Decisions

- Related to: ADR-001
- Related to: ADR-007
- Related to: ADR-011
