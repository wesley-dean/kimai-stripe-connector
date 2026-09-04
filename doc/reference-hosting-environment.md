# Reference Hosting Environment

Date: 2026-09-04

## Purpose

This document describes the environment in which `kimai-stripe-connector` is expected to be developed, dogfooded, and initially operated.

It intentionally omits hostnames, IP addresses, domain names, credentials, account identifiers, and other site-specific addressing information.  The goal is to preserve architectural and operational context without turning the repository into an infrastructure inventory.

This is a **reference environment**, not a product requirement.  The connector should remain usable in ordinary supported Kimai deployments that do not use Docker Swarm, Traefik, NFS, Jenkins, or the same database topology.

## Environment Summary

The reference environment is a small self-hosted/on-premises infrastructure running a multi-node Docker Swarm cluster.

Conceptually:

```text
                    private LAN / VPN
                           |
                           v
                    +-------------+
                    |   Traefik   |
                    | reverse     |
                    | proxy       |
                    +------+------+ 
                           |
              +------------+-------------+
              |                          |
              v                          v
       +-------------+            +-------------+
       |    Kimai    |            | other       |
       | container   |            | internal    |
       | service     |            | services    |
       +------+------+            +-------------+
              |
       +------+--------------------------+
       |                                 |
       v                                 v
+---------------+                +---------------+
| external SQL  |                | shared        |
| database      |                | storage       |
+---------------+                +---------------+
                                         |
                                         v
                                  +-------------+
                                  | ZFS-backed  |
                                  | NAS / NFS   |
                                  +-------------+

Kimai / connector -----------------------> Stripe
                         outbound HTTPS

Stripe -------------------X--------------> Kimai
                         no required
                         inbound path
```

The most important property is the network boundary: Kimai remains an internal application while Stripe provides the Internet-facing customer payment surface.

## Container Orchestration

The normal runtime uses Docker Swarm across multiple hosts.

Relevant characteristics:

- services run as Swarm services/stacks;
- containers may be recreated or moved among eligible nodes;
- application correctness cannot depend on container-local ephemeral state;
- persistent state is supplied by external databases and/or network-backed volumes;
- service discovery and overlay networking are available inside the cluster; and
- deployment configuration is expected to be declarative/reproducible.

The connector does not depend on Docker Swarm APIs or Swarm-specific behavior.  Swarm is an initial hosting choice, not part of the plugin application contract.

## Reverse Proxy and Exposure

Traefik 3.x is the reverse proxy used for containerized HTTP services in the reference environment.

Kimai is routed through the reverse proxy for internal access.  Kimai trusted-host/proxy configuration must agree with the externally presented request host.

The connector's accepted rule is:

```text
Kimai is reachable by authorized internal users
without becoming a public Internet payment endpoint.
```

The connector SHALL NOT require:

- a public Traefik router;
- a public Kimai callback endpoint;
- a Stripe webhook route into Kimai;
- public firewall/NAT exposure for Kimai; or
- an Internet-facing customer portal hosted by Kimai.

A deployment outside this reference environment may use another reverse proxy or no reverse proxy.

## Network Posture

### Inbound access

Kimai is expected to be accessed from trusted/private networks such as the local network or an authenticated VPN path.

ADR-005 makes unsolicited inbound Stripe/public connectivity unnecessary for connector operation.

### Outbound access

Kimai/connector runtime may make outbound HTTPS requests to Stripe for:

- Payment Link creation/retrieval;
- Checkout Session retrieval;
- PaymentIntent retrieval;
- Charge retrieval;
- conservative Payment Link deactivation; and
- scheduled reconciliation.

### Customer payment path

The customer follows a Stripe-hosted Payment Link embedded in or supplied with the Kimai-generated invoice.

The customer communicates with Stripe rather than the internal Kimai deployment during payment.

## Kimai Runtime

The reference Kimai installation is containerized.

Kimai `2.65.0` is the current Accepted initial compatibility baseline under ADR-004.  It is an exact implementation/test target, not a promise that the project will remain permanently pinned to that release.

Operational observations from the reference deployment include:

- external SQL rather than container-local SQLite;
- plugins installed under Kimai's normal plugin directory and managed through Kimai/Symfony lifecycle handling;
- replaceable application containers without loss of business data;
- trusted-host/reverse-proxy configuration matters behind Traefik; and
- observed Kimai container startup shell tracing (`set -x`) can make environment values visible in logs.

The connector therefore does not assume an environment variable is secret merely because it is absent from the web UI.

## Database Services

The broader hosting environment provides shared central database services, including:

- MariaDB/MySQL;
- PostgreSQL; and
- Redis.

The reference Kimai deployment uses an external MySQL/MariaDB-compatible database.

The connector does not introduce a second connector database merely because shared database infrastructure exists.  ADR-001 preserves Kimai + Stripe as the authoritative systems, and a connector-owned payment ledger requires new evidence plus a later ADR.

Redis and PostgreSQL availability likewise do not justify introducing those dependencies.

## Persistent Storage

Persistent container volumes can be backed by network storage exported from a FreeBSD NAS using ZFS and NFS.

This supplies durable storage independent of any one Swarm node.

The connector should minimize its own filesystem persistence requirements and follow normal Kimai plugin/storage conventions.  It should not require a local queue, connector-specific filesystem ledger, or site-specific storage layout for payment correlation.

## Scheduling and Background Work

The environment has Jenkins plus ordinary host/container scheduling mechanisms.

That makes the Accepted polling/repair model practical:

```text
scheduler
   |
   +--> reconciliation command
   |
   +--> cleanup command
```

Suitable deployments may use:

- Jenkins;
- cron;
- systemd timers;
- another container scheduler; or
- manual execution.

The connector does not embed a persistent scheduler merely to perform reconciliation or cleanup.  Commands should remain deterministic, idempotent, externally invokable CLI operations.

Jenkins is reference-environment context, not a product dependency.

## Failure and Availability Assumptions

The connector does not assume uninterrupted availability.

Expected behavior is deliberately conservative:

```text
Kimai unavailable
-> existing Stripe-hosted Payment Links can still be used

Stripe temporarily unavailable
-> automatic link generation/reconciliation may fail or wait
-> Kimai state is not guessed

reconciliation scheduler unavailable
-> Kimai may temporarily show a paid Stripe invoice as unpaid
-> later reconciliation repairs stale state

cleanup scheduler unavailable
-> unused Stripe objects may accumulate
-> billing correctness remains unaffected
```

The architecture favors recoverability/eventual consistency over continuous coupling.

## Configuration and Secret Handling

The accepted configuration model is documented in [`configuration.md`](configuration.md).

Key rules for this environment:

- connector configuration uses `KIMAI_STRIPE_CONNECTOR_*` environment variables;
- environment variables are the primary configuration interface;
- file-backed secret injection is preferred for the Stripe credential in containerized production;
- `.env` support is tertiary local/dev/test convenience;
- the initial connector uses one least-privilege Stripe restricted key under ADR-004;
- never commit the key;
- never place it in Stripe metadata or invoice/template data;
- never intentionally print it in CLI output, logs, or exception text; and
- inspect startup/application logs for accidental disclosure.

Docker/Swarm secrets are a natural fit in this reference environment, but the connector's file-backed secret interface remains runtime-agnostic so equivalent mechanisms can be used elsewhere.

## Observability

The connector should produce useful logs without requiring a dedicated plugin-specific observability stack.

Useful safe fields include:

- connector version;
- safe Kimai instance UUID;
- invoice ID/number;
- Stripe object IDs;
- action attempted;
- ADR-013 operator result category;
- retry/conflict reason; and
- manual-review indication.

Avoid full Stripe payloads, API credentials, unnecessary customer data, and payment-method/card details.

## Development and Dogfooding

The project is developed as a general Kimai plugin while being dogfooded in this environment.

That creates two simultaneous requirements:

1. work cleanly in the reference environment; and
2. avoid encoding this environment as the only supported topology.

Details that remain deployment-specific include:

- Swarm service names;
- site-specific Traefik labels;
- NFS server/export details;
- network layout;
- internal DNS names;
- Jenkins job names;
- database service addresses; and
- node placement rules.

## Disposable E2E Versus Reference Runtime

The long-lived reference environment is not a test fixture.

The executable E2E harness under `tests/e2e/` constructs a fresh Kimai 2.65.0 + MySQL Compose environment with test-owned volumes for each run and destroys containers, network, database state, Kimai data, and installed-plugin state at teardown.

Real Stripe sandbox E2E will reuse that disposable Kimai environment rather than the dogfooding deployment.

See [`testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) and [`../tests/e2e/README.md`](../tests/e2e/README.md).

## Portability Requirements Derived From the Environment

The reference environment reinforces these requirements:

- container replacement must be safe;
- local ephemeral state is not authoritative;
- external scheduler invocation is supported;
- reverse-proxy choice is not hard-coded;
- public ingress is unnecessary;
- database topology/address is not hard-coded;
- Redis/PostgreSQL are not dependencies merely because they exist;
- filesystem persistence remains minimal;
- standard secret injection mechanisms are supported; and
- the plugin follows Kimai's supported installation/runtime model.

## Information Deliberately Excluded

This document intentionally omits:

- hostnames;
- IP addresses;
- domain names/private DNS names;
- VLAN/subnet information;
- firewall addresses/rules;
- credentials/secret values;
- Stripe account identifiers;
- database usernames/passwords;
- storage export paths; and
- personally identifying infrastructure labels.

Those details are unnecessary for understanding the connector and would reduce portability of the repository as a project handoff.

## Related Documentation

- [`project-handoff.md`](project-handoff.md) — current project continuity record
- [`configuration.md`](configuration.md) — Accepted configuration interface
- [`threat-model.md`](threat-model.md) — security boundaries and controls
- [`evaluated-tools.md`](evaluated-tools.md) — product-selection history
- [`decisions.md`](decisions.md) — Accepted ADR index
- [`adr/ADR-001-single-plugin-architecture.md`](adr/ADR-001-single-plugin-architecture.md) — Accepted package-level architecture
- [`adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md`](adr/ADR-004-freeze-initial-compatibility-and-stripe-contract.md) — compatibility/credential baseline
- [`adr/ADR-005-private-kimai-outbound-stripe-integration.md`](adr/ADR-005-private-kimai-outbound-stripe-integration.md) — private-network boundary
- [`testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) — disposable real-Kimai/Stripe-sandbox strategy
