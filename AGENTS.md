# AI / Agent Project Guidance

This repository is expected to be used by humans and AI/LLM-based development tools.  This file provides the minimum context an AI agent should load before proposing or making changes.

## Read These First

Before substantive project work, read these documents in order:

1. `doc/project-handoff.md`
2. `doc/adr/ADR-000-capability-scope-and-epistemic-honesty.md`
3. `doc/adr/ADR-001-single-plugin-architecture.md`
4. `doc/testing/preimplementation-mock-exercise.md`
5. `README.md`
6. `CONTRIBUTING.md`, `SECURITY.md`, and relevant repository automation/configuration

Do not infer missing project requirements from the original repository template.

## Current Project State

At the time this guidance was introduced, the repository was in the design/documentation stage.  There was no production Kimai plugin implementation in the repository.

ADR-001 is `Proposed`, not `Accepted`.  The architecture has been researched and modeled, but it still requires a real Kimai plugin implementation and Stripe test-mode validation before acceptance.

The historical mock exercise reported 103 passing synthetic cases.  Those cases are design evidence, not repository CI evidence.  Do not describe the project as having 103 executable tests until equivalent tests actually exist in the repository.

## Primary Goal

Build a small Kimai plugin that connects Kimai invoices to Stripe without turning Kimai or this connector into a new billing platform.

The intended plugin has two independently disableable capabilities:

1. create or recover a Stripe Payment Link during real Kimai invoice generation and expose the link URL to the invoice renderer; and
2. asynchronously reconcile sufficiently proven Stripe payments back into Kimai so the corresponding invoice can be marked paid.

The current packaging direction is one plugin with internally separated responsibilities.  Two plugins remain a legitimate future alternative if lifecycle, security, dependency, user, or maintenance evidence justifies a split.

## Hard Architectural Constraints

Treat the following as project requirements unless a later accepted ADR changes them:

- Do not patch, fork, or modify Kimai core to implement the connector.
- Use supported Kimai extension/plugin mechanisms.
- Do not require public Internet ingress to Kimai.
- Do not require Stripe webhooks.
- Stripe should remain the public customer payment surface.
- Outbound polling is acceptable.
- The connector must be removable/disableable without preventing ordinary Kimai invoicing.
- The manual Kimai + Stripe workflow is a permanent fallback.
- Payment Link generation and payment reconciliation must be independently disableable.
- Ambiguous financial evidence must result in no automatic Kimai accounting change.
- False negatives are preferable to false positives in automatic paid-state reconciliation.
- Do not automatically reverse a Kimai invoice from paid to unpaid/canceled merely because Stripe later reports a refund or dispute.
- Do not treat a paid Stripe orphan as garbage.
- Cleanup must retain data/payment surfaces when it cannot prove they are safe to deactivate.
- Keep the plugin small and auditable.
- Do not introduce a connector-specific payment ledger unless implementation evidence demonstrates a need.
- Do not build queues, dashboards, webhook controllers, public callbacks, or generalized billing abstractions merely because they could be useful someday.

## Authority Boundaries

Kimai is authoritative for invoice identity, invoice amount/currency, invoice documents, time entries, and Kimai invoice state.

Stripe is authoritative for Payment Links, Checkout Sessions, PaymentIntents, Charges, refunds, disputes, and actual payment movement.

The connector owns the correlation contract and the conservative decision procedure that decides whether Stripe evidence is sufficient to update Kimai.

Do not casually blur these boundaries.

## Failure Philosophy

The connector cannot make Stripe object creation and Kimai invoice persistence one atomic distributed transaction.  The architecture therefore favors idempotency, durable correlation, recovery, and conservative cleanup rather than pretending atomicity exists.

Important examples:

- Stripe may create a Payment Link before Kimai invoice rendering/persistence later fails.
- A retry should recover/reuse an exact compatible link rather than blindly creating another one.
- A same-identity amount/currency conflict should stop automation and require human resolution.
- A Stripe timeout may mean the remote operation committed and the response was lost.
- A reconciliation outage should leave Kimai temporarily stale, not corrupt.
- A cleanup outage should leave extra Stripe objects, not destroy uncertain ones.

## Documentation Philosophy

For architecture, security, reconciliation logic, compatibility, and financial-state behavior, more documentation is preferred to premature compression.

Capture:

- reasoning;
- assumptions;
- upstream facts and versions;
- rejected alternatives;
- failure modes;
- test evidence;
- uncertainty;
- migration concerns;
- operational fallback;
- what would cause a decision to be revisited.

The project owner explicitly prefers preserving context now and distilling later.

When making or materially changing an architectural decision, update or add an ADR rather than allowing the decision to live only in code or a PR conversation.

Distinguish facts, design decisions, inferences, synthetic evidence, and unresolved questions.

## Testing Philosophy

Use the historical mock exercise as a test backlog, not as proof.

High-risk behavior should be represented by executable tests before release, especially:

- preview suppression;
- money/currency normalization;
- stable identity and metadata;
- idempotent Payment Link creation/recovery;
- timeout-after-commit recovery;
- concurrency;
- amount/currency conflicts;
- exact reconciliation evidence;
- partial/unpaid/processing states;
- refunds and disputes;
- paid orphan handling;
- conservative cleanup;
- Kimai persistence failure;
- plugin disable/manual fallback;
- real Stripe test-mode payment behavior.

Prefer adversarial and failure-path tests over happy-path-only coverage.

## Security Philosophy

The plugin will handle a Stripe credential and can change Kimai invoice paid state.  Treat this as a meaningful trust boundary.

- Prefer Stripe restricted keys if the required API operations can be represented safely.
- Never log Stripe secret keys.
- Do not expose secrets to Twig templates, invoice documents, metadata, CLI output, or exception messages intentionally.
- Pay attention to container/entrypoint logging.  The reference Kimai deployment exposed environment values through shell tracing (`set -x`), so an environment variable is not automatically log-safe.
- Keep public ingress unnecessary.
- Fail closed on financial ambiguity.

## Repository Conventions

The repository was created from a general template and includes MegaLinter, pre-commit, CodeQL, OpenSSF Scorecard, Dependabot, Conventional Commit/versioning automation, issue templates, and other scaffold files.

Use Conventional Commit messages.

Follow `.editorconfig`, `.markdownlint.json`, `.yamllint.yaml`, `.pre-commit-config.yaml`, and repository automation where applicable.

The pre-commit configuration intentionally rejects committed `.env` files and scans for secrets.

Do not assume every scaffold file is already correct for this project.  Some are demonstrably template residue and are listed in `doc/project-handoff.md`.

## Licensing Warning

The repository currently contains a CC0 `LICENSE` and `CONTRIBUTING.md` language inherited from the template.  The project has discussed open-source distribution, but the final plugin license has **not** been decided.

Do not treat CC0 as a consciously finalized plugin-license decision merely because it is currently present in the scaffold.  Do not make licensing claims on behalf of the project without resolving this explicitly.  Licensing should be settled before public implementation contributions or a public release create avoidable ambiguity.

## Scope Warning

If a proposed change begins to add any of the following, stop and reconsider scope before implementing:

- subscriptions;
- recurring billing;
- tax computation or filing;
- generalized bookkeeping;
- customer accounting ledgers;
- partial-payment accounting;
- dunning or late-fee workflows;
- automatic refund initiation/accounting;
- automated dispute handling;
- a Stripe dashboard inside Kimai;
- payment credential collection inside Kimai;
- mandatory webhooks/public routes;
- arbitrary payment-processor abstraction without a demonstrated requirement.

A new ADR is normally appropriate before materially expanding into these areas.

## Handoff Rule

When ending a substantial design or implementation session, update `doc/project-handoff.md` if the next session would otherwise need conversational history to understand:

- what changed;
- what is currently believed/decided;
- what remains uncertain;
- what was tested;
- what failed;
- what should happen next.

The repository should be sufficient context for a new chat/session without relying on memory from an earlier conversation.
