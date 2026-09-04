# Implementation Playbook

Date: 2026-09-04

## Purpose

This is the primary execution guide for a fresh engineering session working on `kimai-stripe-connector`.

A session should be able to receive an instruction as short as:

> Go to `doc/implementation-playbook.md` and follow the instructions.

and then make meaningful progress without access to prior chat history.

The expected posture is a trusted engineering colleague and peer.  Exercise independent judgment, challenge weak assumptions, make ordinary reversible decisions autonomously, preserve evidence, and stop only at the explicit boundaries in this document.

## Default Mandate

Unless the project owner narrows the task, a session following this playbook SHOULD:

1. inspect the current repository, branches, pull requests, review threads, and CI state;
2. read the current project documents below;
3. identify the earliest incomplete implementation phase whose prerequisites are satisfied;
4. verify current upstream Kimai, Stripe, PHP, Composer, or dependency facts when the work depends on version-sensitive behavior;
5. make reversible engineering decisions consistent with Accepted ADRs without repeatedly asking permission;
6. implement the smallest coherent increment;
7. add/update appropriate automated tests under ADR-003;
8. run all applicable unit, static, integration, E2E, documentation, and lint checks;
9. require MegaLinter to finish with zero errors and zero warnings;
10. update ADRs/contracts/threat model/handoff when behavior or assumptions materially change;
11. use Conventional Commit messages;
12. open or update a focused pull request; and
13. summarize verified behavior, remaining uncertainty, and the next safe step.

Do not merge or release merely because work is complete.  Those remain explicit owner actions unless repository automation is already deliberately configured to perform that exact class of merge/release.

## Read Before Substantive Work

Read in this order:

1. [`AGENTS.md`](../AGENTS.md)
2. [`decisions.md`](decisions.md)
3. [`project-handoff.md`](project-handoff.md)
4. [`configuration.md`](configuration.md)
5. [`threat-model.md`](threat-model.md)
6. [`documentation.md`](documentation.md)
7. [`testing/test-matrix.md`](testing/test-matrix.md)
8. [`testing/preimplementation-mock-exercise.md`](testing/preimplementation-mock-exercise.md)
9. [`testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md)
10. [`reference-hosting-environment.md`](reference-hosting-environment.md)
11. [`evaluated-tools.md`](evaluated-tools.md) when revisiting product selection/history
12. [`README.md`](../README.md)
13. relevant repository automation/configuration

Use `decisions.md` to locate the ADRs that govern the task.  Read the actual ADR before implementing or changing that decision.

All numbered ADRs currently present in the repository are Accepted as of 2026-09-04.

## Source-of-Truth Order

When documentation appears to conflict:

```text
Accepted ADR
    > current explicit behavioral/configuration/security contract
    > decisions index summary
    > current project handoff
    > README orientation
    > preserved historical rationale
    > prior conversation/memory
```

Historical material can explain why a decision exists; it does not silently override a later Accepted ADR.

The original large proposed ADR-001 is preserved under `doc/adr/rationale/`.  Its current independent decisions now live in focused ADRs 005–011.

## Working Style

Work as a trusted peer, not a deferential step-by-step assistant.

That means:

- exercise independent technical judgment;
- challenge the project owner when evidence warrants it;
- distinguish facts, decisions, inferences, synthetic evidence, and guesses;
- surface contradictions and weak evidence early;
- prefer small, boring, auditable designs;
- prefer reversible changes;
- make normal implementation choices without asking for approval each time;
- do not invent architectural requirements from repository-template residue;
- do not hide uncertainty to keep work moving; and
- do not expand scope merely because an abstraction could be useful someday.

## Hard Architectural Boundaries

Unless superseded by a later Accepted ADR:

- one Kimai plugin contains two independently disableable capabilities;
- Kimai remains authoritative for invoice/time/business state;
- Stripe remains authoritative for payment movement and Stripe payment objects;
- the connector owns only correlation and conservative automation logic;
- Kimai core is not patched, forked, or vendor-modified;
- public ingress to Kimai is not required;
- Stripe webhooks are not required;
- Stripe Payment Links are the customer payment surface;
- manual Payment Link creation remains a permanent fallback;
- manual Kimai paid-state reconciliation remains a permanent fallback;
- ambiguous financial evidence produces no automatic accounting change;
- false negatives are preferred to false-positive payment reconciliation;
- automatic paid-to-unpaid reversal is not part of the initial model;
- paid Stripe orphans are accounting exceptions, not cleanup garbage;
- cleanup retains uncertain objects;
- no connector-owned payment ledger is introduced without new evidence and an ADR;
- implementation state must survive ordinary container replacement; and
- Payment Link/reconciliation responsibilities remain internally separated even though they share one package.

## Initial Compatibility Contract

ADR-004 currently establishes:

```text
Kimai baseline:       2.65.0
PHP:                  >=8.2 <8.6
Stripe PHP:           ^21.3.1
Stripe API behavior:  version aligned with installed SDK
Payment method:       card only
Money:                integer minor units
Currency scope:       ordinary two-decimal currencies initially
Metadata:             version 1 contract
Stripe credential:    one least-privilege restricted key initially
```

Do not broaden these claims casually.

A dependency or API-version update is behavioral compatibility work and requires appropriate tests.

## Configuration Rules

Follow `configuration.md`.

Primary environment namespace:

```text
KIMAI_STRIPE_CONNECTOR_*
```

Environment variables are primary configuration.

File-backed secret injection is preferred for sensitive production/container values.

`.env` is tertiary development/test convenience only.

Never commit Stripe credentials.

If both direct and file-backed Stripe secret forms are supplied, fail configuration validation rather than silently choosing precedence unless a later Accepted decision changes that rule.

## Testing Rule

ADR-003 is mandatory.

An addition, behavior change, bug fix, API change, compatibility patch, retry change, financial-state change, or security-sensitive code change requires appropriate automated tests when reasonably testable.

Examples:

```text
change Stripe API parameters       -> update tests
change metadata contract           -> update tests
change money conversion            -> update tests
change reconciliation criteria     -> update tests
fix retry bug                      -> regression test
fix documentation wording          -> no artificial unit test required
change comment only                -> no artificial unit test required
```

A bug fix SHOULD have a test that fails before the fix and passes after it.

Use `testing/test-matrix.md` to choose the lowest appropriate layer.

### Unit

Use for pure configuration, money, metadata, identity, decision logic, redaction, and result taxonomy.

### Kimai integration

Use for bundle loading, DI wiring, events, invoice model/hydration/rendering, and Kimai state mutation.

### Controlled Stripe/network integration

Use for deterministic timeouts, timeout-after-commit, pagination, rate limits, malformed responses, concurrency, and hostile object states.

### Real Stripe sandbox E2E

Use sparingly for behavior only real Stripe can prove: Payment Link creation, actual metadata propagation, hosted card Checkout, real object relationships, and exact reconciliation.

## Disposable Kimai Requirement

Real Kimai integration/E2E tests SHALL use the disposable harness under `tests/e2e/` unless a specific test needs a justified alternative.

The environment SHALL be created as part of the test run and destroyed by the test run.

It must not depend on the long-lived dogfooding Kimai instance.

Current harness behavior:

```text
fresh Compose project
-> fresh MySQL/Kimai/plugin volumes
-> Kimai 2.65.0
-> stage checked-out source from composer.lock with --no-dev
-> install production-shaped stage into var/plugins
-> kimai:reload
-> verify plugin lifecycle
-> remove plugin
-> kimai:reload
-> destroy containers/networks/volumes and local staging directory
```

The E2E harness deliberately does not copy the repository-root development `vendor/` tree into Kimai.  It builds an isolated runtime dependency tree from the committed lockfile so the lifecycle test exercises the same dependency shape intended for the self-contained production plugin artifact.

`tests/e2e/run.sh` owns cleanup via a shell trap and runs `docker compose down --volumes --remove-orphans`.

Do not turn the E2E harness into a persistent development server.

## Quality Gates

MegaLinter is blocking.

Required configuration:

```text
DISABLE_ERRORS: false
APPLY_FIXES: false
```

A green workflow badge is not sufficient when the report contains warnings.  Fix the underlying problem and rerun until the report is error- and warning-free.

The repository also has:

- PHP 8.2–8.5 unit/static-analysis CI;
- disposable Kimai E2E CI;
- Doxygen generation CI;
- pre-commit secret/file checks;
- Dependabot for GitHub Actions and Composer; and
- semver/release automation.

Do not weaken a quality gate to make a change pass unless the finding is genuinely invalid and the rationale is documented.

## Documentation Rules

Follow ADR-002 and `documentation.md`.

PHP source comments are Doxygen-compatible PHPDoc and should document intent/invariants/failures/security/side effects rather than merely restating declarations.

Generated reference output lives under ignored:

```text
doc/reference/
```

Behavior and documentation must evolve together.

When changing:

- architecture -> update/add ADR and `decisions.md`;
- configuration -> update `configuration.md`;
- trust boundary/credential/external interface/destructive behavior -> update `threat-model.md`;
- test architecture -> update `testing/test-matrix.md` or related testing docs;
- packaging/compatibility -> update the governing ADR and handoff;
- autonomous sequence/stop conditions -> update this playbook; and
- current project state -> update `project-handoff.md`.

## Git / PR Rules

Before editing, inspect current branches, open PRs, recent related commits, checks, and review comments.

For new substantive work, create a focused branch rather than committing directly to `main` unless explicitly instructed otherwise.

Use Conventional Commit messages.

A PR should explain:

- what changed;
- why;
- governing ADRs/contracts;
- tests run;
- security implications;
- compatibility implications; and
- unresolved issues.

Do not merge without explicit authorization unless existing deliberate repository automation already covers the exact class of merge.

## Implementation Phases

The architectural preparation phase is substantially complete.  Begin at the earliest phase whose work is not already present on the current branch/main.

### Phase 1: Foundation and repository scaffold

Expected outcomes:

- valid Kimai plugin bundle skeleton;
- Composer package metadata;
- plugin-local dependency model;
- PHPUnit/static-analysis setup;
- PHP-compatible linting;
- Composer Dependabot;
- disposable Kimai lifecycle E2E;
- Doxygen reference generation;
- build artifacts ignored; and
- all workflows clean.

The `chore/implementation-prep` branch was created to establish these pieces.  Inspect current repository state rather than assuming this phase is still incomplete.

A committed `composer.lock` is desired for reproducible development/release packaging.  If missing, generate and commit it before relying on the package for release reproducibility.

### Phase 2: Configuration, identity, money, metadata, and result primitives

Implement before real Stripe mutation:

- environment parsing/validation;
- direct/file-backed Stripe credential resolution;
- stable Kimai instance UUID;
- exact decimal-to-integer-minor-unit conversion for the accepted currency scope;
- version-1 metadata value objects/validation;
- safe logging/redaction;
- result taxonomy from ADR-013; and
- Stripe client construction/app identification without making a payment mutation.

Implement relevant `CFG-*`, `MNY-*`, metadata, and `THR-*` tests.

### Phase 3: Payment Link capability

Implement through ADR-006's supported Kimai seams.

Required behaviors include:

- preview -> zero Stripe mutation;
- exact compatible existing link -> reuse;
- no exact link -> create one;
- amount/currency/identity conflict -> stop;
- ambiguous candidates -> fail closed;
- expose URL to Kimai invoice renderer;
- safe retry/idempotency;
- timeout-after-commit recovery; and
- recoverable Stripe orphan after Kimai failure.

Add representative real Stripe sandbox E2E after deterministic lower-layer behavior exists.

### Phase 4: Reconciliation

Implement a deterministic external command suitable for cron/Jenkins/systemd/manual execution.

Define/test:

- command name;
- `--dry-run`;
- exit codes;
- human output;
- structured output if justified;
- pagination;
- batch-error behavior;
- concurrency/locking expectations; and
- ADR-013 result categories.

Follow ADR-009 exactly: strong evidence only, fail closed, no automatic post-payment reversal.

### Phase 5: Cleanup

Implement conservative orphan inspection/deactivation under ADR-010.

Provide dry-run first.

Cleanup is never allowed to guess through Kimai unavailability or payment ambiguity.

### Phase 6: Operator runbook

Document at least:

- Stripe paid / Kimai unpaid;
- Kimai failure after link creation;
- conflicting/multiple links;
- credential permission failure/rotation;
- paid orphan;
- cleanup uncertainty;
- reconciliation outage;
- Kimai compatibility failure after upgrade;
- disabling/removing the plugin; and
- return to manual billing.

### Phase 7: Compatibility and release evidence

Before a supported release claim:

- all applicable unit/static/integration/fake-Stripe tests pass;
- Kimai lifecycle E2E passes;
- representative real Stripe sandbox E2E passes;
- preview suppression is verified;
- install/disable/remove is verified;
- secret/log handling is reviewed;
- MegaLinter has zero errors/warnings;
- Doxygen has zero warnings;
- documentation matches behavior; and
- dependency/security checks are clean.

ADR acceptance does not mean implementation evidence can be skipped.

## License Boundary

The repository is currently private, so final public OSS licensing is not an immediate implementation blocker.

Do not make the repository public, publish a public release, or invite public contributions while silently treating the inherited template license as a deliberate final decision.  Resolve the public license before that boundary is crossed.

## Explicit Stop Conditions

Stop and request owner input before:

- merging a PR when explicit merge approval has not been given;
- publishing a release when explicit release approval has not been given;
- making the repository public;
- making an unresolved owner/business/license choice that cannot be derived from accepted project decisions;
- performing destructive actions against a real production Kimai or Stripe environment;
- using live Stripe credentials or charging real money without explicit authorization;
- reversing an Accepted architectural boundary rather than implementing within it;
- introducing mandatory public ingress/webhooks;
- introducing a connector-owned payment ledger/database;
- expanding into subscriptions, tax, dunning, generalized accounting, automated refund/dispute accounting, or other excluded billing-platform scope; or
- encountering genuinely contradictory Accepted requirements that cannot be reconciled through existing ADRs.

Do not stop merely because a normal engineering choice has multiple reasonable implementations.  Choose the safest/reversible option, test it, document material tradeoffs, and continue.

## End-of-Session Handoff

Before ending substantial work, leave the repository sufficient for the next session.

Update `project-handoff.md` when conversational history would otherwise be required to understand:

- current branch/PR state;
- what was implemented;
- what passed/failed;
- version-sensitive findings;
- outstanding uncertainty; and
- the next safe implementation step.
