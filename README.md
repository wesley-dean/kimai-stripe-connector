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

Architecture and implementation-preparation decisions are Accepted.

The repository now contains:

- an inert installable Kimai plugin bundle skeleton;
- PHP/Composer package metadata;
- PHPUnit/PHPStan and PHP coding-standard configuration;
- PHP 8.2–8.5 CI;
- a disposable Kimai 2.65.0 + MySQL plugin-lifecycle E2E harness;
- PHP-aware MegaLinter configuration with auto-fixes disabled;
- Doxygen/PHPDoc source-documentation standards and generated reference tooling;
- Accepted ADRs for architecture, compatibility, testing, payment behavior, packaging, recovery, cleanup, and operator results; and
- detailed design/testing/threat/configuration/handoff documentation.

No production Stripe Payment Link creation or reconciliation logic has been implemented yet.

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
5. [`Configuration Model`](doc/configuration.md)
6. [`Threat Model`](doc/threat-model.md)
7. [`Documentation Standard`](doc/documentation.md)
8. [`Test Architecture and Coverage Matrix`](doc/testing/test-matrix.md)
9. [`Reference Hosting Environment`](doc/reference-hosting-environment.md)
10. [`Pre-Implementation Mock Exercise`](doc/testing/preimplementation-mock-exercise.md)
11. [`Stripe Sandbox E2E Strategy`](doc/testing/e2e-stripe-sandbox.md)
12. [`Evaluated Billing and Time-Tracking Tools`](doc/evaluated-tools.md)

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
- stable operator result categories.

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
- Manual Payment Link creation and manual Kimai reconciliation remain permanent fallbacks.
- Ambiguous financial state fails closed.
- False negatives are preferred to false-positive paid-state transitions.
- Paid Stripe orphans are accounting exceptions, not cleanup garbage.
- Refunds/disputes do not automatically rewrite historical paid state.

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

Compatibility claims must follow executable evidence.

## Plugin Packaging

The plugin installs as:

```text
var/plugins/KimaiStripeConnectorBundle/
```

with namespace:

```text
KimaiPlugin\KimaiStripeConnectorBundle\
```

The release/plugin artifact is self-contained and carries plugin-local Composer production dependencies rather than modifying Kimai's root Composer graph.

Source control ignores the generated/local `vendor/` tree.

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
-> installs checked-out plugin
-> reloads and verifies Kimai
-> removes plugin
-> reloads and verifies Kimai again
-> destroys containers, network, and every named volume
```

Cleanup is guaranteed through a shell trap using:

```text
docker compose down --volumes --remove-orphans
```

The long-lived dogfooding Kimai instance is not the E2E fixture.

## Development Commands

After Composer dependencies are installed:

```text
make test
make analyse
make check
make e2e
make docs
make docs-clean
```

The generated Doxygen reference tree lives under ignored `doc/reference/`.

## Quality Gates

MegaLinter is blocking.

Required policy:

```text
DISABLE_ERRORS: false
APPLY_FIXES: false
```

A successful workflow is considered clean only when MegaLinter reports zero errors and zero warnings.

The repository also runs PHP matrix tests/static analysis, disposable Kimai E2E, and Doxygen generation.

Dependabot covers GitHub Actions and Composer dependencies.  Semver/release automation is intentionally retained during initial development; frequent patch releases are acceptable and useful for visible progress.

## Configuration

Connector environment variables use:

```text
KIMAI_STRIPE_CONNECTOR_*
```

Environment variables are the primary configuration interface.  File-backed secret injection is preferred for sensitive production/container values.  `.env` support is tertiary local/dev/test convenience.

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

## License

The repository is currently private.  The inherited CC0 material remains present while the final public/open-source software-license decision is deferred.

That decision is not an immediate implementation blocker while the repository remains private, but it should be resolved before making the repository public or inviting public contributions/releases under an assumed license.

## Author

- Wes Dean
