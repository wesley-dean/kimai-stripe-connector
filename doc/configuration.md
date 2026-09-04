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

## Implemented Parsing Semantics

Phase 2 implements the environment parser in `Common/Configuration/EnvironmentConfigurationLoader.php`.

Boolean values accept only the following case-insensitive strings:

```text
true
false
1
0
```

Surrounding whitespace, `yes`/`no`, and other implicit truthy values are rejected rather than guessed.

The three enablement switches default to `false` when absent.  The connector therefore remains inert by default.

A Stripe credential and stable instance UUID are required only when the connector is globally enabled and at least one Stripe-backed capability is enabled.  Supplying malformed or contradictory connector-owned values can still fail validation even when the connector is otherwise disabled; disabling the connector is not a mechanism for hiding a broken configuration.

## Initial Configuration Surface

The initial configuration surface is intentionally small.

### Global enablement

```text
KIMAI_STRIPE_CONNECTOR_ENABLED
```

Controls whether connector behavior is globally active.

When false, the connector remains inert and ordinary Kimai behavior continues without requiring a Stripe credential.

Default:

```text
false
```

### Stable Kimai instance identity

```text
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID
```

Provides the stable, non-secret UUID used by the versioned Stripe metadata/correlation contract.

The implementation requires a canonical, non-nil UUID and normalizes the value to lowercase for metadata output.

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

Default:

```text
false
```

### Reconciliation capability

```text
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED
```

Controls Stripe-to-Kimai payment reconciliation behavior.

Default:

```text
false
```

### Cleanup grace period

```text
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS
```

Controls the minimum age before a proven unpaid connector-managed orphan can become eligible for automatic deactivation.

The grace period applies only after the connector has established that no matching Kimai invoice exists.  It does not make an ordinary unpaid invoice link disposable merely because the link is old.

The implemented parsing contract is:

```text
default: 7 days
minimum: 1 day
maximum: 3650 days
syntax:  positive base-10 integer without a sign, fraction, or leading zero
```

The upper bound exists primarily to catch obvious deployment mistakes while still allowing unusually conservative retention policies.  Changing these values later is configuration behavior and requires tests under ADR-003.

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

The implemented credential validator accepts restricted Stripe key shapes beginning with:

```text
rk_test_
rk_live_
```

and rejects broad `sk_` secret keys.  This makes the Accepted least-privilege credential model an executable configuration boundary rather than deployment advice only.

The direct form is useful for disposable local or CI test environments.

The file-backed form is preferred for containerized production deployments using Docker/Swarm secrets, Kubernetes secrets, systemd credentials, Podman secrets, or an equivalent mounted-secret mechanism.

## Stripe Credential Scope

ADR-004 currently requires one restricted Stripe key with only the permissions needed for the connector's accepted behavior.

The verified Stripe sandbox permission set is:

| Stripe resource     | Permission |
|---------------------|------------|
| Payment Links       | Write      |
| Checkout Sessions   | Read       |
| Payment Intents     | Read       |
| Charges             | Read       |
| Products            | None       |
| Prices              | None       |
| All other resources | None       |

`Payment Links: Write` supplies connector-managed Payment Link creation, retrieval/listing, and deactivation.  The real sandbox probe also proved that the inline one-time `price_data` / `product_data` request works while Products and Prices remain `None`.

Phase 4 reconciliation uses Checkout Sessions `Read`, Payment Intents `Read`, and Charges `Read`.  It performs no Stripe mutation and requires no permission expansion beyond the verified Phase 3 set.

The credential should not carry unrelated payout, subscription, customer-management, account-administration, refund, or generalized billing permissions.

The connector SHALL fail clearly when the supplied credential lacks a required permission.

The executable permission evidence and GitHub Actions secret boundary are documented in [`stripe-restricted-key.md`](stripe-restricted-key.md).

No real Stripe credential is required for unit tests or controlled-SDK integration tests.  The manual real-sandbox workflow is the only ordinary repository test path that receives the sandbox restricted key.

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

### Direct secret handling

The direct value is validated as supplied.  The connector does not silently trim or rewrite a directly supplied credential.

This is deliberate: invisible whitespace or other mutation in deployment configuration should produce a configuration error instead of making the effective credential differ from the configured value.

### File path handling

The file-backed form is intentionally runtime-agnostic.  The connector does not require `/run/secrets` specifically.

The path is deployment-controlled configuration and SHALL be treated as security-sensitive input.  Failure to read the configured file is a configuration error, not a reason to fall back silently to another secret source.

The file contents are trimmed before credential validation so ordinary mounted-secret files with a terminal newline work as expected.  A file that is blank after trimming is a configuration error.

## `.env` Support

`.env` support is tertiary.

A `.env` file MAY be used for:

- local development;
- disposable unit/integration environments;
- disposable E2E environments; or
- developer convenience where the file is not committed and the surrounding runtime handles it safely.

The connector does not implement a second `.env` parser or precedence system.  A surrounding runtime or development tool may populate the same `KIMAI_STRIPE_CONNECTOR_*` process environment values from a `.env` file; once populated, they use exactly the same parser and validation path as externally supplied environment variables.

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

Unit tests do not need a real key.  They construct synthetic restricted-key-shaped values at runtime and perform no Stripe request.

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

The Phase 2 Stripe client factory intentionally omits a `stripe_version` override and supplies only the restricted API key plus non-secret application identification.

### Payment methods

Version 1 supports card payments only.  The connector should not expose an arbitrary payment-method configuration knob before additional lifecycle semantics are designed and tested.

### Metadata version

The initial metadata contract is version 1.  Operators do not choose an arbitrary metadata version through configuration.

### Money/currency behavior

Version 1 accepts only the currency/amount semantics defined by ADR-004.  Unsupported money semantics fail closed rather than becoming runtime configuration guesses.

The Phase 2 implementation supports ordinary two-decimal Stripe presentment currencies and explicitly rejects zero-decimal currencies and currencies with Stripe-specific special representation rules.  Decimal amounts are parsed as strings and must be exactly representable in hundredths; binary floating point and rounding are not used.

## Validation Rules

Configuration SHALL fail early and visibly when it is contradictory, malformed, or unsafe.

Implemented validation includes:

- connector disabled with no credential -> valid inert configuration;
- active Stripe-backed capability with a usable direct restricted credential -> valid;
- active Stripe-backed capability with a readable file-backed restricted credential -> valid;
- both credential forms configured -> configuration error;
- broad `sk_` credential -> configuration error;
- configured secret file missing -> configuration error;
- configured secret file unreadable -> configuration error;
- configured secret file blank after trimming -> configuration error;
- active correlation-capable behavior without a valid instance UUID -> configuration error;
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

Phase 2 provides a safe configuration log-context representation that intentionally excludes the credential value, plus deterministic redaction for known secret strings.  Integration/startup logging must use these boundaries rather than dumping configuration objects.

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

Phase 2 provides unit coverage for CFG-001 through CFG-010 and pure redaction/safe-context coverage supporting CFG-011 and CFG-012.  Real Kimai startup/integration log inspection remains required before CFG-011/012 are considered fully proven at the deployment boundary.  CFG-013 remains an integration/development-environment check because the connector intentionally does not own `.env` parsing.

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
- [`doc/stripe-restricted-key.md`](stripe-restricted-key.md) — verified least-privilege Stripe sandbox permissions
- [`doc/threat-model.md`](threat-model.md) — security model
- [`doc/reference-hosting-environment.md`](reference-hosting-environment.md) — sanitized dogfooding environment
- [`doc/testing/test-matrix.md`](testing/test-matrix.md) — executable test-layer mapping
- [`doc/testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) — disposable Kimai + Stripe sandbox E2E strategy
- [`AGENTS.md`](../AGENTS.md) — project execution guidance
