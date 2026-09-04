# ADR-005: Keep Kimai Private and Integrate with Stripe Outbound-Only

Date: 2026-09-04

## Status

Accepted

## Context

Kimai is an internal billing/time-tracking application in the reference deployment.  Stripe already provides the public customer-facing payment surface through hosted Payment Links and Checkout.

Requiring Stripe webhooks or callback routes into Kimai would create a new public trust boundary, firewall/reverse-proxy configuration, certificate/routing concerns, and an additional availability dependency.  The expected billing volume is low enough that asynchronous polling is operationally acceptable.

## Decision

The connector SHALL NOT require public Internet ingress to Kimai.

The connector SHALL NOT require Stripe webhooks for correctness.

Stripe SHALL remain the public customer payment surface.  The connector may make outbound HTTPS requests from the Kimai runtime or an authorized scheduler/runtime to Stripe.

Payment reconciliation SHALL be repair-oriented and externally schedulable through an ordinary CLI command.  Suitable schedulers include cron, systemd timers, Jenkins, container schedulers, and manual execution.

A missed reconciliation run SHALL result in temporary stale Kimai state rather than lost accounting information.  A later run SHALL be able to repair that state from current Kimai and Stripe evidence.

The manual workflow remains permanently valid:

```text
create Kimai invoice
-> create Stripe Payment Link manually
-> include URL with invoice
-> verify payment in Stripe
-> mark Kimai invoice paid manually
```

The plugin may provide optional webhook support in a future architecture only through a later ADR that explicitly evaluates the new ingress and security boundary.

## Consequences

- Reconciliation is eventually consistent rather than immediate.
- Stripe payment remains available even if Kimai is temporarily unavailable.
- Kimai does not need a public route solely for this connector.
- The connector needs deterministic, idempotent reconciliation suitable for repeated execution.
- Temporary scheduler failure is operationally tolerable.

## Considered Alternatives

### Stripe webhooks into Kimai

Rejected for the initial architecture because they introduce public ingress without being necessary for the expected billing volume or correctness model.

### Polling from a separate connector service

Deferred because an additional service increases deployment and maintenance surface without a demonstrated need.  A Kimai plugin CLI command is sufficient initially.

## Related Decisions

- Related to: ADR-001
- Related to: ADR-009
- Related to: `doc/reference-hosting-environment.md`
- Related to: `doc/threat-model.md`
