# Disposable Kimai E2E Harness

This directory contains the executable Kimai runtime harness used to test the
connector against a real Kimai installation.

The harness deliberately follows Kimai's documented Docker Compose model closely:

- an official `kimai/kimai2` Apache image;
- a MySQL database;
- Kimai data and plugin volumes; and
- ordinary Kimai environment variables.

The compatibility baseline pins Kimai `2.65.0` rather than following the moving
`stable` tag so a test result identifies the Kimai version that actually ran.

## Ephemeral by construction

The environment is test-owned and disposable.

`run.sh` creates a unique Docker Compose project name, starts fresh named volumes,
builds the self-contained connector ZIP, extracts it into temporary local staging,
and installs that packaged connector into the project's plugin volume.  An
EXIT/INT/TERM trap always runs:

```text
docker compose down --volumes --remove-orphans
```

The cleanup destroys:

- the Kimai container;
- the MySQL container;
- the Compose network;
- the MySQL data volume;
- the Kimai data volume;
- the installed-plugin volume; and
- the temporary local package/extraction directory.

No test database or Kimai application state is intentionally reused between E2E
runs.

The fixed credentials in `compose.yaml` are non-production fixtures scoped to this
disposable environment.  The database URL itself is assembled by `run.sh` at
runtime instead of being stored as a literal connection URI in the repository.
Real Stripe sandbox credentials are supplied separately through CI/runtime secret
injection when Stripe E2E coverage is enabled; they are never stored in this
Compose file.

## Release-artifact installation

The developer checkout may contain PHPUnit, PHPStan, Symfony test stubs, and other
`require-dev` packages under the repository-local `vendor/` directory.  Those
packages are not part of the runtime plugin artifact and can conflict with classes
already supplied by Kimai if copied into `var/plugins/`.

The lifecycle harness therefore delegates packaging to `tools/package.sh`, which
constructs an allowlisted runtime tree and runs:

```text
composer install --no-dev --prefer-dist --optimize-autoloader
```

against the committed `composer.lock` inside that tree.  The packager creates:

```text
KimaiStripeConnectorBundle-e2e.zip
```

The harness extracts that ZIP, verifies it contains plugin-local
`vendor/autoload.php`, rejects repository-only `tests/` and `.github/` paths, and
copies the extracted `KimaiStripeConnectorBundle/` directory into Kimai.

This distinction is deliberate: lifecycle E2E now validates the same artifact
shape intended for release rather than reconstructing a merely similar
production-shaped tree independently from the packaging code.

The separate package contract under `tests/package/` additionally proves package
contents, SHA-256 generation, production dependency autoloading, and byte-for-byte
reproducibility across two builds of the same source/dependency graph.

## Kimai startup health

Kimai's normal image healthcheck remains the steady-state probe.  A pristine Kimai
instance can spend materially longer on first-run setup and database migrations
than the image's small default startup allowance permits, which can make a healthy
first boot look unhealthy in a disposable environment.

The E2E Compose file therefore keeps the upstream probe cadence and failure rules
but overrides the healthcheck `start_period` to 30 minutes.  Failed probes during
that startup window do not consume the steady-state retry budget, while a
successful probe can still mark Kimai healthy immediately.

If the lifecycle test fails, `run.sh` prints Compose status, Kimai health data, and
recent Kimai/database logs before the cleanup trap removes the environment.

## Current lifecycle test

The harness proves normal plugin lifecycle plus safe production-container
construction of both scheduler-facing commands:

```text
construct fresh Kimai + MySQL
-> wait for healthy Kimai
-> build self-contained plugin ZIP from composer.lock without tests/dev deps
-> extract the ZIP
-> copy packaged plugin into var/plugins/KimaiStripeConnectorBundle
-> kimai:reload
-> verify bundle class resolves
-> run kimai:stripe:reconcile --dry-run with connector disabled
-> require NO_CHANGE without Stripe credentials or Stripe access
-> run kimai:stripe:cleanup --dry-run with connector disabled
-> require NO_CHANGE without Stripe credentials or Stripe access
-> verify Kimai CLI is healthy
-> remove plugin directory
-> kimai:reload
-> verify bundle no longer resolves
-> destroy containers and every named volume
```

The disabled command invocations prove that Kimai 2.65 can discover and
instantiate the reconciliation and cleanup commands plus their real
service-container dependencies without performing financial or Stripe mutation.
This validates an important boundary: the connector remains a normal removable
Kimai plugin rather than a modification to Kimai core.

The lifecycle harness does not authorize an active cleanup operation against a
real Stripe account.  Stripe mutation behavior is covered separately through the
locked SDK with a scripted HTTP boundary and the existing manual sandbox contract
probe.

## Running locally

Requirements:

- Docker with the Compose plugin;
- Composer available on `PATH`;
- `zip`; and
- `unzip`.

Then run:

```text
make e2e
```

The harness builds and installs its own self-contained runtime artifact.  A prior
repository-root `composer install` is not required for E2E.

For package-only validation without starting Kimai, run:

```text
make package-check
```

## Future Stripe E2E

The same disposable Kimai environment can be extended with the high-value real
Stripe sandbox flow described in `doc/testing/e2e-stripe-sandbox.md`:

```text
real disposable Kimai invoice
-> connector-created Payment Link
-> Stripe-hosted sandbox card Checkout
-> real Checkout Session / PaymentIntent / Charge evidence
-> reconciliation command
-> exact Kimai paid-state transition
-> idempotent reconciliation rerun
```

That full hosted-payment path is intentionally not claimed by the current
lifecycle harness.  Stripe-side cleanup is separate from Docker cleanup because
Stripe is an external service.
