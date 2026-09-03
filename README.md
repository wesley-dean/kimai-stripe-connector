# Kimai Stripe Connector

`kimai-stripe-connector` is a proposed small Kimai plugin that connects Kimai invoices to Stripe Payment Links while keeping Kimai as the invoice system and Stripe as the payment processor.

The intended workflow is:

```text
track time in Kimai
-> generate invoice
-> connector creates or recovers Stripe Payment Link
-> invoice contains Stripe-hosted payment URL
-> customer pays Stripe
-> asynchronous reconciliation verifies payment
-> Kimai invoice is marked paid
```

The connector is deliberately designed so Kimai does not need public Internet ingress and Stripe webhooks are not required.

## Project Status

The project is currently in the design/documentation stage.  There is not yet a production plugin implementation in this repository.

The proposed architecture is one plugin with two independently disableable capabilities:

1. Payment Link generation/recovery and invoice rendering support.
2. Conservative Stripe-to-Kimai payment reconciliation.

The main architecture decision is currently **Proposed**, not Accepted.  Acceptance requires a real Kimai plugin implementation and Stripe test-mode validation.

## Start Here

Humans and AI/LLM development tools should read these documents before substantive implementation work:

1. [`AGENTS.md`](AGENTS.md)
2. [`doc/project-handoff.md`](doc/project-handoff.md)
3. [`ADR-000: Capability Scope, Epistemic Honesty, and Separation of Concerns`](doc/adr/ADR-000-capability-scope-and-epistemic-honesty.md)
4. [`ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation`](doc/adr/ADR-001-single-plugin-architecture.md)
5. [`Pre-Implementation Kimai / Stripe Mock Exercise`](doc/testing/preimplementation-mock-exercise.md)

The handoff document records the project history, hard constraints, current decisions, reference environment, unresolved questions, known template residue, and recommended next work so a new development session does not need access to earlier conversations.

## Core Design Principles

The current design intentionally preserves several boundaries:

- Kimai remains authoritative for invoice identity, invoice amount/currency, time records, invoice documents, and Kimai invoice state.
- Stripe remains authoritative for Payment Links, Checkout Sessions, PaymentIntents, Charges, refunds, disputes, and actual payment movement.
- The connector correlates the two systems rather than creating a third payment ledger.
- Kimai core is not patched or forked.
- Kimai does not require public ingress.
- Stripe webhooks are not required.
- Manual Stripe Payment Link creation and manual Kimai payment reconciliation remain permanent fallbacks.
- Ambiguous financial state fails closed.
- False negatives are preferred to false-positive paid-state transitions.
- Paid Stripe orphans are accounting exceptions, not cleanup garbage.
- Refunds/disputes do not automatically rewrite a previously paid Kimai invoice to unpaid.

See ADR-001 for the full rationale, failure model, alternatives, and acceptance criteria.

## Historical Mock Evidence

Before repository implementation began, the architecture was exercised through synthetic/adversarial modeling.  The historical exercise reported:

```text
89 behavioral/adversarial cases passed
5 HTTP/network/concurrency cases passed
9 stateless correlation/recovery cases passed
------------------------------------------
103 total passed
```

Additional probes included 100,000 cent-value conversions, 10,000 unrelated Payment Links plus one exact match, and 20 simultaneous creation attempts converging on one logical Payment Link.

These are **historical design mocks**, not executable repository CI tests.  The original harness and raw output were not committed.  The full surviving record and proposed executable-test backlog are in [`doc/testing/preimplementation-mock-exercise.md`](doc/testing/preimplementation-mock-exercise.md).

## Manual Fallback

The connector must never become necessary to continue billing.

The supported fallback remains:

```text
track time in Kimai
-> generate invoice
-> create Stripe Payment Link manually
-> place URL on or with invoice
-> send invoice
-> confirm payment in Stripe
-> mark Kimai invoice paid manually
```

A connector or compatibility failure should therefore be recoverable by disabling/removing the plugin and continuing manually rather than requiring emergency application development.

## Architecture Decision Records

ADRs live under `doc/adr`, as configured by `.adr-dir`.

The repository's ADR template intentionally prefers thorough documentation over premature compression.  Capture reasoning, alternatives, assumptions, failure modes, and uncertainty.  The documentation can be distilled later after implementation and operational experience establish what is durable.

## Repository Automation

This repository was created from a general project template and already includes tooling such as:

- MegaLinter;
- pre-commit hooks;
- Gitleaks;
- CodeQL;
- OpenSSF Scorecard;
- Dependabot;
- Conventional Commit / automatic semver release automation;
- issue and pull-request templates.

Some template configuration is not yet tailored for a PHP/Composer Kimai plugin.  Known residue and recommended cleanup are documented in [`doc/project-handoff.md`](doc/project-handoff.md).

Do not assume every scaffold file represents a deliberate connector-specific decision.

## License

**License decision pending.**

The repository currently contains a CC0 `LICENSE` and CC0 contribution language inherited from the source project template.  Open-source distribution is desired, but the connector's final software license has not yet been explicitly decided.

Do not interpret the current template license as a finalized architectural or project decision.  Licensing should be resolved before public implementation contributions or a public release.

## Security

The connector will handle Stripe API credentials and can change Kimai invoice paid state.  Least privilege, conservative financial decisions, secret-safe logging, and no unnecessary public ingress are core design concerns.

See [`SECURITY.md`](SECURITY.md), ADR-001, and the project handoff for current security context.  The generic template security policy may require project-specific refinement before public release.

## Author

- Wes Dean
