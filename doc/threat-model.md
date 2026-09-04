# Threat Model

Date: 2026-09-03

## Status

Initial design-stage threat model.

This document is a living security artifact.  It is intentionally written before implementation so that controls and tests can be designed into the connector rather than retrofitted after code exists.

Some threats depend on implementation details that are not yet finalized.  Those are identified explicitly and should be revisited as the plugin structure, configuration handling, Stripe client usage, CLI commands, and Kimai integration become concrete.

## Scope

This threat model covers `kimai-stripe-connector` and its immediate trust relationships with:

- Kimai;
- Stripe;
- the deployment/runtime environment;
- operators/administrators;
- invoice recipients/customers;
- CI/E2E environments;
- repository/release supply chain.

It does not attempt to threat-model all of Kimai, Stripe, Docker, Swarm, Traefik, the database server, or the host operating systems in full.

The connector is security-relevant because it can:

- use a Stripe API credential;
- create/deactivate Stripe Payment Links;
- inspect Stripe payment state;
- alter Kimai invoice paid state;
- influence the payment URL rendered onto invoices.

The connector is **not** intended to:

- receive card numbers;
- store payment credentials;
- host a checkout page;
- receive Stripe webhooks;
- expose a public customer payment endpoint.

## Security Goals

The primary security goals are:

1. A customer must be directed only to the correct Stripe-hosted payment surface for the intended Kimai invoice.
2. The connector must not mark a Kimai invoice paid without strong, exact, unambiguous Stripe evidence.
3. Stripe credentials must remain confidential.
4. A compromised or malformed external response must not produce unsafe accounting changes.
5. A network or partial-failure condition must not create uncontrolled duplicate payment surfaces.
6. Cleanup must not deactivate legitimate or paid payment links when state is uncertain.
7. Kimai must not need public Internet ingress for connector operation.
8. Connector configuration and identity must not be silently altered in ways that cause cross-instance or cross-invoice miscorrelation.
9. The plugin must remain removable/disableable so security incidents do not require emergency Kimai core modification.
10. The release/build chain should make it difficult to introduce an unauthorized connector binary/package without review.

## Non-Goals

The connector does not attempt to guarantee:

- prevention of all compromise of the Kimai host/container;
- prevention of compromise of the Stripe account itself;
- prevention of compromise of operator credentials outside this plugin;
- prevention of malicious changes by an administrator who already has equivalent Kimai/Stripe authority;
- global accounting correctness beyond the narrow automatic transition rules defined by the connector;
- protection against a compromised customer device or browser;
- PCI DSS scope reduction claims beyond the architectural fact that Stripe hosts the payment page.

## Assets

### Stripe API credential

High-value secret.

Compromise may allow an attacker to create/read/update Stripe objects within the credential's granted permissions.

### Kimai invoice state

Security-sensitive business data.

Incorrect mutation can create accounting errors, suppress collection activity, or misrepresent receivables.

### Payment Link URL and identity

The URL is not itself necessarily secret, but its integrity is critical.

A substituted or mismatched payment link could direct a customer to pay the wrong invoice or wrong account.

### Connector instance identity

The stable Kimai instance identifier is non-secret but integrity-sensitive.

Changing it can break correlation or create cross-instance ambiguity.

### Connector metadata contract

The metadata namespace/version/invoice identity/amount/currency fields are integrity-sensitive because reconciliation depends on them.

### Deployment configuration

Feature switches, cleanup grace period, Stripe credential source, and instance identity all affect security behavior.

### Logs

Logs may contain useful forensic evidence, but careless logging could leak secrets or sensitive payment/customer data.

### Release artifacts and source repository

Compromise could introduce malicious code that has access to Stripe credentials and Kimai invoice state.

## Actors

### Authorized operator

A legitimate administrator who can install/configure the plugin and run reconciliation/cleanup commands.

### Kimai user

A legitimate internal user who can create invoices or otherwise influence invoice data.

Their exact permissions will depend on Kimai configuration.

### Customer/invoice recipient

An external person who receives the invoice and follows the Stripe-hosted payment link.

### External attacker

An actor without legitimate Kimai/Stripe administrative access attempting to exploit the connector, manipulate payment state, steal credentials, or redirect payments.

### Malicious/compromised dependency or release artifact

Supply-chain attacker acting through Composer dependencies, GitHub Actions, plugin package/release artifact, or compromised source-control credentials.

### Compromised internal account

A legitimate Kimai or infrastructure account under attacker control.

This actor may have more influence than an Internet-only attacker and must be considered separately.

## Trust Boundaries

### Boundary 1: Internal user to Kimai

```text
operator / Kimai user
        |
        v
      Kimai
```

Kimai authentication/authorization governs who can create invoices, configure the plugin, or invoke administrative actions.

### Boundary 2: Connector to Stripe API

```text
Kimai connector
      |
      | outbound TLS/HTTPS
      v
    Stripe API
```

The connector trusts Stripe responses only after validating expected identity and financial state.

### Boundary 3: Invoice recipient to Stripe-hosted Checkout

```text
customer
   |
   v
Stripe Payment Link / Checkout
```

Kimai is not in this network path.

### Boundary 4: Deployment environment to connector configuration

```text
environment variables / secret files
             |
             v
          connector
```

Configuration is trusted input from the deployment environment but must still be validated for consistency and safety.

### Boundary 5: Scheduler/CLI invocation to reconciliation/cleanup

```text
cron / Jenkins / operator / timer
              |
              v
connector CLI command
```

The connector should assume commands may run repeatedly, concurrently, or after long outages.

### Boundary 6: Repository/build/release to production plugin

```text
source repository
      |
      v
CI / package build
      |
      v
plugin artifact
      |
      v
Kimai runtime
```

Integrity of this path is security-relevant because the plugin has access to sensitive credentials and invoice state.

## Data Flows

### Payment Link creation

```text
Kimai invoice model
      |
      | invoice number / amount / currency / instance ID
      v
connector
      |
      | authenticated Stripe API request
      v
Stripe Payment Link
      |
      | ID + URL + metadata
      v
connector
      |
      v
Kimai invoice rendering
```

### Customer payment

```text
Kimai invoice document
      |
      | Stripe-hosted URL
      v
customer browser
      |
      v
Stripe Checkout
```

No card data should traverse Kimai or the connector.

### Reconciliation

```text
Kimai unpaid invoice
      |
      v
connector
      |
      | read Payment Link / Session / Intent / Charge
      v
Stripe
      |
      | exact evidence
      v
connector decision logic
      |
      v
Kimai paid-state update
```

### Cleanup

```text
connector-managed Stripe links
      |
      v
connector
      |
      +--> check matching Kimai invoice
      +--> check payment evidence
      +--> check age/grace
      |
      v
retain / report / deactivate
```

## Threat Analysis

The categories below borrow from STRIDE, but the document is organized by actual connector behavior rather than forcing every item into one formal bucket.

## Threat: Stripe Credential Disclosure

### Scenario

The Stripe API key appears in:

- container startup logs;
- shell tracing;
- environment dumps;
- exception messages;
- debug output;
- Twig/template variables;
- invoice documents;
- CI logs;
- committed `.env` files;
- support bundles.

### Impact

Depending on permission scope, an attacker may be able to inspect or mutate payment objects.

### Controls

- prefer file-backed secret injection in containerized production;
- support a restricted Stripe key if practical;
- never log the key;
- never pass it to templates;
- never store it in Stripe metadata;
- reject committed `.env` files;
- retain secret scanning in pre-commit/CI;
- test logs for absence of the secret;
- document credential rotation procedure before release;
- review Kimai/container entrypoint behavior for accidental shell tracing exposure.

### Residual risk

A host/container administrator can likely access process/runtime secrets.  The connector cannot protect against an administrator with equivalent infrastructure privilege.

## Threat: Payment Link Substitution or Misdirection

### Scenario

A malicious or buggy actor causes invoice A to render a Payment Link for:

- invoice B;
- another Kimai instance;
- another amount;
- another currency;
- another Stripe account;
- an attacker-controlled external URL.

### Impact

Customer may pay the wrong obligation or wrong recipient.

### Controls

- create/recover links only through the configured Stripe API client;
- exact metadata correlation;
- stable instance identity;
- exact amount/currency checks;
- do not accept arbitrary user-supplied payment URLs as connector-generated links;
- expose only expected Stripe-hosted URL data to invoice templates;
- fail closed on conflicting same-identity links;
- E2E-test rendered invoice URL against the Stripe object created for that invoice.

### Open implementation question

Determine whether the connector should validate the returned Payment Link URL host/domain pattern in addition to trusting the Stripe SDK/API response.

## Threat: False Paid-State Transition

### Scenario

The connector marks an invoice paid based on weak or misleading evidence such as:

- Payment Link exists;
- Checkout Session exists;
- Checkout Session is complete but payment is still processing;
- PaymentIntent is not successful;
- amount/currency mismatch;
- wrong metadata;
- partial payment;
- uncaptured charge;
- refunded/disputed payment;
- ambiguous multiple candidates.

### Impact

Receivable may be incorrectly considered settled, potentially suppressing collection or creating accounting errors.

### Controls

- fail closed on ambiguity;
- exact identity/amount/currency validation;
- inspect multiple Stripe objects where necessary;
- require strong successful-payment state;
- no automatic transition on partial payment;
- no amount-only/customer-only/date-only matching;
- idempotent, repair-style reconciliation;
- automated tests for all negative states;
- initial automatic state transition is monotonic toward paid only.

### Residual risk

The exact definition of sufficient Stripe evidence may need adjustment for supported payment methods and API changes.  Compatibility testing must accompany those changes.

## Threat: Unauthorized Paid-State Mutation Through CLI or Scheduler

### Scenario

An unauthorized process or user invokes reconciliation or manipulates the environment to cause unexpected mutations.

### Impact

Kimai invoice state may change outside intended administrative control.

### Controls

- rely on OS/container/Kimai administrative permissions for command execution;
- commands should not accept arbitrary invoice state as input;
- commands derive state from Kimai + Stripe evidence;
- log safe audit identifiers for mutations;
- support dry-run mode;
- make commands idempotent;
- do not provide an unauthenticated HTTP endpoint for reconciliation.

### Open implementation question

Whether Kimai provides/needs an explicit permission check for plugin CLI commands beyond process-level access should be verified during implementation.

## Threat: Duplicate Payment Links After Network Ambiguity

### Scenario

Stripe commits Payment Link creation but the response is lost.  The connector retries and creates another active link for the same invoice.

### Impact

Multiple customer payment surfaces exist for one invoice, increasing duplicate-payment risk and operational confusion.

### Controls

- deterministic Stripe idempotency identity for immediate retries;
- durable metadata correlation for later recovery;
- exact lookup before/around creation;
- conflict detection;
- concurrency tests;
- cleanup of confirmed unpaid orphaned links.

### Residual risk

Distributed exactly-once behavior is not guaranteed.  The goal is safe idempotent convergence and recoverability.

## Threat: Cross-Instance Correlation Collision

### Scenario

Two Kimai deployments sharing one Stripe account both create an invoice such as `INV-0042`, and the connector correlates the wrong Stripe object.

### Impact

Wrong invoice may receive a Payment Link or paid-state transition.

### Controls

- stable `kimai_instance` identifier in metadata;
- include instance identity in idempotency/correlation logic;
- reject missing/unknown metadata versions;
- test multiple-instance distractor scenarios.

## Threat: Instance Identity Tampering or Rotation

### Scenario

The configured instance ID changes accidentally or maliciously.

### Impact

Historical Stripe objects may no longer correlate; new links may be created for invoices that already have links; cleanup could misclassify historical objects.

### Controls

- treat instance ID as stable deployment identity;
- document rotation as a migration, not routine configuration;
- validate expected format if one is selected;
- surface instance ID in safe startup logs;
- do not auto-clean objects from an old/unknown namespace merely because current identity differs.

## Threat: Metadata Tampering or Collision

### Scenario

Stripe metadata is changed manually or by another integration so that an unrelated object appears to belong to this connector/invoice.

### Impact

Incorrect link reuse or reconciliation could occur.

### Controls

- versioned metadata namespace;
- require multiple mutually consistent fields;
- validate amount/currency against object-native Stripe fields, not metadata alone;
- validate PaymentIntent/Charge relationships where required;
- fail closed on conflicts;
- consider recording immutable correlation values in Kimai metadata as an additional signal, while not making local metadata the sole authority.

### Residual risk

An administrator with broad Stripe write access can modify metadata and other payment objects.  The connector cannot fully protect against a malicious administrator with equivalent financial-system privilege.

## Threat: Cleanup Deactivates a Legitimate Link

### Scenario

Cleanup decides a Payment Link is orphaned because Kimai is temporarily unavailable, slow, or returns incomplete data.

### Impact

Customer loses ability to pay a legitimate outstanding invoice.

### Controls

- if Kimai state cannot be positively established, retain the link;
- grace period applies only after no matching invoice is established;
- paid/payment-evidence links are never automatic cleanup targets;
- dry-run support;
- logs/reporting for deactivation decisions;
- E2E/integration tests around outage and race conditions.

## Threat: Paid Orphan Is Destroyed or Reassigned

### Scenario

A Payment Link has successful payment evidence but no completed Kimai invoice exists, and cleanup either deactivates/discards it as garbage or automatically attaches it to a later invoice.

### Impact

Loss of accounting evidence or incorrect financial attribution.

### Controls

- classify paid orphan as an accounting exception;
- retain and alert;
- never fabricate a Kimai invoice automatically;
- never attach payment to a different invoice automatically;
- require manual review.

## Threat: Refund/Dispute Causes Incorrect Historical Rewrite

### Scenario

A previously paid invoice later receives a Stripe refund or dispute, and the connector automatically marks the Kimai invoice unpaid/canceled.

### Impact

Historical payment fact is overwritten and accounting state becomes misleading.

### Controls

- v1 automatic transition is not-paid -> paid only;
- refunds/disputes after reconciliation are report/manual-review events;
- no automatic paid -> unpaid reversal.

## Threat: Malicious Invoice or Customer Text Reaches Shell/Template Context

### Scenario

Invoice/customer/project text contains shell metacharacters, Twig syntax, HTML, control characters, or other hostile-looking content.

### Impact

Depending on implementation, could produce injection, malformed logs, broken CLI output, template injection, or command execution.

### Controls

- treat business text as data;
- do not construct shell commands from invoice/customer values;
- use Symfony/Stripe APIs directly;
- expose only necessary scalar values to Twig;
- rely on appropriate escaping in templates;
- sanitize/structure logs;
- add adversarial string tests.

### Open implementation question

Review exact Kimai Twig sandbox/escaping behavior for custom hydrator values during plugin implementation.

## Threat: SSRF or Arbitrary Outbound Requests

### Scenario

Attacker influences a URL or API endpoint used by the connector and causes requests to arbitrary internal/external systems.

### Impact

Potential internal network probing, metadata-service access, or credential leakage.

### Controls

- Stripe API base endpoint should not be user-controlled in normal production configuration;
- do not fetch arbitrary URLs from invoice/customer data;
- use the Stripe SDK/client with fixed expected endpoint behavior;
- any test-only fake-Stripe endpoint override must be clearly separated from production configuration and disabled by default.

### Open implementation question

If an API-base override is added for integration tests, define a hard production guard so it cannot be enabled accidentally in live deployments.

## Threat: Environment/Configuration Injection

### Scenario

An attacker or misconfiguration sets values that:

- disable reconciliation;
- enable cleanup unexpectedly;
- change instance identity;
- point secret-file configuration at an unintended file;
- supply malformed booleans/numbers;
- select a test credential in production or vice versa.

### Impact

Loss of functionality, unsafe cleanup, correlation breakage, or use of wrong Stripe environment/account.

### Controls

- namespaced configuration;
- strict validation;
- fail on contradictory secret sources;
- explicit test/live-mode checks where Stripe exposes safe signals;
- readable startup summary of safe configuration values;
- secret-file path treated as deployment-controlled input;
- deployment configuration managed through ordinary infrastructure access controls.

### Open implementation question

Determine whether secret-file paths should be restricted to an expected directory such as `/run/secrets` or remain runtime-agnostic.  Portability and defense-in-depth trade off here.

## Threat: Stripe API Downgrade/Version Drift

### Scenario

A Stripe SDK/API-version change alters Payment Link creation fields, payment lifecycle semantics, or response shapes.

### Impact

Connector may fail open, create incorrect objects, or misinterpret payment state.

### Controls

- explicit compatibility/version policy;
- pin/test supported Stripe SDK versions;
- E2E against Stripe sandbox;
- fail closed on unknown/malformed states;
- do not silently infer new payment states;
- compatibility matrix in CI/release process.

## Threat: Kimai Upgrade Breaks Extension Points

### Scenario

Kimai changes plugin loading, events, hydrators, invoice lifecycle, status handling, or Twig behavior.

### Impact

Payment Link generation may fail or reconciliation may become unsafe.

### Controls

- pin/test supported Kimai versions;
- containerized E2E matrix;
- no core patches;
- plugin is disableable;
- manual workflow remains available;
- do not claim compatibility without test evidence.

## Threat: Supply-Chain Compromise

### Scenario

Malicious code enters through:

- compromised dependency;
- compromised GitHub Action;
- compromised maintainer account;
- malicious PR;
- tampered release artifact;
- build-environment compromise.

### Impact

Attacker gains code execution in Kimai context with access to Stripe credentials and invoice state.

### Existing/Planned Controls

- dependency pinning/lockfiles once Composer is introduced;
- Dependabot;
- CodeQL/static analysis where applicable;
- MegaLinter/security tooling;
- Gitleaks/TruffleHog-style secret scanning;
- OpenSSF Scorecard;
- review through pull requests;
- pinned GitHub Action references already present in template workflows;
- release provenance/attestation should be considered before public distribution;
- minimize dependency count.

### Open implementation questions

- final Composer dependency policy;
- artifact signing/provenance mechanism;
- whether releases will be distributed through GitHub, Kimai Marketplace, Packagist, or another channel;
- final project license and contribution model.

## Threat: Excessive Stripe Credential Privilege

### Scenario

The connector uses a broad Stripe secret key even though it needs only a narrow subset of API operations.

### Impact

Credential compromise has unnecessarily large blast radius.

### Controls

- investigate Stripe restricted keys;
- document required permissions separately for Payment Link generation, reconciliation, and cleanup;
- consider whether one key can safely cover all capabilities or whether optional separate credentials provide meaningful isolation;
- prefer least privilege where operationally reasonable.

### Open architectural question

A single-plugin package does not require a single credential.  Separate credentials may be useful if Stripe permission granularity and operational complexity support it.

## Threat: Capability Disablement Creates Misleading State

### Scenario

Reconciliation is disabled or broken for a long time, leaving paid invoices marked unpaid in Kimai.

### Impact

Operational confusion or duplicate collection effort.

### Controls

- explicit logs/health status for disabled capabilities;
- reconciliation is repairable when re-enabled;
- operator documentation should explain eventual consistency;
- potential future age/backlog reporting for unreconciled invoices;
- do not silently claim synchronization when capability is disabled.

## Threat: Manual Stripe/Kimai Actions Conflict With Automation

### Scenario

An operator manually:

- creates a Payment Link;
- marks an invoice paid;
- edits Stripe metadata;
- refunds a payment;
- deactivates a link

while automation is also operating.

### Impact

Ambiguity or duplicate state changes.

### Controls

- reconciliation respects already-paid invoices;
- exact matching rather than fuzzy inference;
- conflicts fail closed;
- CLI operations idempotent;
- concurrency tests;
- do not assume all invoices use the connector.

## Threat: Denial of Service Through Stripe/API Failures

### Scenario

Stripe is unavailable, rate-limited, or returns errors during invoice generation.

### Impact

If Payment Link creation is synchronous, invoice generation may fail or require bypass/manual fallback.

### Controls

- explicit operator-visible failure;
- documented manual bypass/disable path;
- reconciliation failure must not block invoice generation;
- retry policy should distinguish transient failures from conflicts;
- avoid infinite/blocking retry loops.

### Accepted tradeoff

For a real invoice requiring an automatically inserted link, failing invoice generation can be safer than silently issuing an invoice with an unknown/incorrect payment URL.  Manual fallback remains available.

## Threat: Logging Sensitive Customer/Payment Data

### Scenario

Verbose logs store full Stripe payloads, addresses, card-related metadata, customer emails, or other unnecessary data.

### Impact

Unnecessary privacy exposure and larger incident scope.

### Controls

- structured minimal logs;
- log IDs and states rather than full objects;
- never log card/payment-method details unnecessarily;
- explicit debug mode, if added, must still redact secrets;
- document log-retention considerations.

## Threat: E2E Test Credentials or Data Leak

### Scenario

Stripe sandbox keys or test artifacts leak through CI logs, artifacts, screenshots, or repository files.

### Impact

Lower than production-key compromise but still undesirable; can enable abuse of the sandbox/account and contaminate test evidence.

### Controls

- store sandbox secrets in CI secret storage;
- never commit them;
- redact logs;
- do not upload environment dumps;
- use unique test metadata;
- clean up/deactivate created test links where appropriate;
- review browser screenshots/traces for sensitive test values before artifact retention.

## Threat: Public Ingress Is Accidentally Introduced

### Scenario

Implementation adds a webhook/controller/callback because it seems convenient, and deployment exposes Kimai publicly.

### Impact

Expands attack surface and violates a core architectural constraint.

### Controls

- ADR-001 explicitly rejects mandatory public ingress/webhooks for v1;
- no public route should be introduced without a new architectural decision;
- external polling is the default reconciliation model;
- README/AGENTS/reference environment reinforce the boundary.

## Threat: Plugin Scope Expands Into a Financial System

### Scenario

The connector gradually adds ledger, refund automation, partial payments, tax logic, subscriptions, dunning, or customer accounting.

### Impact

Much larger security/compliance/accounting attack surface; difficult-to-audit code; unclear authority boundaries.

### Controls

- explicit non-goals in ADR-001 and AGENTS.md;
- architecture review/ADR before material scope expansion;
- preserve Kimai and Stripe authority boundaries;
- size/complexity growth treated as a signal to revisit architecture.

## Abuse Cases to Test

The following abuse/security cases should become executable tests where practical:

```text
THR-001 direct Stripe secret never appears in application logs
THR-002 file-backed Stripe secret never appears in application logs
THR-003 invoice preview creates no Stripe object
THR-004 wrong-instance Payment Link is never reused
THR-005 same invoice number + wrong amount is a conflict
THR-006 same invoice number + wrong currency is a conflict
THR-007 arbitrary external URL cannot become connector-generated payment URL
THR-008 completed-but-unpaid Checkout never marks Kimai paid
THR-009 partial payment never marks Kimai paid
THR-010 refunded/disputed evidence does not create unsafe automatic transition
THR-011 ambiguous multiple payment candidates produce no mutation
THR-012 Kimai unavailable during cleanup deactivates nothing
THR-013 paid orphan is retained and reported
THR-014 timeout-after-commit retry does not intentionally create duplicate logical link
THR-015 malformed Stripe response fails closed
THR-016 hostile invoice/customer text is treated as data
THR-017 configuration with both secret forms fails
THR-018 test/live credential mismatch is detected if safely possible
THR-019 reconciliation rerun is idempotent
THR-020 concurrent reconciliation does not produce unsafe state
THR-021 plugin disabled leaves manual Kimai/Stripe workflow viable
```

These identifiers are a proposed security test backlog, not existing repository tests.

## Risk Prioritization

At this design stage, the highest-priority risks are:

### High

1. Stripe credential disclosure.
2. False Kimai paid-state transition.
3. Payment Link miscorrelation/substitution.
4. Supply-chain compromise of plugin code/artifact.
5. Cleanup of legitimate/paid links due to uncertain state.

### Medium

6. Duplicate Payment Links after partial/network failure.
7. Instance identity/configuration drift.
8. Kimai/Stripe API compatibility drift.
9. Sensitive customer/payment data in logs.
10. Manual/automatic race conditions.

### Lower but still relevant

11. Test credential leakage.
12. Denial of service from Stripe outages.
13. Long reconciliation backlog causing stale Kimai state.
14. Scope creep expanding the security boundary.

This prioritization should be revisited after implementation, because concrete code can expose risks not visible in the architecture model.

## Security Design Principles Derived From the Model

The connector should follow these principles:

- fail closed on financial ambiguity;
- use least privilege;
- minimize secrets and dependencies;
- keep Kimai private;
- prefer deterministic/idempotent repair over fragile real-time coupling;
- distinguish absence of evidence from evidence of absence;
- preserve historical accounting facts rather than rewriting them automatically;
- treat cleanup as destructive and therefore conservative;
- keep manual fallback operational;
- log enough for audit/recovery without logging sensitive payloads;
- validate configuration before performing mutations;
- keep compatibility evidence executable where possible.

## Review Triggers

This threat model SHOULD be revisited when:

- plugin code is first implemented;
- the Stripe SDK/API version policy is selected;
- the first restricted-key permission set is known;
- Kimai-native plugin configuration/UI is added;
- support for asynchronous payment methods is added;
- partial payments are considered;
- webhook/public endpoints are proposed;
- refund/dispute automation is proposed;
- a local persistence layer is introduced;
- packaging/release distribution is finalized;
- a security incident or meaningful near-miss occurs;
- a major Kimai or Stripe compatibility change is adopted.

## Related Documentation

- [`doc/configuration.md`](configuration.md) — environment-variable and secret-file configuration model
- [`doc/reference-hosting-environment.md`](reference-hosting-environment.md) — sanitized dogfooding environment and network boundary
- [`doc/decisions.md`](decisions.md) — compact ADR index
- [`doc/adr/ADR-001-single-plugin-architecture.md`](adr/ADR-001-single-plugin-architecture.md) — proposed architecture and failure model
- [`doc/testing/preimplementation-mock-exercise.md`](testing/preimplementation-mock-exercise.md) — historical adversarial design exercise
- [`doc/testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) — real Kimai + Stripe sandbox E2E strategy
- [`SECURITY.md`](../SECURITY.md) — repository vulnerability-reporting policy, currently still partly inherited from the project template
