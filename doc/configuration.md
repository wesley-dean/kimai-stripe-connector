# Configuration Model

Date: 2026-09-04

## Purpose

This document defines the current configuration contract for `kimai-stripe-connector`.

The connector is expected to run primarily inside containerized Kimai deployments.  Environment variables are therefore the primary configuration interface, file-backed secret injection is the preferred container mechanism for sensitive values, and `.env` files are a tertiary development/test convenience.

A `.env` file does not define a separate configuration model.  It is only one mechanism that may populate the same namespaced environment variables used in every other deployment model.

Material changes to this contract must remain synchronized with the relevant ADRs, tests, threat model, and deployment documentation.

## Configuration Hierarchy

The project uses this hierarchy:

```text
1. Environment variables        primary configuration interface
2. Secret-file indirection      preferred for sensitive values in containers
3. .env files                   tertiary development/test convenience
```

The plugin SHALL NOT require a `.env` file to operate.

## Namespace

All connector-owned environment variables SHALL use:

```text
KIMAI_STRIPE_CONNECTOR_
```

The long prefix is deliberate.  It makes ownership unambiguous, avoids collisions with Kimai or unrelated Stripe integrations, and makes container manifests easier to inspect and audit.

Do not introduce generic variables such as:

```text
STRIPE_API_KEY
STRIPE_ENABLED
PAYMENTS_ENABLED
```

unless a later Accepted decision deliberately changes the namespace contract.

## Initial Configuration Surface

The initial configuration surface is intentionally small.

### Global enablement

```text
KIMAI_STRIPE_CONNECTOR_ENABLED
```

Controls whether connector behavior is globally active.

When false, the connector should remain inert and ordinary Kimai behavior should continue without requiring a Stripe credential.

### Stable Kimai instance identity

```text
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID
```

Provides the stable, non-secret UUID used by the versioned Stripe metadata/correlation contract.

The value SHALL remain stable across:

- container recreation;
- application restarts;
- plugin upgrades;
- node rescheduling; and
- scheduler changes.

Changing it after production Stripe objects exist is a metadata-namespace migration, not routine configuration rotation.

### Payment Link capability

```text
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED
```

Controls automatic Payment Link creation/recovery and invoice-rendering support.

### Reconciliation capability

```text
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED
```

Controls Stripe-to-Kimai payment reconciliation behavior.

### Cleanup grace period

```text
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS
```

Controls the minimum age before a proven unpaid connector-managed orphan can become eligible for automatic deactivation.

The grace period applies only after the connector has established that no matching Kimai invoice exists.  It does not make an ordinary unpaid invoice link disposable merely because the link is old.

The final default and accepted numeric bounds remain implementation details and must be documented and tested when configuration parsing is implemented.

### Stripe restricted API credential

Direct secret value:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
```

File-backed secret path:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE
```

The first implementation uses one least-privilege Stripe restricted API key under ADR-004.  These two variables are alternative delivery mechanisms for that same credential, not separate Stripe roles or separate credentials.

The direct form is useful for disposable local or CI test environments.

The file-backed form is preferred for containerized production deployments using Docker/Swarm secrets, Kubernetes secrets, systemd credentials, Podman secrets, or an equivalent mounted-secret mechanism.

## Stripe Credential Scope

ADR-004 currently requires one restricted Stripe key with only the permissions needed for the connector's accepted behavior.

The key should be able to perform the minimum required operations for:

- connector-managed Payment Link creation, retrieval/listing, and deactivation;
- the inline one-time product/price representation used by Payment Link creation;
- Checkout Session retrieval/listing related to connector-managed links;
- PaymentIntent retrieval needed for reconciliation; and
- Charge retrieval needed for reconciliation.

It should not carry unrelated payout, subscription, customer-management, account-administration, or generalized billing permissions.

The exact Stripe dashboard permission labels remain to be verified against a real restricted sandbox key.  Once verified, they should be recorded in operator/deployment documentation and covered by sandbox validation.

The connector SHALL fail clearly when the supplied credential lacks a required permission.

## Secret Resolution

The preferred containerized production pattern is:

```text
container secret mechanism
        |
        v
mounted secret file
        |
        v
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE
        |
        v
connector reads + trims file contents
        |
        v
Stripe client
```

A deployment may instead supply:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=<restricted-key>
```

when the surrounding runtime can protect that value appropriately.

### Both secret forms configured

If both direct and file-backed credential variables are configured simultaneously, configuration validation SHALL fail rather than silently choosing precedence.

Silent precedence is particularly undesirable during key rotation or incident response because the operator may believe one credential is active while the connector is actually using another.

Any future change to this behavior requires documentation and tests under ADR-003.

### File path handling

The file-backed form is intentionally runtime-agnostic.  The connector should not require `/run/secrets` specifically unless implementation evidence demonstrates a security need strong enough to justify reducing portability.

The path is deployment-controlled configuration and SHALL be treated as security-sensitive input.  Failure to read the configured file is a configuration error, not a reason to fall back silently to another secret source.

## `.env` Support

`.env` support is tertiary.

A `.env` file MAY be used for:

- local development;
- disposable unit/integration environments;
- disposable E2E environments; or
- developer convenience where the file is not committed and the surrounding runtime handles it safely.

The repository SHALL NOT commit real `.env` files containing credentials.

The existing pre-commit configuration intentionally rejects common `.env` filenames.

Sample configuration, when added, should use placeholders and a filename compatible with repository policy.

## Containerized Production Example

Conceptually:

```yaml
secrets:
  stripe_api_key:
    external: true

services:
  kimai:
    secrets:
      - stripe_api_key
    environment:
      KIMAI_STRIPE_CONNECTOR_ENABLED: "true"
      KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED: "true"
      KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED: "true"
      KIMAI_STRIPE_CONNECTOR_INSTANCE_ID: "<stable-uuid>"
      KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS: "7"
      KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE: "/run/secrets/stripe_api_key"
```

This example is illustrative.  The connector does not depend on Docker Swarm specifically.

Equivalent mounted-secret mechanisms in other supported runtimes should work without connector-specific logic.

## Development/Test Example

A disposable local or CI environment may use:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED=true
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED=true
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=<test-instance-uuid>
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=<Stripe sandbox restricted key>
```

The direct secret form is acceptable in a controlled ephemeral environment when CI/logging configuration prevents disclosure.

No test should use a live Stripe key unless explicitly authorized for a live-payment exercise, which is outside ordinary repository CI.

## Deployment Values Versus Plugin Behavior

The initial implementation uses environment variables for both deployment identity and behavioral switches because that maps cleanly to immutable/container deployment.

Two conceptual categories still exist.

### Deployment-specific values

Examples:

```text
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE
```

These naturally belong to deployment configuration.

### Plugin behavior

Examples:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS
```

A future Kimai-native settings UI may expose behavioral values if Kimai provides a clean supported mechanism and a later change defines precedence explicitly.

Environment-variable support should remain available for immutable/containerized deployments unless a later Accepted ADR changes that contract.

## Values That Are Not Configuration in Version 1

Several accepted version-1 behaviors are deliberately fixed rather than configurable.

### Stripe API version

The connector follows the API version associated with the exact installed Stripe PHP SDK under ADR-004.  There is no independent `KIMAI_STRIPE_CONNECTOR_STRIPE_API_VERSION` setting in the initial contract.

### Payment methods

Version 1 supports card payments only.  The connector should not expose an arbitrary payment-method configuration knob before additional lifecycle semantics are designed and tested.

### Metadata version

The initial metadata contract is version 1.  Operators do not choose an arbitrary metadata version through configuration.

### Money/currency behavior

Version 1 accepts only the currency/amount semantics defined by ADR-004.  Unsupported money semantics fail closed rather than becoming runtime configuration guesses.

## Validation Rules

Configuration SHALL fail early and visibly when it is contradictory, malformed, or unsafe.

Expected validation includes:

- connector disabled with no credential -> valid inert configuration;
- connector enabled with a usable direct credential -> valid;
- connector enabled with a readable file-backed credential -> valid;
- both credential forms configured -> configuration error;
- configured secret file missing -> configuration error;
- configured secret file unreadable -> configuration error;
- configured secret file blank after trimming -> configuration error;
- capability requiring correlation enabled without a valid instance UUID -> configuration error;
- malformed boolean -> configuration error;
- invalid cleanup grace period -> configuration error; and
- unsupported or contradictory runtime state -> fail closed with a clear operator result.

Do not silently guess through configuration errors that can affect payment behavior.

## Logging Rules

Configuration validation/startup logs MAY report safe values such as:

- connector enabled/disabled;
- Payment Link capability enabled/disabled;
- reconciliation enabled/disabled;
- stable instance UUID;
- cleanup grace period;
- credential source as `direct` or `file`; and
- Stripe SDK/version information that is not secret.

They SHALL NOT report:

- Stripe API key contents;
- secret-file contents;
- environment dumps containing the key;
- full configuration structures containing the key; or
- command lines or exception messages that expose the key.

The reference Kimai container has exhibited shell-tracing behavior capable of exposing environment values.  Secret-safe operation must therefore consider container/entrypoint logging in addition to application logging.

## Precedence Beyond Secret Resolution

The project does not currently define a broad layered precedence model such as:

```text
UI > environment > config file > defaults
```

Do not invent one implicitly.

Environment variables are the primary configuration contract for the first implementation.  A future Kimai-native configuration layer must define precedence and migration behavior deliberately before introduction.

## Testing Requirements

Configuration behavior maps to the `CFG-*` backlog and test architecture in [`testing/test-matrix.md`](testing/test-matrix.md).

The minimum expected cases include:

```text
CFG-001 connector disabled with no secret -> valid inert configuration
CFG-002 connector enabled with direct secret -> valid
CFG-003 connector enabled with secret file -> valid
CFG-004 both secret forms -> configuration error
CFG-005 missing secret file -> configuration error
CFG-006 unreadable secret file -> configuration error
CFG-007 blank secret file -> configuration error
CFG-008 missing/invalid instance UUID when required -> configuration error
CFG-009 malformed boolean -> configuration error
CFG-010 invalid cleanup grace period -> configuration error
CFG-011 logs do not contain direct secret
CFG-012 logs do not contain file-backed secret contents
CFG-013 .env-populated values behave identically to externally supplied values
```

These identifiers describe required coverage.  They are not all implemented merely because they are documented.

Any future configuration behavior change requires appropriate automated tests under ADR-003.

## Security Relationship

Configuration is part of the connector's threat surface because it controls:

- Stripe credential access;
- stable connector identity;
- automatic Payment Link generation;
- automatic reconciliation; and
- cleanup eligibility behavior.

Threats and controls related to configuration are maintained in [`threat-model.md`](threat-model.md).

## Related Decisions and Documentation

- [`doc/adr/ADR-001-single-plugin-architecture.md`](adr/ADR-001-single-plugin-architecture.md) — one package / two capabilities
- [`doc/adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md`](adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md) — initial SDK/payment/money/metadata/credential contract
- [`doc/adr/ADR-005-private-kimai-outbound-stripe-integration.md`](adr/ADR-005-private-kimai-outbound-stripe-integration.md) — private Kimai and outbound-only Stripe boundary
- [`doc/adr/ADR-008-use-versioned-stripe-metadata-for-correlation.md`](adr/ADR-008-use-versioned-stripe-metadata-for-correlation.md) — stable instance identity and metadata correlation
- [`doc/threat-model.md`](threat-model.md) — security model
- [`doc/reference-hosting-environment.md`](reference-hosting-environment.md) — sanitized dogfooding environment
- [`doc/testing/test-matrix.md`](testing/test-matrix.md) — executable test-layer mapping
- [`doc/testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) — disposable Kimai + Stripe sandbox E2E strategy
- [`AGENTS.md`](../AGENTS.md) — project execution guidance
