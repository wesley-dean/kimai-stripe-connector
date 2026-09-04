# ADR-004: Freeze the Initial Compatibility and Stripe Contract

Date: 2026-09-04

## Status

Accepted

## Context

Several pre-implementation choices become expensive after the connector creates real Stripe objects or is installed into multiple Kimai environments.  These include the Kimai/PHP compatibility baseline, Stripe SDK/API-version behavior, metadata namespace, money representation, initial payment-method scope, and credential model.

Current upstream verification on 2026-09-04 establishes:

- Kimai 2.65.0 is the current stable release;
- Kimai 2.65.0 requires PHP 8.2 or newer and officially supports PHP 8.2 through 8.5;
- Kimai plugins live under `var/plugins`, use the `KimaiPlugin` namespace, implement `App\Plugin\PluginInterface`, and declare their minimum Kimai version in `composer.json`;
- Stripe PHP 21.3.1 is the current stable Stripe PHP release verified for this decision;
- recent Stripe PHP SDKs send requests using the API version associated with the SDK release unless the application overrides it;
- Payment Link metadata is copied to Checkout Sessions created from the link; and
- `payment_intent_data.metadata` on a Payment Link is copied to each PaymentIntent created through that link.

## Decision

### Kimai compatibility

The first supported Kimai baseline SHALL be Kimai 2.65.0.

The plugin `composer.json` SHALL declare:

```json
"extra": {
  "kimai": {
    "require": 26500
  }
}
```

The project SHALL NOT claim compatibility with a later Kimai release merely because the minimum-version check allows installation.  A later Kimai version becomes supported after the applicable automated/integration compatibility checks pass.

### PHP compatibility

The initial supported PHP range SHALL be:

```text
>=8.2 <8.6
```

Unit/static-analysis CI SHOULD exercise PHP 8.2, 8.3, 8.4, and 8.5 where tooling permits.  The real Kimai E2E test uses the PHP runtime supplied by the selected official Kimai image rather than replacing Kimai's runtime.

### Stripe PHP SDK

The initial Stripe SDK dependency SHALL be compatible with:

```text
stripe/stripe-php ^21.3.1
```

The project SHALL commit `composer.lock` for reproducible development, testing, and packaged plugin dependency resolution.

Release/plugin packaging SHALL use the exact locked dependency graph.  Dependabot MAY propose compatible updates, but an SDK update is a behavioral dependency change and SHALL run the applicable test suite under ADR-003 before merge.

Pre-release Stripe SDKs SHALL NOT be used for the production connector unless a later decision explicitly adopts one.

### Stripe API version

The connector SHALL initially use the API version selected by the exact installed Stripe PHP SDK version.  It SHALL NOT independently override `Stripe-Version` merely to decouple the API version from the SDK.

This keeps the generated SDK object model and API response version aligned.

A Stripe SDK update therefore also changes the API-version baseline associated with that SDK and must be treated as a compatibility-relevant change with automated tests.

If a future credential type or Stripe integration mode requires an explicit `Stripe-Version` header, that requirement SHALL be documented and tested before use.

### Initial payment-method scope

The first release SHALL support card payments only.

Payment Links created by the connector SHALL explicitly restrict payment methods to `card` rather than inheriting whatever dynamic payment methods happen to be enabled on the Stripe account.

This decision is intentionally narrow.  Asynchronous/local payment methods SHALL require explicit lifecycle analysis and E2E tests before support is claimed.

### Payment Link shape

A connector-created Payment Link SHALL represent one fixed Kimai invoice payment:

- quantity exactly one;
- no customer-adjustable quantity;
- no pay-what-you-want behavior;
- no recurring price;
- no subscription semantics;
- no optional items;
- card payment method only;
- a one-completed-session restriction where supported by the chosen Stripe API; and
- an inline one-time `price_data` / `product_data` representation when supported by the tested API/SDK baseline.

The Product name MAY contain a human-readable invoice label but SHALL NOT be used as a correlation key.

### Metadata contract version 1

The version-1 correlation metadata SHALL contain exactly these required keys:

```text
integration=kimai-stripe-connector
integration_version=1
kimai_instance=<stable instance UUID>
invoice_number=<Kimai invoice number>
invoice_amount=<integer Stripe minor-unit amount>
invoice_currency=<lowercase ISO currency code>
```

The connector SHALL write this contract to:

1. the Payment Link object's metadata; and
2. `payment_intent_data.metadata` when creating/updating the Payment Link.

Stripe copies the Payment Link metadata to Checkout Sessions created through the link.  Stripe applies the nested PaymentIntent metadata to resulting PaymentIntents.  Reconciliation can therefore compare the same identity contract across the link, session, and intent without creating a connector-owned ledger.

Version 1 SHALL NOT include Kimai customer names, email addresses, or other unnecessary personally identifying data.

Unknown `integration_version` values SHALL NOT be interpreted optimistically.  Automatic reconciliation SHALL fail closed until that version is understood.

### Stable instance identity

`kimai_instance` SHALL be a stable UUID supplied through the connector configuration contract.

It SHALL survive container recreation, plugin updates, application restarts, and scheduler changes.  Changing it after Stripe objects have been created is a metadata-namespace migration and SHALL require deliberate operator action and migration guidance.

### Money policy

The connector SHALL represent Stripe amounts internally as integers in Stripe minor units.

Binary floating-point values SHALL NOT be authoritative for conversion or comparison.

The first implementation SHALL support ordinary two-decimal charge currencies only.  Currency codes SHALL be normalized to lowercase before Stripe use and comparison.

Conversion from Kimai's invoice amount SHALL use decimal/string semantics and SHALL succeed only when the amount can be represented exactly as an integer number of hundredths of the invoice currency.

The initial implementation SHALL reject rather than guess for:

- Stripe zero-decimal currencies;
- currencies with Stripe-specific special representation rules;
- amounts with more precision than the supported currency contract; and
- malformed or unsupported currency codes.

Support for zero-decimal or special-case currencies MAY be added later with explicit tests and documentation under ADR-003.  Such support does not require a new architecture ADR if it preserves the integer-minor-unit model and existing fail-closed semantics.

### Stripe credential model

The first implementation SHALL use one Stripe restricted API key for the connector unless sandbox testing demonstrates that the required operations cannot be represented safely with one restricted key.

The key SHALL be scoped to the minimum capabilities needed to:

- create, retrieve/list, and deactivate connector-managed Payment Links;
- create the inline one-time price/product representation required by Payment Link creation;
- retrieve/list Checkout Sessions related to connector-managed links;
- retrieve PaymentIntents and Charges needed for reconciliation; and
- perform no unrelated billing, payout, subscription, customer-management, or account-administration operations.

The exact Stripe dashboard permission labels SHALL be documented after they are verified with a real restricted sandbox key.  The connector SHALL fail clearly when the supplied key lacks a required permission.

The configuration and threat-model requirements for direct versus file-backed secret injection remain in force.

## Compatibility Test Policy

Compatibility claims SHALL be based on executable evidence.

At minimum:

- unit/static-analysis jobs exercise the supported PHP range where practical;
- a disposable Kimai 2.65.0 E2E environment proves plugin load/install/disable/remove behavior;
- Stripe sandbox tests use the locked Stripe PHP SDK dependency; and
- dependency upgrades rerun the behavior that depends on the changed dependency.

## Considered Alternatives

### Follow the Stripe account default API version

Rejected because connector behavior could change when the account default changes independently of the deployed plugin.

### Explicitly pin a separate Stripe API version in configuration

Deferred because modern Stripe PHP releases already align requests with an SDK-associated API version.  A second independently managed version adds another compatibility axis without a demonstrated need.

### Enable all Stripe account payment methods

Rejected for v1 because asynchronous payment methods can have materially different completion/finality semantics.

### Support every Stripe currency immediately

Rejected because currency minor-unit rules include zero-decimal and special cases.  The connector should reject unsupported money semantics rather than silently charge the wrong amount.

### Use multiple Stripe credentials from the start

Deferred because separate keys increase deployment and rotation complexity.  One least-privilege restricted key is the simpler initial model; evidence may justify splitting privileges later.

## Consequences

The first release has an intentionally narrow compatibility and payment surface.

Some legitimate Kimai/Stripe invoices will require manual Payment Link creation until additional currency/payment-method support is implemented.

Dependency updates are visible compatibility events rather than invisible drift.

The metadata schema is stable enough to support stateless recovery and reconciliation across future connector versions.

## Related Decisions

- Related to: ADR-001
- Related to: ADR-003
- Related to: `doc/configuration.md`
- Related to: `doc/threat-model.md`
