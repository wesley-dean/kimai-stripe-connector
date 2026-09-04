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
and installs the checked-out connector into that project's plugin volume.  An
EXIT/INT/TERM trap always runs:

```text
docker compose down --volumes --remove-orphans
```

The cleanup destroys:

- the Kimai container;
- the MySQL container;
- the Compose network;
- the MySQL data volume;
- the Kimai data volume; and
- the installed-plugin volume.

No test database or Kimai application state is intentionally reused between E2E
runs.

The fixed credentials in `compose.yaml` are non-production fixtures scoped to this
disposable environment.  Real Stripe sandbox credentials are supplied separately
through CI/runtime secret injection when Stripe E2E coverage is enabled; they are
never stored in this Compose file.

## Current lifecycle test

The initial harness proves the plugin lifecycle before financial behavior exists:

```text
construct fresh Kimai + MySQL
-> wait for healthy Kimai
-> copy plugin into var/plugins/KimaiStripeConnectorBundle
-> kimai:reload
-> verify bundle class resolves
-> verify Kimai CLI is healthy
-> remove plugin directory
-> kimai:reload
-> verify bundle no longer resolves
-> destroy containers and every named volume
```

This validates an important boundary: the connector is a normal removable Kimai
plugin rather than a modification to Kimai core.

## Running locally

Install plugin dependencies first:

```text
composer install
```

Then run:

```text
make e2e
```

Docker with the Compose plugin must be available.

## Future Stripe E2E

The same disposable Kimai environment will be extended with a small number of
real Stripe sandbox flows described in `doc/testing/e2e-stripe-sandbox.md`.

Those tests will create Stripe sandbox objects carrying a run-specific namespace,
exercise hosted Checkout, reconcile back into this disposable Kimai instance, and
clean up connector-managed sandbox objects when safe.  Stripe-side cleanup is
separate from Docker cleanup because Stripe is an external service.
