# Implementation Playbook

Date: 2026-09-03

## Purpose

This document is the primary execution guide for a fresh human or AI/LLM development session working on `kimai-stripe-connector`.

A session should be able to receive an instruction as short as:

> Go to `doc/implementation-playbook.md` and follow the instructions.

and then make meaningful progress without needing access to the conversation that created the project.

The goal is not to remove judgment.  The goal is to make the project's intent, sequencing, guardrails, documentation expectations, testing expectations, and stop conditions explicit enough that ordinary implementation work can proceed autonomously and conservatively.

## Default Mandate

Unless the user explicitly narrows the task, a session following this playbook SHOULD:

1. inspect the repository and current GitHub state;
2. read the required project documents;
3. identify the next incomplete phase in this playbook;
4. research current upstream Kimai and Stripe behavior when the work depends on version-sensitive facts;
5. make reversible implementation decisions consistent with the documented architecture;
6. record material decisions in ADRs and `doc/decisions.md`;
7. implement the smallest coherent increment;
8. add or update executable tests;
9. run the applicable validation/lint/test suite;
10. update operational, configuration, threat-model, compatibility, and handoff documentation when affected;
11. use Conventional Commit messages;
12. open or update a focused pull request;
13. summarize what changed, what was verified, and what remains unresolved.

The session SHOULD continue through ordinary reversible work without repeatedly asking for confirmation.

## Read Before Doing Work

Read these documents before substantive implementation or design changes:

1. [`AGENTS.md`](../AGENTS.md)
2. [`doc/decisions.md`](decisions.md)
3. [`doc/project-handoff.md`](project-handoff.md)
4. [`doc/configuration.md`](configuration.md)
5. [`doc/threat-model.md`](threat-model.md)
6. [`doc/reference-hosting-environment.md`](reference-hosting-environment.md)
7. [`doc/evaluated-tools.md`](evaluated-tools.md)
8. [`doc/adr/ADR-000-capability-scope-and-epistemic-honesty.md`](adr/ADR-000-capability-scope-and-epistemic-honesty.md)
9. [`doc/adr/ADR-001-single-plugin-architecture.md`](adr/ADR-001-single-plugin-architecture.md)
10. [`doc/testing/preimplementation-mock-exercise.md`](testing/preimplementation-mock-exercise.md)
11. [`doc/testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md)
12. [`README.md`](../README.md)
13. relevant repository automation and contribution/security files.

Do not assume the repository template's defaults are project decisions merely because they already exist.

## Source-of-Truth Order

When documents appear to disagree, use this precedence:

```text
accepted ADR
    > proposed ADR for work governed by that proposal
    > current implementation-specific contract docs
    > decisions index summary
    > project handoff/history
    > README prose
    > historical conversation or assumptions
```

`doc/decisions.md` is an index, not a replacement for ADRs.

If two authoritative documents genuinely conflict, stop the conflicting implementation work, document the inconsistency, and resolve it through an ADR/update rather than silently choosing one.

## Working Style

The project prefers small, boring, auditable software.

Favor:

- supported Kimai extension points;
- explicit configuration;
- conservative financial state transitions;
- deterministic CLI behavior;
- stateless/recoverable designs;
- narrowly scoped dependencies;
- testable seams;
- reversible changes;
- clear failure messages;
- false negatives over false-positive payment reconciliation;
- operational fallbacks that remain usable when the connector fails.

Avoid speculative infrastructure or abstractions merely because they might become useful later.

## Git and Pull Request Rules

### Before editing

Inspect:

- current branch/default branch;
- open pull requests;
- changed files in relevant PRs;
- existing review comments/threads;
- recent commits affecting the same area.

Do not unknowingly duplicate work already in an open PR.

### Branching

For new substantive work, create or use a focused branch rather than committing directly to `main` unless the user explicitly requests otherwise.

Prefer branch names such as:

```text
feat/plugin-skeleton
feat/payment-link-generation
test/kimai-e2e-harness
docs/metadata-contract
chore/php-tooling
```

### Commits

Use Conventional Commit messages.

Prefer multiple coherent commits over one enormous mixed commit when the work naturally separates into documentation, tests, and implementation.

### Pull requests

A PR should describe:

- what changed;
- why;
- relevant ADRs/contracts;
- tests run;
- security/threat-model implications;
- compatibility implications;
- unresolved issues.

Do not merge a PR unless the user explicitly asks for the merge or repository automation is already explicitly configured to do so for that class of change.

Do not publish a release merely because code is ready.

## Research Rules

Kimai, Symfony, Stripe, PHP, Composer, and Stripe API behavior can change.

When implementation depends on current external behavior, verify it against current authoritative sources before coding.

Prefer:

1. upstream source at the exact version being supported/tested;
2. official product/API documentation;
3. official release notes/changelogs;
4. mature dependency documentation.

Record version-sensitive findings in the relevant ADR or compatibility documentation.

Do not silently replace a documented source finding with memory or generic framework knowledge.

## Architectural Invariants

Unless changed by a later accepted ADR:

- Kimai remains authoritative for invoice/time/business state.
- Stripe remains authoritative for payment movement and Stripe payment objects.
- The connector owns correlation and conservative automation logic.
- Kimai core must not be patched or forked.
- Public ingress to Kimai must not be required.
- Stripe webhooks must not be required.
- Payment Link generation and reconciliation remain independently disableable.
- Manual Payment Link creation remains a valid fallback.
- Manual Kimai paid-state reconciliation remains a valid fallback.
- Ambiguous financial evidence produces no automatic paid-state mutation.
- Paid-to-unpaid reversal is not automatic in v1.
- Paid Stripe orphans are accounting exceptions, not cleanup garbage.
- Cleanup keeps uncertain objects rather than destructively guessing.
- No connector-specific durable payment ledger is introduced without new evidence and an ADR.

## Configuration Invariants

Follow [`doc/configuration.md`](configuration.md).

In particular:

```text
KIMAI_STRIPE_CONNECTOR_*
```

is the environment-variable namespace.

Environment variables are the primary configuration interface.

File-backed secret injection is preferred for sensitive production/container values.

`.env` support is tertiary and must not become a separate configuration model.

Never commit production or test Stripe secrets.

## Security Rules

Read [`doc/threat-model.md`](threat-model.md) before modifying security-sensitive behavior.

At minimum:

- use least-privilege Stripe credentials where practical;
- never intentionally log Stripe API secrets;
- never send secrets into invoice templates or Stripe metadata;
- validate external object identity, amount, and currency before financial mutation;
- do not trust a Checkout completion flag alone as universal payment proof;
- keep test endpoint overrides and fake-Stripe facilities out of production paths unless explicitly safe;
- treat cleanup as destructive;
- treat configuration integrity as security-sensitive;
- preserve no-public-ingress as an architectural control;
- update the threat model when a trust boundary or privilege changes.

## Implementation Sequence

The phases below are intentionally ordered to reduce expensive rework.

A session SHOULD begin at the earliest incomplete phase whose prerequisites are satisfied.

### Phase 0: Resolve blocking design decisions

Before substantial feature code, resolve the design decisions that become expensive to change after real Stripe objects or public releases exist.

#### 0.1 Compatibility policy

Research and document:

- minimum supported Kimai version;
- initial tested Kimai version(s);
- supported PHP version(s);
- Composer compatibility constraints;
- Stripe PHP SDK version policy;
- Stripe API-version policy;
- whether the connector explicitly pins `Stripe-Version`;
- compatibility-test matrix expectations.

Create/update an ADR when these become architectural compatibility promises.

#### 0.2 Stripe metadata contract

Freeze the initial correlation namespace and semantics before production Stripe objects are created.

Define at least:

```text
integration
integration_version
kimai_instance
invoice_number
invoice_amount
invoice_currency
```

Document:

- exact names;
- allowed formats;
- normalization;
- which Stripe objects receive/copy metadata;
- identity matching rules;
- version migration policy;
- collision behavior;
- treatment of old/unknown metadata versions.

#### 0.3 Money policy

Define and test:

- authoritative internal representation;
- conversion to Stripe integer minor units;
- rounding rules;
- supported currencies for v1;
- zero-decimal currency behavior;
- any deliberately unsupported unusual currencies;
- mismatch/conflict behavior.

Do not use binary floating point as the authoritative Stripe amount representation.

#### 0.4 Payment-method policy

Determine the first supported Stripe payment methods.

A narrow, well-tested first release is preferred over claiming broad support without modeling asynchronous/final-state behavior.

If cards-only is selected initially, document it explicitly and test the limitation.

#### 0.5 Stripe credential scope

Research the exact Stripe permissions needed for:

- Payment Link lookup/create/deactivate;
- Checkout Session lookup;
- PaymentIntent lookup;
- Charge lookup;
- reconciliation;
- cleanup.

Decide whether one restricted key or separate restricted credentials provides a worthwhile security/operational tradeoff.

Update the threat model and configuration contract accordingly.

#### 0.6 Licensing

Resolve the final project/software license before public implementation contributions or release create ambiguity.

The inherited CC0 files are not a finalized connector license decision.

Licensing is an owner-level decision and is a stop condition if the implementation/release path requires it before the owner has selected one.

### Phase 1: Repository and PHP/Composer scaffold

Prepare the repository for actual PHP development.

Expected work includes, as appropriate:

- `composer.json`;
- package metadata;
- PHP namespace/autoloading;
- PHPUnit;
- static analysis/linting;
- CodeQL PHP coverage;
- Composer Dependabot coverage;
- PHP/Composer-aware MegaLinter configuration;
- `.gitignore` updates;
- removal/correction of stale template residue;
- CI jobs for unit/integration tests;
- release automation review so `main` does not unexpectedly publish premature releases.

Do not add dependencies without a concrete purpose.

### Phase 2: Minimal installable Kimai plugin

Build the smallest valid Kimai plugin with no Stripe behavior enabled.

Acceptance target:

```text
clean Kimai
-> plugin installed
-> Kimai boots
-> plugin configuration loads
-> connector disabled/inert
-> ordinary Kimai invoicing works
-> plugin can be disabled/removed
-> Kimai still works
```

Validate this against a disposable containerized Kimai runtime.

This phase exists to prove plugin packaging/lifecycle before financial logic is introduced.

### Phase 3: Configuration, identity, and money primitives

Implement foundational pieces before Stripe mutation behavior:

- configuration parsing/validation;
- direct/file-backed credential resolution;
- stable connector/Kimai instance identity;
- immutable money/currency normalization;
- metadata value objects/contract;
- safe logging/redaction;
- error taxonomy.

Implement the documented `CFG-*` and applicable `THR-*` tests.

No Stripe object creation is required to complete this phase.

### Phase 4: Payment Link capability

Implement Payment Link generation/recovery through supported Kimai extension points.

Required behaviors include:

- preview creates no Stripe mutation;
- real invoice generation looks for exact existing compatible managed link;
- exact compatible link is reused;
- absent compatible link is created;
- amount/currency/identity conflict fails closed;
- ambiguous multiple matches fail closed;
- created/recovered URL is exposed to invoice rendering through supported Kimai model/hydrator/template behavior;
- direct retry/idempotency behavior is safe;
- timeout-after-commit recovery is tested with a controlled fake Stripe layer;
- Kimai failure after Stripe creation leaves recoverable orphan state rather than hidden corruption.

Add real Stripe sandbox E2E coverage for high-value happy-path behavior.

### Phase 5: Reconciliation capability

Implement reconciliation as a deterministic externally invokable command/process.

Before coding the command, define its operator contract:

- command name;
- `--dry-run` behavior;
- exit codes;
- human-readable output;
- optional structured/JSON output if justified;
- pagination;
- concurrency/locking behavior;
- whether one invoice failure stops or merely flags the run;
- retryable versus non-retryable outcomes.

Automatic mutation direction in v1 is:

```text
Kimai not paid
+ strong exact Stripe payment evidence
-> Kimai paid
```

Do not automatically reverse paid state for later refunds/disputes.

Validate using both controlled integration tests and real Stripe sandbox E2E.

### Phase 6: Cleanup capability

Implement conservative orphan inspection/deactivation outside the correctness path.

Before destructive action:

- prove object is connector-managed;
- prove object belongs to the current instance/namespace;
- prove no matching Kimai invoice exists;
- retain on Kimai/API uncertainty;
- retain recent orphan within grace period;
- retain and surface paid orphan;
- deactivate only stale unpaid orphan that meets all safety criteria.

Provide dry-run support before destructive cleanup.

### Phase 7: Operational runbook

Create/update operator documentation for at least:

- Stripe says paid, Kimai says unpaid;
- invoice generation failed after Payment Link creation;
- multiple matching links exist;
- amount/currency conflict;
- Stripe credential revoked/expired;
- paid orphan discovered;
- cleanup reports uncertainty;
- reconciliation outage;
- plugin compatibility failure after Kimai upgrade;
- disabling connector and returning to manual billing;
- Stripe key rotation.

The runbook should describe what an operator can safely do, not merely internal implementation details.

### Phase 8: Compatibility and release validation

Before claiming a supported release:

- run the documented compatibility matrix;
- run unit/integration/fake-Stripe tests;
- run containerized Kimai + Stripe sandbox E2E;
- verify plugin install/disable/remove behavior;
- verify preview suppression;
- verify Payment Link rendering;
- verify payment and reconciliation;
- verify idempotent reruns;
- verify cleanup safety;
- verify secret/log handling;
- verify dependency/security scanning;
- verify documentation matches behavior.

ADR-001 should remain `Proposed` until its acceptance gate is actually satisfied.

## Test Layer Assignment

Use the cheapest deterministic layer capable of proving each behavior.

### Unit tests

Use for:

- money conversion;
- metadata normalization;
- configuration validation;
- identity matching;
- state-decision logic;
- error/result taxonomy.

### Kimai integration tests

Use for:

- plugin registration;
- DI/container wiring;
- event subscribers;
- preview detection;
- InvoiceModel/hydrator behavior;
- invoice rendering integration;
- paid-state updates.

### Controlled fake-Stripe/network tests

Use for:

- timeout after remote commit;
- malformed responses;
- retry behavior;
- pagination edge cases;
- rate-limit handling;
- concurrency/races;
- deterministic network failures;
- hostile/unexpected API object shapes.

### Real Stripe sandbox E2E

Use sparingly for high-value end-to-end proof:

- real Payment Link creation;
- hosted Checkout;
- successful sandbox card payment;
- real Stripe object relationships;
- exact reconciliation into real containerized Kimai;
- API/SDK compatibility.

Do not use Stripe sandbox as a load-test service.

## Failure/Outcome Taxonomy

Prefer a small shared taxonomy for CLI, logging, tests, and operational docs.

Initial categories should be approximately:

```text
SUCCESS
NOOP
RETRYABLE_FAILURE
CONFIGURATION_ERROR
DATA_CONFLICT
ACCOUNTING_EXCEPTION
UNEXPECTED_FAILURE
```

Do not collapse financial ambiguity into generic success/failure when the operator needs to know that human review is required.

Refine the taxonomy during implementation if evidence requires it, and document changes.

## Documentation Maintenance Rules

When work changes the following, update the listed artifact in the same PR:

| Change | Required documentation |
| --- | --- |
| architectural decision | ADR + `doc/decisions.md` |
| current project state/next work | `doc/project-handoff.md` |
| environment variable or config semantics | `doc/configuration.md` |
| trust boundary/privilege/destructive behavior | `doc/threat-model.md` |
| supported Kimai/PHP/Stripe versions | compatibility documentation/ADR |
| product selection assumptions | `doc/evaluated-tools.md` |
| dogfooding infrastructure assumptions | `doc/reference-hosting-environment.md` |
| test architecture/evidence | files under `doc/testing/` |
| operator recovery procedure | operational runbook |
| implementation sequence/agent autonomy | this playbook |

Do not allow a significant decision to exist only in a PR conversation or chat transcript.

## What the Session May Decide Autonomously

A session SHOULD ordinarily make and document reversible engineering choices without asking the user, including:

- class/file organization consistent with ADR-001;
- test fixture structure;
- internal helper names;
- dependency versions within already established compatibility constraints;
- error-message wording;
- test IDs and grouping;
- CI job organization;
- formatting/lint fixes;
- refactors that do not alter public behavior;
- implementation details with equivalent observable semantics;
- documentation corrections supported by current evidence.

When uncertain between two reversible choices, prefer the smaller, more conventional, more testable option and document anything future maintainers may reasonably question.

## Stop Conditions

A session SHOULD stop the affected work and ask the user only when one of these conditions is reached.

### Owner/business decisions

Examples:

- selecting the final software license;
- deciding a pricing/commercial-support model;
- changing the fundamental product purpose;
- intentionally expanding into subscriptions, taxes, accounting, dunning, or other excluded scope;
- choosing between materially different end-user behaviors where repository evidence does not establish a preference.

### Architecture reversal

Stop before:

- requiring public Kimai ingress;
- requiring Stripe webhooks;
- patching/forking Kimai core;
- introducing a durable connector payment ledger;
- adding a queue/broker as a correctness dependency;
- changing from one plugin to multiple packages for architectural reasons;
- changing the authority boundaries among Kimai, Stripe, and the connector.

Propose an ADR rather than implementing through the reversal.

### Secrets/credentials unavailable

Do not ask for production credentials.

When real Stripe sandbox credentials are required and unavailable, complete everything that can be completed without them, document the blocked E2E step, and stop only that validation step.

### Destructive or production actions

Do not autonomously:

- change live Stripe production objects;
- run cleanup against production;
- change live Kimai invoice paid states merely for testing;
- rotate/revoke production credentials;
- alter production network/firewall exposure;
- delete production data.

Use sandbox/disposable environments instead.

### Merge/release/publication

Do not merge a PR, publish a release, change repository visibility, publish to a marketplace/package registry, or make a public announcement unless the user explicitly requests it or an already-approved repository policy clearly authorizes it.

### Contradictory authoritative requirements

If an accepted ADR or explicit current owner instruction conflicts with another authoritative requirement and the conflict cannot be resolved by reading repository context, stop the conflicting portion and surface it clearly.

## Hands-Off Completion Standard

A good autonomous session should return with something closer to:

```text
I read the project execution guide and current ADRs.
I determined Phase N was the next incomplete phase.
I verified the relevant current Kimai/Stripe behavior.
I implemented X on branch Y.
I added/updated tests A, B, and C.
I ran checks D and E; they passed.
I updated documentation F because the behavior changed.
I opened/updated PR #N.
The remaining blocker is Z, which meets a documented stop condition.
```

rather than:

```text
What would you like me to do first?
```

The project documentation exists specifically to avoid unnecessary rediscovery and repeated clarification.

## Suggested Invocation for a Fresh ChatGPT Session

A minimal instruction should be sufficient:

> Inspect `wesley-dean/kimai-stripe-connector`.  Read `doc/implementation-playbook.md` and follow it.  Make reasonable reversible engineering decisions autonomously, keep the repository documentation synchronized with the work, and continue until you either complete a coherent implementation increment or encounter one of the playbook's explicit stop conditions.  Do not merge or release anything without my explicit approval.

For a narrower task, append the desired phase or outcome, for example:

> Start with the compatibility, metadata, money, and Stripe credential-scope decisions in Phase 0.

or:

> Implement the minimal installable Kimai plugin described in Phase 2.

## End-of-Session Handoff

Before ending a substantial session:

1. update `doc/project-handoff.md` if the next session would otherwise need conversational context;
2. update `doc/decisions.md` for ADR/status changes;
3. update configuration/threat/compatibility/testing/runbook docs when affected;
4. ensure commits/PR description reflect actual scope;
5. record tests actually run and their results;
6. distinguish completed work from planned or blocked work;
7. leave the repository in a state where another session can resume by reading this playbook and the linked documentation.
