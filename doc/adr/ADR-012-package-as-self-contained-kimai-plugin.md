# ADR-012: Package as a Self-Contained Kimai Plugin

Date: 2026-09-04

## Status

Accepted

## Context

Kimai plugins live under `var/plugins/<BundleName>/` and are loaded dynamically so Kimai's root Composer files do not need to be modified.  Kimai's plugin `composer.json` is used for plugin metadata and compatibility information, but Kimai does not automatically install third-party Composer requirements declared by an in-repository plugin.

The connector depends on `stripe/stripe-php`.  Requiring operators to modify Kimai's root `composer.json` would weaken upgrade isolation and violate the project's preference for normal removable plugin packaging.

A current community Kimai plugin with third-party dependencies uses a plugin-local `vendor/` tree and loads that plugin-local Composer autoloader from the bundle entrypoint before Symfony compiles the plugin services.  This pattern preserves Kimai's root dependency graph.

## Decision

The repository root SHALL represent the root of `KimaiStripeConnectorBundle`.

The installed plugin directory SHALL be:

```text
var/plugins/KimaiStripeConnectorBundle/
```

The primary namespace SHALL be:

```text
KimaiPlugin\KimaiStripeConnectorBundle\
```

The plugin SHALL ship its own `composer.json` and locked Composer dependency graph.

Release artifacts SHALL be self-contained and SHALL include production Composer dependencies under a plugin-local:

```text
vendor/
```

The bundle entrypoint SHALL register the plugin-local Composer autoloader when present before plugin services that depend on third-party packages are instantiated.

Installation SHALL NOT require modification of Kimai's root `composer.json`, `composer.lock`, `config/bundles.php`, or vendor tree.

A release artifact SHALL be installable conceptually as:

```text
extract KimaiStripeConnectorBundle-<version>.zip
-> place/rename directory as var/plugins/KimaiStripeConnectorBundle
-> run Kimai plugin/cache reload
-> plugin becomes available
```

The exact release/build target SHALL produce only files needed at runtime plus required project/plugin metadata.  Development-only dependencies and test artifacts SHALL NOT be included in the production plugin ZIP.

`composer.lock` SHALL remain committed to the source repository even though generated `vendor/` contents do not.

## Development Contract

Developers and CI MAY run Composer at the repository root.  Local `vendor/` is build output and SHALL be ignored by Git.

Unit tests, static analysis, documentation generation, and release packaging SHOULD use the same locked dependency graph.

The plugin SHALL identify itself to the Stripe PHP SDK using Stripe's plugin application-identification mechanism before sending API requests.

## Implementation Evidence

Phase 7 implements the release shape through an allowlist-based production packager rather than copying the repository and trying to exclude every development file.  The packaged root contains the maintained runtime source directories, bundle entrypoint, Composer metadata/lockfile, selected project/install metadata, and a plugin-local production `vendor/` generated with `composer install --no-dev`.

The ordinary build command is:

```text
make package VERSION=<version>
```

It creates:

```text
dist/KimaiStripeConnectorBundle-<version>.zip
dist/KimaiStripeConnectorBundle-<version>.zip.sha256
```

`make package-check` builds the same version twice, requires byte-for-byte identical ZIP files, verifies the SHA-256 checksum, inspects required/forbidden paths, validates packaged Composer metadata, and proves the packaged Stripe SDK autoloads.  Package permissions, timestamps, and ZIP entry order are normalized so archive metadata does not defeat reproducibility.

Disposable Kimai lifecycle E2E builds the same ZIP shape, extracts it, installs that extracted plugin under `var/plugins/KimaiStripeConnectorBundle/`, reloads Kimai, exercises the disabled reconciliation/cleanup commands, removes the plugin, and verifies the bundle no longer resolves.  The E2E path therefore validates the actual release package rather than a parallel staging approximation.

The semver workflow builds the self-contained package from the calculated release commit, verifies its checksum, and attaches both the ZIP and checksum to the GitHub release.  GitHub-generated source archives remain source archives and are not treated as installable ADR-012 artifacts.

## Consequences

- The plugin remains removable without dirtying Kimai's root dependency graph.
- Release artifacts are larger because Stripe's PHP SDK is bundled locally.
- Dependency vulnerabilities and updates remain the connector project's responsibility.
- The release process must distinguish development dependencies from runtime dependencies.
- Plugin-local Composer autoload behavior becomes an E2E installation test target.

## Considered Alternatives

### Add Stripe PHP to Kimai's root Composer dependencies

Rejected because it modifies the host application dependency graph and complicates Kimai upgrades/removal.

### Reimplement the required Stripe REST calls without the Stripe SDK

Rejected initially because the official SDK provides typed resource/service behavior, retries/idempotency support, API-version alignment, and a maintained compatibility surface.

### Download dependencies at plugin startup

Rejected because runtime installation would be network-dependent, unpredictable, and inappropriate for a financial integration.

## Related Decisions

- Related to: ADR-004
- Related to: ADR-006
- Related to: ADR-003
