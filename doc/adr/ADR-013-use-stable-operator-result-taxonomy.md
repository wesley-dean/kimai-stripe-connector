# ADR-013: Use a Stable Operator Result Taxonomy

Date: 2026-09-04

## Status

Accepted

## Context

Reconciliation, cleanup, configuration validation, and future operational commands need a shared vocabulary for outcomes.  Without one, logs, CLI exit behavior, tests, and runbooks tend to invent subtly different meanings for the same conditions.

The connector must distinguish ordinary idempotent no-op conditions from retryable infrastructure failure and from financial ambiguity that requires a human decision.

## Decision

Operator-facing connector operations SHALL classify results into a small stable taxonomy.

The initial categories are:

```text
SUCCESS
NO_CHANGE
RETRYABLE
CONFIGURATION_ERROR
CONFLICT
MANUAL_REVIEW_REQUIRED
INTERNAL_ERROR
```

### SUCCESS

The requested safe mutation or operation completed successfully.

Examples:

- an exact Payment Link was created;
- an invoice was safely marked paid; or
- a proven stale unpaid orphan was deactivated.

### NO_CHANGE

The operation completed successfully and intentionally made no state change.

Examples:

- reconciliation reruns against an already correctly paid invoice;
- cleanup finds no eligible orphans; or
- an exact compatible Payment Link is already present and requires no mutation.

`NO_CHANGE` is not an error.

### RETRYABLE

The connector cannot complete the operation because a temporary external or infrastructure condition prevents a reliable decision.

Examples:

- Stripe API timeout;
- Stripe rate limiting;
- temporary Kimai/database persistence failure; or
- scheduler-visible network failure.

A retryable condition SHALL NOT cause the connector to guess financial state.

### CONFIGURATION_ERROR

The requested operation cannot run safely because connector configuration is missing, contradictory, malformed, or unusable.

Examples include a missing credential, unreadable secret file, malformed UUID, or unsupported currency configuration.

### CONFLICT

The connector has found mutually incompatible facts that represent the same logical identity and automation cannot safely choose a meaning.

Examples include an existing connector-managed link for the same invoice identity with a different amount/currency or multiple competing exact-looking candidates.

### MANUAL_REVIEW_REQUIRED

The connector has identified a legitimate financial/accounting condition for which automatic mutation is deliberately outside policy.

Examples include a paid orphan, a post-payment refund/dispute anomaly, or supported evidence that is internally consistent but requires accounting judgment rather than automation.

### INTERNAL_ERROR

The connector encountered an unexpected programming/invariant failure rather than a modeled external condition.

These results require investigation and SHALL NOT be used as a catch-all for known Stripe/Kimai failure states.

## CLI Behavior

Human-readable CLI output SHALL include the result category for material actions or failures.

Machine-readable output, if provided, SHALL use stable category identifiers rather than parsing prose.

The first reconciliation command establishes the following process exit-code contract:

| Result category          | Exit code |
|--------------------------|----------:|
| `SUCCESS`                |         0 |
| `NO_CHANGE`              |         0 |
| `RETRYABLE`              |        10 |
| `CONFIGURATION_ERROR`    |        20 |
| `CONFLICT`               |        30 |
| `MANUAL_REVIEW_REQUIRED` |        40 |
| `INTERNAL_ERROR`         |        50 |

These values SHALL remain stable for machine consumers unless a later ADR deliberately changes the contract.

A batch command MAY continue processing independent invoices after an item-level conflict/error when doing so is safe.  For reconciliation, the process exit code SHALL be the highest mapped exit code among all item outcomes.  This preserves independent batch progress while ensuring that any material failure or operator-review condition remains visible to cron, Jenkins, systemd, or another scheduler.

A run-level failure that prevents invoice-level processing SHALL exit with the code mapped to that result category.  Disabled reconciliation and a run with no connector-managed Payment Links are successful `NO_CHANGE` outcomes and SHALL exit zero.

## Logging

Structured logs SHOULD include the result category plus safe identifiers sufficient for investigation.  Secret values, payment credentials, and unnecessary customer data SHALL remain excluded.

## Consequences

- Tests, runbooks, CLI commands, and logs can share one outcome vocabulary.
- Expected no-op behavior is not confused with failure.
- Financial ambiguity is distinguished from transient infrastructure problems.
- Schedulers can distinguish retry, configuration repair, financial conflict, manual review, and internal defects without parsing prose.
- Future categories and exit-code changes should be added sparingly because both may become machine-consumed contracts.

## Related Decisions

- Related to: ADR-003
- Related to: ADR-009
- Related to: ADR-010
- Related to: ADR-011
