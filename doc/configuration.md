# Configuration Model

Date: 2026-09-03

## Purpose

This document defines the intended configuration interface for `kimai-stripe-connector` before implementation begins.

The connector is expected to run primarily inside containerized Kimai deployments.  The configuration model therefore treats environment variables as the primary deployment interface, file-backed secret injection as the preferred container mechanism for sensitive values, and `.env` files as a tertiary convenience for local development and test environments.

The exact set of configuration keys may evolve during implementation.  Changes that materially alter deployment or compatibility expectations should be reflected here and, when architectural, in the relevant ADR.

## Configuration Hierarchy

The intended hierarchy is:

```text
1. Environment variables        primary configuration interface
2. Secret-file indirection      preferred for sensitive values in containers
3. .env files                   tertiary development/test convenience
```

A `.env` file does not define a separate configuration model.  It is merely one mechanism that may populate the same namespaced configuration values used elsewhere.

## Namespace

All connector-owned environment variables SHOULD use the prefix:

```text
KIMAI_STRIPE_CONNECTOR_
```

The long prefix is intentional.

It:

- makes ownership unambiguous;
- avoids collisions with Kimai's own environment variables;
- avoids collisions with unrelated Stripe integrations;
- leaves room for future Kimai or third-party Stripe configuration;
- makes container manifests easier to inspect and audit.

Avoid generic variables such as:

```text
STRIPE_API_KEY
STRIPE_ENABLED
PAYMENTS_ENABLED
```

unless a future upstream convention requires otherwise.

## Proposed Variables

The initial configuration surface is expected to include values similar to the following.

### General

```text
KIMAI_STRIPE_CONNECTOR_ENABLED
```

Controls whether the connector is globally active.

```text
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID
```

Provides the stable, non-secret Kimai connector instance identity used in Stripe metadata/correlation.

The instance ID must remain stable across:

- container recreation;
- application restarts;
- plugin upgrades;
- node rescheduling.

Changing it after production use is effectively a metadata namespace migration.

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

### Cleanup behavior

```text
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS
```

Controls the minimum age before an unpaid connector-managed orphan may become eligible for deactivation.

The default, bounds, and exact semantics remain an implementation decision.  The grace period applies to orphaned links without a matching Kimai invoice, not to ordinary unpaid invoices.

### Stripe credential

Direct secret value:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
```

File-backed secret path:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE
```

The connector SHOULD support both mechanisms.

The direct value is convenient for disposable development/test environments.

The file-backed form is preferred for containerized production environments that provide secrets as mounted files, such as Docker/Swarm secrets.

## Secret Resolution

The intended production pattern is approximately:

```text
container secret mechanism
        |
        v
/run/secrets/<secret-file>
        |
        v
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE
        |
        v
connector reads file contents
        |
        v
Stripe client
```

A deployment may instead provide:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=<test-or-production-key>
```

when that is operationally appropriate.

### Both secret forms configured

If both:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
```

and:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE
```

are configured simultaneously, the preferred initial behavior is to **fail configuration validation** rather than silently choose precedence.

Silent precedence creates avoidable operational ambiguity, especially during key rotation or incident response.

If implementation experience demonstrates a strong reason for deterministic precedence instead, document and test it explicitly.

## `.env` Support

`.env` support is tertiary.

A `.env` file MAY be used for:

- local development;
- disposable integration tests;
- disposable E2E environments;
- developer convenience where the file is not committed and the surrounding runtime handles it safely.

The plugin SHALL NOT require a `.env` file to operate.

The repository SHALL NOT commit real `.env` files containing credentials.

The existing repository pre-commit configuration intentionally rejects common `.env` filenames.

Sample/example configuration may be provided using non-secret placeholder files whose names do not violate repository policy.

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

This is illustrative only.  The connector must not depend on Docker Swarm specifically.

Equivalent file-backed secret injection from Kubernetes, Podman, systemd credentials, or another container/runtime mechanism should remain possible.

## Development/Test Example

A disposable local or CI environment may instead use:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED=true
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED=true
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=<test-instance-id>
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=<Stripe sandbox key>
```

The direct secret form is acceptable in a controlled ephemeral test environment when the CI secret-management and logging configuration keep the value protected.

## Behavioral Configuration Versus Deployment Configuration

The initial implementation may use environment variables for both deployment identity and behavioral switches because that works naturally in immutable/container deployments.

Conceptually, however, two categories exist.

### Deployment-specific values

Examples:

```text
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE
```

These are naturally supplied by the deployment environment.

### Plugin behavior

Examples:

```text
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS
```

If Kimai exposes a clean, supported, durable plugin-configuration mechanism suitable for these settings, a future implementation may provide a UI or Kimai-native configuration layer.

Environment-variable support should remain available for immutable/containerized deployments unless a later ADR deliberately changes that contract.

## Validation Rules

Configuration should fail early and visibly when it is contradictory or unsafe.

Examples include:

- connector enabled but no usable Stripe credential configured;
- both direct and file-backed Stripe credentials configured;
- secret file configured but missing/unreadable;
- empty Stripe credential after trimming file contents;
- missing stable instance ID when a capability requiring correlation is enabled;
- malformed instance ID if a strict format is adopted;
- invalid boolean values;
- invalid cleanup grace period;
- unsupported Stripe/API-mode combinations once those rules are defined.

Do not silently guess through configuration errors that can affect payment behavior.

## Logging Rules

Configuration validation and startup logs MAY report safe values such as:

- connector enabled/disabled;
- Payment Link capability enabled/disabled;
- reconciliation enabled/disabled;
- safe instance ID;
- cleanup grace period;
- whether credential source is `direct` or `file`.

They SHALL NOT report:

- Stripe API key contents;
- secret-file contents;
- environment dumps containing the key;
- full configuration structures that accidentally include the key.

Given the observed shell-tracing behavior in the reference Kimai container, secret-safe operation must consider startup/entrypoint logging in addition to application logging.

## Precedence Beyond Secret Resolution

The project has not yet defined a broad multi-layer precedence system such as:

```text
UI > environment > config file > defaults
```

Do not invent one prematurely.

For the first implementation, environment variables should be the clear primary configuration contract.  Any later Kimai-native UI/configuration layer should define precedence explicitly and include migration/testing guidance.

## Testing Requirements

Configuration behavior should have executable tests covering at least:

```text
CFG-001 connector disabled with no secret -> valid inert configuration
CFG-002 connector enabled with direct secret -> valid
CFG-003 connector enabled with secret file -> valid
CFG-004 both secret forms -> configuration error
CFG-005 missing secret file -> configuration error
CFG-006 unreadable secret file -> configuration error
CFG-007 blank secret file -> configuration error
CFG-008 missing instance ID when required -> configuration error
CFG-009 malformed boolean -> configuration error
CFG-010 invalid cleanup grace period -> configuration error
CFG-011 logs do not contain direct secret
CFG-012 logs do not contain file-backed secret contents
CFG-013 .env-populated variables behave identically to externally supplied variables
```

The identifiers above are proposed documentation/test-backlog identifiers, not existing repository tests.

## Security Relationship

Configuration is part of the connector's threat surface because it controls:

- Stripe credentials;
- connector identity;
- whether automatic payment-link generation occurs;
- whether reconciliation can modify Kimai invoice state;
- cleanup behavior.

Threats and controls related to configuration are documented in [`doc/threat-model.md`](threat-model.md) once that document exists.

## Related Documentation

- [`doc/reference-hosting-environment.md`](reference-hosting-environment.md) — sanitized dogfooding environment
- [`doc/adr/ADR-001-single-plugin-architecture.md`](adr/ADR-001-single-plugin-architecture.md) — connector architecture
- [`doc/testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) — disposable real-Kimai/Stripe-sandbox E2E strategy
- [`AGENTS.md`](../AGENTS.md) — AI/agent project guidance
