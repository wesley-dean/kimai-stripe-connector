# ADR-003: Require Tests for Behavioral Changes

Date: 2026-09-04

## Status

Accepted

## Context

The connector changes financial state and depends on two independently evolving systems.  Regressions can therefore be more consequential than ordinary presentation defects.  The project also uses AI/LLM-assisted development, which increases the value of executable contracts that survive across sessions.

A blanket rule that every changed line requires a new test would create meaningless tests for prose, comments, formatting, and other non-behavioral changes.  A permissive rule that leaves tests to discretion would allow API, reconciliation, money, configuration, and failure-path behavior to change without durable evidence.

The useful boundary is observable behavior.

## Decision

Every addition, change, bug fix, compatibility adjustment, or patch that changes or introduces executable behavior SHALL include new or updated automated tests when an automated test can reasonably verify that behavior.

Tests SHALL normally be part of the same coherent change as the behavior they protect.

Examples that normally require test work include:

- adding or changing a Stripe API call;
- changing request parameters or metadata;
- changing Kimai event integration;
- changing configuration parsing or precedence;
- changing money or currency conversion;
- changing correlation rules;
- changing reconciliation evidence requirements;
- changing cleanup eligibility;
- changing exception or retry behavior;
- fixing a defect in executable code;
- changing CLI exit status or machine-readable output;
- changing supported-version compatibility behavior; and
- changing security-sensitive validation or redaction.

A bug fix SHOULD include a regression test that would have failed before the fix and passes after it.

Changes that do not change executable behavior do not require a new unit test merely to satisfy this ADR.  Examples include:

- correcting prose;
- changing documentation text;
- adding or improving comments without changing executable code;
- formatting-only changes;
- typo corrections;
- generated-documentation configuration changes that are already verified by an appropriate documentation/build check; and
- metadata-only repository changes with no runtime effect.

The absence of a newly added test for such a change is not a coverage defect.

## Appropriate Test Layer

The project SHALL use the cheapest deterministic layer that can prove the behavior.

- Unit tests: pure configuration, money, metadata, identity, state-decision, and result-taxonomy logic.
- Kimai integration tests: plugin loading, dependency injection, event subscribers, invoice model/hydrator behavior, rendering seams, and Kimai state mutation.
- Controlled fake-Stripe tests: network failures, timeout-after-commit, pagination, retries, rate limits, malformed API responses, and concurrency.
- Real Stripe sandbox E2E: a small number of high-value proofs that exercise real Stripe object relationships and hosted Checkout.

The ADR does not require every behavior to be tested at every layer.

## Documentation Relationship

Documentation establishes the intended contract before or alongside implementation.  Tests verify that contract; tests do not silently become the only place where important product behavior is defined.

When a behavioral change requires documentation, the documentation and tests SHALL be updated in the same coherent change.

## CI and Completion

A behavioral change is incomplete when its appropriate automated tests are absent, failing, skipped without documented reason, or unable to run in the supported project workflow.

Required validation includes the applicable unit/integration/E2E suite plus the repository's lint and documentation gates.  MegaLinter must remain error- and warning-free under the project policy.

## Considered Alternatives

### Require a new unit test for every change

Rejected because prose, documentation, formatting, and other non-behavioral edits do not gain safety from artificial unit tests.

### Require tests only for new features

Rejected because bug fixes and compatibility patches are especially valuable places for regression tests.

### Leave test coverage entirely to reviewer judgment

Rejected because the project benefits from a durable default rule that future human and AI/LLM contributors can apply consistently.

## Consequences

The project will generally move somewhat more slowly per behavioral patch and should accumulate a stronger executable specification over time.

Tests will focus on observable contracts rather than implementation trivia.

Reviewers may accept no-test changes when the change is genuinely non-behavioral, but the reason should be evident from the diff.

## Related Decisions

- Related to: ADR-001
- Related to: ADR-002
