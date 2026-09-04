# Operator Runbook

Date: 2026-09-04

## Purpose

This runbook describes routine operation, incident response, recovery, disablement,
and manual fallback for `kimai-stripe-connector`.

It is written for an operator who may need to recover billing safely without
understanding the connector internals.  The governing principle is conservative:
when payment or identity evidence is uncertain, preserve the evidence, make no
automatic financial guess, and use the manual billing fallback.

Accepted ADRs remain authoritative.  In particular:

- ADR-005 keeps Kimai private and uses outbound-only Stripe access;
- ADR-008 defines exact versioned Stripe correlation metadata;
- ADR-009 governs strong-evidence reconciliation;
- ADR-010 governs conservative orphan cleanup;
- ADR-011 governs retry/idempotency behavior; and
- ADR-013 defines operator result categories and process exit codes.

## Supported Operational Baseline

The current validated baseline is:

```text
Kimai:             2.65.0
PHP:               >=8.2 <8.6
Stripe PHP SDK:    ^21.3.1
Payment methods:   card only
Metadata:          version 1
Money:             integer minor units
Currency scope:    ordinary two-decimal currencies
```

The connector uses one least-privilege restricted Stripe key with the verified
permission shape documented in `stripe-restricted-key.md`.

Do not casually change the stable Kimai instance UUID.  Existing Stripe metadata
uses that UUID as part of the correlation namespace.  Changing it is a migration,
not ordinary secret rotation or routine configuration maintenance.

## Routine Commands

### Reconciliation

Inspect without changing Kimai:

```text
php bin/console kimai:stripe:reconcile --dry-run
```

Apply safe proven paid-state transitions:

```text
php bin/console kimai:stripe:reconcile
```

Reconciliation never accepts an invoice number or desired paid state from the
caller.  It discovers connector-managed Stripe evidence and changes Kimai only
when identity, amount, currency, and payment evidence are mutually consistent.

### Cleanup

Inspect orphan cleanup eligibility without changing Stripe:

```text
php bin/console kimai:stripe:cleanup --dry-run
```

Deactivate only proven stale unpaid orphan Payment Links:

```text
php bin/console kimai:stripe:cleanup
```

Cleanup never accepts an invoice number, Payment Link ID, or desired Stripe state
from the caller.  It can only deactivate a Payment Link after repeated Kimai and
Stripe revalidation proves the link is safe to deactivate.

### Recommended command order

For routine scheduled operation, reconciliation is the payment-correctness path
and should run independently of cleanup.  Cleanup can run less frequently.

A reasonable operator sequence after configuration changes or an incident is:

```text
reconcile --dry-run
-> inspect result
-> reconcile if safe
-> cleanup --dry-run
-> inspect result
-> cleanup if safe
```

Do not make cleanup availability a prerequisite for billing or reconciliation.
Accumulated Stripe clutter is preferable to an incorrect destructive cleanup.

## Operator Result Categories

Both commands use the same stable result vocabulary and process codes:

```text
SUCCESS                  0   safe requested operation completed
NO_CHANGE                0   operation completed and intentionally changed nothing
RETRYABLE                10  temporary external/infrastructure condition
CONFIGURATION_ERROR      20  configuration prevents safe operation
CONFLICT                 30  mutually incompatible evidence needs resolution
MANUAL_REVIEW_REQUIRED   40  financial/accounting judgment is required
INTERNAL_ERROR           50  unexpected invariant/programming failure
```

For a batch, the command may continue independent invoice groups when safe and
returns the highest mapped process code that occurred.

### Scheduler behavior

Treat the categories differently:

- `SUCCESS` / `NO_CHANGE`: ordinary successful completion.
- `RETRYABLE`: retry later with normal backoff.  Do not spin aggressively on
  network failure or Stripe rate limiting.
- `CONFIGURATION_ERROR`: stop automatic retries until configuration is repaired.
- `CONFLICT`: preserve all evidence and require investigation before changing the
  conflicting objects.
- `MANUAL_REVIEW_REQUIRED`: create an operator/accounting work item.  Repeating
  the command does not resolve a paid orphan, refund/dispute anomaly, or other
  condition requiring judgment.
- `INTERNAL_ERROR`: stop automated mutation, preserve logs/output, and investigate
  the connector or runtime before continuing.

Schedulers SHOULD avoid overlapping runs.  The connector is designed to converge
safely under ordinary retries, but it does not claim an internal distributed lock.

## Stripe Paid / Kimai Unpaid

This is the primary reconciliation incident.

### First response

Run:

```text
php bin/console kimai:stripe:reconcile --dry-run
```

Find the relevant invoice line and inspect its result category.

### If dry-run reports `SUCCESS` with `would_change=yes`

The connector has proven a safe paid transition.  Run:

```text
php bin/console kimai:stripe:reconcile
```

Then verify that Kimai shows the invoice paid.  A successful automatic transition
uses the proven Stripe Charge creation timestamp as the source payment time and
converts it to the invoice creation timezone before Kimai stores its date-only
payment field.

### If dry-run reports `RETRYABLE`

Do not mark the invoice paid based only on the failed connector run.  Restore
Stripe/Kimai/database/network availability and retry later.

If the business requires immediate handling during a prolonged outage, use the
manual fallback: independently verify the exact Stripe payment, then mark the
correct Kimai invoice paid manually according to accounting policy.

### If dry-run reports `CONFLICT`

Do not choose a candidate automatically.  Inspect:

- Kimai invoice number, amount, and currency;
- connector `kimai_instance` metadata;
- all competing Payment Links;
- associated Checkout Sessions, PaymentIntents, and Charges; and
- whether any competing object has actual payment activity.

Preserve all objects until the conflict is understood.  Do not edit Stripe
metadata merely to make the connector accept an object.

### If dry-run reports `MANUAL_REVIEW_REQUIRED`

Treat this as an accounting decision, not a retry problem.  Typical causes include
paid orphans, refund/dispute anomalies, or other payment evidence that automation
is intentionally forbidden to resolve.

Independently verify the obligation and payment in Stripe and Kimai.  If the
correct business action is to mark an existing Kimai invoice paid, do so manually
and retain enough evidence for later audit.

## Kimai Failure After Payment Link Creation

Stripe and Kimai do not share one transaction.  A Payment Link can therefore be
created even when Kimai later fails to complete invoice rendering or persistence.

### Immediate response

Do not immediately create another Payment Link.  Restore Kimai first, then retry
the normal invoice-generation path.  Payment Link creation/recovery is designed to
rediscover one exact compatible connector-managed link instead of blindly creating
a duplicate after an uncertain outcome.

After Kimai is healthy:

1. retry the invoice operation through the normal Kimai workflow;
2. verify that the invoice has the expected Stripe-hosted URL;
3. run reconciliation dry-run if payment may already have occurred; and
4. leave unmatched Stripe objects alone until their state is understood.

If no Kimai invoice ultimately exists, the Stripe object is an orphan.  Cleanup
may later deactivate it only after the configured grace period and only if Stripe
evidence is conclusively unpaid.  Age alone never authorizes deactivation.

## Conflicting or Multiple Payment Links

The connector intentionally stops when one logical invoice has incompatible or
multiple plausible managed Payment Links.

### Containment

If new invoice rendering could create more confusion, temporarily disable automatic
Payment Link generation while leaving reconciliation available:

```text
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED=false
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true
```

Apply the configuration through the normal deployment/restart mechanism.

### Investigation

For every competing link, compare:

```text
integration
integration_version
kimai_instance
invoice_number
invoice_amount
invoice_currency
```

Then inspect Stripe-native amount/currency and payment evidence.

Do not assume the newest link is correct.  Do not assume an unpaid link is safe to
deactivate if another payment attempt is open or evidence is incomplete.

Cleanup will not automatically resolve a multiple-link conflict.  Human resolution
must preserve any paid or ambiguous object and may deactivate an independently
proven unused/unpaid duplicate through Stripe only after the operator has verified
that doing so is safe.

Once the conflict is resolved, run reconciliation dry-run before re-enabling
automatic Payment Link generation.

## Stripe Credential Permission Failure

The connector accepts restricted `rk_test_` / `rk_live_` credential shapes and
rejects broad `sk_` secret keys.

The verified permission set is:

```text
Payment Links       Write
Checkout Sessions   Read
Payment Intents     Read
Charges             Read
Products            None
Prices              None
all other resources None
```

If Stripe returns an authorization/permission failure:

1. do not broaden the credential preemptively;
2. compare the key against `stripe-restricted-key.md`;
3. verify that the expected key is actually mounted/configured;
4. verify that direct and file-backed key forms are not both configured; and
5. repair the least-privilege key or deployment secret source.

A missing required permission is a configuration problem.  Granting unrelated
Stripe powers is not a valid repair.

## Restricted-Key Rotation

Treat rotation as a deployment change, not an application-data migration.

Recommended sequence:

1. create a replacement restricted key with the same verified least-privilege
   permissions;
2. place it in the deployment secret store without exposing it in source, chat,
   logs, PR comments, or committed `.env` files;
3. update the deployment to reference the replacement key;
4. ensure only one credential delivery form is configured;
5. recreate/restart the Kimai runtime as required by the deployment platform;
6. run reconciliation `--dry-run` and cleanup `--dry-run`;
7. confirm there are no permission/configuration errors; and
8. revoke the old key after the replacement is proven in the intended runtime.

File-backed secret delivery is preferred in containerized production.  Do not
change `KIMAI_STRIPE_CONNECTOR_INSTANCE_ID` during credential rotation.

When practical, rotate during a low invoice-generation period.  Dry-run commands
prove the required read path but do not themselves prove Payment Link write access;
the replacement key should therefore copy the already verified permission shape
exactly unless a later tested capability deliberately changes it.

## Paid Stripe Orphan

A paid orphan means Stripe proves a payment for connector-managed evidence, but no
matching Kimai invoice exists.

The connector deliberately returns `MANUAL_REVIEW_REQUIRED`.  Cleanup retains the
Payment Link and payment evidence.

Operator response:

1. preserve the Stripe Payment Link, Checkout Session, PaymentIntent, and Charge;
2. identify the intended business obligation from authoritative records;
3. determine why the Kimai invoice is missing;
4. restore/recreate missing Kimai business data only from trustworthy records, not
   by guessing from Stripe metadata; and
5. resolve the accounting state manually.

Do not delete or deactivate the paid evidence merely to reduce Stripe clutter.
Do not attach the payment to a different invoice solely because the amount looks
similar.  Do not modify metadata to force automatic reconciliation.

## Cleanup Uncertainty

Use cleanup dry-run before a live cleanup after configuration changes, incidents,
or long periods without cleanup:

```text
php bin/console kimai:stripe:cleanup --dry-run
```

A live cleanup is appropriate only for items the command itself classifies as safe
for automatic deactivation.

The current automatic policy requires positive terminal-unpaid evidence.  Examples
that are retained include:

- a matching Kimai invoice;
- a recent orphan inside the grace period;
- multiple managed links for one logical invoice;
- an open Checkout Session;
- a processing PaymentIntent;
- any Charge evidence not covered by the narrow safe-unpaid policy;
- paid/refunded/disputed evidence;
- unknown future Stripe statuses; and
- any Kimai/Stripe read failure.

When cleanup is uncertain, leaving the Payment Link in Stripe is the expected safe
failure mode.  Investigate manually rather than weakening the policy or reducing
the grace period to force deletion.

## Reconciliation Outage

A temporary Stripe, network, Kimai, or database outage should ordinarily surface as
`RETRYABLE` rather than creating guessed financial state.

### Short outage

- allow the scheduler to retry with reasonable backoff;
- avoid overlapping/manual duplicate runs while the scheduled job is active;
- keep invoice/payment evidence unchanged; and
- resume ordinary reconciliation after service restoration.

### Prolonged outage

Billing does not depend on connector availability.  Use the manual fallback for
urgent invoices/payments:

```text
verify payment directly in Stripe
-> identify exact Kimai invoice
-> mark Kimai invoice paid manually
```

When reconciliation returns, already-paid invoices are expected to converge as
idempotent no-change cases unless separate anomalous evidence requires review.

## Kimai Compatibility Failure After Upgrade

The validated Kimai baseline is 2.65.0.  A later Kimai version is not automatically
a supported compatibility claim merely because the plugin appears to load.

If an upgrade causes plugin loading, command, rendering, or persistence failure:

1. stop automatic reconciliation/cleanup until behavior is understood;
2. disable automatic Payment Link generation if invoice rendering is affected;
3. preserve the existing Stripe and Kimai data;
4. use manual billing/reconciliation as needed;
5. either roll Kimai back to the last validated deployment or disable/remove the
   plugin; and
6. reproduce the new Kimai version in the disposable compatibility harness before
   changing the supported baseline.

Do not patch Kimai core or its root Composer dependency graph to make the connector
work.  Compatibility fixes belong in the plugin with tests and an explicit
compatibility/documentation update.

## Disabling Connector Behavior

### Disable everything

Set:

```text
KIMAI_STRIPE_CONNECTOR_ENABLED=false
```

Then apply configuration through the normal deployment/restart process.

With the connector globally disabled, reconciliation and cleanup report
`NO_CHANGE` and do not access Stripe.

Disabling the connector does not remove existing Stripe Payment Links, erase
historical payment evidence, or rewrite already-generated invoice documents.

### Disable Payment Link creation only

Set:

```text
KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED=false
```

Reconciliation may remain enabled.

### Disable reconciliation and cleanup

Set:

```text
KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=false
```

Cleanup is reconciliation-side maintenance, so this also disables automatic
cleanup.  Payment Link generation may remain independently enabled.

## Removing the Plugin

The connector is a self-contained Kimai plugin under:

```text
var/plugins/KimaiStripeConnectorBundle/
```

A conservative removal sequence is:

1. set `KIMAI_STRIPE_CONNECTOR_ENABLED=false` and apply the deployment change;
2. verify manual billing is available;
3. stop or quiesce the Kimai runtime if required by the deployment platform;
4. remove only `var/plugins/KimaiStripeConnectorBundle/`;
5. run Kimai's plugin/cache reload in the application runtime:

```text
php bin/console kimai:reload --env=prod
```

6. restart/recreate Kimai as required by the deployment platform;
7. verify normal Kimai CLI/UI operation; and
8. verify that the `kimai:stripe:*` commands are no longer registered.

The plugin does not require Kimai root Composer changes, so removal should not
involve editing Kimai's root `composer.json`, `composer.lock`, or vendor tree.

Removing the plugin does not deactivate existing Stripe Payment Links.  Review
those separately according to business/accounting requirements.

## Return to Manual Billing

The connector is optional by design.  If it is disabled, removed, degraded, or
under investigation, continue billing manually:

```text
track time in Kimai
-> generate invoice in Kimai
-> create the correct Stripe Payment Link manually
-> place/include the Stripe URL in the customer-facing invoice workflow
-> send invoice
-> verify payment directly in Stripe
-> mark the exact Kimai invoice paid manually
```

Keep the same discipline used by the connector:

- verify invoice identity, amount, and currency;
- do not infer payment from a Payment Link or Checkout Session alone;
- retain evidence of the actual Stripe payment;
- handle refunds/disputes as accounting events rather than silently rewriting
  historical state; and
- preserve the stable Kimai instance UUID/configuration if the connector may be
  re-enabled later.

Manual fallback is a supported operating mode, not an emergency-only failure.

## Post-Incident Recovery Checklist

After any material incident, configuration repair, credential rotation, or
compatibility change:

1. confirm the configured Kimai instance UUID has not drifted;
2. confirm the restricted Stripe key source and least-privilege permissions;
3. run reconciliation `--dry-run`;
4. resolve `CONFLICT` / `MANUAL_REVIEW_REQUIRED` items deliberately;
5. run live reconciliation if the dry-run proves safe transitions;
6. run cleanup `--dry-run`;
7. run live cleanup only for connector-authorized safe candidates;
8. verify scheduler non-overlap and expected exit-code handling; and
9. return automatic Payment Link generation to service only after the payment
   state is understood.

## Evidence to Preserve During Investigation

Record safe identifiers and outcomes sufficient to reconstruct what happened:

```text
Kimai invoice number
Kimai instance UUID
invoice amount/currency
Stripe Payment Link ID
Checkout Session ID
PaymentIntent ID
Charge ID
operator result category
command exit code
timestamps of relevant operator actions
```

Do not record Stripe API keys or secret-file contents in tickets, logs, chat, or
runbook notes.

## Related Documentation

- [`configuration.md`](configuration.md)
- [`stripe-restricted-key.md`](stripe-restricted-key.md)
- [`threat-model.md`](threat-model.md)
- [`decisions.md`](decisions.md)
- [`testing/test-matrix.md`](testing/test-matrix.md)
- [`adr/ADR-009-reconcile-only-on-strong-stripe-payment-evidence.md`](adr/ADR-009-reconcile-only-on-strong-stripe-payment-evidence.md)
- [`adr/ADR-010-clean-up-only-proven-unpaid-orphan-payment-links.md`](adr/ADR-010-clean-up-only-proven-unpaid-orphan-payment-links.md)
- [`adr/ADR-011-design-for-idempotent-recovery-from-partial-failure.md`](adr/ADR-011-design-for-idempotent-recovery-from-partial-failure.md)
- [`adr/ADR-013-use-stable-operator-result-taxonomy.md`](adr/ADR-013-use-stable-operator-result-taxonomy.md)
