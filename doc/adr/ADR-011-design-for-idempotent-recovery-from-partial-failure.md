# ADR-011: Design for Idempotent Recovery from Partial Failure

Date: 2026-09-04

## Status

Accepted

## Context

Stripe object creation and Kimai invoice persistence cannot participate in one atomic transaction.  Network failures can also leave the connector uncertain whether a remote Stripe mutation committed.

Expected failure sequences include:

```text
send Stripe create request
-> Stripe commits
-> response is lost
-> connector observes timeout
```

and:

```text
Stripe Payment Link created
-> Kimai render/persistence fails
-> Stripe object remains
```

Pretending these operations are atomic would turn ordinary distributed-system failure into duplicate payment surfaces or hidden inconsistency.

## Decision

The connector SHALL be designed around idempotency, durable correlation, rediscovery, and repair rather than distributed atomicity.

For immediate retry of a Stripe mutation, the connector SHOULD use Stripe idempotency facilities where appropriate.

For recovery beyond Stripe's idempotency retention window, the connector SHALL use the stable versioned metadata contract defined in ADR-008 to rediscover an exact compatible object.

A retry SHALL NOT blindly create a second Payment Link when the connector cannot determine whether an earlier create request committed.

When exactly one existing connector-managed Payment Link matches the expected instance identity, invoice identity, amount, currency, and metadata contract, the connector SHOULD recover/reuse that link.

When an object with the same logical invoice identity conflicts on financial meaning, including amount or currency, the connector SHALL stop automatic mutation and require manual resolution rather than silently reuse or create a competing payment surface.

When multiple plausible matches exist and the connector cannot prove which one is correct, it SHALL fail closed.

The implementation SHALL be safe under repeated scheduler runs, retries, application restarts, and ordinary container replacement.

Authoritative connector state SHALL NOT depend on ephemeral container-local files.

Concurrency behavior SHALL be tested.  Concurrent attempts to create the same logical payment surface must converge safely or fail conservatively without creating uncontrolled duplicates.

## Consequences

- Stripe may contain recoverable orphan objects after Kimai failures.
- The connector needs exact correlation and conflict diagnostics.
- Some ambiguous states require manual review instead of automatic repair.
- Retry and timeout paths are first-class test cases, not rare edge cases.

## Considered Alternatives

### Treat Stripe and Kimai as one transaction

Rejected because no distributed transaction spans these systems.

### Store authoritative pending state only in the container filesystem

Rejected because containers are replaceable and ephemeral in the reference environment and should be portable elsewhere.

### Always create a new link after timeout

Rejected because the remote operation may have committed before the response was lost.

### Add a connector-owned durable transaction ledger immediately

Rejected because Stripe metadata and Kimai state provide a simpler recovery mechanism.  A separate ledger requires new evidence and a later ADR.

## Related Decisions

- Related to: ADR-006
- Related to: ADR-007
- Related to: ADR-008
- Related to: ADR-010
- Related to: ADR-003
