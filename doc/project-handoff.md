# Project Handoff

Date: 2026-09-04

## Purpose

This document gives a new human or AI/LLM engineering session enough current context to resume `kimai-stripe-connector` without the conversation that produced the project.

For autonomous work, begin with [`implementation-playbook.md`](implementation-playbook.md), then use [`decisions.md`](decisions.md) to locate the relevant Accepted ADRs.  ADRs and explicit contracts remain authoritative over this handoff.

## Current State

Phase 1 foundation work was merged to `main` through PR #5.  The current implementation work is Phase 2 on:

```text
feat/phase-2-primitives
```

with draft PR #6:

```text
feat: implement phase 2 connector primitives
```

Do not assume the branch or PR remains open forever; inspect current repository and PR state when resuming.

All numbered ADRs 000 through 013 remain Accepted.  No Accepted architectural boundary has been reversed by Phase 2.

The repository now contains:

- an installable Kimai plugin bundle skeleton;
- PHP/Composer package metadata and a committed lockfile resolved on minimum-supported PHP 8.2;
- PHPUnit, PHPStan, PHPCS, and PHP-CS-Fixer project-local tooling;
- PHP 8.2–8.5 CI using the same locked development dependency graph;
- a disposable Kimai 2.65.0 + MySQL plugin-lifecycle E2E harness;
- production-shaped E2E plugin staging using `composer install --no-dev`;
- blocking MegaLinter with zero-error/zero-warning policy and auto-fixes disabled;
- Doxygen/PHPDoc documentation tooling with warnings treated as failures;
- Accepted architecture/configuration/testing/security contracts; and
- Phase 2 pure/local primitives for configuration, identity, money, metadata, secret safety, operator results, and Stripe client construction.

No production Stripe Payment Link creation, deactivation, payment reconciliation, Kimai paid-state mutation, or other payment mutation exists yet.

No real Stripe credential is required for Phase 2.  Unit tests construct synthetic restricted-key-shaped strings at runtime.  Do not add a real Stripe key merely to run the current unit/static/lifecycle suite.

## Phase 2 Implemented Primitives

### Configuration

`Common/Configuration/EnvironmentConfigurationLoader.php` implements the namespaced environment contract from `doc/configuration.md`.

Implemented semantics include:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED                  default false
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED    default false
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED   default false
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS       default 7, range 1..3650
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID               stable canonical non-nil UUID
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY            direct restricted key
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE       mounted-secret path
```

Boolean syntax accepts case-insensitive `true`, `false`, `1`, and `0` only.  Whitespace and alternate truthy spellings are rejected rather than guessed.

A credential and instance UUID are required when the connector is globally enabled and at least one Stripe-backed capability is enabled.  Contradictory or malformed values remain configuration errors even when unrelated behavior is disabled; disabling the connector is not intended to hide broken configuration.

Direct and file-backed credential forms are mutually exclusive.  File-backed secret contents are trimmed to tolerate a terminal newline.  The direct form is not silently trimmed.

The loader has both an explicit-map entry point for deterministic tests and a process-environment entry point.  Tests prove that process-environment values use the same parser, preserving the contract that `.env` is only a possible environment-population mechanism rather than a second configuration system.

### Stripe credential

`StripeCredential` accepts restricted Stripe credential shapes beginning with:

```text
rk_test_
rk_live_
```

and rejects broad `sk_` secret keys.  This implements the Accepted least-privilege credential boundary rather than treating it as deployment advice only.

The credential object can distinguish test from live mode from the key prefix for later safety checks, but Phase 2 does not invent an expected-mode configuration switch.

### Stable Kimai identity

`KimaiInstanceId` validates a canonical non-nil UUID and normalizes it to lowercase for metadata output.

The configured UUID is a deployment identity, not a generated-per-container value.  It must survive restarts, container replacement, plugin upgrades, node rescheduling, and scheduler changes.  Changing it after Stripe objects exist is a metadata namespace migration.

### Money

`Money` and `CurrencyCode` implement the ADR-004 version-1 money policy:

- decimal invoice input is parsed as text;
- binary floating point is never authoritative;
- no rounding is performed;
- at most two fractional decimal digits are accepted;
- amount must be positive;
- currency is normalized to lowercase;
- ordinary two-decimal Stripe presentment currencies are supported;
- zero-decimal and Stripe special-representation currencies fail closed; and
- amounts above Stripe's eight-digit minor-unit payment limit fail before remote mutation.

The current maximum represented Stripe amount is:

```text
99,999,999 minor units
```

For USD this is `$999,999.99`.

Stripe minimum-charge rules vary by currency and are deliberately not encoded in the pure `Money` value object.  Payment-surface eligibility can be handled at the Stripe capability boundary without corrupting exact invoice-money representation.

### Metadata

`InvoiceMetadata` implements version 1 of the required Stripe correlation contract:

```text
integration=kimai-stripe-connector
integration_version=1
kimai_instance=<stable UUID>
invoice_number=<Kimai invoice number>
invoice_amount=<integer minor units>
invoice_currency=<lowercase currency>
```

Generated connector metadata contains those six fields.  Parsing requires every field, validates the known integration/version/identity/money contract, and fails closed on unknown versions or malformed values.

Unrelated metadata fields do not redefine connector identity and are ignored by the parser.  Version 1 does not add customer names, email addresses, or other unnecessary PII.

### Secret-safe diagnostics

`ConnectorConfiguration::safeLogContext()` exposes only non-secret operational fields such as capability flags, cleanup grace, instance UUID, and credential source.

`SecretRedactor` provides deterministic replacement of known non-empty secret values with `[REDACTED]`.

These are pure primitives.  Real Kimai startup/integration log inspection remains necessary before THR-001/THR-002 and CFG-011/CFG-012 are considered fully proven at the deployment boundary.

### Operator result taxonomy

`OperatorResult` implements ADR-013 exactly:

```text
SUCCESS
NO_CHANGE
RETRYABLE
CONFIGURATION_ERROR
CONFLICT
MANUAL_REVIEW_REQUIRED
INTERNAL_ERROR
```

Only `SUCCESS` and `NO_CHANGE` are successful outcomes.  Exact future CLI numeric exit-code mapping remains deferred until the first CLI command implementation, as ADR-013 permits.

### Stripe client factory

`StripeClientFactory` constructs the locked Stripe PHP SDK client with:

- the validated restricted credential; and
- non-secret application identification for `kimai-stripe-connector`.

It deliberately does not override `Stripe-Version`; the exact installed Stripe PHP SDK controls the API-version baseline under ADR-004.

Constructing the client performs no remote request and no payment mutation.

## Test Coverage Added in Phase 2

Current Phase 2 unit coverage includes:

- configuration validity, malformed values, and contradictory secret forms;
- direct and file-backed credential resolution;
- process-environment/parser equivalence;
- restricted-key enforcement;
- stable UUID validation and normalization;
- exact money conversion and malformed precision;
- zero/negative and unsupported-currency failure;
- Stripe eight-digit amount upper boundary and overflow rejection;
- exact version-1 metadata generation/parsing;
- unknown/malformed metadata failure;
- safe configuration log context;
- deterministic secret redaction;
- ADR-013 result categories; and
- Stripe client construction with SDK-aligned API version behavior.

See [`testing/test-matrix.md`](testing/test-matrix.md) for scenario mapping.

The first Phase 2 CI pass demonstrated 70 PHPUnit tests / 107 assertions passing and PHPStan level 8 clean before PHPCS/PHP-CS-Fixer found style-only issues.  Those style findings were corrected.  Subsequent commits added the Stripe amount bound and process-environment coverage, so do not quote the earlier 70/107 count as the final Phase 2 test count without checking the final head.

## Verification State

At the time this handoff text was written, final-head CI was still running because recent code/documentation commits retriggered the workflows.

Verified earlier during this Phase 2 branch:

- PHPUnit behavior was passing before later additive tests;
- PHPStan level 8 reported no errors;
- disposable Kimai lifecycle E2E passed on an earlier Phase 2 head;
- Doxygen/Documentation passed on multiple Phase 2 heads; and
- identified PHPCS/PHP-CS-Fixer findings were style-only and were fixed rather than suppressed.

Required before describing PR #6 as clean:

1. verify PHP 8.2, 8.3, 8.4, and 8.5 all pass on the final head;
2. verify Documentation/Doxygen passes with zero warnings;
3. verify disposable Kimai E2E passes on the final head;
4. inspect the final MegaLinter report, not merely workflow conclusion, and require every active linter to report zero errors and zero warnings;
5. correct any CSpell/table/style findings rather than weakening the gate; and
6. update PR #6 validation status with the verified results.

Keep PR #6 draft until owner review unless the owner explicitly requests otherwise.  Do not merge or release without explicit owner authorization.

## Phase 1 E2E / Packaging Finding to Preserve

The disposable Kimai E2E harness must continue staging a production-shaped plugin from the committed lockfile using `composer install --no-dev`.

Do not copy the repository-root development `vendor/` tree into Kimai.  That earlier test shape introduced development Symfony/PSR dependencies into Kimai and caused duplicate `Psr\Container\ContainerInterface` declarations even though pristine Kimai itself was healthy.

The production artifact carries only the plugin's production dependency subset under ADR-012.  The repository development dependency graph is not the install artifact.

The harness remains disposable and destroys containers, networks, volumes, and its local staging directory after every run.

## Security / Secret Boundary

No real key belongs in source control, documentation examples, test fixtures, PR comments, or ordinary chat messages.

When later Stripe sandbox work needs a credential, use a Stripe **restricted test-mode** key (`rk_test_...`) with the minimum permissions required by ADR-004.  Prefer repository/CI secret storage for GitHub Actions and file-backed secret injection for containerized tests where practical.

Do not use a live key or perform a live charge without explicit owner authorization.

The exact Stripe dashboard restricted-key permission labels still need validation with a real sandbox key before production guidance is final.  That work belongs at the Phase 3/real Stripe boundary, not Phase 2.

## Architecture Boundaries That Still Apply

Unless a later Accepted ADR changes them:

- one Kimai plugin contains Payment Link and reconciliation capabilities with shared common primitives;
- Kimai remains authoritative for invoice/business state;
- Stripe remains authoritative for payment movement and Stripe objects;
- the connector owns exact correlation and conservative automation logic only;
- no connector-owned payment ledger/database is introduced without evidence and a later ADR;
- Kimai core and root Composer state are not patched;
- no public Kimai ingress or Stripe webhook is required;
- Stripe Payment Links remain the customer payment surface;
- manual Payment Link creation and manual reconciliation remain permanent fallbacks;
- ambiguity causes no automatic financial mutation;
- false negatives are preferred to false-positive paid-state transitions;
- paid orphans are retained for accounting review;
- refunds/disputes do not automatically reverse historical Kimai paid state; and
- cleanup retains uncertainty.

## Next Safe Step After Phase 2

Once PR #6 is verified, reviewed, and owner-merged, begin Phase 3 Payment Link capability work through ADR-006's supported Kimai extension seams.

Do not jump directly to a real Stripe call.  Build deterministic lower-layer behavior first, including:

```text
preview -> zero mutation
exact compatible link -> reuse
no compatible link -> creation decision
same identity + wrong amount/currency -> CONFLICT
multiple candidates -> fail closed
idempotency/retry request construction
timeout-before/after-commit recovery behavior
safe URL/rendering boundary
```

Then add a small representative real Stripe sandbox E2E subset for assumptions only Stripe can prove.

That is the point at which a real restricted test-mode Stripe key is expected to become necessary.  The owner should be given exact secret-creation and injection instructions at that time; do not ask them to paste the secret into the repository or chat.
