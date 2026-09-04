# ADR-002: Use Documentation-First PHP Source Comments and Generated Reference Docs

Date: 2026-09-04

## Status

Accepted

## Context

The project owner maintains two related repositories, `wesley-dean/bootstrap` and
`wesley-dean/adrctl`, that treat source documentation as part of the engineering
contract rather than as optional cleanup.

Bootstrap ADR-045 establishes a deliberately narrative-heavy, Doxygen-style
source-commenting standard intended to preserve purpose, assumptions, failure
modes, safety mechanisms, examples, and ambiguity for humans and AI/LLM-assisted
development.  `adrctl` ADR-017 adapts that standard into a repository contract
that distinguishes README orientation, contributor guidance, ADR rationale,
normative product specification, implementation contracts in source comments,
and generated reference documentation.  `adrctl` ADR-020 later refines the
persistence model so generated Doxygen HTML remains reproducible build output
rather than committed source.

The Kimai Stripe Connector will be implemented primarily in PHP.  Copying the
Bash-specific comment syntax or Bash Doxygen input filter would be inappropriate,
but losing the information model would discard a useful maintenance and safety
practice precisely where the connector handles payment state and credentials.

Current Doxygen releases support PHP natively and understand C-like documentation
blocks in PHP source.  The project therefore does not need a language-conversion
filter merely to obtain generated reference documentation.

## Decision Drivers

- Preserve the documentation-first philosophy used by the sibling projects.
- Make implementation contracts understandable without reverse-engineering code.
- Keep architecture rationale separate from per-class and per-method contracts.
- Make financial-state invariants, side effects, and failure behavior visible near
  the code that enforces them.
- Support AI/LLM-assisted maintenance without encouraging speculative rationale.
- Generate browsable reference documentation directly from maintained source.
- Avoid maintaining generated HTML as a second source of truth.
- Use PHP-native syntax and type information rather than mechanically translating
  Bash conventions.

## Decision

The project SHALL use a documentation-first source-commenting standard for PHP
implementation code.

The normative source-documentation standard SHALL live in
`doc/documentation.md`.

Hand-maintained PHP source SHALL use Doxygen-compatible PHPDoc-style blocks for,
where applicable:

- files/modules;
- public classes, interfaces, traits, and enums;
- public methods and functions;
- non-trivial protected/private helpers;
- important properties and policy-defining constants;
- configuration boundaries represented in PHP;
- non-obvious value objects or data structures;
- safety mechanisms and validation guards; and
- behavior whose failure semantics or side effects are not obvious from the
  declaration alone.

Source documentation SHALL explain intent, constraints, ownership, invariants,
side effects, security posture, and failure behavior rather than merely restating
PHP syntax.

PHP declarations and native type hints SHALL remain authoritative for type shape.
Documentation SHOULD add semantic meaning that PHP's type system does not express.

The project SHALL preserve the following Doxygen concepts from the sibling
repositories, using them only where they improve understanding:

- `@file`;
- `@brief`;
- `@details`;
- `@param`;
- `@return`;
- `@retval` when particular return values carry distinct semantics;
- `@throws`;
- `@var` for documented properties/state when useful;
- `@see` for directly relevant contracts;
- `@deprecated` when an API is intentionally retained but discouraged;
- `@todo` when intent is genuinely uncertain;
- `@par` sections for examples, invariants, side effects, security considerations,
  failure modes, ownership, or other narrative material; and
- `@code` / `@endcode` for realistic examples.

Bash-specific conventions SHALL NOT be copied mechanically.  In particular, PHP
methods do not need synthetic `@fn` declarations because Doxygen can parse PHP
declarations directly, and the Bash input filter used by the sibling projects is
not required.

A root `Doxyfile` SHALL define reproducible HTML reference generation.

Generated reference documentation SHALL live under:

```text
doc/reference/
```

Generated reference output SHALL be ignored by Git and SHALL NOT be treated as
maintained source.  The maintained sources of truth are the PHP source comments,
`Doxyfile`, ADRs, specifications, README, AGENTS, and other hand-maintained
Markdown documentation.

The project SHOULD publish generated reference documentation through CI/GitHub
Pages once implementation and release automation are established.  CI generation
SHOULD use the same `Doxyfile` as local generation and SHOULD fail on Doxygen
warnings that indicate malformed or incomplete documentation contracts.

Documentation-only modifications to existing source SHALL preserve executable
behavior.  If intent cannot be established from repository evidence, the change
SHALL surface that uncertainty with a specific `@todo` rather than invent a
plausible rationale.

## Documentation Roles

The project SHALL keep documentation responsibilities distinct:

- `README.md`: human-facing orientation, project purpose, status, and primary use;
- `AGENTS.md`: contributor and AI/LLM operating guidance;
- `doc/adr/`: durable architectural decisions and historical rationale;
- `doc/decisions.md`: compact ADR discovery/index, never authoritative over ADRs;
- `doc/connector-spec.md`: current public behavioral contract once behavior is
  defined strongly enough to specify;
- `doc/configuration.md`: configuration contract and precedence;
- `doc/threat-model.md`: security assets, trust boundaries, threats, controls, and
  residual risk;
- `doc/testing/`: test strategy, evidence, and historical test-design material;
- `doc/documentation.md`: normative source-documentation standard;
- source comments: implementation-level contracts and reasoning close to code;
- `doc/reference/`: generated Doxygen reference output, ignored by Git; and
- `doc/project-handoff.md`: continuity context that would otherwise live only in
  conversations.

A document SHALL NOT silently take over another document's authority.  ADRs
explain why a durable decision was made; the specification describes current
public behavior; source comments describe implementation contracts.

## Required Content

Where applicable, a documented PHP entity SHOULD address:

- purpose and responsibility;
- background or motivation when the reason is not obvious;
- parameters and semantic expectations;
- return semantics;
- exceptions and failure modes;
- preconditions and assumptions;
- invariants that must remain true;
- external side effects;
- ownership/caller boundaries;
- security or privacy considerations;
- configuration dependencies and defaults;
- idempotency or concurrency behavior;
- non-goals or deliberately excluded behavior; and
- realistic examples when examples materially reduce ambiguity.

Omissions are expected when a field does not apply.  Omissions SHOULD be
intentional rather than the result of treating documentation as decoration.

## Doxygen Warning Policy

The Doxygen configuration SHOULD enable warnings for malformed documentation and
missing parameter documentation and SHOULD make CI fail after warnings have been
reported.  This follows the repository's broader quality-gate posture: a green
workflow should not hide documentation warnings.

The initial configuration MAY be adjusted if real Kimai/Symfony integration code
reveals warnings that cannot distinguish project-owned public contracts from
framework-required boilerplate.  Such adjustments should narrow false positives
without abandoning the documentation-first requirement.

## Considered Alternatives

### Rely only on PHPDoc conventions without generated documentation

PHPDoc comments would still help IDEs and maintainers, but the sibling projects
establish a useful browsable reference surface and a repeatable documentation
build.  Doxygen can provide that surface directly for PHP.

### Use a PHP-specific documentation generator instead of Doxygen

Tools such as phpDocumentor may eventually provide useful ecosystem-specific
features.  Introducing a second documentation model now would reduce consistency
with the sibling projects without a demonstrated project need.  This decision can
be revisited if Doxygen's PHP support proves materially inadequate.

### Commit generated HTML

Generated HTML duplicates information already represented in maintained source,
creates noisy diffs, and can drift.  The later `adrctl` decision to keep generated
reference output out of Git is the stronger precedent.

### Document only public APIs

The connector's most consequential logic may live in private validation,
correlation, reconciliation, and cleanup helpers.  Non-trivial private behavior
that enforces financial or security invariants should be documented even when it
is not part of the public API.

## Consequences

Source files will be more verbose and documentation maintenance will consume real
engineering effort.

That cost is accepted because the connector must remain understandable during
financial-state investigation, compatibility failures, security review, and
AI/LLM-assisted maintenance.

Generated API/reference documentation can be recreated from repository source
without committing generated HTML.

Documentation drift becomes an engineering defect rather than cosmetic debt.

## Open Questions and Follow-Ups

- Decide the exact Composer/build command that will wrap `doxygen Doxyfile` once
  the PHP package skeleton exists.
- Decide whether generated reference docs will be published through GitHub Pages,
  release artifacts, or both.
- Decide whether a PHP-specific documentation tool should supplement Doxygen if
  concrete limitations appear.
- Define the first complete `doc/connector-spec.md` as implementation contracts
  become executable rather than merely proposed architecture.

## Related Decisions

- Related to: ADR-000
- Related to: ADR-001
- Adapted from: `wesley-dean/bootstrap` ADR-041 and ADR-045
- Adapted from: `wesley-dean/adrctl` ADR-017 and ADR-020
