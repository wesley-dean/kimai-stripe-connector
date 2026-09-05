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
injection when Stripe E2E coverage is enabled; they are never stored in Compose or
committed test data.

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

This distinction is deliberate: lifecycle E2E validates the same artifact shape
intended for release rather than reconstructing a merely similar production-shaped
tree independently from the packaging code.

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

The ordinary non-credentialed harness proves normal plugin lifecycle plus safe
production-container construction of both scheduler-facing commands:

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
locked SDK, controlled HTTP tests, and the explicitly invoked sandbox flows.

## Hosted Stripe payment harness

`run-stripe-hosted.sh` is the Phase 7b executable harness for the canonical paid
business flow.  It is intentionally separate from `make e2e` because it requires a
real restricted Stripe test credential and one human-hosted Checkout action.

The harness:

```text
constructs fresh Kimai + MySQL
-> generates a fresh Kimai instance UUID for this disposable deployment
-> builds and installs the same self-contained release artifact
-> creates disposable customer/project/activity/timesheet data through Kimai's API
-> creates a real Kimai invoice through kimai:invoice:create
-> lets the production InvoicePreRenderEvent subscriber create the Payment Link
-> verifies exactly one active $12.34 USD card-only Payment Link
-> prints the Stripe-hosted test Checkout URL
-> waits for the operator to complete the test-card payment
-> proves exact Session / PaymentIntent / Charge evidence
-> proves Kimai is still unpaid before reconciliation
-> runs reconciliation in dry-run mode and requires would_change=yes
-> runs real reconciliation and requires changed=yes
-> verifies the exact Kimai invoice is paid with a payment date
-> reruns reconciliation and requires an idempotent NO_CHANGE
-> deactivates run-owned Stripe Payment Links
-> destroys all disposable Kimai/MySQL state
```

The Stripe evidence helper deliberately checks the same successful-payment
predicates used by production reconciliation: exact connector metadata, amount,
currency, object relationships, a complete/paid Checkout Session, succeeded and
fully received PaymentIntent, captured Charge, zero refund, and no dispute.

Every hosted run generates a new `KIMAI_STRIPE_CONNECTOR_INSTANCE_ID`.  That is
intentional: each run is a genuinely new disposable Kimai deployment, and its
unique namespace prevents old deactivated sandbox links from becoming ambiguous
same-invoice evidence in later runs.

The only direct database fixture operations are test-owned setup seams for a
bearer API token and invoice template.  Customer/project/activity/timesheet setup
uses Kimai's API, and invoice creation uses Kimai's own `kimai:invoice:create`
command so the production pre-render event path is exercised.

The harness refuses a live-mode Stripe credential.  Its EXIT/INT/TERM cleanup only
deactivates connector-managed Payment Links carrying the current run's unique
instance UUID; it never performs global sandbox cleanup.

## Manual Stripe Sandbox workflow

`.github/workflows/stripe-sandbox.yml` remains `workflow_dispatch` only and uses
the protected `stripe-sandbox` GitHub Actions environment.  The workflow first
runs the existing restricted-key contract probe and then starts the hosted-payment
harness.

The Stripe secret is injected only into steps that require it.  Checkout, PHP
setup, and dependency installation do not receive the credential.

During the hosted step, the Actions log displays an `ACTION REQUIRED` block with a
Stripe-hosted Payment Link and the public Stripe success-card test data.  The job
waits up to 15 minutes by default for that browser payment, then continues through
reconciliation and idempotence automatically.

The presence of this harness in the repository is not itself evidence that the
hosted flow has passed.  The stronger Phase 7b claim requires a successful real
manual sandbox workflow run.

## Running locally

Ordinary lifecycle requirements:

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

The hosted-payment harness additionally requires `curl`, `jq`, and the restricted
Stripe sandbox key in `STRIPE_SANDBOX_RESTRICTED_KEY`:

```text
STRIPE_SANDBOX_RESTRICTED_KEY=... bash tests/e2e/run-stripe-hosted.sh
```

Do not paste or commit the key merely to run this test.  The preferred shared
execution path is the protected manual GitHub Actions workflow described above.

See `doc/testing/e2e-stripe-sandbox.md` for the governing real-system strategy and
claim boundaries.
