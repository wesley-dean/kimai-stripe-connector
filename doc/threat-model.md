# Threat Model

Date: 2026-09-04

## Status

Living pre-feature threat model.

The repository now contains an inert Kimai plugin skeleton, PHP/Composer tooling, and a disposable Kimai lifecycle E2E harness, but it does not yet contain production Stripe mutation or reconciliation behavior.  This model therefore distinguishes between controls already present in the repository, controls fixed by Accepted ADRs but not yet implemented, and genuinely unresolved implementation details.

The threat model must evolve with the code.  A trust-boundary, credential, external-interface, destructive-operation, payment-lifecycle, persistence, or distribution change should update this document in the same coherent change.

## Scope

This document covers `kimai-stripe-connector` and its immediate trust relationships with:

- Kimai;
- Stripe;
- the deployment/runtime environment;
- operators and administrators;
- invoice recipients/customers;
- local and CI test environments; and
- the repository/build/release supply chain.

It does not attempt to threat-model all of Kimai, Stripe, Docker, Docker Swarm, Traefik, MySQL/MariaDB, the host operating system, or the surrounding network in full.

The connector is security-relevant because it can eventually:

- use a Stripe restricted API credential;
- create and deactivate Stripe Payment Links;
- inspect Stripe payment state;
- influence the payment URL rendered on a Kimai invoice; and
- change Kimai invoice paid state.

The connector is not intended to:

- receive card numbers;
- store payment credentials;
- host Checkout;
- receive Stripe webhooks;
- expose a public customer payment endpoint; or
- become a general accounting/payment ledger.

## Governing Accepted Decisions

The following ADRs directly constrain this model:

- ADR-001: one Kimai plugin with two independently disableable capabilities;
- ADR-003: executable behavior changes require appropriate automated tests;
- ADR-004: initial Kimai/PHP/Stripe/payment/money/credential contract;
- ADR-005: Kimai remains private and Stripe integration is outbound-only;
- ADR-006: supported Kimai plugin/extension seams only;
- ADR-007: Stripe Payment Links are the customer payment surface;
- ADR-008: versioned Stripe metadata provides durable correlation;
- ADR-009: reconciliation requires strong exact payment evidence;
- ADR-010: cleanup deactivates only proven unpaid orphans;
- ADR-011: partial failures are handled through idempotency and rediscovery;
- ADR-012: release artifacts are self-contained Kimai plugins; and
- ADR-013: operator-visible results use a stable taxonomy.

Historical detail from the original broad ADR-001 remains available under `doc/adr/rationale/`, but the focused Accepted ADRs above are normative.

## Security Goals

The primary goals are:

1. A customer is directed only to the intended Stripe-hosted payment surface for the intended Kimai invoice.
2. The connector never marks a Kimai invoice paid without strong, exact, unambiguous Stripe evidence.
3. Stripe credentials remain confidential.
4. Malformed, stale, hostile, or ambiguous remote data cannot create unsafe accounting changes.
5. Partial failures and uncertain network outcomes converge safely rather than multiplying payment surfaces.
6. Cleanup never deactivates a legitimate or paid Payment Link when state is uncertain.
7. Kimai does not require public Internet ingress for connector operation.
8. Stable instance identity and metadata cannot silently drift without producing detectable conflicts.
9. The plugin remains removable/disableable without patching Kimai core.
10. The build/release path makes unauthorized dependency or source changes difficult to hide.
11. Logs provide enough evidence for diagnosis without becoming a secondary store of secrets or customer/payment data.

## Non-Goals

The connector cannot guarantee:

- protection from a fully compromised Kimai host/container;
- protection from a fully compromised Stripe account;
- protection from an administrator who already has equivalent infrastructure/financial privilege;
- global accounting correctness outside the connector's narrow automatic transitions;
- protection from a compromised customer browser/device; or
- PCI/compliance conclusions beyond the architectural fact that Stripe hosts payment entry.

## Assets

### Stripe restricted API key

High-value secret.  ADR-004 selects one least-privilege restricted key for the initial connector.

Compromise may allow an attacker to read or mutate Stripe objects within the granted permission set.

### Kimai invoice state

Security-sensitive business data.  Incorrect paid-state mutation can suppress collection activity or misrepresent receivables.

### Payment Link identity and URL

The URL is not necessarily secret, but its integrity is critical.  Substitution can direct payment to the wrong invoice or payment surface.

### Stable Kimai instance UUID

Non-secret but integrity-sensitive.  Rotation is a metadata namespace migration.

### Versioned metadata contract

Integrity-sensitive correlation data.  Reconciliation and rediscovery rely on exact version/instance/invoice/amount/currency semantics.

### Deployment configuration

Capability switches, secret source, instance identity, and cleanup settings affect security behavior.

### Logs and diagnostic artifacts

Useful forensic evidence that can become a privacy/secret leak if overly verbose.

### Source, dependencies, CI, and release artifact

Compromise of this path can put attacker-controlled code in a Kimai process with access to Stripe credentials and invoice state.

## Actors

### Authorized operator

Legitimate administrator who installs/configures the plugin and invokes scheduled/manual commands.

### Kimai user

Legitimate internal user whose Kimai permissions may allow invoice creation or influence invoice data.

### Customer/invoice recipient

External user who receives a Kimai invoice and follows the Stripe-hosted payment link.

### Internet attacker

External actor without legitimate Kimai/Stripe administrative access.

### Compromised internal account

Legitimate Kimai/infrastructure account controlled by an attacker.  This actor has materially more influence than an Internet-only attacker.

### Supply-chain attacker

Actor introducing malicious code through source control, dependencies, CI actions, build infrastructure, or release artifacts.

## Trust Boundaries

### Boundary 1: Internal user to Kimai

```text
operator / Kimai user
        |
        v
      Kimai
```

Kimai authentication and authorization govern user access.  The connector should not create parallel weak authorization paths.

### Boundary 2: Connector to Stripe

```text
Kimai connector
      |
      | outbound TLS/HTTPS
      v
    Stripe API
```

Stripe responses are authoritative only for Stripe-owned facts.  They still require exact identity and financial validation before Kimai mutation.

### Boundary 3: Customer to Stripe Checkout

```text
Kimai invoice
     |
     | Stripe-hosted Payment Link URL
     v
customer browser
     |
     v
Stripe-hosted Checkout
```

Kimai is not in the customer payment network path.

### Boundary 4: Deployment configuration to connector

```text
environment variables / mounted secret files
                 |
                 v
              connector
```

Deployment configuration is privileged input but still receives strict syntax/consistency validation.

### Boundary 5: Scheduler/operator to CLI commands

```text
cron / Jenkins / systemd / operator
               |
               v
        connector command
```

Commands must tolerate repeated, delayed, and potentially concurrent execution.

### Boundary 6: Repository/build to installed plugin

```text
source + lockfile
       |
       v
CI / package build
       |
       v
self-contained plugin artifact
       |
       v
Kimai var/plugins
```

The plugin carries its own production Composer dependencies under ADR-012.

### Boundary 7: Test infrastructure to Stripe sandbox

```text
ephemeral Kimai test runtime
       |
       | outbound HTTPS
       v
Stripe sandbox
```

Sandbox credentials are still secrets.  Test isolation and cleanup must not depend on production infrastructure.

## Core Data Flows

### Payment Link generation

```text
Kimai invoice model
      |
      | invoice identity / amount / currency / instance UUID
      v
connector
      |
      | authenticated Stripe API request
      v
Stripe Payment Link
      |
      | ID / URL / metadata
      v
connector
      |
      v
Kimai invoice rendering
```

### Reconciliation

```text
Kimai unpaid invoice
      |
      v
connector
      |
      | read exact Link / Session / PaymentIntent / Charge evidence
      v
Stripe
      |
      | mutually consistent evidence
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
      +--> establish current Kimai invoice existence
      +--> establish payment evidence
      +--> establish grace period
      |
      v
retain / report / deactivate
```

## Threat: Stripe Credential Disclosure

### Scenarios

The key appears in:

- container startup/shell tracing;
- application logs;
- environment dumps;
- exception messages;
- Twig variables or generated invoices;
- CI logs/artifacts;
- committed `.env` files; or
- support/debug bundles.

### Impact

Attacker gains the Stripe capabilities granted to the restricted key.

### Controls

- one least-privilege restricted key under ADR-004;
- file-backed secret delivery preferred in containerized production;
- direct environment secret supported only as an alternate delivery mechanism;
- both secret forms simultaneously configured -> configuration failure;
- key is never sent to templates or Stripe metadata;
- application/CLI logs must redact secrets;
- pre-commit/CI secret scanning remains enabled;
- common committed `.env` files are rejected;
- log-content tests cover direct and file-backed secrets;
- credential rotation is an operator procedure before production release; and
- reference-container shell-tracing behavior is treated as a real exposure risk.

### Residual risk

A sufficiently privileged host/container administrator can access runtime secrets.  The connector cannot isolate a secret from equivalent infrastructure privilege.

## Threat: Excessive Stripe Credential Privilege

### Scenario

The connector is deployed with a broad Stripe secret key or a restricted key granting unrelated powers.

### Impact

Credential compromise has a much larger blast radius than connector behavior requires.

### Accepted control

ADR-004 selects one least-privilege restricted key initially.  The key should grant only the operations needed for connector-managed Payment Links, inline one-time product/price creation, Checkout Session retrieval, PaymentIntent retrieval, and Charge retrieval.

Payout, subscription, customer-management, account-administration, and unrelated generalized billing permissions are outside the intended permission set.

### Remaining validation work

Verify the exact Stripe dashboard permission labels using a real restricted sandbox key.  Document that verified permission set and test the failure mode for a key missing each required class of operation.

Separate credentials are not the initial design.  Splitting privileges later requires evidence that the blast-radius benefit outweighs deployment/rotation complexity.

## Threat: Payment Link Substitution or Misdirection

### Scenarios

Invoice A receives a link belonging to:

- invoice B;
- another Kimai instance;
- another amount/currency;
- another Stripe account; or
- an attacker-controlled arbitrary URL.

### Impact

Customer may pay the wrong obligation or payment recipient.

### Controls

- link creation/recovery only through the configured Stripe client;
- exact versioned metadata correlation;
- stable instance UUID;
- object-native amount/currency validation;
- arbitrary user-supplied URLs are never treated as connector-created links;
- conflicting same-identity links fail closed;
- rendered URL is tested against the exact Stripe object for the invoice; and
- Payment Link/Checkout remain Stripe-hosted under ADR-007.

### Implementation decision still required

Determine whether returned Payment Link URLs should receive explicit Stripe hostname/scheme validation in addition to trusting the authenticated Stripe SDK response.  Any validation must avoid fragile assumptions about legitimate Stripe URL evolution.

## Threat: False Paid-State Transition

### Scenarios

Kimai is marked paid because a link/session exists, Checkout completed while payment is still processing, the PaymentIntent is unsuccessful, the amount/currency/metadata is wrong, payment is partial, a charge is uncaptured, or multiple candidates are ambiguous.

### Impact

Unsettled receivable appears settled.

### Controls

- ADR-009 fail-closed reconciliation;
- exact metadata version, instance, invoice, amount, and currency;
- Stripe-native fields validated rather than trusting metadata alone;
- strong PaymentIntent/Charge evidence where required;
- no amount-only/customer-only/date-only matching;
- no automatic partial-payment settlement;
- completed Checkout alone is not universal payment proof;
- initial automatic direction is not-paid -> paid only; and
- negative/adversarial cases are required by the test matrix.

### Residual risk

Stripe payment-state semantics can evolve.  SDK/API updates are compatibility-relevant behavioral changes and require tests under ADR-003/ADR-004.

## Threat: Unauthorized Paid-State Mutation Through CLI/Scheduler

### Scenario

An unauthorized process invokes reconciliation or manipulates its environment.

### Controls

- rely on OS/container/Kimai administrative boundaries for command execution;
- no unauthenticated HTTP reconciliation endpoint;
- command does not accept arbitrary desired invoice state;
- command derives any mutation from Kimai + Stripe evidence;
- dry-run support;
- idempotent behavior;
- minimal audit-safe identifiers in logs; and
- stable operator result taxonomy under ADR-013.

### Implementation validation

Verify whether Kimai provides any additional plugin CLI permission mechanism beyond process-level access.  Do not invent an HTTP authorization layer merely to solve a CLI concern.

## Threat: Duplicate Payment Links After Uncertain Network Outcome

### Scenario

Stripe creates a Payment Link, the response is lost, and a retry creates another active payment surface.

### Controls

- Stripe idempotency for immediate retries where appropriate;
- durable metadata rediscovery after the idempotency window;
- exact lookup before creation/retry;
- same-identity financial conflict detection;
- controlled timeout-after-commit tests;
- concurrency tests; and
- conservative unpaid-orphan cleanup.

### Residual risk

Exactly-once distributed behavior is not guaranteed.  ADR-011 requires safe convergence and recoverability instead.

## Threat: Cross-Instance Correlation Collision

### Scenario

Two Kimai installations sharing Stripe both create an invoice with the same invoice number.

### Controls

- stable `kimai_instance` UUID in metadata;
- instance identity included in correlation/idempotency decisions;
- unknown/missing metadata versions fail closed; and
- multi-instance distractor tests.

## Threat: Instance Identity Drift

### Scenario

`KIMAI_STRIPE_CONNECTOR_INSTANCE_ID` changes accidentally or maliciously.

### Impact

Historical Stripe objects become undiscoverable, duplicate links may be created, or cleanup may misclassify older objects.

### Controls

- UUID is stable deployment identity;
- safe startup logging may include the UUID;
- rotation is documented as migration;
- old/unknown namespaces are never automatically cleaned merely because the current ID differs; and
- configuration validation requires an acceptable UUID when correlation-capable behavior is enabled.

## Threat: Metadata Tampering or Collision

### Scenario

Another integration or privileged Stripe operator changes metadata to make an unrelated object appear connector-managed.

### Controls

- versioned namespace;
- multiple mutually consistent identity fields;
- amount/currency checked against Stripe-native fields;
- relationships among Link, Session, PaymentIntent, and Charge checked where required;
- unknown versions fail closed; and
- conflicts return `CONFLICT` or `MANUAL_REVIEW_REQUIRED` rather than mutation.

### Residual risk

An administrator with broad Stripe write authority can manipulate both metadata and payment objects.  The connector cannot fully defend against equivalent financial-system privilege.

## Threat: Cleanup Deactivates a Legitimate Link

### Scenario

Kimai is temporarily unavailable, incomplete, or slow and cleanup interprets uncertainty as absence.

### Controls

- no successful Kimai existence check -> retain;
- age alone never makes a legitimate invoice link disposable;
- grace period applies only to proven orphans;
- any payment evidence -> retain/report;
- paid orphan -> `MANUAL_REVIEW_REQUIRED`;
- dry-run before destructive operation; and
- deactivation decisions receive structured minimal logs/tests.

## Threat: Paid Orphan Is Destroyed or Reassigned

### Scenario

Stripe proves payment but no completed Kimai invoice exists.

### Controls

- paid orphan is an accounting exception;
- retain evidence;
- never fabricate an invoice;
- never attach payment to another invoice automatically; and
- require human review.

## Threat: Refund/Dispute Rewrites Historical Payment

### Scenario

A previously reconciled payment is refunded/disputed and automation flips Kimai back to unpaid/canceled.

### Control

ADR-009 records historical payment monotonically in the initial model.  Refund/dispute conditions are surfaced for review and do not trigger automatic paid-state reversal.

## Threat: Malicious Business Text Reaches Shell/Template/Log Context

### Scenarios

Customer/project/invoice text contains shell metacharacters, Twig syntax, HTML, control characters, terminal escapes, or other hostile-looking content.

### Controls

- treat business text as data;
- never build shell commands from invoice/customer values;
- use Symfony/Stripe APIs directly;
- expose only necessary scalar connector values to Twig;
- rely on appropriate template escaping;
- structure/sanitize logs; and
- adversarial string tests.

### Implementation validation

Verify actual Kimai/Twig escaping behavior for connector hydrator values when invoice rendering is implemented.

## Threat: SSRF or Arbitrary Outbound Requests

### Scenario

An attacker controls an endpoint/URL and causes connector requests to internal or unintended systems.

### Controls

- production Stripe base endpoint is not a user-controlled connector setting;
- no arbitrary URL fetches from business data;
- authenticated Stripe SDK/client used for production requests; and
- test-only fake-Stripe endpoints, if introduced, remain clearly separated from production configuration.

### Implementation requirement

If endpoint override exists for deterministic tests, add a hard production guard and tests proving ordinary production configuration cannot redirect the Stripe client.

## Threat: Configuration Injection or Contradiction

### Scenarios

Configuration disables safety-relevant capabilities unexpectedly, changes instance identity, points a secret-file path somewhere unintended, supplies malformed values, or mixes test/live credentials.

### Controls

- `KIMAI_STRIPE_CONNECTOR_*` namespace;
- strict parsing/validation;
- both secret sources -> error;
- unreadable/blank secret file -> error;
- invalid UUID/boolean/grace period -> error;
- file path is deployment-controlled and never silently replaced;
- safe startup configuration summary; and
- no independent operator-selectable Stripe API version/payment-method/metadata version in v1.

### Remaining implementation question

The secret-file path remains runtime-agnostic rather than restricted to `/run/secrets`.  Revisit only if implementation evidence shows portability creates an unacceptable local-file disclosure risk.

## Threat: Stripe SDK/API Version Drift

### Scenario

Dependency updates change request fields, generated object models, or payment semantics.

### Controls

- ADR-004 baseline `stripe/stripe-php ^21.3.1`;
- exact lockfile required for reproducible builds;
- connector follows SDK-associated API version rather than account-default drift;
- dependency changes are behavioral changes requiring tests;
- real Stripe sandbox E2E covers high-value contract behavior; and
- unknown/malformed payment states fail closed.

## Threat: Kimai Upgrade Breaks Extension Points

### Scenario

Kimai changes plugin loading, invoice events, hydrators, rendering, paid-state APIs, or console lifecycle behavior.

### Controls

- initial baseline Kimai 2.65.0;
- exact-version disposable lifecycle E2E;
- supported extension seams only;
- no core patches;
- connector can be disabled/removed;
- manual workflow remains available; and
- later-version compatibility is claimed only after executable evidence.

## Threat: Supply-Chain Compromise

### Scenarios

Malicious dependency, compromised GitHub Action, maintainer account, PR, build environment, or release artifact introduces code with access to Stripe/Kimai authority.

### Current repository controls

- PR-based review workflow;
- pinned GitHub Action references in project workflows;
- MegaLinter with errors enabled and auto-fixes disabled;
- PHP unit/static-analysis CI;
- disposable Kimai E2E;
- Doxygen warning gate;
- Dependabot for GitHub Actions and Composer;
- Gitleaks/TruffleHog-style secret scanning in repository tooling;
- self-contained plugin packaging with an explicit dependency graph; and
- deliberate preference for a small dependency surface.

CodeQL and OpenSSF Scorecard are not relied upon as current blocking controls for this private repository.

### Required before release-quality packaging

- commit `composer.lock` as required by ADR-004;
- build production artifacts from the locked graph;
- verify packaged artifact lifecycle in disposable Kimai;
- decide release provenance/signing/attestation strategy before public distribution; and
- resolve the public license before crossing the repository-public/public-contribution boundary.

## Threat: Capability Disablement Leaves Misleading Stale State

### Scenario

Reconciliation remains disabled/broken while Stripe payments accumulate.

### Impact

Kimai may show paid invoices as unpaid, creating operational confusion or duplicate collection attempts.

### Controls

- capability state is explicit configuration;
- reconciliation is repair-style and catches up later;
- disabled/error state should be visible in logs/operator results;
- manual reconciliation remains available; and
- future backlog-age reporting may be added if operational evidence warrants it.

## Threat: Manual Actions Race With Automation

### Scenarios

Operator manually creates/deactivates a link, marks an invoice paid, edits metadata, or refunds a payment while connector automation operates.

### Controls

- exact matching rather than fuzzy inference;
- already-paid Kimai state is respected;
- idempotent commands;
- conflicts fail closed;
- concurrency tests; and
- connector does not assume every Kimai invoice was created with the connector.

## Threat: Stripe Outage or Rate Limit Blocks Invoice Automation

### Scenario

Stripe is unavailable during synchronous automatic Payment Link creation.

### Accepted tradeoff

For an invoice expected to contain an automatically generated Stripe link, explicit invoice-generation failure can be safer than issuing a document with an unknown or incorrect payment surface.

### Controls

- operator-visible error/result;
- bounded retry behavior;
- distinguish retryable transport failure from conflict;
- no infinite blocking retry loop;
- manual connector disable/manual link creation remains available; and
- reconciliation failures remain separate from invoice-generation correctness.

## Threat: Sensitive Customer/Payment Data in Logs

### Scenario

Full Stripe payloads, addresses, emails, payment-method data, or invoice content are logged unnecessarily.

### Controls

- log identifiers and state transitions rather than full objects;
- never log API keys;
- avoid payment-method/card detail logging;
- debug mode, if added, still redacts secrets;
- structured result taxonomy avoids dumping objects merely to explain an outcome; and
- operator documentation should address log retention once production deployment begins.

## Threat: E2E Credential/Data Leak

### Scenario

Sandbox key or test customer/payment data leaks through CI logs, browser traces, screenshots, artifacts, or repository files.

### Controls

- CI secret storage;
- no committed sandbox keys;
- no shell tracing of secrets;
- run-specific identifiers;
- minimal artifacts;
- review screenshots/traces before retention;
- ephemeral Kimai/MySQL containers/volumes; and
- Stripe-side test objects identified for safe cleanup/deactivation.

## Threat: Public Ingress Is Accidentally Introduced

### Scenario

A webhook/controller/callback seems convenient and leads to Kimai being exposed publicly.

### Controls

- ADR-005 forbids required public ingress/webhooks;
- no public route is added without a new Accepted architectural decision;
- externally scheduled polling/repair is the reconciliation model; and
- disposable E2E should preserve the same no-Stripe-to-Kimai network property.

## Threat: Plugin Scope Expands Into a Financial Platform

### Scenario

Ledger, tax, subscriptions, dunning, refund automation, partial-payment accounting, customer accounting, or generalized processor abstraction accumulate around the connector.

### Controls

- explicit scope boundaries in ADR-001/AGENTS/playbook;
- new architecture ADR before material expansion;
- Kimai/Stripe authority separation;
- no connector-owned ledger without evidence; and
- growing implementation complexity is treated as a design-warning signal.

## Required Abuse/Security Coverage

The `THR-*` identifiers below are tracked in [`testing/test-matrix.md`](testing/test-matrix.md).  Documentation defines required coverage; it does not imply that every test already exists.

```text
THR-001 direct Stripe secret never appears in logs
THR-002 file-backed Stripe secret never appears in logs
THR-003 invoice preview creates no Stripe object
THR-004 wrong-instance Payment Link is never reused
THR-005 same identity + wrong amount is a conflict
THR-006 same identity + wrong currency is a conflict
THR-007 arbitrary external URL cannot become connector-generated payment URL
THR-008 completed-but-unpaid Checkout never marks Kimai paid
THR-009 partial payment never marks Kimai paid
THR-010 refund/dispute evidence does not create unsafe automatic reversal
THR-011 ambiguous multiple candidates produce no mutation
THR-012 Kimai unavailable during cleanup deactivates nothing
THR-013 paid orphan is retained/reported
THR-014 timeout-after-commit retry converges without intentional duplicate logical link
THR-015 malformed Stripe response fails closed
THR-016 hostile business text remains data
THR-017 both Stripe secret forms configured -> configuration error
THR-018 test/live credential mismatch is detected if safely possible
THR-019 reconciliation rerun is idempotent
THR-020 concurrent reconciliation does not create unsafe state
THR-021 plugin disabled leaves manual workflow viable
```

Behavior-changing fixes or implementations for these areas require appropriate regression/behavior tests under ADR-003.

## Risk Prioritization

### High

1. Stripe credential disclosure or excessive privilege.
2. False Kimai paid-state transition.
3. Payment Link miscorrelation/substitution.
4. Supply-chain compromise of plugin code/dependencies/artifact.
5. Cleanup of legitimate/paid links through uncertain state.

### Medium

6. Duplicate Payment Links after uncertain remote commit.
7. Instance identity/configuration drift.
8. Kimai/Stripe compatibility drift.
9. Sensitive customer/payment information in logs/artifacts.
10. Manual/automatic races.

### Lower but operationally relevant

11. Sandbox credential leakage.
12. Stripe outage causing invoice automation failure.
13. Long reconciliation backlog leaving stale Kimai state.
14. Scope creep enlarging the trust/compliance boundary.

Revisit prioritization after each major capability lands because concrete implementation can expose risks not visible at architecture time.

## Security Design Principles

The connector should consistently:

- fail closed on financial ambiguity;
- use least privilege;
- minimize secrets and dependencies;
- keep Kimai private;
- prefer deterministic/idempotent repair over fragile real-time coupling;
- distinguish absence of evidence from evidence of absence;
- preserve historical payment facts rather than rewriting them automatically;
- treat cleanup as destructive;
- preserve manual fallback;
- log enough for audit/recovery without logging sensitive payloads;
- validate configuration before mutation;
- keep compatibility evidence executable; and
- keep test infrastructure ephemeral and separate from the dogfooding environment.

## Review Triggers

Revisit this model when:

- configuration/credential parsing is implemented;
- the exact Stripe restricted-key permission labels are verified;
- Payment Link creation is implemented;
- real Stripe sandbox E2E is enabled;
- reconciliation is implemented;
- cleanup is implemented;
- support for asynchronous payment methods or additional currencies is proposed;
- Kimai-native settings/UI is introduced;
- a public route/webhook is proposed;
- a local persistence layer is proposed;
- release packaging/provenance is finalized;
- the repository becomes public;
- a major Kimai/Stripe compatibility baseline changes; or
- a security incident/near-miss reveals a missing threat.

## Related Documentation

- [`configuration.md`](configuration.md) — current namespaced configuration/secret contract
- [`decisions.md`](decisions.md) — Accepted ADR index
- [`adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md`](adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md) — credential, money, payment-method, SDK contract
- [`adr/ADR-005-private-kimai-outbound-stripe-integration.md`](adr/ADR-005-private-kimai-outbound-stripe-integration.md) — no-public-ingress boundary
- [`adr/ADR-008-use-versioned-stripe-metadata-for-correlation.md`](adr/ADR-008-use-versioned-stripe-metadata-for-correlation.md) — identity/correlation
- [`adr/ADR-009-reconcile-only-on-strong-stripe-payment-evidence.md`](adr/ADR-009-reconcile-only-on-strong-stripe-payment-evidence.md) — reconciliation safety
- [`adr/ADR-010-clean-up-only-proven-unpaid-orphan-payment-links.md`](adr/ADR-010-clean-up-only-proven-unpaid-orphan-payment-links.md) — cleanup safety
- [`adr/ADR-011-design-for-idempotent-recovery-from-partial-failure.md`](adr/ADR-011-design-for-idempotent-recovery-from-partial-failure.md) — retry/recovery safety
- [`reference-hosting-environment.md`](reference-hosting-environment.md) — sanitized dogfooding network/runtime context
- [`testing/test-matrix.md`](testing/test-matrix.md) — required test-layer mapping
- [`testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) — ephemeral Kimai + real Stripe sandbox strategy
- [`../tests/e2e/README.md`](../tests/e2e/README.md) — executable disposable Kimai lifecycle harness
- [`../SECURITY.md`](../SECURITY.md) — vulnerability reporting policy
