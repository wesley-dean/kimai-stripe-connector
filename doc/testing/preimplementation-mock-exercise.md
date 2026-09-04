# Pre-Implementation Kimai / Stripe Mock Exercise

Date: 2026-09-03

## Status and Purpose

This document preserves the pre-implementation mock exercise that informed ADR-001, "Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation."

The exercise was performed before implementation code existed in this repository.  Its purpose was architectural exploration: to test whether the proposed Kimai/Stripe interaction model had obvious correctness failures, race conditions, recovery gaps, or unsafe accounting behavior before committing to a plugin design.

This document is intentionally detailed.  It is a design-evidence record, not a concise test report.

## Evidence Classification

The evidence in this document MUST NOT be confused with repository CI evidence.

The original conversational mock harness, exact source code, random seeds, individual test-function names, and raw terminal output were not committed to this repository.  The surviving record consists of the scenarios, counts, behaviors, and conclusions reported during the design conversation.

Accordingly:

- the reported totals are historical design evidence;
- the scenario descriptions are a reconstruction of what was exercised and discussed;
- the results are useful for shaping implementation tests;
- the results are not independently reproducible from this repository today;
- the project SHALL recreate the important cases as executable tests before the corresponding behavior is considered implemented;
- if a future repository test contradicts this document, the executable repository test and current upstream behavior take precedence;
- this document should then be updated to preserve the history and explain the discrepancy rather than silently rewriting the past.

The mock exercise reported:

```text
Behavioral/adversarial layer:          89 passed, 0 failed
HTTP/network/concurrency layer:         5 passed, 0 failed
Stateless correlation/recovery layer:   9 passed, 0 failed
                                        -------------------
Total:                                 103 passed, 0 failed
```

In addition, two larger-scale exercises were reported:

```text
Money conversion sweep:       100,000 cent values exercised
Stripe-link distractor test:   10,000 unrelated Payment Links plus one exact match
Concurrent creation test:      20 simultaneous attempts for one logical invoice
```

These larger-scale exercises are supporting probes rather than additional independently counted tests in the 103 total.

## Questions the Mock Exercise Tried to Answer

The exercise was designed around several architectural questions.

### Can a Stripe Payment Link be created early enough to appear on the Kimai invoice?

Kimai creates the document before it creates and persists the final `Invoice` entity.  The proposed architecture therefore uses invoice pre-render behavior to obtain the Stripe URL and invoice-model hydration to expose that URL to the renderer.

The mock treated Payment Link creation as a pre-render concern and tested the consequences of failure after Stripe had already accepted the creation request.

### Can invoice previews avoid mutating Stripe?

Preview rendering must not create real remote payment artifacts.

The mock explicitly treated preview suppression as an invariant.

### Can retries avoid duplicate Payment Links?

A network timeout is ambiguous.  Stripe might have committed the object even when the connector never receives the response.

The mock exercised timeout-after-commit behavior, deterministic/idempotent retry behavior, and later recovery through stable metadata.

### Can the connector operate without a local Stripe mapping ledger?

The exercise explored whether Stripe metadata could be sufficient to rediscover the Payment Link corresponding to a Kimai invoice.

This led to the design preference for largely stateless correlation, with optional Kimai metadata as a convenience rather than the sole source of truth.

### Can reconciliation be conservative enough to avoid false paid-state transitions?

The exercise intentionally treated "do nothing" as the safe result for ambiguous, incomplete, mismatched, refunded, disputed, partial, or otherwise contradictory Stripe state.

### Can orphaned Payment Links be handled safely?

The mock explored the case where Stripe succeeds but Kimai rendering or persistence fails, leaving a Payment Link without a completed Kimai invoice.

The important result was that an unpaid orphan can be rediscovered and reused or eventually deactivated, while a paid orphan must be treated as an accounting exception.

### Can concurrency collapse to one logical Payment Link?

The HTTP-layer exercise included multiple simultaneous attempts to create a Payment Link for the same logical invoice.

The reported result was one Stripe Payment Link shared by all successful logical outcomes rather than one link per contender.

## Proposed System Model Used by the Exercise

The conceptual system under test was:

```text
+-------------------+
|      Kimai        |
|                   |
| invoice model     |
| invoice number    |
| amount/currency   |
| paid state        |
+---------+---------+
          |
          | pre-render event
          v
+-------------------+
| Kimai Stripe      |
| Connector         |
|                   |
| identity          |
| money conversion  |
| metadata          |
| link service      |
| reconciler        |
| cleanup logic     |
+---------+---------+
          |
          | outbound HTTPS/API
          v
+-------------------+
|      Stripe       |
|                   |
| Payment Link      |
| Checkout Session  |
| PaymentIntent     |
| Charge            |
+-------------------+
```

No inbound webhook path was part of the model.

The customer-facing payment path was modeled as Stripe-hosted:

```text
Kimai-generated invoice
        |
        | embedded payment URL
        v
Stripe Payment Link
        |
        v
Stripe Checkout
        |
        v
Stripe payment processing
```

Kimai was not modeled as a public payment endpoint.

## Authority Model Used by the Exercise

The mock assumed the following ownership boundaries.

Kimai owns:

- invoice identity;
- invoice number;
- invoice total;
- invoice currency;
- whether its own invoice record is marked paid;
- payment date once reconciled.

Stripe owns:

- Payment Link creation and lifecycle;
- Checkout Session state;
- PaymentIntent state;
- Charge state;
- actual payment/refund/dispute information.

The connector owns:

- its metadata namespace;
- the correlation algorithm;
- the decision rule for when Stripe evidence is sufficient to change Kimai;
- safe retry and cleanup behavior.

The mock did not model the connector as a third payment ledger.

## Core Invariants

The exercise was organized around invariants more than around a particular class design.

### Preview invariant

```text
IF invoice is a preview
THEN Stripe mutation count must remain zero.
```

### Exact-identity invariant

A Stripe object must not be associated with a Kimai invoice solely because the amount, customer, date, or invoice number looks plausible.

The intended correlation includes at least:

```text
connector namespace
+ metadata version
+ Kimai instance identity
+ invoice number
+ amount
+ currency
```

### Money invariant

Stripe amount values must represent the exact intended invoice amount in the currency's required units.

The mock included a large value-conversion sweep to look for floating-point conversion defects.

The final implementation is still required to use an explicit money strategy rather than relying on the mock result.

### No silent financial mutation invariant

If an existing Stripe Payment Link identifies the same logical invoice but its financial data differs, the connector must stop rather than modify or reuse the link silently.

### Idempotent creation invariant

Repeated attempts to create the logical Payment Link for one invoice should converge on one logical remote object even when the response to an earlier attempt was lost.

### No weak reconciliation invariant

The presence of any one of the following is insufficient by itself to mark Kimai paid:

- a Payment Link;
- a Checkout Session;
- a completed Checkout Session;
- a PaymentIntent with incomplete evidence;
- a Charge with inconsistent evidence;
- an amount match without exact metadata identity.

### False-negative preference

When evidence is incomplete or contradictory:

```text
leave Kimai unpaid
```

is preferred to:

```text
mark Kimai paid speculatively
```

### No automatic reverse-accounting invariant

A later refund or dispute must not automatically rewrite a previously reconciled Kimai invoice from paid to unpaid/canceled.

### Cleanup safety invariant

If the cleanup process cannot positively establish that a managed Payment Link is both orphaned and unpaid, it must retain the link.

### Paid-orphan invariant

A Stripe Payment Link with successful payment evidence and no matching Kimai invoice is not garbage.

It requires manual accounting review.

## Mock Layer 1: Behavioral and Adversarial Model

### Reported result

```text
89 passed
0 failed
```

This layer modeled the connector behavior directly with synthetic Kimai and Stripe state.  It was intended to explore decision logic and state transitions quickly without depending on a real network service.

The exact implementation language and individual test names are not retained in the repository record, so this section documents the behaviors that were reported as covered.

### Invoice preview suppression

The mock included invoice-preview behavior.

Expected result:

```text
preview=true
-> no Payment Link lookup that mutates state
-> no Payment Link creation
-> no remote object left behind
```

The reported result satisfied that expectation.

Implementation follow-up:

- create a Kimai integration test using the real `InvoiceModel::isPreview()` behavior;
- assert that the fake Stripe client records zero mutation calls.

### Canonical invoice identity

The model used explicit invoice identity rather than fuzzy matching.

Representative data included:

```text
kimai_instance=<stable instance identifier>
invoice_number=<invoice number>
amount=<minor units>
currency=<normalized currency>
```

The exercise treated the instance identifier as necessary to avoid collisions such as:

```text
Kimai instance A -> INV-0042
Kimai instance B -> INV-0042
```

when both instances share one Stripe account.

### Exact money conversion

The behavioral layer included money conversion checks, supplemented by a larger sweep described later.

The purpose was to prevent bugs such as:

```text
875.10 -> 87509
```

or:

```text
875.10 -> 87511
```

caused by naive binary floating-point arithmetic.

The reported mock behavior produced exact expected cent values for the exercised USD-like cases.

This result does **not** settle support for zero-decimal or unusual-minor-unit currencies.

### Duplicate invoice preflight

The mock included behavior intended to prevent Payment Link creation for an invoice identity that Kimai already recognizes as a completed/persisted invoice.

The exact placement of this check relative to Kimai's own duplicate-number check remains an implementation detail because Kimai dispatches its pre-render event before its internal `hasInvoice()` duplicate check.

The design implication is important: the plugin cannot blindly assume Kimai's later duplicate-number check will prevent an unnecessary Stripe mutation.  The plugin may need its own preflight check or robust recovery semantics.

### Existing exact Payment Link reuse

The model exercised an existing Payment Link that matched:

- connector namespace;
- Kimai instance;
- invoice number;
- amount;
- currency.

Expected result:

```text
reuse existing URL
create zero additional links
```

The reported result matched that expectation.

### Existing amount mismatch

Representative condition:

```text
existing Stripe object:
  invoice_number=INV-0042
  amount=87500
  currency=usd

current Kimai invoice:
  invoice_number=INV-0042
  amount=90000
  currency=usd
```

Expected result:

```text
CONFLICT
-> do not reuse
-> do not mutate old link automatically
-> do not create a second competing link silently
-> require operator resolution
```

The reported mock behavior failed closed.

### Existing currency mismatch

Representative condition:

```text
existing Stripe object:
  invoice_number=INV-0042
  amount=87500
  currency=usd

current Kimai invoice:
  invoice_number=INV-0042
  amount=87500
  currency=eur
```

Expected result:

```text
CONFLICT
```

The reported behavior failed closed.

### Wrong Kimai instance

The model included plausible Stripe objects for the same invoice number but another Kimai instance.

Expected result:

```text
ignore as unrelated
```

The reported behavior preserved instance namespace separation.

### Wrong invoice metadata

The model included Stripe objects with:

- correct amount but wrong invoice number;
- correct invoice number but wrong connector namespace;
- otherwise plausible values without the exact metadata identity.

Expected result:

```text
never treat as the target payment
```

The reported behavior rejected those distractors.

### Stripe API failure before creation

The model included a remote error in which Stripe clearly failed to create a Payment Link.

Expected result:

```text
invoice automation does not invent a URL
Kimai paid state unchanged
operator can use manual fallback
```

The reported behavior did not fabricate success.

### Malformed Payment Link response

The model included malformed or incomplete Stripe responses.

Examples conceptually include missing:

- link ID;
- URL;
- expected amount/currency information;
- required identity metadata.

Expected result:

```text
reject response
no false success
```

The reported behavior failed closed.

### Timeout before known commit

The model exercised timeout behavior where the connector could not know whether Stripe committed the object.

The design conclusion was that retry logic must assume ambiguity rather than interpreting timeout as definite failure.

### Timeout after Stripe commit

This was one of the most important modeled failure modes.

Sequence:

```text
connector -> create Payment Link
Stripe -> commits Payment Link
network -> response lost
connector -> observes timeout
connector -> retries
```

Expected result:

```text
recover original logical link
create no duplicate logical payment surface
```

The mock reported successful recovery when the retry used the same logical identity/idempotency strategy.

This behavior was tested more concretely again in the HTTP layer.

### Invoice-template hydration

The behavioral model included making the Payment Link URL available to invoice rendering.

The intended structure later became:

```text
InvoicePreRenderEvent
-> obtain link
-> put link information on InvoiceModel
-> InvoiceModelHydrator exposes data
-> Twig renders invoice.stripe.payment_url
```

The mock treated successful hydration as a prerequisite for invoice output containing the URL.

### Kimai persistence after successful Stripe creation

The model included the normal case:

```text
Stripe link created/recovered
-> Kimai invoice generated
-> invoice persisted
```

The resulting mapping remained recoverable through Stripe metadata.

### Kimai rendering/save failure after Stripe creation

This modeled the architectural blemish discussed in ADR-001.

Sequence:

```text
Stripe link created
-> Kimai render or persistence fails
-> no completed Kimai invoice
-> link remains in Stripe
```

Expected result:

```text
no false Kimai invoice
no false paid state
link remains recoverable
```

The mock exposed this as a real partial-failure condition rather than masking it.

The later stateless layer tested recovery from this state more directly.

### Delayed/unpaid Checkout Session

The model included sessions that existed but were not actually paid.

Expected result:

```text
Kimai remains unpaid
```

The reported behavior did not treat mere Checkout existence as payment.

### Checkout complete without sufficient payment evidence

The model treated Checkout completion as insufficient by itself.

Expected result:

```text
no paid transition unless payment evidence also satisfies the policy
```

This aligns with Stripe's distinction between Checkout completion and final payment processing.

### PaymentIntent not succeeded

The model included PaymentIntents in states that did not establish successful payment.

Expected result:

```text
Kimai remains unpaid
```

The reported behavior failed closed.

### Amount received mismatch

A PaymentIntent or other Stripe evidence reporting a received amount different from the Kimai invoice amount was treated as a mismatch.

Expected result:

```text
Kimai remains unpaid
manual review/conflict reporting
```

### Partial payment

Partial payment conditions were exercised.

Expected result:

```text
not fully paid
-> do not mark Kimai paid automatically
```

The connector did not attempt to create a partial-payment ledger.

### Wrong currency during reconciliation

A payment in the wrong currency was rejected even if the numeric amount looked equivalent or plausible.

Expected result:

```text
no paid transition
```

### Wrong metadata during reconciliation

A successful payment with metadata identifying another invoice or instance was treated as unrelated.

Expected result:

```text
no paid transition for target invoice
```

### Unsuccessful PaymentIntent

Unsuccessful intents were explicitly included among the adversarial states.

Expected result:

```text
no paid transition
```

### Uncaptured Charge

A Charge that had not satisfied the required capture semantics was included.

Expected result:

```text
no paid transition
```

This preserves the distinction between authorization-like states and completed payment evidence.

### Refunded Charge

A refunded payment was included.

For an invoice not yet reconciled as paid, refunded evidence was not treated as sufficient to mark the invoice paid.

For an invoice already marked paid, the architectural decision later became:

```text
report refund
-> do not automatically rewrite Kimai to unpaid
```

### Partial refund

Partial-refund conditions were included.

Expected automatic action:

```text
no speculative accounting rewrite
```

The exact future reporting format remains open.

### Disputed Charge

A disputed payment was included.

Expected behavior:

```text
if not yet reconciled, do not use disputed evidence for automatic paid transition
if already reconciled, report dispute rather than reversing automatically
```

### Ambiguous multiple candidate payments

The model included multiple plausible candidates where exact automatic selection could not be proven.

Expected result:

```text
AMBIGUOUS
-> no Kimai state change
```

This is a central fail-closed behavior.

### Reconciliation persistence failure

The model included the case where Stripe evidence was sufficient but saving the paid state in Kimai failed.

Expected result:

```text
Stripe remains unchanged
Kimai may remain unpaid
later reconciliation retries from observed current state
```

The design avoids a separate connector transaction ledger whose commit could become a third source of ambiguity.

### Shell metacharacters and hostile-looking strings

The reported adversarial suite included text containing shell metacharacters or otherwise awkward input.

The purpose was to ensure that invoice/customer/metadata values are treated as data rather than shell syntax or executable command fragments.

The exact strings are not retained in the record.

Implementation follow-up:

- add representative punctuation, Unicode, whitespace, quote, ampersand, pipe, semicolon, dollar-sign, backtick, and newline cases to metadata/CLI-output tests where upstream limits permit them;
- do not construct shell commands from financial/customer input.

### Large sets of plausible distractors

The behavioral exercise included unrelated Stripe state intended to look plausible enough to catch overly broad matching.

This was expanded substantially in the stateless correlation layer.

Expected result:

```text
exact identity wins
plausibility does not
```

## Money Conversion Sweep

### Reported scale

The design conversation reported a sweep over:

```text
100,000 cent values
```

with no conversion mismatch reported for the modeled USD-like two-decimal currency behavior.

### Intended property

For a value intended to represent exactly N cents, the Stripe amount should be:

```text
N
```

and not a neighboring value caused by floating-point representation.

### What this exercise proved weakly

It provided confidence that the particular modeled conversion routine did not exhibit an obvious off-by-one-cent defect across the exercised range.

### What it did not prove

It did not prove:

- support for every currency;
- correctness for zero-decimal currencies;
- correctness for three-decimal currencies or special settlement rules;
- correctness of Kimai's own upstream float representation for every possible invoice calculation;
- correct tax/rounding interaction;
- correct behavior for arbitrarily large invoice totals;
- correctness of whatever money implementation the repository ultimately chooses.

The repository implementation must therefore treat this sweep as a test-design seed, not as a license to use floating-point arithmetic casually.

## Mock Layer 2: HTTP, Network Failure, and Concurrency

### Reported result

```text
5 passed
0 failed
```

This layer placed an HTTP boundary between the connector-like logic and a local fake Stripe service.

The purpose was to test behaviors that an in-memory fake can easily miss:

- request/response boundaries;
- connection failure;
- response loss after remote mutation;
- idempotent retry behavior;
- concurrent requests.

The local HTTP fake was intended to act like Stripe only for the narrow behaviors under test.  It was not a complete Stripe emulator.

### Normal HTTP creation

The baseline request successfully created the modeled Payment Link and returned the expected identifier/URL.

The connector accepted only a response satisfying the expected contract.

### HTTP failure before commit

A request failure before the fake service committed the logical object was modeled.

Expected result:

```text
no remote object exists
retry can create one normally
```

### Connection dropped after commit

This was the key network experiment.

The fake service intentionally behaved as follows:

```text
receive create request
-> commit logical Payment Link
-> terminate/drop connection before client receives successful response
```

The connector therefore observed a network failure despite remote success.

A retry using the same logical idempotency identity recovered the already-created link.

Reported outcome:

```text
one logical Payment Link
no duplicate from retry
```

This exercise materially changed the design posture: timeout handling must be recovery-oriented rather than "retry means create another."

### Repeated safe retry

The layer also exercised repeated requests for the same logical creation operation.

Expected result:

```text
same logical object/result
```

rather than accumulating new Payment Links.

### Twenty-way concurrent creation

The HTTP exercise issued:

```text
20 simultaneous creation attempts
```

for the same logical invoice.

Reported outcome:

```text
all logical attempts converged on one Payment Link
```

The point was not to establish Stripe's production implementation details.  It was to prove that the connector design can have a concurrency contract in which competing callers share one logical idempotency identity and do not intentionally mint 20 payment surfaces.

Implementation follow-up:

- recreate this with the actual Stripe client abstraction and a deterministic fake server;
- test the race against Stripe test mode if feasible without creating brittle tests;
- test whether local lookup-before-create can race and ensure the remote create request remains idempotent;
- test reconciliation concurrency separately.

## Mock Layer 3: Stateless Correlation and Recovery

### Reported result

```text
9 passed
0 failed
```

This layer explored whether the connector could avoid depending on a local Kimai-side Stripe mapping ledger.

The key idea was:

> Store enough stable identity information in Stripe metadata that the connector can rediscover the correct Payment Link later, even if Kimai failed after Stripe creation.

### Proposed metadata identity

The conversational design used a shape similar to:

```text
integration=kimai-stripe-v1
kimai_instance=<stable UUID>
invoice_number=<invoice number>
customer_id=<optional Kimai customer identity>
amount=<minor units>
currency=<currency>
```

The exact production keys were later left open for ADR/implementation refinement.

### Recovery after Kimai failure

Modeled sequence:

```text
first attempt:
  invoice INV-0042
  -> create plink_A
  -> Kimai render/save fails
  -> no completed invoice record

later retry:
  invoice INV-0042
  same instance
  same amount
  same currency
  -> search Stripe-managed links
  -> find plink_A
  -> validate exact financial identity
  -> reuse plink_A
  -> do not create plink_B
```

Reported result:

```text
successful reuse
```

This is the central reason the orphan condition was downgraded from a major architectural flaw to a manageable partial-failure artifact.

### Recovery independent of old idempotency-key lifetime

Stripe idempotency keys have finite retention semantics outside the connector's control.

The stateless recovery model therefore does not depend on an old idempotency key being retrievable forever.

After the immediate retry window, stable metadata becomes the durable recovery mechanism.

### Exact match among 10,000 unrelated Payment Links

The exercise populated the fake Stripe side with:

```text
10,000 unrelated Payment Links
+ 1 exact intended match
```

The lookup logic selected only the exact match.

The purpose was to detect matching logic that accidentally chose:

- the first equal amount;
- the first equal invoice number;
- a customer-name match;
- a temporally nearby link;
- another Kimai instance's object;
- a partial metadata match.

Reported result:

```text
exact intended link selected
```

### No exact match

When no Payment Link satisfied the complete identity/financial contract, the model treated the state as "not found" rather than choosing a near match.

Expected next action:

```text
create new link if there is no conflicting identity evidence
```

### One exact match plus distractors

When one exact link and many plausible distractors existed, the exact link was reused.

### Conflicting same-identity amount

If an object identified the same Kimai instance/invoice but disagreed on amount, the model returned conflict rather than not-found.

That distinction matters because silently treating it as not-found and creating another active link could leave two payment surfaces for one invoice identity.

### Conflicting same-identity currency

The same fail-closed behavior applied to currency mismatch.

### Multiple exact/ambiguous matches

Where multiple managed objects could not be disambiguated safely, the model treated the result as an error/manual-review condition rather than choosing arbitrarily.

### Paid orphan

The stateless model distinguished:

```text
orphaned link + no payment
```

from:

```text
orphaned link + successful payment evidence
```

The latter was never eligible for automatic garbage collection.

### Unpaid stale orphan

An old, connector-managed, unpaid Payment Link with no corresponding Kimai invoice was eligible for eventual deactivation after a grace period.

The cleanup decision remained contingent on Kimai being queryable and the absence of payment evidence.

## Reconciliation Model in More Detail

The mock exercise treated reconciliation as a conservative proof problem.

The question was not:

> Is there something in Stripe that looks like this invoice?

The question was:

> Is there enough mutually consistent Stripe evidence to justify changing Kimai's accounting-facing invoice state automatically?

### Expected successful evidence chain

The conceptual chain was:

```text
Kimai invoice
   |
   v
managed Payment Link
   |
   v
Checkout Session
   |
   v
PaymentIntent
   |
   v
Charge
```

The exact production implementation may vary by Stripe payment method and API shape, but the mock required consistency across multiple layers rather than trusting one convenient field.

### Checkout Session checks

The modeled policy expected the relevant session to satisfy conditions such as:

- associated with the intended Payment Link;
- correct connector/invoice identity;
- correct amount;
- correct currency;
- completion state consistent with successful checkout;
- payment status consistent with payment.

### PaymentIntent checks

The modeled policy expected the relevant PaymentIntent to satisfy conditions such as:

- referenced by the intended session;
- successful state;
- correct amount;
- correct amount received;
- correct currency;
- consistent connector/invoice identity where available.

### Charge checks

The modeled policy expected the relevant Charge to satisfy conditions such as:

- belongs to the intended PaymentIntent;
- paid;
- captured where capture semantics require it;
- correct amount;
- correct captured amount;
- not refunded;
- zero refunded amount in the clean happy path;
- no dispute condition that undermines automatic confidence.

### Fail-closed examples

Each of the following was intended to yield no automatic paid transition:

```text
no Payment Link
wrong Payment Link
wrong Kimai instance
wrong invoice number
wrong amount
wrong currency
Checkout open
Checkout complete but payment unpaid
PaymentIntent processing
PaymentIntent failed
PaymentIntent canceled
amount_received too small
uncaptured Charge
partial payment
full refund
partial refund
active dispute
multiple ambiguous candidates
malformed Stripe state
Kimai save failure
```

## Cleanup Model in More Detail

The cleanup mock was based on a deliberately asymmetric safety policy.

The connector is allowed to leave garbage behind when uncertain.

It is not allowed to destroy a potentially legitimate payment surface merely to keep Stripe tidy.

### Candidate selection

Only Payment Links positively identified as:

- created/managed by this connector metadata namespace; and
- belonging to this Kimai instance

are cleanup candidates.

Unmanaged Stripe Payment Links are out of scope.

### Decision table

| Condition | Modeled action |
| --- | --- |
| Matching Kimai invoice exists | Keep |
| Kimai unavailable/uncertain | Keep |
| No Kimai invoice, link recent | Keep |
| No Kimai invoice, successful payment evidence | Keep and alert |
| No Kimai invoice, no payment, older than grace period | Deactivate |
| Metadata ambiguous | Keep and report |
| Link belongs to other Kimai instance | Ignore |

### Why deactivation rather than deletion

Stripe Payment Links support deactivation.  The design prefers deactivation because it closes the active payment surface while preserving historical evidence that the object existed.

The mock treated deletion-like erasure as unnecessary.

## Failure Taxonomy Used by the Exercise

The mock work implicitly separated failures into categories.  The repository implementation should make these categories explicit because they deserve different logs and exit behavior.

### Expected no-op

Examples:

- invoice preview;
- unpaid invoice with no successful Stripe payment yet;
- already-reconciled invoice where Stripe agrees;
- valid long-lived unpaid invoice during cleanup.

### Retryable operational failure

Examples:

- Stripe timeout;
- transient HTTP 5xx;
- Kimai temporary persistence failure;
- rate limit;
- temporary network loss.

### Configuration failure

Examples:

- missing Stripe credential;
- wrong API mode/account;
- unsupported Stripe API version;
- missing stable Kimai instance identity;
- unsupported currency configuration.

### Data conflict

Examples:

- same invoice identity, different amount;
- same invoice identity, different currency;
- multiple exact/ambiguous Stripe mappings;
- malformed metadata contract.

### Accounting exception

Examples:

- paid Stripe orphan;
- refund after reconciliation;
- dispute after reconciliation;
- unexpected overpayment;
- partial payment if unsupported.

### Successful mutation

Examples:

- Payment Link created;
- exact Payment Link recovered/reused;
- orphan Payment Link deactivated;
- Kimai invoice marked paid.

## What the Mock Exercise Changed in the Architecture

The exercise was not merely confirmatory.  It changed the proposed design.

### Change 1: treat Payment Link creation as recoverable, not transactional with Kimai

It is impossible to make Stripe object creation and Kimai invoice persistence one atomic database transaction.

The mock made that boundary explicit.

The chosen response is recovery, not pretend atomicity.

### Change 2: prefer Stripe metadata as durable correlation evidence

Earlier design thinking leaned more heavily toward saving the Stripe Payment Link ID into Kimai immediately.

The partial-failure exercise showed that Stripe may contain the object before the final Kimai invoice exists.

Stable Stripe metadata therefore became more important than local persistence for recovery.

### Change 3: make local connector state optional rather than fundamental

The ability to rediscover links by identity means a connector-specific mapping table is not obviously necessary.

This supports a smaller plugin and fewer migrations.

### Change 4: classify orphan links by payment state

The exercise clarified that:

```text
unpaid orphan = cleanup candidate
paid orphan = accounting exception
```

Those states must never be handled by the same automatic cleanup rule.

### Change 5: separate single-use link safety from payment proof

The Payment Link's completed-session limit can reduce duplicate customer payments.

It does not replace reconciliation evidence.

### Change 6: preserve one-way accounting automation

The exercise reinforced the rule that automatically setting paid from strong evidence is fundamentally different from automatically undoing paid later because Stripe reports a refund or dispute.

The latter remains manual-review territory.

## What the Mock Exercise Did Not Prove

This section is deliberately prominent because the reported 103/103 result can otherwise create false confidence.

The mocks did **not** prove that a real Kimai plugin will load.

They did not prove:

- Symfony service/container configuration;
- Kimai bundle discovery;
- plugin manifest correctness;
- event-subscriber registration;
- model-hydrator registration;
- Twig sandbox behavior with the chosen values;
- real invoice PDF generation;
- compatibility with every Kimai invoice renderer;
- cache-clear/install lifecycle;
- compatibility with future Kimai versions.

The mocks did **not** prove real Stripe API compatibility.

They did not prove:

- required `stripe/stripe-php` version;
- account API-version behavior;
- exact `price_data` request shape in the selected API version;
- restricted-key permission sufficiency;
- real Stripe pagination behavior under account-scale conditions;
- every current Payment Link field/constraint;
- behavior of every payment method;
- real rate-limit handling;
- real test-clock behavior;
- exact dispute/refund test fixtures.

The mocks did **not** prove correct accounting for all payment methods.

In particular, asynchronous methods require separate treatment of:

- Checkout completion;
- processing state;
- eventual success;
- eventual failure;
- retry opportunity after a one-completed-session limit is consumed.

The mocks did **not** prove multi-currency correctness.

The 100,000-value sweep was a cent-oriented probe, not a comprehensive currency model.

The mocks did **not** prove production-scale lookup performance.

The 10,000-distractor test exercised decision correctness in a synthetic data set.  It did not measure real Stripe API pagination latency, rate limits, or cost.

The mocks did **not** prove security.

They did not replace:

- dependency scanning;
- secret-handling review;
- log review;
- least-privilege key configuration;
- static analysis;
- threat modeling;
- code review.

The mocks did **not** prove exactly-once behavior.

The design goal is idempotent/recoverable behavior, not a distributed exactly-once guarantee that Stripe and Kimai cannot jointly provide.

The mocks did **not** prove that the historical 103 cases can still be reproduced.

That is precisely why the project must convert this document into repository tests.

## Required Repository Test Reification

The historical mock record should become a test backlog.

The project SHOULD create traceable repository tests with stable identifiers so future documentation can say exactly which mock-derived cases are represented by executable tests.

A suggested organization follows.

### Payment Link unit tests

```text
PL-001 preview creates no remote mutation
PL-002 exact existing link is reused
PL-003 missing link is created
PL-004 amount mismatch fails closed
PL-005 currency mismatch fails closed
PL-006 wrong instance is ignored
PL-007 wrong invoice metadata is ignored
PL-008 multiple ambiguous matches fail closed
PL-009 malformed Stripe response fails closed
PL-010 deterministic idempotency identity is stable
PL-011 timeout recovery does not intentionally duplicate
PL-012 renderer hydration exposes only intended safe values
```

### Money tests

```text
MNY-001 exact USD cents
MNY-002 boundary values
MNY-003 zero amount policy
MNY-004 negative amount policy
MNY-005 zero-decimal currency behavior
MNY-006 supported non-two-decimal currency behavior
MNY-007 rounding mismatch detection
MNY-008 large invoice amount bounds
```

### Reconciliation tests

```text
REC-001 no link -> no change
REC-002 link without session -> no change
REC-003 checkout open -> no change
REC-004 checkout complete but unpaid -> no change
REC-005 PaymentIntent processing -> no change
REC-006 PaymentIntent failed -> no change
REC-007 wrong amount -> no change/conflict
REC-008 wrong currency -> no change/conflict
REC-009 wrong instance -> no change
REC-010 wrong invoice -> no change
REC-011 partial payment -> no paid transition
REC-012 uncaptured charge -> no paid transition
REC-013 refunded charge -> no paid transition for unreconciled invoice
REC-014 disputed charge -> no paid transition for unreconciled invoice
REC-015 exact successful evidence -> mark paid
REC-016 rerun after paid -> idempotent no-op
REC-017 Kimai persistence failure -> no false success
REC-018 already manually paid -> preserve state
REC-019 later refund after paid -> report only
REC-020 later dispute after paid -> report only
REC-021 paid orphan -> manual-review condition
REC-022 multiple candidates -> no automatic selection
```

### Cleanup tests

```text
CLN-001 valid invoice link -> retain
CLN-002 Kimai unavailable -> retain
CLN-003 recent orphan -> retain
CLN-004 old unpaid orphan -> deactivate
CLN-005 paid orphan -> retain + alert
CLN-006 other-instance link -> ignore
CLN-007 unmanaged link -> ignore
CLN-008 malformed metadata -> retain/report
CLN-009 dry run -> no mutation
```

### HTTP/network tests

```text
NET-001 normal create
NET-002 failure before commit
NET-003 connection drop after commit
NET-004 retry after ambiguous response recovers logical object
NET-005 repeated retry remains idempotent
NET-006 rate-limit response
NET-007 transient 5xx retry policy
NET-008 malformed HTTP body
```

### Concurrency tests

```text
CON-001 20 simultaneous creates -> one logical link
CON-002 simultaneous reconciliation -> one monotonic paid outcome
CON-003 cleanup racing invoice retry does not deactivate valid recovered link
CON-004 manual paid update racing reconciliation remains safe
```

### Kimai integration tests

```text
KIM-001 plugin installs
KIM-002 service container compiles
KIM-003 subscriber is registered
KIM-004 hydrator is registered
KIM-005 preview creates no Stripe object
KIM-006 real invoice contains payment URL
KIM-007 render failure after create leaves recoverable orphan
KIM-008 exact retry reuses orphan
KIM-009 amount-changing retry fails closed
KIM-010 reconciliation updates paid state
KIM-011 payment date set according to policy
KIM-012 plugin can be disabled and manual workflow remains viable
```

### Stripe test-mode tests

```text
STR-001 real test Payment Link creation
STR-002 working hosted URL
STR-003 successful card payment
STR-004 declined card
STR-005 customer retry after decline
STR-006 one-completed-session behavior
STR-007 deactivated link behavior
STR-008 exact session/payment reconciliation
STR-009 refund after payment
STR-010 dispute fixture if supported
STR-011 asynchronous payment lifecycle for each supported asynchronous method
```

The identifiers above are proposed documentation aids, not existing test names.

## Acceptance Relationship to ADR-001

ADR-001 remains Proposed partly because this mock exercise is synthetic and non-reproducible from the repository.

The mock evidence is sufficient to justify implementation exploration.

It is not sufficient to justify production confidence.

Before ADR-001 becomes Accepted, the project should demonstrate at least the end-to-end conditions listed in ADR-001's "Validation Required Before Acceptance" section and should recreate the highest-risk mock cases as executable tests.

## Recommended Interpretation of the 103/103 Result

The correct interpretation is:

> No contradiction was found in the modeled architecture across the reported 103 synthetic cases, and the exercise exposed useful failure modes that improved the design.

The incorrect interpretation is:

> The connector has 103 passing tests and is proven safe.

There was no repository connector implementation at the time.

The value of the exercise was architectural falsification, not release certification.

## Historical Conclusions from the Exercise

The principal conclusions carried forward into ADR-001 were:

1. The one-plugin architecture is technically plausible.
2. Payment Link creation can be modeled as a recoverable pre-render operation.
3. Invoice previews must suppress Stripe mutation.
4. Stable connector metadata is essential.
5. A stable Kimai instance identifier prevents cross-instance invoice-number collisions.
6. Stripe idempotency is useful for immediate retry but should not be the sole durable recovery mechanism.
7. An orphaned unpaid Payment Link is manageable operational debris.
8. A paid orphan is an accounting exception and must never be garbage-collected automatically.
9. Exact amount and currency agreement are required for link reuse and reconciliation.
10. Ambiguous state should leave Kimai unchanged.
11. Reconciliation should be repair-oriented and retryable.
12. The automatic state transition should initially be monotonic toward paid only.
13. Refunds and disputes should be surfaced, not automatically translated into Kimai state reversal.
14. Cleanup should fail safe by retaining links when Kimai state cannot be proven.
15. One-completed-session Payment Link restrictions reduce duplicate-payment exposure but do not prove successful payment.
16. The plugin can remain largely stateless if Stripe metadata is used as durable correlation evidence.
17. The most significant residual risk is plugin/API lifecycle compatibility, not an obvious flaw in the payment-correlation algorithm.
18. The manual workflow remains the operational escape hatch and should never be removed.

## Future Updates to This Document

When executable tests are added, this document SHOULD be updated to map each historical scenario to:

- repository test file;
- test identifier/name;
- commit or release where it became executable;
- whether the executable test preserved or contradicted the historical result.

When a historical claim cannot be recreated, the document SHOULD record that plainly.

When an upstream Kimai or Stripe behavior changes, the document SHOULD preserve the original mock assumption and add the newer evidence rather than rewriting history to make the old mock appear prescient.

## Related Documents

- [ADR-001: Use a Single Kimai Plugin for Stripe Payment Links and Reconciliation](../adr/ADR-001-single-plugin-architecture.md)
- [ADR-000: Capability Scope, Epistemic Honesty, and Separation of Concerns](../adr/ADR-000-capability-scope-and-epistemic-honesty.md)

## Upstream References Relevant to Recreating the Mocks

### Kimai

- <https://github.com/kimai/kimai/tree/2.65.0>
- `src/Invoice/InvoiceService.php`
- `src/Invoice/InvoiceModel.php`
- `src/Invoice/InvoiceModelHydrator.php`
- `src/Invoice/Renderer/AbstractTwigRenderer.php`
- `src/Event/InvoiceCreatedEvent.php`
- `src/Entity/Invoice.php`
- `src/Repository/InvoiceRepository.php`

### Stripe

- Payment Link creation: <https://docs.stripe.com/api/payment-link/create>
- Payment Link update/deactivation: <https://docs.stripe.com/api/payment-link/update>
- Payment Link customization: <https://docs.stripe.com/payment-links/customize>
- Checkout Session listing: <https://docs.stripe.com/api/checkout/sessions/list>
- PaymentIntent API: <https://docs.stripe.com/api/payment_intents>
- Charge API: <https://docs.stripe.com/api/charges>
- Payment Link ad-hoc `price_data` change: <https://docs.stripe.com/changelog/basil/2025-07-30/ad-hoc-prices-for-payment-links>
