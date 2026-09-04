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

The initial reconciliation command SHALL NOT claim distributed locking or serialized execution.  Before a Kimai paid-state mutation it SHALL re-read the invoice and refuse financial drift; an already-paid invoice SHALL remain an idempotent no-op.  Operators SHOULD configure cron, Jenkins, systemd, or another scheduler to avoid overlapping reconciliation processes.  If external serialization is required, it SHOULD be provided by the scheduler/runtime, for example with an appropriate single-run job policy or host-level locking mechanism.

Two overlapping reconciliation processes may both observe the same unpaid invoice before either commits.  Because reconciliation is Stripe-read-only and the only automatic Kimai transition is monotonic unpaid-to-paid with the same proven payment date, those writes converge on the same financial state.  Duplicate Kimai update-event execution remains possible, so overlapping runs are an operational condition to avoid rather than a behavior the connector promises to serialize internally.

Cleanup also SHALL NOT claim a transaction spanning Kimai and Stripe or connector-owned distributed locking.  Before Payment Link deactivation it SHALL re-query Kimai, rediscover the current managed Payment Link set, rediscover Stripe payment evidence, and re-run the conservative cleanup decision.  Its Stripe mutation boundary SHALL then retrieve the selected Payment Link and revalidate exact identity/metadata before setting `active=false`.

Cleanup deactivation is a monotonic idempotent Stripe mutation.  If an API failure leaves the connector uncertain whether `active=false` committed, the operation SHALL be reported as retryable rather than followed by a different destructive action.  A later run can rediscover the Payment Link and treat an already-inactive link as a no-op.

A small remote race remains possible between the final Stripe read and Stripe's update because Kimai and Stripe do not share a transaction.  The connector reduces the preventable race window through repeated evidence reads but does not promise impossible cross-system atomicity.  Operators SHOULD avoid overlapping cleanup jobs, and an unexpected late payment or other anomaly SHALL be retained/surfaced by subsequent reconciliation rather than causing an automatic historical paid-state reversal.

## Consequences

- Stripe may contain recoverable orphan objects after Kimai failures.
- The connector needs exact correlation and conflict diagnostics.
- Some ambiguous states require manual review instead of automatic repair.
- Retry and timeout paths are first-class test cases, not rare edge cases.
- Reconciliation remains financially idempotent without introducing connector-owned distributed-lock state.
- Cleanup uses repeated revalidation and one-way deactivation rather than connector-owned transaction state.
- Operators must avoid overlapping reconciliation jobs when duplicate Kimai update-event execution would be undesirable.
- Operators should also avoid overlapping cleanup jobs even though repeated `active=false` application converges safely.

## Considered Alternatives

### Treat Stripe and Kimai as one transaction

Rejected because no distributed transaction spans these systems.

### Store authoritative pending state only in the container filesystem

Rejected because containers are replaceable and ephemeral in the reference environment and should be portable elsewhere.

### Always create a new link after timeout

Rejected because the remote operation may have committed before the response was lost.

### Add a connector-owned durable transaction ledger immediately

Rejected because Stripe metadata and Kimai state provide a simpler recovery mechanism.  A separate ledger requires new evidence and a later ADR.

### Add connector-owned distributed locking for reconciliation immediately

Rejected for the initial release because reconciliation performs no Stripe mutation, the Kimai transition is monotonic/idempotent, and introducing shared lock state would add deployment coupling without improving the underlying payment evidence.  Scheduler-level non-overlap plus last-moment Kimai revalidation is the smaller initial contract.

### Add connector-owned distributed locking for cleanup immediately

Rejected because cleanup is low-frequency hygiene work, the mutation is monotonic/idempotent, and conservative repeated reads materially reduce the race window without adding deployment-coupled state.  A later operational requirement for stronger serialization can use scheduler-level locking or justify a new decision.

## Related Decisions

- Related to: ADR-006
- Related to: ADR-007
- Related to: ADR-008
- Related to: ADR-009
- Related to: ADR-010
- Related to: ADR-003
