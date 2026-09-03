# Project Handoff

Date: 2026-09-03

## Purpose

This document exists so a new human or AI/LLM development session can resume work on this repository without access to the conversation that created the initial architecture.

Read this document before making implementation decisions.  Then read ADR-001 and the pre-implementation mock record in full.

## Executive Summary

`kimai-stripe-connector` is intended to be a small self-hosted Kimai plugin that connects Kimai invoices to Stripe Payment Links while preserving Kimai as the invoice system and Stripe as the payment processor.

The desired user workflow is:

```text
track time in Kimai
-> generate invoice
-> connector creates or recovers exact Stripe Payment Link
-> invoice contains Stripe-hosted payment URL
-> customer pays Stripe
-> asynchronous connector reconciliation verifies payment
-> Kimai invoice becomes paid
```

Kimai must not require public Internet ingress.  Stripe webhooks are not required.  Manual operation remains a permanent fallback.

The current architectural direction is **one plugin with two independently disableable capabilities**:

1. Payment Link generation/recovery and invoice-rendering support.
2. Payment reconciliation that conservatively marks Kimai invoices paid.

ADR-001 is currently `Proposed`, not `Accepted`.

## Repository State at Handoff

At this handoff point:

- the repository is still primarily a project scaffold plus design documentation;
- no production PHP Kimai plugin implementation has been committed;
- no real Stripe credentials are in the repository;
- no live Stripe payment has been executed by the design conversation;
- no packaged plugin has yet been installed and tested against the reference Kimai instance;
- the main implementation architecture is documented in `doc/adr/ADR-001-single-plugin-architecture.md`;
- the historical synthetic design exercise is documented in `doc/testing/preimplementation-mock-exercise.md`;
- AI/agent-specific guidance is in `AGENTS.md`;
- ADR-000 provides project-level epistemic/separation-of-concerns rules;
- the repository was cloned from a generic template, and several root/config files still contain template residue.

The open documentation PR at the time of this handoff is PR #3, branch `docs/adr-single-plugin-architecture`.

## Why the Project Exists

The original operational need was replacing a very low-volume Harvest billing/time-tracking workflow with a self-hosted system.

The environment already uses Stripe for payments.  Kimai became the preferred self-hosted time/invoice system after comparing alternatives.

The fully manual workflow is viable:

```text
Kimai invoice
-> create Stripe Payment Link manually
-> paste URL into/with invoice
-> send
-> customer pays
-> operator checks Stripe
-> operator marks Kimai invoice paid
```

The connector exists to remove the repetitive link-generation step and the risk that paid Stripe invoices remain stale/unpaid in Kimai because manual reconciliation was forgotten.

The expected billing volume is small.  Complexity therefore has to justify itself strongly.

## Product Philosophy

The plugin should be small, boring, conservative, removable, and auditable.

The project does **not** want to build a general billing platform.

A useful summary sentence is:

> Create or recover a Stripe payment link for a Kimai invoice, expose that link to the invoice, and conservatively mark the invoice paid when Stripe proves the exact payment succeeded.

If implementation starts requiring a large persistence layer, queues, public controllers, dashboards, broad accounting abstractions, or custom billing state machines, revisit the architecture before continuing.

## Hard Requirements

These came from the original design constraints and should be treated as requirements until changed by a later accepted ADR:

- self-hosted Kimai;
- no Kimai public ingress required;
- no mandatory Stripe webhook endpoint;
- outbound HTTPS from Kimai/runtime to Stripe is acceptable;
- outbound polling via cron/Jenkins/manual command is acceptable;
- Kimai core patches/forks/vendor modifications are unacceptable;
- use supported Kimai extension points;
- manual Payment Link creation remains valid;
- manual paid-state reconciliation remains valid;
- plugin disable/remove must not make billing impossible;
- customer payments already hosted by Stripe must continue even if Kimai is down;
- ambiguous financial state must fail closed;
- false negatives are preferable to false positives;
- paid-to-unpaid state reversal is not automatic in v1;
- refunds/disputes are surfaced for review;
- cleanup is conservative;
- paid orphans are never garbage;
- two plugin responsibilities must remain internally separated even while packaged as one plugin.

## Current Architecture

Conceptually:

```text
KimaiStripeConnector
|
+-- Common
|   +-- configuration
|   +-- Stripe client
|   +-- instance identity
|   +-- invoice identity
|   +-- money/currency normalization
|   +-- metadata contract
|   +-- validation/errors
|
+-- PaymentLink
|   +-- InvoicePreRenderEvent subscriber
|   +-- find/recover exact Payment Link
|   +-- create Payment Link when absent
|   +-- safe idempotency behavior
|   +-- InvoiceModel data injection
|   +-- InvoiceModelHydrator
|   +-- orphan cleanup command
|
+-- Reconciliation
    +-- find candidate unpaid invoices
    +-- correlate exact Payment Link
    +-- inspect Checkout Session
    +-- inspect PaymentIntent
    +-- inspect Charge
    +-- mark exact Kimai invoice paid
    +-- report later anomalies
```

The class names/namespaces above are conceptual and not yet frozen.

## Kimai 2.65.0 Source Findings Already Established

The design conversation inspected Kimai 2.65.0 source rather than relying only on high-level documentation.

Key findings that drove the architecture:

- `InvoiceService::createInvoice()` dispatches `InvoicePreRenderEvent` before rendering.
- Kimai's own duplicate invoice-number check happens after pre-render.
- the invoice document is rendered before the final `Invoice` entity is created/saved;
- `InvoiceCreatedEvent` occurs too late to insert a newly generated Stripe URL into the already-rendered invoice;
- `InvoiceModel::isPreview()` exists and can be used to ensure invoice previews have zero Stripe mutations;
- `InvoiceModel` supports options;
- `InvoiceModelHydrator` can add data to `InvoiceModel::toArray()`;
- the Twig invoice renderer exposes that array as `invoice`;
- invoice entities have metadata support;
- Kimai exposes paid state and payment date operations suitable for reconciliation.

The intended supported seam is therefore:

```text
InvoicePreRenderEvent
-> create/recover Payment Link
-> store link info on InvoiceModel
-> InvoiceModelHydrator exposes link
-> Twig template renders link
```

Do not replace this with a core patch merely because another approach feels easier.

Relevant upstream reference used during design:

- Kimai tag/version: `2.65.0`
- <https://github.com/kimai/kimai/tree/2.65.0>

Important files are listed in ADR-001 and the mock record.

## Stripe Design Already Established

The intended customer-facing Stripe object is a **Payment Link**, not a Stripe Invoice and not a raw one-off Checkout Session URL.

Reasons:

- Kimai remains invoice authority;
- Stripe remains payment authority;
- Payment Link gives a durable hosted payment URL;
- no Stripe Invoicing product/fee semantics are needed merely to collect payment;
- metadata can correlate Stripe objects to Kimai invoices;
- Payment Links can be deactivated later;
- Payment Links can be limited to one completed Checkout Session.

A subtle point already discovered:

> A completed Checkout Session is not necessarily proof that payment is final/successful.

Asynchronous payment methods can complete Checkout while payment remains processing.  The one-completed-session restriction is a duplicate-payment safety control, not reconciliation proof.

The first implementation should therefore either support a deliberately tested subset of payment methods (cards would be the simplest initial target) or explicitly model/test asynchronous-method behavior before claiming support.

## Proposed Metadata Contract

The final keys are not yet frozen, but the architecture requires a versioned correlation contract.

Current proposed shape:

```text
integration=kimai-stripe-connector
integration_version=1
kimai_instance=<stable instance UUID>
invoice_number=<Kimai invoice number>
invoice_amount=<integer minor currency units>
invoice_currency=<normalized lowercase currency>
```

Potential additional fields:

```text
kimai_customer_id=<Kimai customer ID>
created_by=kimai-stripe-connector
```

The stable Kimai instance identity matters because two Kimai installations can both have invoice `INV-0042` while sharing a Stripe account.

The instance identity must survive:

- container recreation;
- plugin upgrades;
- app restarts.

Changing it later is effectively a metadata namespace migration.

## Money Handling

Do not use binary floating-point arithmetic as the authoritative Stripe amount representation.

The pre-implementation mock included a 100,000-cent conversion sweep for USD-like values and reported no mismatch, but that does not establish a production money model.

Implementation needs to decide deliberately how to support:

- ordinary two-decimal currencies;
- zero-decimal currencies;
- any unusual supported minor-unit currencies;
- rounding/conversion from whatever Kimai exposes internally.

If v1 intentionally supports only a subset of currencies, document that clearly rather than accidentally assuming two decimals everywhere.

## Payment Link Creation/Recovery Rules

Desired behavior:

```text
invoice preview
-> zero Stripe mutation
```

```text
real invoice + no existing exact link
-> create one
```

```text
real invoice + exactly one compatible existing link
-> reuse it
```

```text
same invoice identity + different amount/currency
-> conflict
-> do not mutate/reuse silently
-> do not blindly create competing payment surface
```

```text
multiple ambiguous candidate links
-> fail closed
-> manual review
```

The link should be fixed-amount, fixed-currency, quantity one, no customer-adjustable quantity, and no pay-what-you-want behavior.

## Idempotency and Partial Failure

The design explicitly assumes this can happen:

```text
connector sends create request
-> Stripe creates Payment Link
-> response is lost
-> connector observes timeout
```

A retry must not blindly create another payment surface.

Immediate retry should use Stripe idempotency where appropriate.

Longer-term recovery should use the stable metadata contract because Stripe idempotency retention is finite and external to the connector.

Another unavoidable sequence is:

```text
Stripe link created
-> Kimai render/persistence fails
-> no completed Kimai invoice
-> Stripe link remains
```

This is an accepted partial-failure condition.

An exact later retry should rediscover/reuse the link if identity, amount, and currency still match.

If the same invoice number is retried with changed financial meaning, automatic behavior should stop and require a human decision.

## Orphan Cleanup

Expected future CLI command, final name not frozen:

```text
bin/console kimai:stripe:cleanup
```

Conceptual cleanup rules:

```text
managed link + Kimai invoice exists
-> keep

managed link + Kimai unavailable/uncertain
-> keep

managed link + no invoice + recent
-> keep

managed link + no invoice + successful payment evidence
-> keep + alert/manual review

managed link + no invoice + no payment + older than grace
-> deactivate
```

The original conversation considered a seven-day grace period as a reasonable candidate, but this is not yet a final decision.

Do **not** deactivate a valid link merely because it is old.  A legitimate unpaid invoice may remain open for months.

The key criterion is "no Kimai invoice," not "old link."

Cleanup is outside the correctness path.  If the cleanup job does not run, the expected failure is Stripe clutter, not broken billing.

## Reconciliation

Expected future CLI command, final name not frozen:

```text
bin/console kimai:stripe:reconcile
```

The reconciler should be suitable for cron, Jenkins, systemd timer, or manual execution.

The design prefers a repair-style process:

```text
observe current Kimai state
+ observe current Stripe state
-> derive whether one safe monotonic change exists
-> apply it
```

This makes missed runs recoverable.

### Minimum Identity Expectations

Never reconcile by amount alone, customer alone, date proximity, or invoice number alone.

Expected identity is approximately:

```text
connector namespace
+ metadata version
+ Kimai instance UUID
+ invoice number
+ amount
+ currency
```

### Evidence Expectations

The pre-implementation model conservatively checked multiple Stripe layers:

- Payment Link;
- Checkout Session;
- PaymentIntent;
- Charge.

The exact production checks may depend on the supported payment methods/API version, but the principle is fixed: automatic paid state requires positive, unambiguous, mutually consistent evidence.

No automatic paid transition for:

- no session;
- open checkout;
- completed checkout with unpaid/processing payment;
- failed/canceled/processing PaymentIntent;
- wrong amount;
- wrong currency;
- wrong metadata;
- wrong Kimai instance;
- partial payment;
- uncaptured charge where capture matters;
- refund before initial reconciliation;
- dispute before initial reconciliation;
- multiple ambiguous candidates.

### Automatic State Direction

Initial automatic transition:

```text
Kimai not-paid
+
strong exact Stripe evidence
-> Kimai paid
```

Do not automatically perform the reverse because of a later refund/dispute.

A refund/dispute after an invoice was marked paid is an accounting event requiring human review, not proof that the historical payment never happened.

## Historical Mock Evidence

The design conversation reported:

```text
89 behavioral/adversarial cases passed
5 HTTP/network/concurrency cases passed
9 stateless correlation/recovery cases passed
------------------------------------------
103 total passed
```

Additional probes included:

- 100,000 cent-value money conversions;
- 10,000 unrelated Payment Links plus one exact intended match;
- 20 simultaneous creation attempts converging on one logical Payment Link.

The full surviving scenario reconstruction is in:

`doc/testing/preimplementation-mock-exercise.md`

Critical caveat: these were conversational/pre-repository mocks.  The original harness and raw output are not committed.  Do not claim this repository has 103 executable tests.

The next implementation session should convert those cases into actual repository tests and map them back to the proposed IDs in the mock document.

## Acceptance Gate for ADR-001

ADR-001 includes a concrete validation list before it should become Accepted.  The important idea is that a real plugin must be installed against a test Kimai runtime and exercised against Stripe test mode.

The handoff session should not mark ADR-001 Accepted merely because code compiles.

End-to-end evidence should include at least:

- plugin installation;
- clean Kimai startup;
- preview suppression;
- real Payment Link creation;
- invoice rendering with correct URL;
- orphan creation and recovery;
- financial conflict detection;
- Stripe test-mode successful payment;
- exact reconciliation;
- reconciliation idempotency;
- cleanup safety;
- plugin disable/manual fallback;
- secret/log review.

## Reference Deployment Context

The original Kimai instance used during product evaluation was:

- Kimai `2.65.0`;
- Dockerized/self-hosted;
- external MySQL/MariaDB;
- behind Traefik;
- Apache inside the Kimai container listened on port `8001` in that deployment;
- `TRUSTED_HOSTS` configuration was required;
- the Kimai Docker entrypoint used shell tracing (`set -x`) and could expose environment values in logs;
- plugin deployment behavior observed during evaluation copied bundles into `var/plugins` and required cache/application lifecycle handling.

These are **reference-environment observations**, not necessarily product requirements.

Do not hard-code:

- Docker Swarm;
- Traefik;
- NFS;
- a specific database host;
- port 8001;
- Jenkins;
- the original private hostname/network layout

into a general OSS plugin unless Kimai itself requires a particular behavior.

The original infrastructure used Docker Swarm, Traefik, central DB services, and NFS-backed storage.  Those facts are useful for dogfooding but should not narrow the plugin unnecessarily.

## Deployment/Operational Preferences Learned During Evaluation

The original operator strongly prefers:

- supported plugin mechanisms rather than core changes;
- conservative behavior;
- minimal custom image requirements;
- boring upgrades;
- small release artifacts;
- clear rollback/disable steps;
- avoiding complex Jenkins/git-pull/custom-image deployment choreography where a normal plugin release can work;
- pull/poll integration instead of inbound webhooks when practical.

If a proposed packaging solution makes plugin installation substantially more complex than Kimai's normal plugin model, revisit it.

## One Plugin Versus Two

Two plugins were seriously considered:

```text
PaymentLinksBundle
ReconciliationBundle
```

Advantages:

- package-level fault isolation;
- independent removal;
- potentially separate Stripe restricted keys;
- independent release cadence.

Downsides:

- duplicated/shared identity/money/metadata logic;
- two Kimai compatibility surfaces;
- two versions/releases;
- possible metadata-contract drift;
- temptation to introduce a third shared library.

The current direction is one package with internal separation.

Do not treat the two-plugin idea as rejected forever.  ADR-001 lists conditions that should trigger reconsideration.

## Licensing and OSS Status

The project owner expressed a preference for OSS.

However, the final license has **not been decided**.

The repository template currently contains:

- `LICENSE` = CC0 1.0 Universal;
- `CONTRIBUTING.md` language stating contributions are CC0;
- `README.md` template text claiming CC0.

Those are scaffold artifacts, not a documented final plugin-license decision.

A prior design conversation considered AGPL-3.0-or-later as a possible conservative choice because Kimai itself is AGPL, but no final legal/license conclusion was reached.

Do not publish or solicit implementation contributions under contradictory licensing assumptions without resolving this first.

A dedicated licensing ADR or explicit project-owner decision is appropriate before public release.

## Repository Template Residue to Review

The repository was created from a generic template.  The next session should not assume all existing files describe this project accurately.

Known residue/issues include:

- `README.md` still describes itself as a template and references `adr-tools` rather than the newer `adrctl` preference used elsewhere by the repository owner;
- README path text mentions `doc/adrs/...` while `.adr-dir` actually points to `doc/adr`;
- `SUPPORT.md` refers to "this action" and links to a different repository (`publish_container`);
- `CONTRIBUTING.md` refers to `LICENSE.md`, while the file present is `LICENSE`;
- `CONTRIBUTING.md` and `LICENSE` currently encode CC0 despite the connector license being unresolved;
- `SECURITY.md` describes a generic personal project and may need connector-specific disclosure/support wording;
- `cspell.json` contains unrelated words from other projects and lacks expected Kimai/Stripe vocabulary;
- CodeQL currently analyzes GitHub Actions only, not PHP, because no PHP code existed when the template was copied;
- Dependabot currently tracks GitHub Actions only; Composer support should be added when `composer.json` exists;
- `.gitignore` is a very broad generic template and should eventually gain PHP/Composer/Kimai-specific entries where needed;
- semver/release automation may create releases immediately on `main` pushes and should be reviewed before the first real public release;
- MegaLinter auto-fix behavior and signing-secret assumptions should be reviewed before relying on it;
- issue-branch automation depends on a `PAT` secret;
- Scorecard/private-repository behavior may need adjustment before public release.

Do not clean all template residue blindly in the same implementation change.  Preserve working project conventions where useful, and change artifacts deliberately.

## Existing Repository Engineering Conventions

The scaffold already establishes several conventions that should generally be respected unless deliberately changed:

- `.editorconfig` uses spaces, two-space indentation for most files, LF, UTF-8;
- Markdown lint configuration allows long lines and relaxes several formatting rules;
- YAML lint extends defaults with a high line-length limit;
- pre-commit checks whitespace, EOF/newlines, YAML/JSON/XML, AWS credentials, Gitleaks, and prohibits committed `.env` files;
- MegaLinter security flavor is configured;
- GitHub Actions are SHA-pinned;
- CodeQL and OpenSSF Scorecard workflows exist;
- Conventional Commits drive automatic semver/release behavior;
- Dependabot groups minor/patch updates;
- CODEOWNERS assigns the repository to `wesley-dean`;
- ADR directory is `doc/adr`.

When PHP/Composer code is introduced, update the repository automation deliberately to include the new ecosystem.

## Security Expectations for Implementation

The plugin will hold or access Stripe API credentials and can mark invoices paid.

Implementation should therefore:

- prefer restricted Stripe keys where practical;
- document exact required permissions;
- avoid full account keys if unnecessary;
- never log keys;
- never render keys into invoices/Twig;
- never place keys in Stripe metadata;
- avoid exception/debug output that contains secrets;
- test log behavior;
- account for the reference Kimai entrypoint's `set -x` behavior;
- retain no card/payment credential data in Kimai;
- keep Stripe-hosted checkout as the PCI-sensitive surface;
- add appropriate secret scanning and PHP static analysis as code appears.

Potential separate credentials for link mutation versus reconciliation reads remain an open design option.

## Recommended Next Work

A sensible sequence for the next chat/session is:

1. Read ADR-001 and the mock document fully.
2. Review current Kimai plugin-development documentation and Kimai 2.65/current source before generating a skeleton.
3. Resolve the license question before public implementation contributions or release.
4. Decide the minimum supported Kimai/PHP versions and Composer/Stripe SDK approach.
5. Decide the first supported Stripe payment methods, likely beginning with cards unless there is a reason to take on asynchronous-method complexity immediately.
6. Define/freeze metadata contract v1 and money representation.
7. Create the smallest valid Kimai plugin skeleton using supported mechanisms.
8. Add executable tests for identity, money, preview suppression, and Payment Link resolution before adding full reconciliation.
9. Implement Payment Link creation/recovery/hydration.
10. Exercise against a real Kimai test instance and Stripe test mode.
11. Implement reconciliation after link-generation behavior is stable.
12. Implement cleanup after orphan behavior is demonstrated.
13. Update CodeQL/Dependabot/MegaLinter/README/install docs for PHP/Composer.
14. Revisit ADR-001 status only after the acceptance evidence exists.

This sequence is a recommendation, not a new architectural decision.  Another sequence is acceptable if it preserves the same safety boundaries.

## Questions Still Open

Important unresolved questions include:

- final OSS license;
- exact Kimai plugin package structure;
- minimum supported Kimai version;
- PHP version range;
- Stripe PHP SDK version;
- Stripe API-version policy;
- whether to pin `Stripe-Version`;
- whether Payment Link `price_data` should be used;
- supported currencies and money library/representation;
- initial payment-method set;
- exact Stripe restricted-key permissions;
- one versus separate Stripe credentials;
- metadata key names and versioning;
- whether Kimai metadata should cache Payment Link IDs;
- whether Stripe-side metadata lookup is sufficiently efficient without local indexing;
- cleanup grace period;
- payment-date timestamp source;
- treatment of manually paid invoices with still-active links;
- CLI output/exit code/JSON/dry-run conventions;
- how refunds/disputes are surfaced;
- compatibility matrix and release-support policy;
- public Kimai marketplace submission/support model.

Do not silently choose answers to these questions when they materially affect compatibility, security, or accounting semantics.  Record important decisions.

## Scope Exclusions to Preserve

Absent a later ADR, do not expand v1 into:

- recurring billing;
- subscriptions;
- tax engine;
- tax filing;
- accounting ledger;
- generalized accounts receivable;
- partial-payment accounting;
- installment plans;
- dunning/late fees;
- automated refund accounting;
- automated disputes/chargebacks;
- Stripe dashboard clone;
- card collection inside Kimai;
- public Kimai payment portal;
- mandatory webhook infrastructure;
- generic multi-processor abstraction.

## How to Resume in Another ChatGPT Chat

A useful first prompt to another session is:

> Please inspect `wesley-dean/kimai-stripe-connector` on GitHub.  Start by reading `AGENTS.md`, `doc/project-handoff.md`, ADR-000, ADR-001, and `doc/testing/preimplementation-mock-exercise.md`.  Treat the repository as the source of truth for prior design context.  Do not assume the historical 103 mocks are executable repository tests.  Continue from the documented open questions and current proposed one-plugin architecture.

The new session should not need access to the conversation that preceded this repository documentation.
