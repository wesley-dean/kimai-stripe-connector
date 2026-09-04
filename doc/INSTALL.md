# Installation Guide

Date: 2026-09-04

## Purpose

This guide describes how to install, configure, verify, upgrade, disable, and
remove `kimai-stripe-connector` without modifying Kimai's root Composer dependency
graph.

The connector is a self-contained Kimai plugin.  Its installed directory is:

```text
var/plugins/KimaiStripeConnectorBundle/
```

The plugin carries its own production Composer dependencies under its local
`vendor/` directory.  Installation must not modify Kimai's root `composer.json`,
`composer.lock`, `config/bundles.php`, or root `vendor/` tree.

For routine operation and incident response after installation, see
[`operator-runbook.md`](operator-runbook.md).  For the complete configuration
contract, see [`configuration.md`](configuration.md).

## Supported Baseline

The current validated compatibility baseline is:

```text
Kimai:             2.65.0
PHP:               >=8.2 <8.6
Stripe PHP SDK:    ^21.3.1
PHP extensions:    curl, json, mbstring
Payment methods:   card only
Metadata version:  1
```

Compatibility claims follow executable evidence.  A newer Kimai, PHP, or Stripe
SDK version should not be assumed compatible merely because installation appears
to succeed.

## Before Installation

You need:

- a supported Kimai installation;
- filesystem access to Kimai's `var/plugins/` directory;
- permission to run Kimai's console command;
- a stable UUID for this Kimai instance;
- outbound HTTPS access from Kimai to Stripe when Stripe-backed capabilities are
  enabled; and
- a least-privilege Stripe restricted key when Payment Link generation or
  reconciliation is enabled.

Kimai does not need public Internet ingress.  The connector does not require a
Stripe webhook endpoint.

### Stable instance UUID

Choose one UUID for the Kimai installation and preserve it across container
replacement, restarts, upgrades, node rescheduling, and scheduler changes.

Configure it as:

```text
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=<stable-uuid>
```

Do not rotate this value as if it were a secret.  Existing Stripe objects use the
UUID as part of their correlation namespace, so changing it after use is a data
migration decision.

### Stripe restricted key

The verified least-privilege permission set is:

| Stripe resource     | Permission |
|---------------------|------------|
| Payment Links       | Write      |
| Checkout Sessions   | Read       |
| Payment Intents     | Read       |
| Charges             | Read       |
| Products            | None       |
| Prices              | None       |
| All other resources | None       |

The connector accepts restricted key forms beginning with `rk_test_` or
`rk_live_`.  Broad `sk_` secret keys are rejected.

For production/container deployments, prefer a mounted secret file and configure:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE=/path/to/mounted/secret
```

The direct form is also supported when the surrounding runtime can protect it:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=<restricted-key>
```

Do not configure both forms at the same time.  The connector intentionally treats
that as a configuration error rather than guessing which credential should win.

Never place a real Stripe key in source control, documentation, a committed
`.env` file, an image layer, or a command-line argument likely to be captured in
logs or process listings.

## Choose an Installation Source

There are two valid installation paths.

### Preferred: verified self-contained release artifact

A release artifact intended for installation must contain the plugin runtime files
and its production-only local `vendor/` tree.

Do not assume GitHub's automatically generated "Source code" ZIP or tarball is an
installable plugin package.  Source archives do not normally contain generated
Composer dependencies.

Phase 7 will verify the repository's release automation against the self-contained
artifact contract in ADR-012.  Until that verification is complete, use the
source-build procedure below unless you have independently verified that a given
release archive contains the required production dependency shape.

When using a verified release artifact:

1. Extract the artifact outside the live Kimai plugin directory.
2. Confirm the extracted plugin root contains `composer.json`, `composer.lock`,
   the bundle source, and `vendor/autoload.php`.
3. Place or rename that root directory as:

   ```text
   <kimai-root>/var/plugins/KimaiStripeConnectorBundle/
   ```

4. Continue with [Configure the connector](#configure-the-connector).

### Current supported path: build a production-shaped plugin from source

Build from a reviewed tag or commit, not from an arbitrary moving branch.

The following reproduces the production staging model exercised by the disposable
Kimai E2E harness:

```bash
git clone https://github.com/wesley-dean/kimai-stripe-connector.git
cd kimai-stripe-connector
git checkout <tag-or-reviewed-commit>

stage="$(mktemp -d)"

tar \
  --exclude='./.git' \
  --exclude='./vendor' \
  --exclude='./tests' \
  --exclude='./build' \
  --exclude='./coverage' \
  --exclude='./dist' \
  --exclude='./doc/reference' \
  -cf - . | tar -C "${stage}" -xf -

composer install \
  --working-dir="${stage}" \
  --no-dev \
  --no-interaction \
  --prefer-dist \
  --optimize-autoloader
```

Verify the staged tree contains:

```text
vendor/autoload.php
```

and does not contain:

```text
tests/
```

Then copy the contents of the staging directory into a directory named exactly:

```text
<kimai-root>/var/plugins/KimaiStripeConnectorBundle/
```

Do not copy a repository development `vendor/` directory into Kimai.  A developer
checkout may contain PHPUnit, PHPStan, Symfony development packages, and other
`require-dev` dependencies that are intentionally absent from the production
plugin.

Do not run Composer against Kimai's root project to install the connector.

## Configure the Connector

All connector-owned environment variables use the namespace:

```text
KIMAI_STRIPE_CONNECTOR_*
```

The connector is inert by default because all enablement switches default to
`false`.

A typical fully enabled deployment uses:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED=true
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED=true
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true
KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=<stable-uuid>
KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS=7
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE=/path/to/mounted/secret
```

If the runtime protects direct environment secrets appropriately, the final line
may instead be:

```text
KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=<restricted-key>
```

Use only one credential form.

### Capability switches

`KIMAI_STRIPE_CONNECTOR_ENABLED` is the global switch.

`KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED` enables automatic Payment Link
creation/recovery during supported Kimai invoice rendering.

`KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED` enables the externally scheduled
Stripe-to-Kimai reconciliation capability.  Cleanup is an operator-invoked
maintenance action on this reconciliation side and does not have a third
independent enablement switch.

`KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS` defaults to 7 and accepts a positive
integer from 1 through 3650.  Age alone never makes a Payment Link safe to remove;
the cleanup planner must also prove the orphan is unpaid and unambiguous.

For exact parsing rules and container-secret examples, read
[`configuration.md`](configuration.md).

## Load the Plugin

After placing the plugin directory and supplying deployment configuration, run
Kimai's reload command from the Kimai application root:

```bash
php bin/console kimai:reload --env=prod
```

In a containerized deployment, run the equivalent command inside the Kimai
container using the deployment's normal container-management tool.

The plugin directory name must remain exactly:

```text
KimaiStripeConnectorBundle
```

The connector's primary namespace is:

```text
KimaiPlugin\KimaiStripeConnectorBundle\
```

## Verify the Installation

First confirm Kimai can resolve the connector commands:

```bash
php bin/console list kimai:stripe --env=prod
```

You should see:

```text
kimai:stripe:reconcile
kimai:stripe:cleanup
```

### Safe initial command checks

Before allowing scheduled mutation, inspect reconciliation behavior:

```bash
php bin/console kimai:stripe:reconcile --dry-run --env=prod
```

Inspect cleanup separately:

```bash
php bin/console kimai:stripe:cleanup --dry-run --env=prod
```

A disabled capability returns `NO_CHANGE` and exit code 0.  An enabled capability
with invalid configuration should fail visibly rather than silently guessing.

The command result categories and process exit codes are:

| Result category          | Exit code |
|--------------------------|----------:|
| `SUCCESS` / `NO_CHANGE`  |         0 |
| `RETRYABLE`              |        10 |
| `CONFIGURATION_ERROR`    |        20 |
| `CONFLICT`               |        30 |
| `MANUAL_REVIEW_REQUIRED` |        40 |
| `INTERNAL_ERROR`         |        50 |

Do not treat `CONFLICT` or `MANUAL_REVIEW_REQUIRED` as conditions that should be
retried indefinitely.  Follow [`operator-runbook.md`](operator-runbook.md) for
operator handling.

## Recommended First Enablement

For a new deployment, prefer a staged rollout:

1. Install the production-shaped plugin while connector capabilities are disabled.
2. Run `kimai:reload` and confirm the commands resolve.
3. Configure the stable instance UUID and restricted Stripe credential.
4. Enable only the capabilities you intend to use.
5. Recreate/restart the Kimai runtime if your deployment requires that for new
   environment values.
6. Run reconciliation and cleanup with `--dry-run`.
7. Confirm logs contain no credential material and no unexpected configuration or
   permission errors.
8. Only then schedule non-dry-run reconciliation or cleanup.

Do not use live Stripe credentials or real money merely to prove installation.
Ordinary installation verification can be performed without creating a charge.

## Scheduling Reconciliation and Cleanup

The connector does not require a continuously running worker or inbound webhook.
Use the deployment's existing scheduler, such as cron, Jenkins, or a systemd
timer, to invoke the commands.

Reconciliation:

```bash
php bin/console kimai:stripe:reconcile --env=prod
```

Cleanup:

```bash
php bin/console kimai:stripe:cleanup --env=prod
```

Avoid overlapping runs of the same maintenance command.  The connector is
idempotent and revalidates aggressively, but it does not claim a distributed lock
or a transaction spanning Kimai and Stripe.

Use the exit-code handling documented in
[`operator-runbook.md`](operator-runbook.md) rather than treating every non-zero
result as a generic retry.

## Upgrading

Treat a connector upgrade as a plugin replacement, not a modification to Kimai's
root dependencies.

Recommended sequence:

1. Stop or pause scheduled reconciliation and cleanup.
2. Preserve the current deployment configuration, mounted Stripe secret, and
   stable `KIMAI_STRIPE_CONNECTOR_INSTANCE_ID`.
3. Build or obtain the new production-shaped plugin in a separate directory.
4. Verify the new tree contains its own `vendor/autoload.php` and production
   dependencies.
5. Replace the entire `KimaiStripeConnectorBundle` directory rather than overlaying
   files onto an older plugin tree.
6. Run:

   ```bash
   php bin/console kimai:reload --env=prod
   ```

7. Run reconciliation and cleanup with `--dry-run`.
8. Resume scheduled commands only after the dry runs and logs are clean.

Do not change the stable instance UUID as part of an ordinary upgrade.

Before upgrading Kimai itself beyond the project's validated compatibility
baseline, follow the compatibility guidance in the operator runbook and verify the
connector against the target Kimai version in a disposable environment first.

## Disabling Without Removing

To stop connector automation while leaving the plugin installed, set:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED=false
```

Then recreate or restart the Kimai runtime as required by the deployment so the
new environment reaches new PHP processes.

You may also disable Payment Link generation and reconciliation independently:

```text
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED=false
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=false
```

With the connector disabled, ordinary Kimai billing remains available and manual
Stripe Payment Links/manual paid-state reconciliation remain supported fallbacks.

Disabling the connector does not delete existing Stripe Payment Links and does not
rewrite Kimai invoice history.

## Removing the Plugin

Before removal:

1. Stop scheduled reconciliation and cleanup.
2. Disable connector behavior and confirm billing can continue manually.
3. Preserve any operational evidence needed for unresolved payments or accounting
   review.

Remove:

```text
<kimai-root>/var/plugins/KimaiStripeConnectorBundle/
```

Then run:

```bash
php bin/console kimai:reload --env=prod
```

Removal does not delete Stripe Payment Links, refund or reverse payments, or
change existing Kimai paid-state history.  Resolve any remaining accounting work
through Stripe and Kimai directly.

Because the connector never modifies Kimai's root Composer graph, removal should
not require editing Kimai's root Composer files or vendor tree.

## Manual Billing Fallback

Installation, upgrade, or connector failure must never block billing.  The
supported fallback remains:

```text
track time in Kimai
-> generate invoice
-> create Stripe Payment Link manually
-> include the URL with the invoice
-> send the invoice
-> verify payment in Stripe
-> mark the invoice paid manually in Kimai
```

Use Stripe payment evidence rather than Payment Link or Checkout existence alone
when deciding that an invoice was actually paid.

## Troubleshooting

### Kimai does not see the plugin or commands

Check:

- the directory is exactly `var/plugins/KimaiStripeConnectorBundle/`;
- the plugin root contains the connector source and `composer.json`;
- `vendor/autoload.php` exists inside the plugin directory;
- filesystem ownership/permissions allow the Kimai process to read the plugin;
- `php bin/console kimai:reload --env=prod` completed successfully; and
- the installed build is compatible with the running PHP and Kimai versions.

Do not solve a plugin-loading problem by adding the connector's dependencies to
Kimai's root Composer project.

### `CONFIGURATION_ERROR`

Read the command message and check the namespaced environment values.  Common
causes include:

- malformed boolean values;
- missing or invalid stable instance UUID;
- both direct and file-backed credential forms configured;
- missing, unreadable, or blank secret file;
- use of a broad `sk_` Stripe key; or
- an invalid cleanup grace period.

### Stripe permission failure

Compare the restricted key against the permission table in this guide and
[`stripe-restricted-key.md`](stripe-restricted-key.md).  Do not broaden unrelated
Stripe permissions as a first response.

### Reconciliation or cleanup reports conflict/manual review

Preserve the evidence and follow the corresponding procedure in
[`operator-runbook.md`](operator-runbook.md).  Do not edit Stripe metadata, delete
paid evidence, or force Kimai financial state merely to make the automated command
return success.

## Related Documentation

- [`README.md`](../README.md) — project orientation
- [`configuration.md`](configuration.md) — authoritative configuration contract
- [`operator-runbook.md`](operator-runbook.md) — routine operation and incident
  response
- [`stripe-restricted-key.md`](stripe-restricted-key.md) — Stripe permission and
  secret boundary
- [`adr/ADR-012-package-as-self-contained-kimai-plugin.md`](adr/ADR-012-package-as-self-contained-kimai-plugin.md)
  — self-contained plugin packaging decision
- [`testing/test-matrix.md`](testing/test-matrix.md) — executable evidence and test
  responsibilities
