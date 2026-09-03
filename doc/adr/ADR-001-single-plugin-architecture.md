# ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation

Date: 2026-09-03

## Status

Proposed

## Intent and Documentation Posture

This ADR records the initial architectural direction for the Kimai Stripe Connector.  It is intentionally expansive.  The project is at a stage where preserving the reasoning, constraints, rejected alternatives, failure modes, assumptions, and unresolved questions is more valuable than producing a compact decision summary.  The document can be distilled later after implementation and operational experience establish which details remain important.

The purpose of documenting the decision before implementation is not to justify complexity.  It is to prevent accidental complexity and scope drift.  A narrow connector can gradually become a billing platform if each individually reasonable feature is added without retaining the original boundary.  This ADR therefore records both what the connector is intended to do and what it is explicitly not intended to become.

The current decision is **Proposed**, rather than Accepted.  The architecture has been examined through Kimai source review, Stripe documentation review, conversational design work, and synthetic/adversarial modeling.  A packaged Kimai plugin has not yet been installed and exercised end-to-end against a real Kimai runtime and Stripe test-mode account.  Acceptance should follow evidence from an actual plugin installation and representative Stripe test-mode transactions.

This ADR deliberately distinguishes among:

- facts verified from Kimai or Stripe documentation/source;
- architectural decisions made by this project;
- inferences made from those facts;
- results from pre-implementation synthetic modeling; and
- open questions that require implementation or live integration testing.

That distinction is important because this connector affects invoice state and payment records.  Confidence should follow evidence rather than convenience.

## Context

### Origin of the project

The project emerged while evaluating alternatives to Harvest for a small billing and time-tracking workflow.  Kimai was attractive because it already provides mature time tracking and invoice generation while supporting self-hosted deployment.  Stripe was already the preferred payment processor.

The simplest workable operating model requires no software integration at all:

```text
track time in Kimai
-> generate a Kimai invoice
-> open Stripe manually
-> create a Stripe Payment Link
-> copy the Stripe URL
-> place the URL on or with the Kimai invoice
-> send the invoice
-> customer pays through Stripe
-> mark the Kimai invoice paid manually
```

That manual workflow remains important to this decision.  It establishes that the connector is an optimization rather than a prerequisite for billing.  If the connector is removed, broken, incompatible, or intentionally disabled, billing can continue.

The connector exists to remove two clerical gaps:

1. creating and inserting the Stripe-hosted payment URL; and
2. reflecting a confirmed Stripe payment as paid in Kimai.

The intended result is:

```text
Kimai time entries
      |
      v
Kimai invoice generation
      |
      | plugin creates or recovers payment link
      v
invoice containing Stripe-hosted payment URL
      |
      v
customer pays Stripe
      |
      | asynchronous reconciliation
      v
Kimai invoice marked paid
```

### Why this is a connector rather than a billing system

Kimai and Stripe already own the difficult domains.

Kimai owns time tracking and invoice records.  Stripe owns payment collection and the actual movement of money.  The project should connect those systems without creating a third financial authority.

The connector should therefore answer narrow questions:

- What Stripe Payment Link corresponds to this Kimai invoice?
- What URL should be rendered on the invoice?
- Has Stripe provided sufficiently strong evidence that this exact Kimai invoice has been paid?
- Is a Stripe Payment Link an abandoned connector artifact that can be safely deactivated?

It should not answer broad accounting questions that belong elsewhere.

### Authority boundaries

The project SHALL preserve the following authority boundaries.

Kimai is authoritative for:

- time entries;
- customer/project/activity data used to construct the invoice;
- invoice number and identity;
- invoice amount as represented by Kimai;
- invoice currency as represented by Kimai;
- invoice document generation;
- Kimai invoice status;
- Kimai payment date after reconciliation or manual entry.

Stripe is authoritative for:

- Payment Links;
- Checkout Sessions;
- PaymentIntents;
- Charges;
- actual payment processing;
- refund state;
- dispute state;
- payment-method processing state;
- whether money was actually received through Stripe.

The connector is authoritative only for:

- its own metadata namespace and correlation rules;
- the rules by which a Kimai invoice maps to Stripe objects;
- the decision procedure for whether Stripe evidence is sufficient to update Kimai;
- connector-specific operational commands such as reconciliation and orphan cleanup.

The connector SHALL NOT create a parallel payment ledger.

### Operational constraints

The project is shaped by several strong operational preferences and constraints:

- Kimai should remain private and internal-only.
- Kimai should not require public Internet ingress for payment processing.
- Stripe should remain the only public payment surface required by the workflow.
- Stripe webhooks should not be required.
- Outbound polling is acceptable.
- Low-frequency cron or Jenkins execution is acceptable.
- Manual intervention for genuinely exceptional accounting conditions is acceptable.
- Kimai core source changes, forks, and vendor patches are unacceptable.
- Supported Kimai plugin and extension mechanisms are acceptable.
- The plugin must remain removable.
- A plugin failure must not prevent customers from paying already-issued Stripe Payment Links.
- A reconciliation failure must not prevent new invoices from being generated.
- Ambiguous payment state must result in no automatic accounting change.
- False negatives are preferable to false positives: a genuinely paid invoice temporarily remaining unpaid in Kimai is less harmful than marking an unpaid invoice paid.

### Two responsibilities exist even if there is one package

The proposed single package contains two responsibilities with different timing and risk profiles.

**Payment Link generation is workflow automation.**  It occurs while a Kimai invoice is being created.  Its job is to obtain the correct Stripe-hosted payment URL and expose it to the invoice renderer.

**Payment reconciliation is accounting-related automation.**  It occurs later and asynchronously.  Its job is to decide whether Stripe has provided sufficient evidence to mark an existing Kimai invoice paid.

These responsibilities should remain internally distinct even when distributed together.

### Verified Kimai 2.65.0 extension points

The initial design review examined Kimai 2.65.0 source rather than assuming that an abstract plugin mechanism would be sufficient.

Relevant observations include:

1. `InvoiceService::createInvoice()` dispatches `InvoicePreRenderEvent` before invoice rendering.
2. The duplicate invoice-number check occurs after the pre-render event and before rendering.
3. The invoice document is rendered before the `Invoice` entity is constructed and saved.
4. `InvoicePostRenderEvent` occurs after rendering and before the generated invoice file is saved.
5. The invoice entity is saved before `InvoiceCreatedEvent` is dispatched.
6. `InvoiceCreatedEvent` therefore occurs too late to place a newly generated Payment Link into the document that was already rendered.
7. `InvoiceModel` exposes `isPreview()`, allowing a plugin to avoid creating Stripe objects for invoice previews.
8. `InvoiceModel` supports model options.
9. Kimai provides the `InvoiceModelHydrator` extension point.
10. `InvoiceModel::toArray()` collects data from registered model hydrators.
11. Kimai's Twig invoice renderer exposes the result of `InvoiceModel::toArray()` as the `invoice` rendering variable.
12. Kimai invoices support metadata fields.
13. Kimai invoices expose `new`, `pending`, `paid`, and `canceled` statuses and a payment date.
14. Kimai's own invoice behavior uses normal invoice entity/service operations to update paid status and payment date.

These observations support a design in which an event subscriber obtains a Stripe Payment Link during the pre-render phase and a model hydrator exposes that URL to the invoice template.

Conceptually:

```text
InvoicePreRenderEvent
        |
        | if preview -> no Stripe mutation
        |
        v
PaymentLinkService
        |
        +-- find exact existing link
        |        |
        |        +-- exact match -> reuse
        |        +-- conflict -> stop
        |
        +-- no match -> create link
        |
        v
place payment-link information on InvoiceModel
        |
        v
InvoiceModelHydrator
        |
        v
invoice.stripe.* available to Twig
        |
        v
Kimai renders invoice
```

The implementation details may change, but the design SHOULD prefer these supported seams over decorators or changes to Kimai core.

### Verified Stripe capabilities relevant to the design

Current Stripe documentation confirms several capabilities used by the architecture:

- Payment Links provide a durable Stripe-hosted URL.
- Payment Links support metadata.
- Payment Link metadata is copied to Checkout Sessions created by that Payment Link.
- Checkout Sessions can be listed with a `payment_link` filter.
- Payment Links can be deactivated by setting `active=false`.
- Payment Links can be limited to a specified number of completed Checkout Sessions, including a limit of one.
- Current Stripe Payment Links support ad-hoc `price_data` for generating a Price inline with Payment Link creation when using a sufficiently recent Stripe API version.

The last point has a versioning implication.  Ad-hoc `price_data` support for Payment Links was introduced in Stripe's 2025-07-30 Basil API family.  The connector SHALL NOT assume that every Stripe account or SDK/API-version combination exposes this behavior without verification.  API-version compatibility must be explicit and tested.

### A subtle Stripe lifecycle distinction

Stripe's single-use Payment Link restriction is based on completed Checkout Sessions.  Stripe also documents that a Checkout Session can be `complete` while payment processing is still in progress.

Therefore:

```text
Checkout Session complete
```

is not, by itself, equivalent to:

```text
invoice safely proven paid
```

The one-completed-session limit is useful as a customer-facing and duplicate-payment safety control.  It SHALL NOT be used as reconciliation evidence by itself.

This also raises an unresolved question for asynchronous payment methods.  If a payment method can complete Checkout while the underlying payment remains pending, a one-session Payment Link may become unavailable for another attempt before final success or failure is known.  The first release SHOULD either:

- restrict supported payment methods to a deliberately tested set with lifecycle behavior compatible with the connector; or
- explicitly model, test, and document asynchronous payment behavior before enabling it.

The project SHALL NOT infer that all payment methods behave like cards.

### Pre-implementation synthetic modeling

Before this repository contained implementation code, the proposed architecture was exercised through conversational mock modeling.  These tests are useful design evidence but are **not** repository CI evidence and SHALL be recreated as executable project tests before the corresponding behavior is considered implemented.

The modeling reported 103 passing cases across three groups:

- 89 behavioral/adversarial cases;
- 5 HTTP/network/concurrency cases; and
- 9 stateless correlation/recovery cases.

The modeled cases included, among other things:

- invoice preview suppression;
- exact money conversion into minor currency units;
- duplicate invoice preflight behavior;
- Stripe API errors;
- malformed Stripe responses;
- timeout before Stripe commit;
- timeout after Stripe commit;
- retry using the same idempotency key;
- reuse of an existing exact Payment Link;
- refusal to reuse an amount-mismatched Payment Link;
- refusal to reuse a currency-mismatched Payment Link;
- namespace separation between multiple Kimai instances;
- 20 concurrent creation attempts converging on one logical Payment Link;
- delayed or unpaid Checkout Sessions;
- partial payment conditions;
- wrong currency;
- wrong invoice metadata;
- wrong Kimai instance metadata;
- unsuccessful PaymentIntents;
- uncaptured Charges;
- refunds;
- partial refunds;
- disputes;
- ambiguous multiple matches;
- Kimai save failure after Stripe success;
- recovery from an orphaned Payment Link;
- discovery of an exact matching link among 10,000 unrelated Payment Links;
- large numbers of unrelated or plausible-but-wrong payment records;
- shell metacharacters and other hostile-looking text values; and
- failure during reconciliation persistence.

A separate modeled conversion exercise checked a large number of cent values to detect floating-point conversion errors.  The implementation SHALL nevertheless use an explicit money representation and SHALL NOT rely on binary floating-point arithmetic for Stripe amounts merely because synthetic conversion tests passed.

The architectural value of the mock exercise was not only that the proposed happy path worked.  It exposed two important changes to the design:

1. Stripe-side metadata can make link correlation largely stateless from Kimai's perspective.
2. An orphaned Payment Link is recoverable and therefore closer to garbage-collection debris than a transaction-integrity failure, provided paid orphans are never automatically discarded.

## Decision Drivers

The following factors most strongly influence this decision:

- Keep the installation and compatibility surface small.
- Keep the implementation small enough to audit.
- Preserve a permanently valid manual fallback.
- Preserve internal separation between synchronous link creation and asynchronous reconciliation.
- Allow either capability to be disabled independently.
- Avoid duplicate Stripe configuration across separate packages unless isolation later proves worth the cost.
- Avoid duplicate implementations of invoice identity, money normalization, metadata parsing, and Stripe object validation.
- Avoid introducing a third shared package solely to support two small plugins.
- Avoid Kimai core modifications.
- Avoid public ingress into Kimai.
- Avoid making webhook delivery part of correctness.
- Prefer outbound polling and eventual consistency.
- Prefer false negatives to false positives in payment reconciliation.
- Treat refunds and disputes as accounting exceptions requiring human attention, not as automatic state reversals.
- Keep Stripe authoritative for payment movement.
- Keep Kimai authoritative for invoices.
- Make retry behavior safe after uncertain network outcomes.
- Make orphaned remote objects discoverable and conservatively cleanable.
- Keep the plugin optional and operationally disposable.
- Preserve the possibility of splitting the package later based on evidence rather than speculative modularity.
- Make the public interface and metadata contract stable enough that future versions can migrate without losing historical correlation.

## Decision

The project SHALL initially be implemented and distributed as **one Kimai plugin** containing two independently disableable capabilities:

1. Stripe Payment Link generation/recovery and invoice rendering support; and
2. Stripe payment reconciliation into Kimai invoice paid state.

The single package is a packaging decision, not a decision to blend the two responsibilities internally.

### Package-level architecture

Conceptually:

```text
KimaiStripeConnector
|
+-- Common
|   +-- configuration
|   +-- Stripe API client construction
|   +-- Kimai instance identity
|   +-- invoice identity
|   +-- money normalization
|   +-- metadata contract
|   +-- common validation/errors
|
+-- PaymentLink
|   +-- pre-render subscriber
|   +-- Payment Link lookup
|   +-- Payment Link creation
|   +-- idempotent recovery
|   +-- invoice model hydration
|   +-- orphan inspection/cleanup
|
+-- Reconciliation
    +-- unpaid invoice selection
    +-- Payment Link correlation
    +-- Checkout Session validation
    +-- PaymentIntent validation
    +-- Charge validation
    +-- paid-state update
    +-- post-payment anomaly reporting
```

The exact PHP namespaces/classes may differ.  The important decision is the dependency direction and responsibility boundary.

`PaymentLink` and `Reconciliation` MAY depend on `Common`.

`PaymentLink` SHALL NOT depend on `Reconciliation`.

`Reconciliation` SHALL NOT require `PaymentLink` to be enabled at runtime when sufficient connector metadata exists to correlate the invoice and Stripe records.

The two capabilities SHALL be independently disableable.

### Configuration boundary

The plugin SHOULD expose configuration sufficient to support at least:

- global plugin enable/disable;
- Payment Link capability enable/disable;
- reconciliation capability enable/disable;
- Stripe secret/restricted API credential;
- Kimai instance identity;
- Stripe API-version expectation when relevant;
- optional payment-method policy;
- cleanup grace period;
- reconciliation/cleanup command behavior;
- test-mode versus live-mode expectations where safely detectable.

Configuration SHOULD be explicit enough that a misconfigured environment fails visibly rather than silently using surprising defaults.

The implementation SHALL NOT log Stripe secret keys.

The implementation SHOULD avoid passing Stripe credentials through mechanisms known to echo environment variables or command invocations into logs.

### Stable Kimai instance identity

Invoice number alone is insufficient as a global identifier because two Kimai instances may both generate an invoice such as `INV-0042` while sharing one Stripe account.

Each Kimai installation using the connector SHALL therefore have a stable connector instance identifier, expected to be a UUID or equivalently collision-resistant opaque identifier.

The identifier SHALL:

- remain stable across plugin upgrades;
- remain stable across container recreation;
- remain stable across application restarts;
- be unique among Kimai instances that may share a Stripe account;
- avoid containing secrets;
- be suitable for storage in Stripe metadata.

Changing the instance identifier after production use is effectively a metadata-namespace migration and SHALL be treated accordingly.

### Metadata contract

The connector SHALL use a versioned Stripe metadata contract.

A proposed initial contract is:

```text
integration=kimai-stripe-connector
integration_version=1
kimai_instance=<stable instance UUID>
invoice_number=<Kimai invoice number>
invoice_amount=<integer minor currency units>
invoice_currency=<normalized lowercase currency>
```

Additional values MAY include:

```text
kimai_customer_id=<Kimai customer ID>
created_by=kimai-stripe-connector
```

The exact contract remains an implementation follow-up, but several properties are decided now:

- identity fields SHALL be machine-readable;
- money SHALL be represented as integer minor units where the currency model permits;
- currency SHALL be normalized consistently;
- metadata SHALL be treated as a correlation mechanism, not as a replacement financial ledger;
- metadata version changes SHALL be explicit;
- reconciliation SHOULD tolerate historical metadata versions deliberately rather than by accident;
- unrecognized metadata versions SHALL fail closed for automated state changes.

Invoice numbers and customer-visible values SHALL NOT be assumed to be safe identifiers merely because they are human-readable.

### Money representation

The connector SHALL NOT use binary floating-point arithmetic as the authoritative representation of Stripe amounts.

Kimai may expose invoice totals as floating-point values internally.  The connector SHALL convert them into a deliberate money representation at the integration boundary and validate the conversion.

The implementation SHOULD use either:

- integer minor currency units derived with currency-aware rules; or
- a mature arbitrary-precision money library suitable for PHP and the supported currency set.

The connector SHALL NOT hard-code an assumption that every currency has two decimal places unless the supported-currency policy explicitly restricts the connector to such currencies.

Currency exponent/zero-decimal behavior is therefore an implementation requirement, even if the initial deployment uses USD.

### Payment Link creation lifecycle

The Payment Link capability SHALL run only during real invoice generation.

If `InvoiceModel::isPreview()` is true, the connector SHALL perform no Stripe mutation.

A conceptual pre-render algorithm is:

```text
receive InvoicePreRenderEvent
        |
        +-- connector disabled? -> no automatic Stripe action
        |
        +-- preview? -> no Stripe action
        |
        v
construct canonical invoice identity
        |
        v
search/recover managed Payment Link
        |
        +-- exactly one valid financial match -> reuse it
        |
        +-- conflicting identity/financial data -> fail closed
        |
        +-- multiple ambiguous managed matches -> fail closed
        |
        +-- no match -> create new Payment Link
        |
        v
attach link information to InvoiceModel
        |
        v
InvoiceModelHydrator exposes data to renderer
```

### Payment Link financial shape

The initial Payment Link SHOULD represent exactly one invoice total.

Expected properties include:

- one-time payment mode;
- fixed amount;
- fixed currency;
- quantity of one;
- no adjustable quantity;
- no customer-entered amount;
- no promotion codes unless a later decision explicitly supports them;
- no automatic Stripe post-purchase invoice generation, because Kimai remains the invoice authority;
- connector metadata attached to the Payment Link;
- a limit of one completed Checkout Session if live testing confirms acceptable behavior for the supported payment methods.

If Stripe `price_data` is used to create an ad-hoc Product/Price inline, the connector SHALL declare and test the minimum Stripe API version required for that capability.

The connector SHOULD use a customer-visible Product/Price description that makes the payment purpose understandable, for example:

```text
Invoice INV-0042
```

The Stripe object name is presentation information.  The connector SHALL continue to use machine-readable metadata for identity.

### Single-use Payment Link behavior

The project intends to use Stripe's completed-session limit of one where compatible with supported payment methods.

This control exists primarily to reduce accidental duplicate payment through the same link.

It SHALL NOT be treated as proof that money was successfully and finally received.

The reconciliation algorithm SHALL independently validate payment state.

If asynchronous payment methods are supported, the interaction among:

- Checkout Session completion;
- Payment Link automatic deactivation;
- PaymentIntent processing;
- eventual success/failure; and
- customer retry behavior

SHALL be tested explicitly before release.

### Invoice rendering contract

The connector SHALL expose Stripe payment information to invoice templates through supported Kimai invoice-model hydration rather than requiring core changes.

The desired rendering contract is conceptually similar to:

```text
invoice.stripe.payment_url
invoice.stripe.payment_link_id
```

The exact Twig key names remain subject to implementation review.

The plugin MAY expose additional non-sensitive rendering values, such as a human-readable payment-method label, if useful.

Stripe secrets, internal API credentials, raw payment objects, or diagnostic data SHALL NOT be exposed to templates.

The invoice template remains responsible for presentation.  The connector provides data.

### Behavior when automatic Payment Link generation fails

The plugin SHALL NOT silently produce a document that appears to promise online payment when no valid Payment Link was obtained.

The implementation should make the failure visible and actionable.

A preferred operational failure mode is:

```text
automatic Payment Link generation failed
-> invoice generation is stopped or explicitly bypassed
-> operator receives a clear reason
-> operator may disable/bypass automation
-> operator may use the manual Stripe workflow
```

The exact UI/command mechanics of bypass are an implementation decision.

There SHALL be an explicit way to continue billing manually without modifying plugin code.

### Idempotency and uncertain network outcomes

A network timeout does not prove that Stripe failed to create an object.

The connector SHALL account for the case:

```text
connector sends create request
-> Stripe commits Payment Link
-> response is lost
-> connector observes timeout
```

The connector SHOULD use Stripe idempotency keys for mutation requests where appropriate.

An idempotency key SHOULD be deterministic for one logical creation attempt and SHOULD be derived from stable connector identity rather than random retry-local state.

Stripe idempotency alone SHALL NOT be the only long-term recovery mechanism because idempotency-key retention has a finite lifetime and is an API behavior external to Kimai.

Stable metadata and exact-match lookup provide the longer-lived recovery mechanism.

### Recovery of an existing Payment Link

When a managed Payment Link already exists for an invoice identity, the connector SHALL validate the financial data before reuse.

Reuse requires agreement on all fields that affect payment meaning, including at minimum:

- connector namespace;
- metadata contract version supported by the code;
- Kimai instance identity;
- invoice number;
- invoice amount;
- currency;
- link active state or a deliberately handled inactive state;
- expected one-time payment semantics.

A mismatched link SHALL NOT be edited in place merely to make it fit a newly rendered invoice.

Example:

```text
Stripe link metadata:
  invoice_number=INV-0042
  amount=87500
  currency=usd

current Kimai invoice:
  invoice_number=INV-0042
  amount=90000
  currency=usd
```

The correct automatic result is a conflict, not mutation and not reuse.

This refusal is deliberate.  Once an external payment object has been associated with an invoice identity, changing its financial meaning silently is more dangerous than requiring a human decision.

### Orphaned Payment Links

The Kimai event ordering creates an unavoidable partial-failure window:

```text
Stripe Payment Link created successfully
        |
        v
invoice URL injected into render model
        |
        v
Kimai render/file/database operation fails
        |
        v
no completed Kimai invoice exists
        |
        v
Stripe Payment Link remains
```

This condition is accepted.

An unpaid orphan does not, by itself, represent lost money or an incorrect Kimai accounting state.  It is an unused remote object.

The design reduces the risk in several ways:

- the URL normally was never sent to the customer if Kimai failed before invoice delivery;
- metadata identifies the intended Kimai instance and invoice;
- a retry can rediscover the object;
- an exact financial match can be reused;
- a mismatch is surfaced as a conflict;
- old unpaid orphans can be deactivated conservatively;
- paid orphans are treated as accounting exceptions rather than garbage.

### Worst-case orphan scenario

The more serious orphan sequence is:

```text
1. Connector creates Stripe Payment Link for INV-0042.
2. Kimai fails before completing the invoice.
3. The Payment Link remains active.
4. The URL becomes known to the intended customer or another party.
5. A payment is successfully made.
6. Kimai has no corresponding completed invoice record.
```

The customer is sending money to the merchant rather than money leaving the merchant unexpectedly.  The accounting state is nevertheless inconsistent and must be handled manually.

The connector SHALL NOT invent a Kimai invoice or silently attach the payment to a different invoice.

A paid orphan SHALL be reported as a high-priority manual reconciliation condition.

### Orphan cleanup

The plugin SHOULD provide an explicit cleanup/inspection command suitable for weekly or similarly low-frequency execution.

A conceptual command name is:

```text
bin/console kimai:stripe:cleanup
```

The final name is not decided.

Cleanup SHALL be scoped only to Payment Links positively identified as belonging to this connector and this Kimai instance.

The cleanup algorithm SHALL be conservative:

```text
list managed Stripe Payment Links
        |
        v
for each link:
        |
        +-- matching Kimai invoice exists -> retain
        |
        +-- Kimai cannot be queried reliably -> retain
        |
        +-- no Kimai invoice, link younger than grace period -> retain
        |
        +-- no Kimai invoice, payment evidence exists -> ALERT / retain
        |
        +-- no Kimai invoice, no payment, older than grace period
               -> deactivate
```

Cleanup SHALL never interpret inability to reach Kimai as evidence that an invoice does not exist.

Failures such as:

- Kimai timeout;
- authentication failure;
- HTTP 5xx;
- malformed response;
- database error; or
- uncertainty about invoice identity

SHALL result in deactivating nothing for the affected determination.

The initial grace-period candidate is seven days.  The exact value remains open.

The grace period applies only to links for which **no matching Kimai invoice exists**.  A legitimate invoice that remains unpaid for 30, 60, 90, or more days SHALL NOT have its Payment Link deactivated merely because the link is old.

Cleanup failure is maintenance debt, not a billing outage.  If cleanup does not run for several weeks, the expected consequence is additional unused Stripe objects rather than broken invoices or payment loss.

### Payment reconciliation

Reconciliation SHALL be asynchronous.

A conceptual command name is:

```text
bin/console kimai:stripe:reconcile
```

The final name is not decided.

The command is expected to be suitable for:

- cron;
- systemd timers;
- Jenkins;
- another external scheduler; or
- manual execution.

The plugin SHALL NOT require an internal persistent job scheduler merely to perform reconciliation.

The first implementation SHOULD favor a repair/reconciliation model rather than an event-delivery model:

```text
observe current Kimai state
+
observe current Stripe state
-> derive whether a safe monotonic update exists
-> apply only that update
```

This means a missed run is recoverable by a later run.

### Reconciliation identity

The reconciler SHALL correlate Stripe objects to a Kimai invoice through explicit connector identity rather than customer name, description text, amount alone, or temporal proximity.

Amount equality by itself is insufficient.

Customer email equality by itself is insufficient.

Invoice number by itself is insufficient when multiple Kimai instances may share a Stripe account.

The expected minimum correlation is:

```text
connector namespace
+ metadata contract version
+ Kimai instance identity
+ invoice number
+ amount
+ currency
```

### Reconciliation evidence

The reconciler SHALL not mark a Kimai invoice paid merely because a Payment Link exists.

It SHALL not mark a Kimai invoice paid merely because a Checkout Session exists.

It SHALL not mark a Kimai invoice paid merely because a Checkout Session is complete.

The first implementation is expected to validate multiple Stripe layers, including the relevant Checkout Session, PaymentIntent, and Charge where present and applicable.

A conservative successful case is expected to require evidence broadly equivalent to:

```text
Checkout Session:
  belongs to expected Payment Link
  connector metadata matches
  amount_total matches invoice
  currency matches invoice
  status indicates completed checkout
  payment_status indicates paid

PaymentIntent:
  is the intent referenced by the relevant session
  status indicates succeeded
  amount matches invoice
  amount_received matches expected amount
  currency matches invoice
  connector identity is consistent where metadata is present/propagated

Charge:
  belongs to the expected PaymentIntent
  paid is true
  captured is true when capture semantics require it
  amount matches invoice
  amount_captured matches expected amount
  refunded is false
  amount_refunded is zero
  no known dispute condition invalidates automatic confidence
```

The exact Stripe-field requirements SHALL be encoded in tests and may vary based on supported payment methods and Stripe API semantics.

The principle is stable: **automatic paid state requires positive, unambiguous, internally consistent evidence.**

### Reconciliation decision matrix

The intended high-level behavior is:

| Stripe/Kimai condition | Automatic action |
| --- | --- |
| No matching Payment Link | No change |
| Matching link, no Checkout Session | No change |
| Checkout open | No change |
| Checkout complete but payment pending/unpaid | No change |
| PaymentIntent processing | No change |
| PaymentIntent failed/canceled | No change |
| Amount mismatch | No change; report conflict |
| Currency mismatch | No change; report conflict |
| Metadata/instance mismatch | No change; report conflict |
| Multiple ambiguous candidate payments | No change; report ambiguity |
| Partial payment | No change unless separately designed later |
| Successful exact payment evidence | Mark Kimai invoice paid |
| Kimai already paid and Stripe agrees | No change |
| Kimai already paid and later Stripe refund/dispute exists | Do not reverse automatically; report |
| Paid Stripe orphan with no Kimai invoice | Do not fabricate invoice; report |

### Monotonic automatic state transition

The reconciler's permitted automatic accounting transition SHALL initially be one-way:

```text
Kimai not paid
+
strong Stripe evidence of exact payment
-> Kimai paid
```

The connector SHALL NOT automatically perform:

```text
Kimai paid -> unpaid
Kimai paid -> canceled
Kimai paid -> new
Kimai paid -> pending
```

because of a later refund, dispute, chargeback, reversal, or other Stripe event.

Those events are meaningful, but they are not equivalent to the statement "the original invoice was never paid."  Automatically rewriting historical invoice state risks conflating payment history with later accounting events.

The connector MAY surface such conditions through logs, command exit status, reports, or future notification mechanisms.  The accounting response remains human-controlled unless a later ADR explicitly broadens the automation.

### Payment date

When marking a Kimai invoice paid, the connector SHOULD use a payment date derived from authoritative Stripe transaction timing rather than simply the reconciliation run time, if Kimai semantics and Stripe evidence allow this unambiguously.

The exact Stripe timestamp and timezone conversion rules require implementation review.

The connector SHALL avoid changing an existing manually entered payment date without an explicit and documented rule.

### Manual payments and out-of-band payments

Kimai invoices may be paid by means that did not originate from the connector's Stripe Payment Link.

The reconciler SHALL respect an invoice already marked paid manually.

The connector SHALL NOT require all Kimai invoices to be paid through Stripe.

If an invoice is manually marked paid while an active connector-managed Payment Link still exists, deactivation behavior should be considered during implementation.  Automatically deactivating such a link may be useful, but it introduces another state-changing action and therefore remains an open question rather than an implicit requirement.

### Independent capability operation

Payment Link generation and reconciliation SHALL be independently enableable.

The following states SHALL be legitimate:

```text
Payment Links enabled
Reconciliation enabled
```

```text
Payment Links enabled
Reconciliation disabled
```

```text
Payment Links disabled
Reconciliation enabled
```

```text
Payment Links disabled
Reconciliation disabled
```

The last state effectively leaves the plugin installed but inert.

Disabling reconciliation SHALL not prevent Payment Link generation.

Disabling Payment Link generation SHALL not prevent the reconciler from processing historical connector-managed invoices whose Stripe correlation can still be established.

### Manual fallback as a design requirement

The connector SHALL never become necessary to continue billing.

The canonical manual fallback remains:

```text
track time
-> generate invoice
-> create Stripe Payment Link manually
-> place Stripe URL on or with invoice
-> send invoice
-> check Stripe manually
-> mark invoice paid manually
```

The project SHOULD document this fallback prominently enough that operators can use it during a plugin or Stripe API incident without reverse-engineering normal behavior.

The plugin SHOULD have an explicit operational bypass rather than requiring code removal during an incident.

A plugin compatibility failure after a Kimai upgrade should be recoverable operationally by disabling/removing the plugin and reverting to manual billing.

No connector outage should create an emergency requirement to modify Kimai core.

### Security and credential posture

The connector crosses a high-value trust boundary because it needs Stripe API credentials and can modify Kimai invoice state.

The project SHALL apply least privilege where practical.

The Stripe credential SHOULD be a restricted key rather than a full secret key if Stripe's permission model can support the required operations reliably.

The required Stripe permissions should be documented explicitly after implementation determines the precise API calls.

If the Payment Link and reconciliation capabilities can use meaningfully different restricted credentials without unreasonable configuration complexity, the plugin MAY support separate credentials.

A possible future split is:

```text
Payment Link credential:
  create/read/update Payment Links
  create related Price/Product objects if required

Reconciliation credential:
  read Payment Links
  read Checkout Sessions
  read PaymentIntents
  read Charges
```

Cleanup requires the ability to deactivate managed Payment Links and therefore has mutation privileges similar to the Payment Link capability.

The plugin SHALL NOT:

- emit Stripe secret keys in logs;
- expose keys in invoice templates;
- copy secrets into Stripe metadata;
- include secrets in exception messages intentionally;
- include secrets in CLI output;
- store secrets in generated invoice files.

The project SHOULD review Kimai/container startup behavior for accidental environment-variable disclosure.  Prior operational experience showed that container entrypoints can echo environment values through shell tracing, so merely placing a secret in an environment variable is not sufficient reason to assume it will remain absent from logs.

### Network posture

The connector SHALL require outbound HTTPS access from Kimai or the execution environment to Stripe.

The connector SHALL NOT require Stripe to initiate inbound connections to Kimai.

The first architecture SHALL NOT require:

- public Traefik routes to Kimai;
- webhook endpoints;
- public callback controllers;
- an Internet-facing relay service;
- a public message queue.

This keeps the customer payment surface entirely at Stripe.

### Logging and observability

Financial automation should be explainable after the fact.

The plugin SHOULD log enough structured information to reconstruct connector decisions without logging secrets or sensitive payment details unnecessarily.

Useful fields may include:

- connector version;
- Kimai instance identifier or a safe shortened representation;
- invoice number;
- Kimai invoice ID when available;
- Stripe Payment Link ID;
- Checkout Session ID;
- PaymentIntent ID;
- Charge ID;
- action attempted;
- action result;
- conflict reason;
- whether an operation was a dry run;
- whether a link was created, recovered, reused, retained, or deactivated;
- whether reconciliation changed Kimai state;
- whether manual review is required.

Logs SHOULD distinguish among:

- expected no-op;
- retryable operational failure;
- configuration failure;
- data conflict;
- accounting exception; and
- successful state change.

The connector SHOULD avoid logging full Stripe object payloads by default.

### Command behavior and exit status

Reconciliation and cleanup commands SHOULD support predictable automation semantics.

Potential expectations include:

- exit zero when execution completed and no unresolved critical condition exists;
- nonzero exit for configuration or operational failure;
- distinct reporting for manual-review accounting exceptions;
- optional `--dry-run` behavior before destructive cleanup or paid-state changes;
- stable machine-readable output as a future enhancement if Jenkins/monitoring integration needs it.

Exact exit-code conventions remain an implementation decision.

### Dry-run behavior

A dry-run mode would be particularly useful for:

- reconciliation;
- orphan cleanup;
- future migration commands.

Dry-run mode SHALL make no Stripe or Kimai state changes.

It SHOULD report what would have changed and why.

A dry run SHALL not be presented as proof that the corresponding live mutation would succeed; it validates decision logic against observed state, not credentials for mutation or remote write availability.

### Compatibility and plugin lifecycle

The largest remaining architectural discomfort is not the Payment Link or reconciliation algorithm.  It is the Kimai plugin lifecycle.

Kimai plugins are deployed as bundles under its plugin directory and can have version compatibility constraints.  The project therefore inherits an ongoing responsibility to test connector compatibility with supported Kimai releases.

The plugin SHOULD remain small partly to minimize this maintenance surface.

The project SHOULD document:

- minimum supported Kimai version;
- maximum tested Kimai version when appropriate;
- PHP version requirements;
- Stripe PHP SDK version requirements if the SDK is used;
- Stripe API version assumptions;
- installation steps;
- upgrade steps;
- rollback/disable steps;
- cache-clear requirements;
- compatibility test policy.

A Kimai upgrade that breaks the plugin must not create a billing outage because the manual workflow remains supported.

### Stripe API versioning

Stripe API behavior changes over time.

The connector SHALL treat Stripe API versioning as an explicit compatibility dimension rather than assuming current documentation describes every account's default behavior.

This is especially important for Payment Link `price_data`, which was added to the Payment Link creation API in a 2025 API version family.

The implementation SHALL decide whether to:

- pin an API version on requests;
- require a minimum account/API version;
- rely on the Stripe SDK's configured API version; or
- avoid newer API features when compatibility benefit outweighs convenience.

Whichever approach is chosen SHALL be documented and tested.

### Statelessness and persisted connector state

The preferred design avoids creating a connector-specific database ledger if Stripe metadata and Kimai invoice identity are sufficient for correlation.

The plugin SHOULD remain as stateless as practical.

Kimai invoice metadata MAY store Stripe identifiers for convenience, diagnostics, or performance, but correctness SHOULD NOT depend solely on those local fields if the Stripe objects can be rediscovered safely through the connector metadata contract.

This has several benefits:

- fewer plugin database migrations;
- less state to repair;
- easier recovery after partial failures;
- easier plugin removal;
- reduced coupling to Kimai's internal persistence model.

If implementation experience shows that efficient or safe reconciliation requires local durable state, that addition should be documented rather than introduced silently.

### Data-retention posture

The connector need not aggressively delete historical Stripe objects merely for aesthetic cleanliness.

Paid Payment Links are useful historical artifacts and MAY remain in Stripe indefinitely according to normal Stripe/account retention behavior.

Inactive unpaid orphan links MAY also remain without harming correctness.

The cleanup command exists to reduce active abandoned payment surfaces, not to maintain a perfectly tidy Stripe object list.

The project SHALL prefer operational safety over cosmetic garbage collection.

### Failure behavior matrix

The following table records intended behavior for representative failures.

| Failure | Expected behavior |
| --- | --- |
| Stripe unavailable during invoice generation | Fail automatic link generation visibly; manual bypass remains available |
| Stripe times out before known commit | Retry/recover safely |
| Stripe commits link but response is lost | Recover via idempotency and/or metadata lookup; do not create duplicates blindly |
| Kimai render fails after link creation | Leave recoverable orphan; retry may reuse it |
| Kimai database save fails after link creation | Leave recoverable orphan; no fabricated invoice |
| Existing exact link found | Reuse after validation |
| Existing link amount differs | Stop automation; manual resolution required |
| Existing link currency differs | Stop automation; manual resolution required |
| Multiple matching links are ambiguous | Stop automation; manual resolution required |
| Preview render | No Stripe mutation |
| Reconciliation Stripe timeout | No Kimai change; retry later |
| Kimai save fails during reconciliation | Report failure; later run reevaluates current state |
| Checkout complete but payment not final | No Kimai paid transition |
| Successful exact payment | Mark Kimai paid |
| Partial payment | No automatic paid transition |
| Refund after invoice marked paid | Do not reverse automatically; report |
| Dispute after invoice marked paid | Do not reverse automatically; report |
| Paid Stripe orphan | No cleanup/deletion; manual accounting review |
| Cleanup cannot reach Kimai | Deactivate nothing |
| Cleanup finds old unpaid orphan | Deactivate after grace period |
| Cleanup does not run | No billing failure; stale Stripe objects may accumulate |
| Plugin incompatible after Kimai upgrade | Disable plugin and use manual workflow |

### Testing expectations

The implementation SHALL convert the pre-implementation design cases into repository tests.

At minimum, testing SHOULD include:

#### Unit-level behavior

- canonical invoice identity;
- metadata serialization/parsing;
- money conversion;
- currency normalization;
- preview suppression;
- exact-match decision logic;
- mismatch/conflict decision logic;
- reconciliation decision matrix;
- cleanup decision matrix;
- one-way paid-state transition rules.

#### Stripe client behavior

- expected create request;
- metadata placement;
- idempotency-key behavior;
- pagination;
- malformed responses;
- HTTP 4xx;
- HTTP 5xx;
- network timeout;
- connection drop after remote commit;
- retry behavior;
- rate limiting where practical to simulate.

#### Concurrency

- simultaneous attempts to create the same logical link;
- simultaneous reconciliation runs;
- cleanup racing with invoice retry;
- reconciliation racing with manual paid-state changes.

#### Kimai integration

- plugin installation;
- service registration;
- event subscriber registration;
- model hydrator registration;
- invoice preview;
- invoice creation;
- invoice rendering with payment URL;
- invoice creation failure after Stripe mutation;
- invoice paid-state update;
- payment-date behavior;
- plugin disable/remove fallback.

#### Stripe test-mode integration

- creation of a real test-mode Payment Link;
- rendered invoice contains working test URL;
- successful card payment;
- failed card payment;
- retried payment;
- exact reconciliation;
- single-use behavior;
- deactivated link behavior;
- refund after reconciliation;
- dispute simulation if Stripe test facilities support representative behavior;
- supported asynchronous payment methods if any are enabled.

#### Upgrade/compatibility testing

- at least the minimum supported Kimai release;
- current targeted Kimai release;
- newer Kimai releases before claiming support;
- supported PHP versions;
- supported Stripe SDK/API versions.

### Implementation size as a design signal

The plugin is expected to remain small.

Code size is not itself a correctness metric, but unexpected growth is a signal that the project may be crossing its intended boundary.

If implementing the initial scope requires substantial amounts of:

- custom persistence;
- custom queues;
- controllers;
- dashboards;
- webhook infrastructure;
- payment ledgers;
- complex migrations; or
- bespoke billing abstractions,

then the architecture should be revisited before continuing.

The project should be able to explain its purpose in one sentence:

> Create or recover a Stripe payment link for a Kimai invoice, expose that link to the invoice, and conservatively mark the invoice paid when Stripe proves the exact payment succeeded.

### Scope boundary and non-goals

This connector SHALL remain a connector rather than becoming an accounting, ERP, or billing platform.

The following are outside the current scope unless separately proposed and accepted:

- recurring billing;
- subscription lifecycle management;
- automatic tax calculation;
- tax filing;
- customer accounting ledgers;
- generalized bookkeeping;
- accounts receivable management beyond Kimai's existing invoice status;
- partial-payment accounting;
- installment plans;
- payment-plan negotiation;
- dunning;
- automated late fees;
- generalized Stripe Customer synchronization;
- automatic refund initiation;
- automatic refund accounting;
- automatic dispute handling;
- automatic chargeback resolution;
- automatic reversal of Kimai paid state;
- copying Stripe's dashboard into Kimai;
- storing card/payment credentials;
- PCI-sensitive payment collection inside Kimai;
- public Kimai payment pages;
- mandatory webhook infrastructure;
- public ingress as a prerequisite;
- replacing Stripe receipts;
- replacing Kimai invoice generation;
- supporting arbitrary payment processors through an abstraction layer before a real requirement exists.

A request in one of these areas should trigger a scope review and usually a new ADR.

### Distribution and licensing are separate decisions

This repository was created from a general project template and currently contains template-era licensing/documentation material.  That existing material SHALL NOT be interpreted as the final licensing decision for the connector.

Open-source distribution has been discussed as desirable, but the final license, contribution model, public release process, and possible support model are orthogonal to the one-plugin architecture and should be documented separately.

Likewise, whether the connector is listed in Kimai's plugin marketplace or otherwise marketed is not decided by this ADR.

## Considered Alternatives

### Two independent Kimai plugins

One plugin could create and embed Stripe Payment Links while a second plugin performs payment reconciliation.

This approach has substantial architectural merit.

Advantages include:

- package-level separation of concerns;
- independent installation;
- independent removal;
- smaller capability-specific failure blast radius;
- potentially separate restricted Stripe credentials;
- independent release cadence;
- the ability to use only Payment Links or only reconciliation;
- clearer distinction between workflow automation and accounting-related automation.

The disadvantages are primarily operational and maintenance-related:

- two Kimai plugin manifests/packages;
- two compatibility surfaces for every Kimai release;
- two version numbers;
- two release artifacts;
- two installation/update operations;
- possible duplicate configuration;
- risk of metadata-contract drift;
- risk of money-normalization drift;
- risk of invoice-identity drift;
- either duplicated Stripe client behavior or a third common package;
- release-ordering concerns if one plugin understands a newer metadata version than the other.

A shared third package could remove duplication:

```text
KimaiStripeCommon
      ^       ^
      |       |
PaymentLink  Reconciliation
```

However, that would turn a small connector into a small package ecosystem before evidence demonstrates a need.

The single-plugin approach is therefore preferred initially.

The two-plugin design SHALL be reconsidered if any of the following become true:

- Payment Link generation and reconciliation require materially different Kimai compatibility ranges;
- they require materially different Stripe SDK versions;
- they acquire independent release cadences;
- one becomes useful to a substantially different user population;
- security policy requires hard package-level credential isolation;
- one capability grows large while the other remains small;
- separate maintainers assume responsibility;
- package-level blast-radius isolation becomes operationally valuable;
- shared code becomes negligible enough that duplication is cheaper than coupling;
- users consistently request one capability without wanting to install the other;
- marketplace/distribution rules favor separate packages.

The project SHALL not split merely because two conceptual responsibilities exist.  The split should be justified by lifecycle evidence.

### Payment Link plugin only

A smaller plugin could automate Payment Link creation and invoice rendering while leaving payment reconciliation manual.

Advantages:

- smallest financial automation surface;
- no automated Kimai accounting state changes;
- easier implementation;
- easier testing;
- lower Stripe read-permission requirements;
- fewer accounting edge cases.

Disadvantages:

- Stripe can show the invoice payment as successful while Kimai continues to show it unpaid;
- operators must continue checking Stripe and marking Kimai manually;
- Kimai's invoice archive becomes less accurate unless manual reconciliation is performed consistently.

This remains a viable staged implementation approach.  Payment Link functionality MAY be completed and validated before automatic reconciliation is enabled.

It is not the target architecture because accurate paid status in Kimai is a desired outcome.

### Reconciliation plugin only

A reconciliation-only plugin could leave Payment Link creation manual and automate only the paid-state update.

Advantages:

- less coupling to Kimai's pre-render invoice lifecycle;
- no orphan Payment Link creation problem;
- valuable if operators are comfortable creating Stripe links manually.

Disadvantages:

- it retains the copy/paste workflow that motivated the connector;
- manually created links would need connector-compatible metadata for robust automatic correlation;
- users could create links incorrectly or omit metadata, reducing reconciliation confidence.

This does not satisfy the full workflow goal.

### No plugin

The fully manual workflow is operationally sufficient.

Advantages:

- no custom code;
- no Kimai plugin lifecycle;
- no compatibility maintenance;
- no Stripe API key inside Kimai;
- very low operational complexity;
- excellent failure transparency.

Disadvantages:

- repetitive clerical steps;
- payment status can drift between Stripe and Kimai;
- manual link creation can introduce amount/currency mistakes;
- manual reconciliation can be forgotten.

This alternative remains the mandatory fallback and the standard against which connector complexity should be justified.

If maintaining the plugin ever costs more than the clerical work it removes, abandoning the plugin is a legitimate outcome.

### Core Kimai modification or fork

Kimai could be patched to add Stripe behavior directly.

This is rejected.

Reasons include:

- upgrade burden;
- vendor divergence;
- loss of supported extension boundaries;
- larger regression surface;
- difficult public distribution;
- conflict with the project's explicit rule against core patches/forks when supported extension mechanisms exist.

### Custom Kimai container image containing patched integration code

The connector could be baked into a custom image or copied into a derived Kimai image as part of deployment.

This is not selected as the architectural requirement.

A deployment may eventually package the supported plugin in a custom image for operational convenience, but correctness and compatibility SHALL depend on the normal plugin contract rather than source patching.

### External standalone service instead of Kimai plugin

A separate daemon or service could poll Kimai and Stripe, create Payment Links, and update invoices through APIs.

Potential advantages:

- decouples runtime from Kimai plugin lifecycle;
- could be implemented in another language;
- independent deployment and release cadence.

Disadvantages:

- inserting a Payment Link into the invoice before rendering becomes harder;
- requires an API or synchronization protocol for the invoice-generation moment;
- adds another deployed service;
- adds another authentication boundary;
- creates more operational infrastructure than the expected volume warrants.

This may be reconsidered if Kimai plugin compatibility becomes the dominant maintenance problem.

### Webhook-driven reconciliation

Stripe could call a public webhook whenever payment state changes.

Advantages:

- near-real-time updates;
- natural event-driven integration;
- no polling delay.

Disadvantages:

- requires public ingress somewhere;
- requires webhook signature handling;
- introduces delivery/retry/idempotency concerns;
- requires public routing or a relay;
- creates a new externally reachable surface;
- is unnecessary for the expected invoice volume and acceptable eventual consistency.

Webhook support is therefore rejected as a requirement for the initial architecture.

A future optional webhook mode would require a new decision because it changes the network/security posture.

### Stripe-hosted Invoice instead of Payment Link

Stripe can provide hosted invoice/payment experiences through Stripe Billing/Invoicing.

This would make Stripe another invoice authority and could introduce additional product/fee semantics.  The desired architecture keeps Kimai as the invoice system and uses Stripe only for payment collection.

This alternative is therefore not selected.

### Raw Checkout Session URL instead of Payment Link

A connector could create a Checkout Session directly and place its URL on the Kimai invoice.

A Checkout Session is a transaction/session object rather than the desired durable payment-link abstraction.  Payment Links better match the requirement for a customer-facing URL that can survive independently of the Kimai runtime.

This alternative is not selected for the initial design.

### Store Stripe IDs only in Kimai metadata

The plugin could treat Kimai invoice metadata as the sole durable mapping from invoice to Stripe objects.

Advantages:

- direct lookup;
- no need to search Stripe metadata;
- straightforward diagnostics from Kimai.

Disadvantages:

- pre-render Stripe creation occurs before the completed invoice entity exists;
- partial failures can leave Stripe state without local mapping;
- plugin correctness becomes more dependent on Kimai persistence;
- recovery after failed invoice creation becomes harder;
- plugin removal/migration carries more local state.

Kimai metadata MAY still cache useful Stripe identifiers, but the preferred design uses Stripe metadata as a durable recovery/correlation mechanism so local persistence is not the only source of mapping truth.

## Consequences

### Positive consequences

- One plugin is installed and versioned.
- One metadata contract is maintained.
- One compatibility matrix is maintained.
- Shared correlation and money logic cannot drift across packages accidentally.
- Payment Link generation and reconciliation remain internally distinct.
- Either capability can be disabled.
- Kimai remains private/internal-only.
- Stripe remains the public payment surface.
- No inbound webhooks are required.
- Existing Payment Links continue to function if Kimai or the plugin is offline.
- Manual billing remains available.
- Payment Link creation can become effectively idempotent through Stripe idempotency plus durable metadata recovery.
- Orphaned links are recoverable.
- Unpaid orphans can be cleaned conservatively.
- Paid orphans become visible accounting exceptions rather than silently lost context.
- Reconciliation can be retried because it derives action from current state.
- A missed polling run is not a correctness failure.
- False-positive paid state is deliberately discouraged.
- Refunds and disputes do not silently rewrite invoice history.
- The project can remain small enough to audit.
- A future package split remains possible.

### Negative consequences

- One package contains two responsibilities with different risk profiles.
- A package-level compatibility failure may make both capabilities unavailable until the plugin is disabled or repaired.
- Kimai plugin compatibility becomes an ongoing maintenance responsibility.
- Stripe API compatibility becomes an ongoing maintenance responsibility.
- Stripe credentials exist in or near the Kimai runtime.
- Payment Link creation before invoice persistence creates a partial-failure window.
- Polling introduces eventual consistency.
- Reconciliation must model more Stripe state than a link-only plugin.
- The connector must remain current with relevant Stripe payment lifecycle behavior.
- Supporting asynchronous payment methods may substantially expand testing and state handling.
- Cleanup and reconciliation commands add operational tasks, even if they are low-frequency and noncritical.
- Public/open-source distribution would create compatibility/support expectations beyond the original local use case.

### Risks explicitly accepted

The project accepts eventual consistency between Stripe and Kimai.

The project accepts that an invoice may be genuinely paid in Stripe while Kimai still shows unpaid until reconciliation runs.

The project accepts that Stripe may contain unused orphaned Payment Links.

The project accepts that cleanup may be delayed or skipped without harming billing correctness.

The project accepts manual intervention for conflicting financial state.

The project accepts manual intervention for paid orphans.

The project accepts manual intervention for refunds, disputes, reversals, and other post-payment accounting exceptions.

The project accepts that a future Kimai upgrade may temporarily require disabling the plugin and using the manual workflow.

### Risks explicitly not accepted

The project does not accept silently marking an invoice paid from weak or ambiguous evidence.

The project does not accept matching a payment based only on amount.

The project does not accept matching a payment based only on customer identity.

The project does not accept matching a payment based only on invoice number when multiple Kimai instances may share Stripe.

The project does not accept silently changing a Payment Link amount to fit a regenerated invoice with the same number.

The project does not accept automatic deletion/deactivation when Kimai state cannot be determined reliably.

The project does not accept treating a paid orphan as garbage.

The project does not accept automatic paid-to-unpaid reversal merely because of a later Stripe refund or dispute.

The project does not accept public Kimai ingress as a hidden requirement.

The project does not accept Kimai core modification as the normal integration mechanism.

The project does not accept making the plugin a prerequisite for receiving customer payments.

## Open Questions and Follow-Ups

The following questions remain intentionally open and should be resolved through implementation, dedicated ADRs, or live testing.

### Kimai packaging and compatibility

- What exact bundle/plugin structure should be used for Kimai 2.65+?
- What is the minimum Kimai version worth supporting?
- How will compatibility with new Kimai versions be tested before release?
- What parts of the Kimai plugin installation require cache clearing or restart?
- Can plugin disablement be made operationally straightforward enough for incident fallback?
- Should installation be a source directory, release archive, Composer-based operation, or another Kimai-supported approach?

### Stripe client and API version

- Will the project use `stripe/stripe-php` directly?
- What minimum Stripe PHP SDK version is required?
- Should the connector pin `Stripe-Version` on requests?
- What minimum Stripe API version is required if `price_data` is used?
- Should the connector avoid `price_data` to support older Stripe API versions?
- How should API-version incompatibility be detected and reported?

### Payment methods

- Which payment methods will be supported initially?
- Should the first release support cards only?
- Is ACH desirable enough to justify asynchronous lifecycle handling?
- How does single-completed-session limiting behave for each supported asynchronous payment method?
- What customer retry behavior exists after asynchronous failure?
- Should Payment Link creation explicitly set allowed payment methods rather than relying on Stripe's dynamic account settings?

### Money and currencies

- Will the first release support all Stripe/Kimai currencies or a deliberately smaller set?
- What money library or decimal strategy will be used?
- How will zero-decimal currencies be handled?
- How will currencies with unusual minor-unit behavior be handled?
- How will rounding differences between Kimai and Stripe be prevented or surfaced?

### Metadata contract

- What exact key names will be used?
- What are Stripe's current metadata key/value length limits relevant to invoice numbers?
- Should Kimai customer ID be included?
- Should the Kimai invoice database ID be added after creation as optional metadata?
- How will metadata contract v2 be introduced later without losing reconciliation for v1 objects?

### Link lookup and scale

- What Stripe API operation is best for rediscovering managed Payment Links by metadata?
- If Stripe does not provide server-side metadata filtering for Payment Links, how much pagination is acceptable?
- Should Kimai metadata cache the Payment Link ID to avoid repeated scans while preserving Stripe metadata as recovery authority?
- What scale threshold would justify local indexing/persistence?

### Link lifecycle

- Should successful Payment Links remain active after reconciliation if Stripe's single-use restriction already deactivates them?
- Should a manually paid Kimai invoice cause its managed Payment Link to be deactivated?
- What should happen when an inactive exact Payment Link is discovered during invoice retry?
- Should old unpaid orphan links be deactivated after 7 days or another period?
- Should cleanup support `--dry-run` by default or require an explicit mutation flag?

### Reconciliation

- Which exact Stripe timestamps should determine Kimai's payment date?
- Should the reconciler examine only Kimai unpaid invoices or also audit paid invoices for later anomalies?
- If it audits paid invoices, how should refund/dispute warnings be surfaced without overwhelming normal command output?
- How should multiple successful sessions for a malformed or historically reusable Payment Link be handled?
- Should partial payments remain unsupported, or should they be reported specially?
- What should the command do if a payment succeeds for an amount greater than the Kimai invoice?
- How should manually paid invoices be treated when Stripe also later records payment?

### Credentials

- What is the narrowest Stripe restricted-key permission set for Payment Link generation?
- What is the narrowest permission set for reconciliation?
- What is required for cleanup/deactivation?
- Should one key or separate keys be the recommended configuration?
- How will keys be provided without accidental log exposure in common container deployments?

### Observability

- What logging format should be used?
- Should commands support JSON output?
- What exit codes are useful for Jenkins/cron?
- Should manual-review conditions return a nonzero code?
- Is there value in a `kimai:stripe:status` diagnostic command?

### Public/open-source distribution

- What license should be used?
- Does Kimai's AGPL licensing materially constrain the plugin license choice?
- Should the project be published publicly immediately or after local dogfooding?
- Should there be a compatibility table in the README?
- Should a public release be submitted to the Kimai plugin marketplace?
- What support commitment, if any, accompanies public release?

### Revisit one plugin versus two

The one-plugin decision should be revisited if implementation evidence shows that the benefits of package-level isolation exceed the additional maintenance cost.

The decision is particularly likely to deserve review if:

- the plugin grows beyond a small auditable connector;
- the two capabilities acquire independent user communities;
- security requirements favor separate credentials and deployment;
- one capability changes rapidly while the other is stable;
- Kimai compatibility differs by capability;
- Stripe dependencies differ by capability;
- marketplace/distribution concerns favor separation;
- the common code becomes trivially small;
- users need to uninstall one capability entirely rather than merely disable it.

## Validation Required Before Acceptance

This ADR SHOULD move from Proposed to Accepted only after a representative implementation demonstrates the following against a real Kimai test environment and Stripe test mode:

1. The plugin installs using supported Kimai plugin mechanisms.
2. Kimai starts cleanly with the plugin enabled.
3. Invoice preview creates no Stripe object.
4. A real invoice creates or recovers exactly one intended Payment Link.
5. The rendered invoice contains the correct Stripe URL.
6. A failed Kimai render after Stripe creation leaves a recoverable link.
7. Retrying the same unchanged invoice reuses the intended link.
8. A changed invoice amount is detected as a conflict rather than silently mutating the existing link.
9. A customer can successfully pay through Stripe test mode while Kimai remains non-public.
10. Reconciliation can positively identify the exact payment.
11. Reconciliation marks the correct Kimai invoice paid and no other invoice.
12. Reconciliation can run again safely without duplicate side effects.
13. Reconciliation failure leaves Kimai unchanged.
14. Cleanup can identify an unpaid orphan.
15. Cleanup does not deactivate a valid invoice's link.
16. Cleanup does not treat a paid orphan as garbage.
17. Disabling the plugin leaves the manual Kimai + Stripe workflow usable.
18. Secrets do not appear in expected application/plugin logs during normal operation.

Acceptance does not require every future feature or open question to be solved.  It requires enough end-to-end evidence that the selected architecture is real rather than merely plausible.

## Evidence and References

### Kimai 2.65.0 source reviewed during design

Relevant upstream files include:

- `src/Invoice/InvoiceService.php`
- `src/Invoice/InvoiceModel.php`
- `src/Invoice/InvoiceModelHydrator.php`
- `src/Invoice/Renderer/AbstractTwigRenderer.php`
- `src/Event/InvoiceCreatedEvent.php`
- `src/Entity/Invoice.php`
- `src/Repository/InvoiceRepository.php`

Source tag/reference used during the initial review:

- <https://github.com/kimai/kimai/tree/2.65.0>

### Stripe documentation reviewed during design

- Payment Link creation: <https://docs.stripe.com/api/payment-link/create>
- Payment Link customization and completed-session limit: <https://docs.stripe.com/payment-links/customize>
- Payment Link update/deactivation: <https://docs.stripe.com/api/payment-link/update>
- Checkout Session listing and `payment_link` filter: <https://docs.stripe.com/api/checkout/sessions/list>
- Ad-hoc Payment Link `price_data` API change: <https://docs.stripe.com/changelog/basil/2025-07-30/ad-hoc-prices-for-payment-links>

These references document the design assumptions at the time of this ADR.  Stripe behavior and Kimai extension APIs can change, so implementation and release testing remain authoritative for supported versions.

## Related Decisions

- Related to: ADR-000: Capability Scope, Epistemic Honesty, and Separation of Concerns

Future related ADRs are expected for subjects such as:

- plugin/package structure;
- Stripe API version and dependency policy;
- metadata contract;
- security/credential model;
- supported payment methods;
- licensing and open-source distribution;
- release and compatibility policy.
