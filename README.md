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
2. [`doc/decisions.md`](doc/decisions.md)
3. [`doc/project-handoff.md`](doc/project-handoff.md)
4. [`Configuration Model`](doc/configuration.md)
5. [`Threat Model`](doc/threat-model.md)
6. [`Reference Hosting Environment`](doc/reference-hosting-environment.md)
7. [`Evaluated Billing and Time-Tracking Tools`](doc/evaluated-tools.md)
8. [`ADR-000: Capability Scope, Epistemic Honesty, and Separation of Concerns`](doc/adr/ADR-000-capability-scope-and-epistemic-honesty.md)
9. [`ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation`](doc/adr/ADR-001-single-plugin-architecture.md)
10. [`Pre-Implementation Kimai / Stripe Mock Exercise`](doc/testing/preimplementation-mock-exercise.md)
11. [`Containerized Kimai + Stripe Sandbox E2E Testing`](doc/testing/e2e-stripe-sandbox.md)

The handoff document records the project history, hard constraints, current decisions, reference environment, unresolved questions, known template residue, and recommended next work so a new development session does not need access to earlier conversations.

[`doc/configuration.md`](doc/configuration.md) defines the connector's configuration contract.  Namespaced environment variables are the primary interface, file-backed secret injection is preferred for sensitive values in containerized production, and `.env` files are only a tertiary development/test convenience rather than a separate configuration system.

[`doc/threat-model.md`](doc/threat-model.md) is the living architecture threat model.  It captures assets, trust boundaries, attack scenarios, controls, residual risks, open security questions, and a proposed abuse/security test backlog.  Revisit it when implementation details, Stripe permissions, supported payment methods, public interfaces, persistence, or packaging materially change.

[`doc/reference-hosting-environment.md`](doc/reference-hosting-environment.md) describes the sanitized dogfooding/runtime environment: container orchestration, reverse proxy, external databases, NFS-backed persistence, scheduling, private-network posture, and outbound Stripe connectivity.  It intentionally omits hostnames, IP addresses, domain names, credentials, and other site-specific identifiers.  Treat it as operational context, not as a portability requirement for the plugin.

[`doc/evaluated-tools.md`](doc/evaluated-tools.md) preserves the Harvest-replacement comparison that led to Kimai.  Consult it before reopening previously rejected products such as Invoice Ninja, Miru, Dolibarr, SolidInvoice, or ERPNext.  Those decisions are historical rather than permanent: reconsider a tool when project requirements or the product have materially changed, and verify current upstream facts before relying on the older evaluation.

### Decision index

[`doc/decisions.md`](doc/decisions.md) is the fast-reference index for project ADRs.  It summarizes each ADR decision in a few sentences, records its current status, and links back to the source ADR.

Use the decision index to discover which decisions matter to the task at hand.  Do **not** use it as a replacement for the ADRs when implementing or interpreting architecture.  The full ADR contains the rationale, alternatives, constraints, failure modes, and nuance; if the summary ever conflicts with its ADR, the ADR is authoritative.

When an ADR is added, accepted, superseded, deprecated, or materially changed, `doc/decisions.md` should be updated in the same change.

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

Keep [`doc/decisions.md`](doc/decisions.md) synchronized with the ADR set so humans and tools have a reliable compact index without sacrificing the full ADR record.

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

See [`doc/threat-model.md`](doc/threat-model.md), [`doc/configuration.md`](doc/configuration.md), [`SECURITY.md`](SECURITY.md), ADR-001, and the project handoff for current security context.  The generic template security policy may require project-specific refinement before public release.

## Author

- Wes Dean
