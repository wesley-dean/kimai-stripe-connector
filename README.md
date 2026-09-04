# Kimai Stripe Connector

`kimai-stripe-connector` is a small Kimai plugin that connects Kimai invoices to Stripe Payment Links while keeping Kimai as the invoice system and Stripe as the payment processor.

Intended workflow:

```text
track time in Kimai
-> generate invoice
-> connector creates or recovers exact Stripe Payment Link
-> invoice contains Stripe-hosted payment URL
-> customer pays Stripe
-> asynchronous reconciliation proves the exact payment
-> Kimai invoice becomes paid
```

Kimai does not need public Internet ingress and Stripe webhooks are not required.

## Project Status

Phases 1 through 6 are merged.  Phase 7 compatibility and release evidence is the next project boundary.

The repository now contains:

- a self-contained installable Kimai plugin design and production-shaped staging path;
- PHP/Composer package metadata and a committed minimum-PHP-resolved `composer.lock`;
- PHPUnit, PHPStan, PHPCS, and PHP-CS-Fixer project-local tooling;
- PHP 8.2–8.5 CI using the same locked dependency graph;
- a disposable Kimai 2.65.0 + MySQL plugin-lifecycle E2E harness;
- PHP-aware MegaLinter configuration with auto-fixes disabled;
- Doxygen/PHPDoc source-documentation standards and generated reference tooling;
- production Payment Link generation/recovery through Kimai's invoice pre-render seam;
- conservative read-only Stripe reconciliation evidence discovery;
- fail-closed reconciliation planning and batch orchestration;
- a Kimai paid-state persistence adapter with last-moment consistency checks;
- the external `kimai:stripe:reconcile` command with `--dry-run` and stable exit codes;
- conservative orphan-cleanup planning and a narrowly scoped Payment Link deactivation boundary;
- the `kimai:stripe:cleanup` command with `--dry-run` and the same operator-result taxonomy;
- a manual real-Stripe sandbox contract probe;
- an operator runbook covering routine operation, incidents, disablement, and manual fallback; and
- detailed installation/design/testing/threat/configuration/handoff documentation.

Phase 7 remains responsible for proving the full hosted Stripe payment-to-Kimai reconciliation path and verifying that release automation produces the self-contained production artifact required by ADR-012.

## Installation

Installation, configuration, verification, upgrade, disablement, removal, and troubleshooting are documented in:

[`doc/INSTALL.md`](doc/INSTALL.md)

The connector installs under:

```text
var/plugins/KimaiStripeConnectorBundle/
```

and must carry its own production Composer dependencies rather than modifying Kimai's root Composer graph.

Until Phase 7 verifies the release artifact pipeline, do not assume GitHub's automatically generated source archives are installable plugin packages.  The installation guide includes a source-build procedure that reproduces the production-shaped staging model already exercised by the disposable Kimai E2E harness.

## Start Here

For a fresh implementation session, begin with:

[`doc/implementation-playbook.md`](doc/implementation-playbook.md)

A minimal instruction is:

> Inspect this repository, read `doc/implementation-playbook.md`, and follow it.  Work as a trusted engineering peer, make ordinary reversible decisions autonomously, keep tests and documentation synchronized, and do not merge or release without explicit approval.

The playbook directs the session through the supporting documentation.

High-value entry points:

1. [`AGENTS.md`](AGENTS.md)
2. [`Implementation Playbook`](doc/implementation-playbook.md)
3. [`Decision Index`](doc/decisions.md)
4. [`Project Handoff`](doc/project-handoff.md)
5. [`Installation Guide`](doc/INSTALL.md)
6. [`Operator Runbook`](doc/operator-runbook.md)
7. [`Configuration Model`](doc/configuration.md)
8. [`Threat Model`](doc/threat-model.md)
9. [`Documentation Standard`](doc/documentation.md)
10. [`Test Architecture and Coverage Matrix`](doc/testing/test-matrix.md)
11. [`Reference Hosting Environment`](doc/reference-hosting-environment.md)
12. [`Pre-Implementation Mock Exercise`](doc/testing/preimplementation-mock-exercise.md)
13. [`Stripe Sandbox E2E Strategy`](doc/testing/e2e-stripe-sandbox.md)
14. [`Evaluated Billing and Time-Tracking Tools`](doc/evaluated-tools.md)

## Architecture Decisions

ADRs live under `doc/adr/`, and `doc/decisions.md` is the fast discovery index.

All current numbered ADRs are Accepted as of 2026-09-04.

The original ADR-001 became too broad during architecture exploration.  Its full proposed rationale is preserved under:

```text
doc/adr/rationale/ADR-001-proposed-rationale.md
```

Current independent decisions are split into focused ADRs, including:

- one plugin / two independently disableable capabilities;
- tests for behavioral changes;
- initial compatibility/Stripe contract;
- private Kimai/outbound-only integration;
- supported Kimai extension seams;
- Stripe Payment Links;
- versioned metadata correlation;
- conservative reconciliation;
- conservative orphan cleanup;
- idempotent partial-failure recovery;
- self-contained plugin packaging; and
- stable operator result categories and scheduler-facing exit codes.

Use the decision index to find the authoritative ADR rather than relying on this summary.

## Core Design Principles

The connector preserves several important boundaries:

- Kimai remains authoritative for invoice identity, amount/currency, time/business records, invoice documents, and Kimai invoice state.
- Stripe remains authoritative for Payment Links, Checkout Sessions, PaymentIntents, Charges, refunds, disputes, and actual payment movement.
- The connector correlates the systems rather than creating a third payment ledger.
- Kimai core is not patched or forked.
- Kimai does not require public ingress.
- Stripe webhooks are not required.
- Payment Link creation and reconciliation are independently disableable.
- Cleanup is an operator-invoked maintenance action on the reconciliation side of the initial two-capability architecture.
- Manual Payment Link creation and manual Kimai reconciliation remain permanent fallbacks.
- Ambiguous financial state fails closed.
- False negatives are preferred to false-positive paid-state transitions.
- Paid Stripe orphans are accounting exceptions, not cleanup garbage.
- Refunds/disputes do not automatically rewrite historical paid state.
- Age alone never authorizes destructive cleanup.

## Initial Compatibility Scope

The current Accepted baseline is:

```text
Kimai:             2.65.0
PHP:               >=8.2 <8.6
Stripe PHP SDK:    ^21.3.1
Payment methods:   card only initially
Money:             integer Stripe minor units
Currencies:        ordinary two-decimal currencies initially
Metadata version:  1
Stripe credential: one least-privilege restricted key initially
```

`composer.lock` is resolved from the minimum supported PHP 8.2 environment and committed.  PHP 8.2, 8.3, 8.4, and 8.5 CI install that same graph.

Compatibility claims must follow executable evidence.

## Reconciliation Command

Phase 4 provides the externally schedulable command:

```text
php bin/console kimai:stripe:reconcile
```

A non-destructive inspection run is:

```text
php bin/console kimai:stripe:reconcile --dry-run
```

The command accepts no invoice number and no desired paid state.  It discovers connector-managed Stripe evidence for the configured Kimai instance and permits a Kimai paid transition only after exact identity, amount, currency, and mutually consistent successful card-payment evidence are proven.

Human output includes the stable ADR-013 result category.  Process exit codes are:

| Result category          | Exit code |
|--------------------------|----------:|
| `SUCCESS` / `NO_CHANGE`  |         0 |
| `RETRYABLE`              |        10 |
| `CONFIGURATION_ERROR`    |        20 |
| `CONFLICT`               |        30 |
| `MANUAL_REVIEW_REQUIRED` |        40 |
| `INTERNAL_ERROR`         |        50 |

For a safe multi-invoice batch, independent invoices may continue after an item-level problem and the final process code is the highest applicable mapped code.

When an automatic transition succeeds, Kimai's payment date is derived from the proven Stripe Charge creation timestamp, converted to the invoice creation timezone before Kimai stores its date-only payment field.  The later reconciliation execution time is not used as the accounting date.

## Cleanup Command

Phase 5 provides an operator-invoked maintenance command:

```text
php bin/console kimai:stripe:cleanup
php bin/console kimai:stripe:cleanup --dry-run
```

Cleanup is intentionally outside the billing correctness path.  It can only deactivate a connector-managed Stripe Payment Link when the connector proves that no matching Kimai invoice exists, the link is at or beyond the configured grace period, the Payment Link remains active, and observed Stripe payment state is conclusively unpaid rather than merely lacking proof of success.

The initial conclusively-unpaid policy is deliberately narrow: no Checkout Sessions, or expired/unpaid attempts with no Charge and no received money.  Open, processing, unknown, contradictory, paid, refunded, disputed, or otherwise ambiguous evidence retains the Payment Link.

Before an actual deactivation, cleanup repeats the Kimai invoice lookup, managed Payment Link discovery, and Stripe child-evidence discovery.  The Stripe writer then retrieves the Payment Link again and validates exact ID, metadata, creation time, and active state before setting `active=false`.

The command accepts no invoice number, Payment Link ID, or desired Stripe state.  It uses the same ADR-013 process exit-code mapping as reconciliation.

Cleanup requires the existing global and reconciliation switches.  There is no third cleanup capability switch in the initial architecture.  The grace period is controlled by:

```text
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS
```

with a default of 7 days.

Operational procedures and incident response are documented in [`doc/operator-runbook.md`](doc/operator-runbook.md).

## Plugin Packaging

The plugin installs as:

```text
var/plugins/KimaiStripeConnectorBundle/
```

with namespace:

```text
KimaiPlugin\KimaiStripeConnectorBundle\
```

ADR-012 requires installable release artifacts to be self-contained and carry plugin-local Composer production dependencies rather than modifying Kimai's root Composer graph.  Phase 7 will verify that the release automation actually produces that artifact shape.

Source control ignores the generated/local `vendor/` tree while committing the dependency lockfile.

See [`doc/INSTALL.md`](doc/INSTALL.md) for the currently supported installation paths.

## Testing

Behavior changes require appropriate tests under ADR-003.

The repository separates test responsibilities:

```text
unit
-> pure configuration/money/metadata/decision logic

Kimai integration
-> plugin/event/rendering/Kimai-state behavior

controlled Stripe/network
-> deterministic timeout/retry/race/failure behavior

real Stripe sandbox E2E
-> small number of high-value real API/Checkout proofs
```

See [`doc/testing/test-matrix.md`](doc/testing/test-matrix.md).

### Disposable Kimai environment

`tests/e2e/` contains a real Kimai lifecycle harness based closely on Kimai's documented Docker Compose deployment.

Each run:

```text
creates fresh Compose project + named volumes
-> starts Kimai 2.65.0 + MySQL
-> stages plugin source with composer install --no-dev from composer.lock
-> installs the production-shaped stage under var/plugins
-> reloads and verifies Kimai
-> exercises disabled reconciliation/cleanup commands without Stripe access
-> removes plugin
-> reloads and verifies Kimai again
-> destroys containers, network, every named volume, and the local stage
```

The E2E harness intentionally does not copy a developer checkout's `vendor/` tree into Kimai.  That tree may contain PHPUnit, PHPStan, Symfony development packages, and other dependencies that are not part of the production plugin artifact and can collide with Kimai's own dependency graph.

Cleanup is guaranteed through a shell trap using:

```text
docker compose down --volumes --remove-orphans
```

The long-lived dogfooding Kimai instance is not the E2E fixture.

## Development Commands

After Composer development dependencies are installed, the ordinary PHP commands are:

```text
make test
make analyse
make lint
make format-check
make check
make docs
make docs-clean
```

`make check` is the ordinary local PHP gate and runs PHPUnit, PHPStan, PHPCS, and PHP-CS-Fixer in check-only mode.

`make format` applies the configured PHP-CS-Fixer rules explicitly.  CI and MegaLinter do not auto-fix source.

`make e2e` is independent of the repository-root development `vendor/` tree.  It requires Docker with Compose and Composer on `PATH`, then stages its own production dependency graph from the committed lockfile.

The generated Doxygen reference tree lives under ignored `doc/reference/`.

## Quality Gates

MegaLinter is blocking.

Required policy:

```text
DISABLE_ERRORS: false
APPLY_FIXES: false
```

A successful workflow is considered clean only when MegaLinter reports zero errors and zero warnings across every enabled linter.

The repository also runs:

- PHP 8.2–8.5 against the committed lockfile with PHPUnit, PHPStan, PHPCS, and PHP-CS-Fixer;
- disposable Kimai E2E; and
- Doxygen generation with warnings treated as failures.

Dependabot covers GitHub Actions and Composer dependencies.  Semver/release automation is intentionally retained during initial development; frequent patch releases are acceptable and useful for visible progress.

CodeQL and OpenSSF Scorecard are not relied upon as blocking controls while this repository remains private.

## Configuration

Connector environment variables use:

```text
KIMAI_STRIPE_CONNECTOR_*
```

Environment variables are the primary configuration interface.  File-backed secret injection is preferred for sensitive production/container values.  `.env` support is tertiary local/dev/test convenience.

Reconciliation requires the global connector switch, reconciliation capability switch, stable instance UUID, and restricted Stripe credential.  Payment Link generation may remain independently disabled.

Cleanup uses that same reconciliation-side configuration and additionally applies `KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS`.  It does not introduce a separate cleanup enablement variable.

See [`doc/configuration.md`](doc/configuration.md).

## Manual Fallback

The connector must never become necessary to continue billing:

```text
track time in Kimai
-> generate invoice
-> create Stripe Payment Link manually
-> include URL
-> send invoice
-> verify payment in Stripe
-> mark invoice paid manually
```

See [`doc/operator-runbook.md`](doc/operator-runbook.md) for disablement, incident response, and return-to-manual procedures.

## License

The repository is currently private.  The inherited CC0 material remains present while the final public/open-source software-license decision is deferred.

That decision is not an immediate implementation blocker while the repository remains private, but it should be resolved before making the repository public or inviting public contributions/releases under an assumed license.

## Author

- Wes Dean
