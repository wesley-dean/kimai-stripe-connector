# Source Documentation Standard

This document defines the source-code documentation standard for the Kimai Stripe
Connector.  It adapts the documentation-first conventions used by
`wesley-dean/bootstrap` and `wesley-dean/adrctl` to PHP and to this project's
financial and security responsibilities.

ADR-002 is the architectural decision behind this standard.  This document is the
working normative guide for contributors.

## Purpose

Source documentation is part of the implementation contract.

The intended reader may be:

- a contributor unfamiliar with the code;
- a maintainer returning months later;
- a reviewer investigating a payment-state discrepancy;
- a developer debugging a Kimai compatibility problem;
- a security reviewer tracing credential or metadata flow; or
- an AI/LLM-based development tool operating without conversational history.

The standard therefore optimizes for comprehension under cognitive load rather
than brevity.

Comments should explain what a reader cannot reliably recover from syntax alone:
intent, responsibility, constraints, assumptions, invariants, ownership, failure
semantics, side effects, security posture, and the reason a non-obvious guard
exists.

## Documentation Targets

Document the following whenever they exist:

- PHP files/modules;
- public classes, interfaces, traits, and enums;
- public methods and functions;
- non-trivial protected/private helpers;
- important properties and policy-defining constants;
- configuration boundaries represented in code;
- value objects or structures whose invariants are not obvious;
- validation and safety guards;
- reconciliation and cleanup decisions;
- external API boundaries; and
- exceptional behavior that a maintainer could otherwise misinterpret.

Trivial accessors and framework boilerplate do not need ceremonial prose when the
native declaration completely communicates the contract.  Documentation density
should follow semantic risk, not line count.

## PHP Comment Form

Use PHPDoc/Doxygen-compatible block comments:

```php
/**
 * @brief One-sentence summary.
 *
 * @details
 * Longer explanation of responsibility, constraints, and reasoning.
 */
```

Do not mechanically reproduce the Bash syntax from sibling repositories.  PHP is
parsed natively by Doxygen, so methods normally do not need an explicit `@fn`
entry.

Use native PHP parameter, return, property, union, nullable, and enum types as the
primary type contract.  Documentation should add semantic meaning rather than
repeat a type declaration word-for-word.

## File Documentation

Every maintained PHP implementation file SHOULD begin with a file-level block when
the file has a meaningful module responsibility:

```php
<?php

/**
 * @file PaymentLinkService.php
 * @brief Creates or recovers connector-managed Stripe Payment Links.
 *
 * @details
 * This module owns the Stripe-side creation/recovery boundary for one Kimai
 * invoice identity.  It must not mark invoices paid and must not create a local
 * payment ledger.
 *
 * @par Security
 * Stripe credentials must never be included in exceptions, logs, invoice model
 * data, or rendered documents.
 *
 * @par Invariants
 * A reusable link must match the connector namespace, Kimai instance, invoice
 * identity, amount, and currency exactly.
 */
```

A file block should establish module responsibility and important boundaries.  It
should not repeat every class member documented later in the file.

## Class and Interface Documentation

Document a class or interface with enough context to establish its ownership
boundary:

```php
/**
 * @brief Correlates one Kimai invoice with connector-managed Stripe objects.
 *
 * @details
 * The correlator is responsible for proving identity and financial equivalence.
 * It does not decide whether a Kimai invoice should be mutated; reconciliation
 * policy remains a separate responsibility.
 *
 * @par Invariants
 * Correlation never treats invoice number alone as sufficient identity.
 *
 * @par Side Effects
 * None.  Correlation is observational and must not mutate Kimai or Stripe.
 */
final class StripeCorrelationService
{
}
```

For interfaces, document the behavioral contract and caller expectations rather
than implementation strategy.

## Method and Function Documentation

A non-trivial method SHOULD document the applicable fields below.

### `@brief`

Use one concise sentence describing the responsibility rather than the mechanism.

Good:

```text
@brief Determines whether Stripe evidence is sufficient to mark an invoice paid.
```

Weak:

```text
@brief Loops through sessions and checks them.
```

### `@details`

Use `@details` for context a maintainer would otherwise have to reconstruct:

- why the method exists;
- how it fits the ownership boundary;
- why a conservative choice is intentional;
- what evidence is trusted;
- what the method deliberately refuses to infer; or
- why an apparently redundant validation guard is necessary.

### `@param`

Describe semantics, units, accepted forms, normalization, and ownership where they
matter.

```php
/**
 * @param Money $invoiceAmount Authoritative Kimai invoice amount; already
 *     normalized according to the connector's supported-currency policy.
 */
```

Do not write `@param string $currency The currency string.` when the declaration
already says that and no additional contract exists.

### `@return`

Document semantic meaning rather than repeating the type.

```php
/**
 * @return ReconciliationDecision A non-mutating decision describing whether the
 *     observed Stripe evidence is strong enough for the caller to update Kimai.
 */
```

### `@retval`

Use `@retval` when individual scalar or enum-like return values have distinct
behavioral meaning worth calling out.

For object-returning PHP APIs, `@return` plus enum/value-object documentation is
usually clearer.

### `@throws`

Document exceptions that form part of the method contract, especially when the
caller is expected to distinguish configuration, transient operational failure,
data conflict, or accounting exception.

```php
/**
 * @throws CorrelationConflict When matching metadata exists but amount or
 *     currency contradicts the Kimai invoice.
 * @throws StripeUnavailable When Stripe cannot be queried reliably enough to
 *     produce a safe decision.
 */
```

Do not list every incidental implementation exception that is caught internally
and never escapes the method.

## Narrative Sections

Doxygen `@par` sections provide a consistent way to preserve the richer content
used by the sibling projects without inventing PHP-specific tags.

Use these headings where applicable:

- `@par Preconditions`
- `@par Assumptions`
- `@par Invariants`
- `@par Side Effects`
- `@par Ownership`
- `@par Failure Modes`
- `@par Security`
- `@par Idempotency`
- `@par Concurrency`
- `@par Compatibility`
- `@par Non-Goals`
- `@par Examples`

Not every block should contain every heading.  Use a section when its absence would
force a future reader to infer consequential behavior.

## Examples

Examples should resemble real maintainer or caller behavior and should clarify a
contract rather than merely repeat the method signature.

```php
/**
 * @brief Produces the connector metadata contract for a Kimai invoice.
 *
 * @par Examples
 * @code{.php}
 * $metadata = $factory->forInvoice($invoice);
 * assert($metadata['integration'] === 'kimai-stripe-connector');
 * assert($metadata['invoice_number'] === $invoice->getInvoiceNumber());
 * @endcode
 */
```

Examples that depend on Stripe, Kimai, or Symfony APIs should use supported public
interfaces and should be updated when compatibility policy changes.

## Financial-State Documentation

Code that can influence invoice paid state, Payment Link reuse, or cleanup
eligibility requires unusually explicit documentation.

Document, as applicable:

- authoritative inputs;
- exact identity checks;
- amount/currency checks;
- required Stripe object state;
- evidence that is insufficient;
- false-positive versus false-negative posture;
- idempotency behavior;
- timeout-after-commit handling;
- concurrency behavior;
- manual fallback;
- whether the operation mutates Kimai, Stripe, both, or neither; and
- what happens when evidence is ambiguous.

A maintainer should not have to infer from control flow why a questionable payment
does not mark an invoice paid.

## Security Documentation

Security-sensitive classes and methods should document:

- credential exposure boundaries;
- whether sensitive values may enter logs or exceptions;
- trust boundaries crossed by an operation;
- externally supplied identifiers or URLs;
- validation performed before network access or mutation;
- destructive behavior such as Payment Link deactivation; and
- assumptions whose failure could create an SSRF, confused-deputy, cross-instance,
  or accounting risk.

Do not place secrets, live keys, customer data, or sensitive operational values in
documentation examples.

## Configuration Documentation

Configuration has two documentation homes:

1. `doc/configuration.md` defines the user/operator contract, precedence, defaults,
   and deployment guidance.
2. Source comments document the implementation boundary that consumes and
   validates those values.

When code introduces, removes, renames, changes the default of, or changes the
semantics of a configuration value, update both surfaces in the same coherent
change.

Configuration constants or value-object properties SHOULD use `@var`, `@brief`,
or normal PHPDoc prose when the name and PHP type are insufficient to communicate
units, secrecy, precedence, or safety semantics.

## Constants and Properties

Document policy-defining constants and non-obvious state.

```php
/**
 * @var int
 * @brief Minimum orphan age, in seconds, before an unpaid link may be considered
 * for conservative cleanup.
 *
 * @details
 * Age alone never authorizes deactivation.  Identity, payment state, and Kimai
 * lookup results must also satisfy cleanup policy.
 */
private const ORPHAN_GRACE_SECONDS = 604800;
```

Avoid comments that merely restate literal values.

## Ambiguity and `@todo`

Never invent rationale to make documentation look complete.

When repository evidence cannot establish purpose, assumptions, or whether a
special case is intentional, use a specific `@todo`:

```php
/** @todo Confirm whether this retry boundary is required by Kimai lifecycle behavior. */
```

Good TODOs state what is unknown.  They do not fabricate a proposed explanation.

A visible uncertainty marker is preferable to confident but false documentation.

## Documentation-Only Changes

When a change is explicitly documentation-only:

- executable behavior must remain unchanged;
- work in bounded, reviewable increments;
- do not opportunistically refactor while adding comments;
- review the diff to confirm only intended documentation changed;
- run syntax/lint/tests when practical; and
- run Doxygen generation when PHP documentation is affected.

If preserving behavior cannot be established confidently, stop the documentation
rewrite rather than silently alter code.

## Generated Reference Documentation

Doxygen reads maintained PHP source directly using the root `Doxyfile` and writes
HTML under:

```text
doc/reference/
```

`doc/reference/` is generated build output and is ignored by Git.

Generated HTML must never be edited manually.  Fix source comments or `Doxyfile`
and regenerate instead.

Until the project has a settled PHP build orchestration surface, local generation
is:

```text
doxygen Doxyfile
```

A future Composer/Make/CI wrapper should call the same configuration rather than
creating a second documentation-generation path.

The intended CI posture is strict: malformed documentation should fail the docs
job rather than produce a green workflow with hidden warnings.

## Documentation Ownership Map

Use the following document for the corresponding kind of information:

| Information                             | Authoritative home                       |
|-----------------------------------------|------------------------------------------|
| Project purpose and orientation         | `README.md`                              |
| Contributor/agent operating rules       | `AGENTS.md`                              |
| Architectural rationale and history     | `doc/adr/`                               |
| ADR discovery summary                   | `doc/decisions.md`                       |
| Current public behavior                 | `doc/connector-spec.md` once established |
| Configuration contract                  | `doc/configuration.md`                   |
| Security model                          | `doc/threat-model.md`                    |
| Test strategy/evidence                  | `doc/testing/`                           |
| Source-comment requirements             | `doc/documentation.md`                   |
| Implementation contracts                | PHP source comments                      |
| Generated API/reference pages           | `doc/reference/`                         |
| Operator procedures / incident response | `doc/operator-runbook.md`                |
| Cross-session continuity                | `doc/project-handoff.md`                 |

When a behavior crosses several surfaces, update all affected authoritative homes
in the same coherent change rather than allowing one to drift.

## Completion Standard

A PHP implementation change is not documentation-complete when a reader can only
understand the new behavior by reconstructing it from code.

Before considering a change complete, ask:

- Does the relevant ADR/specification still describe the intended behavior?
- Does source documentation explain the implementation contract and important
  reasoning?
- Are parameters, failures, side effects, and invariants documented where they are
  consequential?
- Did configuration or threat-model behavior change?
- Can Doxygen generate the reference output without documentation errors or
  warnings attributable to the change?

The goal is not documentation volume for its own sake.  The goal is to preserve
the knowledge needed for sound judgment and fewer incidents.
