# Reference Hosting Environment

Date: 2026-09-03

## Purpose

This document describes the environment in which `kimai-stripe-connector` is expected to be developed, dogfooded, and initially operated.

It intentionally omits hostnames, IP addresses, domain names, credentials, account identifiers, and other site-specific addressing information.  The goal is to preserve the architectural and operational context that matters to the project without turning the repository into an infrastructure inventory.

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

The most important environmental property for this project is the network boundary: Kimai is intended to remain an internal application while Stripe provides the Internet-facing customer payment surface.

## Container Orchestration

The normal runtime environment uses Docker Swarm across multiple hosts.

Relevant characteristics include:

- services are deployed as Swarm services/stacks rather than as manually managed long-running containers;
- containers may be recreated or moved among eligible nodes;
- application correctness must therefore not depend on container-local ephemeral state;
- persistent state is supplied through external databases and/or network-backed volumes;
- service discovery and overlay networking are available inside the cluster;
- deployment configuration is expected to be declarative and reproducible.

The connector itself should not depend on Docker Swarm-specific APIs or behavior.  Swarm is where the initial installation will run, not part of the connector's application contract.

## Reverse Proxy and Application Exposure

Traefik 3.x is the reverse proxy used for containerized HTTP services in the reference environment.

Kimai is routed through the reverse proxy for internal access.  The deployment previously established that the Kimai container serves HTTP on its application port and that Kimai's trusted-host/proxy configuration must agree with the externally presented request host.

For this connector, the important rule is:

```text
Kimai should be reachable by authorized internal users
without becoming a public Internet payment endpoint.
```

The connector SHALL NOT require:

- a public Traefik router;
- a public Kimai callback endpoint;
- a Stripe webhook route into Kimai;
- public firewall/NAT exposure for Kimai;
- an Internet-facing customer portal hosted by Kimai.

A deployment outside this reference environment may use another reverse proxy or no reverse proxy at all.

## Network Posture

The reference environment follows a primarily private-service model.

### Inbound access

Kimai is expected to be accessed from trusted/private networks such as the local network or an authenticated VPN path.

The initial connector architecture does not require unsolicited inbound connections from Stripe or other public services.

### Outbound access

Kimai or the connector runtime may make outbound HTTPS connections to Stripe.

This is sufficient for:

- creating Payment Links;
- retrieving Payment Links;
- retrieving Checkout Sessions;
- retrieving PaymentIntents;
- retrieving Charges;
- deactivating connector-managed orphaned Payment Links when appropriate;
- reconciliation polling.

### Customer payment path

The customer follows a Stripe-hosted Payment Link embedded in or supplied with the Kimai-generated invoice.

The customer communicates with Stripe, not with the internal Kimai deployment, during payment.

This network separation is one of the reasons Stripe Payment Links were selected over payment workflows that require a publicly reachable billing application.

## Kimai Runtime

The reference Kimai installation is containerized.

The version used during the original architecture investigation was Kimai `2.65.0`.  That version is a design reference, not a permanent version pin for the project.

Operational observations from the reference deployment include:

- Kimai uses an external SQL database rather than a container-local SQLite database;
- plugins are installed under Kimai's plugin directory and require normal Kimai/Symfony cache and application lifecycle handling;
- the application container can be replaced without losing business data;
- trusted-host/reverse-proxy configuration matters when Kimai is behind Traefik;
- the observed container entrypoint used shell tracing (`set -x`), which can make environment-variable handling relevant to secret exposure in logs.

The connector should therefore avoid assuming that an environment variable is automatically secret merely because it is not rendered in the web UI.

## Database Services

The broader hosting environment provides shared central database services, including:

- MariaDB/MySQL;
- PostgreSQL;
- Redis.

The reference Kimai deployment uses an external MySQL/MariaDB-compatible database.

This connector should avoid introducing a second database merely for connector state unless implementation evidence demonstrates that durable connector-specific persistence is actually necessary.

The preferred architecture remains largely stateless from the connector's perspective, using Kimai's existing invoice state plus Stripe metadata/correlation information.

Redis is available in the environment but is not currently required by the connector architecture.

PostgreSQL is available in the environment but is not currently required by the connector architecture.

Availability of those services SHALL NOT be used as justification to introduce them without a real requirement.

## Persistent Storage

Persistent container volumes in the reference environment can be backed by network storage exported from a FreeBSD NAS using ZFS and NFS.

This provides durable storage independent of any one Swarm node.

The connector should minimize its own filesystem persistence requirements.  In particular, it should not require a local database, local queue, or connector-specific filesystem ledger merely to correlate Kimai invoices with Stripe payments.

If the plugin needs generated artifacts, caches, or ordinary Kimai plugin files, it should follow Kimai's normal storage conventions rather than inventing a site-specific storage layout.

## Scheduling and Background Work

The environment has external job-scheduling/automation capability available, including Jenkins and ordinary host/container scheduling mechanisms.

That makes the proposed polling model practical:

```text
scheduler
   |
   +--> kimai:stripe:reconcile
   |
   +--> kimai:stripe:cleanup
```

The connector does not need to embed a complex persistent scheduler merely to run reconciliation or cleanup.

Suitable deployments may use:

- Jenkins;
- cron;
- systemd timers;
- another container scheduler;
- manual command execution.

The commands should therefore be ordinary deterministic CLI operations that can be invoked externally.

Jenkins is part of the reference environment, not a product dependency.

## Failure and High-Availability Assumptions

The environment is resilient enough that containers can be recreated, restarted, or rescheduled, but the connector design does not assume uninterrupted availability.

The expected behavior during outages is deliberately conservative:

```text
Kimai unavailable
-> existing Stripe-hosted payment links can still be used by customers

Stripe temporarily unavailable
-> automatic link generation/reconciliation may fail or wait
-> Kimai state must not be guessed

reconciliation scheduler unavailable
-> Kimai may temporarily show a paid Stripe invoice as unpaid
-> later reconciliation should repair the stale state

cleanup scheduler unavailable
-> unused Stripe objects may accumulate
-> billing correctness should be unaffected
```

The architecture favors recoverability and eventual consistency over requiring every component to be continuously available.

## Secret Handling

The runtime will need a Stripe API credential for the connector.

The hosting environment supports ordinary container secret/configuration mechanisms, but the project has not yet frozen the exact production secret-delivery method.

Regardless of the mechanism chosen, the following apply:

- prefer a Stripe restricted API key if the required permissions can be expressed safely;
- never commit a Stripe key to the repository;
- never place the key in Stripe metadata;
- never expose it in invoice templates;
- never intentionally print it in CLI output;
- never intentionally include it in exception text;
- inspect container startup/application logs for accidental exposure;
- do not assume environment variables are log-safe in every container entrypoint.

The repository's pre-commit configuration already rejects committed `.env` files and includes secret-scanning controls.

## Observability

The hosting environment already supports centralized service operation and logging patterns typical of a containerized environment.

The connector should produce useful application/CLI logs without requiring a dedicated observability stack.

Useful connector log fields include:

- connector version;
- safe Kimai instance identifier;
- invoice number/ID;
- Stripe object IDs;
- action attempted;
- action result;
- retry/conflict reason;
- whether manual review is required.

The connector should avoid logging full Stripe payloads or customer/payment details unless required for a specific diagnostic mode.

## Development and Dogfooding Relationship

The project is expected to be developed as a general Kimai plugin while being dogfooded in this reference environment.

That creates two simultaneous requirements:

1. the plugin should work cleanly in this environment; and
2. implementation must not accidentally encode this environment as the only supported topology.

Examples of details that should remain outside application logic include:

- Docker Swarm-specific service names;
- Traefik labels unique to the local deployment;
- NFS server details;
- local network layout;
- internal DNS names;
- Jenkins job names;
- specific database service addresses;
- particular node placement rules.

These belong to deployment configuration, not connector source code.

## E2E Test Environment Versus Reference Runtime

The repository also documents a disposable containerized E2E environment in [`doc/testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md).

That test environment and this reference hosting environment have different purposes.

The reference environment is the real dogfooding/runtime context.

The E2E environment should be disposable and reproducible, typically using a dedicated Kimai container, disposable database, the plugin under test, and Stripe sandbox credentials.

Tests should not depend on the long-lived reference environment or its business data.

## Portability Requirements Derived from the Environment

The reference environment suggests several portability requirements for the connector:

- container replacement must be safe;
- local ephemeral state must not be authoritative;
- external scheduler invocation must be supported;
- reverse-proxy choice must not be hard-coded;
- no public ingress should be required;
- no specific database server address or topology should be assumed;
- no shared Redis/PostgreSQL service should be required merely because one exists;
- filesystem persistence requirements should remain minimal;
- secrets should be injectable through standard deployment mechanisms;
- the plugin should follow Kimai's supported installation/runtime model.

## Information Deliberately Excluded

This document intentionally does not contain:

- hostnames;
- IP addresses;
- domain names;
- private DNS names;
- VLAN/subnet information;
- firewall addresses/rules;
- credentials;
- secret names/values;
- Stripe account identifiers;
- database usernames/passwords;
- storage export paths;
- personally identifying infrastructure labels.

Those details are unnecessary for understanding the connector architecture and would make the repository less suitable as a portable project handoff.

## Related Documentation

- [`doc/project-handoff.md`](project-handoff.md) — complete project continuity record
- [`doc/evaluated-tools.md`](evaluated-tools.md) — billing/time-tracking products evaluated before selecting Kimai
- [`doc/decisions.md`](decisions.md) — compact ADR decision index
- [`doc/adr/ADR-001-single-plugin-architecture.md`](adr/ADR-001-single-plugin-architecture.md) — proposed connector architecture
- [`doc/testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) — disposable real-Kimai/Stripe-sandbox E2E strategy
