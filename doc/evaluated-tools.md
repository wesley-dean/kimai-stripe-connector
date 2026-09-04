# Evaluated Billing and Time-Tracking Tools

Date: 2026-09-03

## Purpose

This document records the tools evaluated while replacing Harvest and explains why self-hosted Kimai became the preferred foundation for the work that eventually became `kimai-stripe-connector`.

The purpose is historical continuity.  A future maintainer or AI/LLM session should not repeatedly reopen previously explored alternatives merely because the original comparison happened outside this repository.

This is **not** a claim that the rejected tools are bad products.  Most were rejected because they did not fit the particular operational constraints, risk tolerance, expected usage volume, or deployment model of this project.

Product features, pricing, licenses, and deployment models can change.  The statements below describe the evaluation as it existed in August and September 2026.  If an alternative is reconsidered later, current upstream facts should be reverified rather than assuming this historical record is still current.

## Original Problem

The existing tool was Harvest.  The desired replacement needed to cover a very small but real business workflow:

```text
record time
-> create invoice
-> send invoice
-> collect payment through Stripe
-> preserve an understandable payment/invoice history
```

The historical usage volume was tiny: approximately six invoices over six years, previously for one client.  Future volume could increase, but the project explicitly did not want to deploy an ERP-sized system in anticipation of hypothetical growth.

The minimum useful time record was approximately:

```text
person
+ date
+ duration
+ optional commentary
```

Tags were useful but secondary.

## Decision Drivers Used During the Evaluation

The following constraints strongly shaped the comparison.

### Deployment and ownership

Preferred:

- open source where practical;
- self-hosted;
- containerized deployment;
- suitable for an existing Docker Swarm environment;
- ordinary persistent storage, including NFS-backed volumes where appropriate;
- ability to use existing central database infrastructure;
- multi-user support.

The environment already had PostgreSQL, MariaDB/MySQL, and Redis available.

SQLite as the required or primary production database was unacceptable.

A required Elasticsearch or OpenSearch dependency was also unacceptable.

### Network posture

A central architectural preference was:

```text
internal application
-> outbound Internet access permitted as needed

public Internet
-X-> internal application
```

The billing/time application should not need public ingress merely to collect Stripe payments.

Inbound Stripe webhooks were not desired as a requirement.

Outbound polling, cron, Jenkins, or manual reconciliation were acceptable because the billing volume is very small and immediate synchronization is unnecessary.

### Payments

Stripe was the preferred payment processor.

PayPal support was not a requirement.

Manual payment reconciliation was acceptable if it remained rare and understandable.

A product did not need perfect native Stripe automation if a low-friction manual or supported-plugin path existed.

### Operational philosophy

The preferred system should be:

- mature;
- boring;
- understandable;
- well documented;
- reasonably easy to upgrade;
- small enough for the actual problem;
- recoverable when integrations fail.

The project preferred supported plugin/API surfaces over core patches, forks, or vendor modifications.

The evaluation repeatedly penalized solutions that solved the requirement only by introducing a new public endpoint, a large application stack, or substantial custom glue.

## Summary

| Tool | Historical disposition | Primary reason |
| --- | --- | --- |
| Harvest | Replace incumbent | Recurring commercial cost was difficult to justify for extremely low usage; not self-hosted/open-source |
| Kimai self-hosted | **Selected** | Best fit for mature self-hosted time tracking/invoicing; Stripe gap could be handled manually or through a small supported plugin |
| Kimai Cloud | Not selected | Hosted rather than self-hosted; did not solve the desired Stripe integration path as cleanly as self-hosted Kimai |
| Miru Cloud | Viable but not selected | Attractive native time-to-invoice-to-Stripe flow, but hosted-service dependence and product/documentation/licensing churn reduced confidence |
| Miru self-hosted | Rejected for this deployment | The self-hosted path was not judged mature/comfortable enough relative to Kimai and did not outweigh the project's concerns about churn and maintainability |
| Invoice Ninja self-hosted | Rejected | Native Stripe workflow depended on a publicly reachable client portal, conflicting with the no-public-ingress requirement |
| Dolibarr | Rejected | Much broader ERP/CRM surface than the small time-and-invoice problem justified |
| SolidInvoice | Rejected | Did not provide the required native time-tracking workflow |
| ERPNext | Rejected | Considerably more platform/ERP complexity than the use case justified |

The remainder of this document preserves the reasoning in more detail.

## Harvest

### Status

Incumbent; intentionally being replaced.

### Why it was originally acceptable

Harvest already covered the core business workflow:

- time tracking;
- invoicing;
- multi-user use;
- a familiar operating model.

There was no urgent functional crisis forcing replacement.

### Why replacement was pursued

The historical invoice volume was extremely small, so an ongoing commercial subscription was difficult to justify economically.

The project also had substantial existing self-hosting infrastructure and a general preference for owning small internal workflows where the operational burden is reasonable.

Harvest therefore became the baseline against which alternatives were compared rather than the selected long-term system.

### What would cause reconsideration

Harvest would become more attractive again if:

- billing volume increased enough that the subscription cost became negligible relative to administrative time;
- maintaining a self-hosted alternative became unexpectedly expensive;
- Kimai/plugin compatibility became a recurring operational burden.

The project should not maintain custom software merely to avoid a small SaaS bill if the maintenance cost becomes greater than the benefit.

## Kimai Self-Hosted

### Status

**Selected foundation.**

### Why it fit

Kimai provided the combination the project was seeking:

- mature time tracking;
- invoice generation;
- multi-user operation;
- self-hosting;
- normal database-backed deployment;
- a documented plugin/extension model;
- a sufficiently focused product scope.

It was large enough to replace Harvest's core functions without becoming a full ERP deployment.

### Stripe weakness

The principal gap was Stripe integration.

Kimai did not provide the exact desired native workflow:

```text
Kimai invoice
-> Stripe-hosted payment URL on invoice
-> Stripe payment
-> Kimai automatically reflects paid state
```

However, the gap was bounded and had a perfectly acceptable manual fallback:

```text
create Kimai invoice
-> manually create Stripe Payment Link
-> include link on/with invoice
-> customer pays Stripe
-> manually mark Kimai invoice paid
```

Because billing volume was so low, this manual path was operationally acceptable even if no plugin were ever written.

That observation is foundational to `kimai-stripe-connector`: the connector improves an already viable workflow rather than rescuing an unusable one.

### Why it beat broader platforms

Kimai concentrated on the problem actually being solved.  It avoided requiring the organization to operate CRM, inventory, accounting, ERP, or unrelated business-management modules merely to track time and create a few invoices.

### Remaining concerns

The main concern discovered after deployment was the plugin lifecycle rather than the underlying billing model.

The project therefore treats plugin compatibility, packaging, upgrade testing, and the ability to disable the connector cleanly as important design requirements.

## Kimai Cloud

### Status

Not selected.

### Attractive qualities

Kimai Cloud offered much of the maturity and product focus that made Kimai itself attractive while avoiding local application administration.

### Why self-hosted Kimai was preferred

The project already had suitable infrastructure and explicitly preferred an internal self-hosted billing/time application.

More importantly for the connector project, arbitrary local plugin development and testing fit naturally with self-hosted Kimai.  A hosted Kimai deployment would not provide the same direct plugin-development environment and did not eliminate the Stripe workflow gap that motivated the connector.

### Reconsideration

Kimai Cloud could be reconsidered if maintaining self-hosted Kimai became disproportionate to the business value of the workflow and the desired Stripe integration became available through supported hosted functionality.

## Miru Cloud

### Status

Viable alternative; not selected.

### Why it was attractive

Miru Cloud was one of the strongest alternatives because it offered a much more direct workflow:

```text
time tracking
-> invoice
-> Stripe payment
```

The historical pricing reviewed for a very small team was inexpensive, approximately $1 per internal member per month at the time of evaluation.  For two internal users, the absolute hosted cost was therefore not a serious financial obstacle.

The evaluation also found no separate hidden Miru transaction fee beyond normal Stripe-related payment costs in the workflow examined.

### Why it was not selected

The concern was less the price than the product's stability and long-term predictability.

During evaluation there were signs of product/documentation/licensing/pricing churn.  The project owner explicitly disliked the feeling of depending on a product that might change direction rapidly in a "too Silicon Valley" fashion.

Other maturity gaps observed during the evaluation included:

- partial payments were not supported in the desired way;
- tax/VAT behavior appeared less mature than desired;
- hosted-service data-location/GDPR questions required consideration;
- relying on the hosted product would surrender some of the deployment/control advantages that motivated replacing Harvest with self-hosted software.

None of those items necessarily makes Miru Cloud unsuitable in general.  In combination, they made the mature and self-hosted Kimai path feel more predictable.

### Reconsideration

Miru Cloud deserves fresh evaluation if:

- the product reaches a clearly stable long-term operating model;
- documentation/licensing/pricing stabilize;
- its payment and tax capabilities mature;
- maintaining Kimai and its connector proves more expensive than expected.

Because Miru Cloud already had a strong end-user workflow, it should not be dismissed permanently on historical impressions alone.

## Miru Self-Hosted

### Status

Rejected for the original deployment.

### Why it was considered

A self-hosted Miru could, in principle, combine the attractive Miru workflow with the project's preference for internal ownership.

### Why it was rejected

During the evaluation, the self-hosted path did not inspire enough confidence to outweigh Kimai's maturity, documentation, and clearer operational model.

The same broader concerns about project churn and long-term predictability applied, while self-hosting would also transfer deployment and maintenance responsibility back to the operator.  That combination weakened Miru's strongest advantage over Kimai: its hosted convenience and integrated workflow.

The surviving design record does not preserve one single technical incompatibility that permanently disqualifies self-hosted Miru.  The decision was comparative: Kimai was the more mature and better-understood self-hosted foundation for this environment.

### Reconsideration

If self-hosted Miru changes substantially, it should be reevaluated from current documentation rather than relying on this historical conclusion.

## Invoice Ninja Self-Hosted

### Status

Rejected.

### Why it was attractive

Invoice Ninja is a mature invoicing-oriented product with native Stripe support and a substantial user base.  It initially looked like a strong candidate because it could reduce the amount of custom Stripe integration work.

### Disqualifying architectural mismatch

The native Stripe/customer payment workflow relies on the Invoice Ninja client-facing portal being reachable by the customer.

That conflicts directly with the desired network boundary:

```text
Internet
-X-> internal billing application
```

The project did not want to make the billing system publicly routable merely to use Stripe.

A Lambda/webhook relay or other custom public intermediary was considered conceptually, but that would replace one small integration problem with additional public infrastructure and bespoke synchronization logic.

At the project's very low transaction volume, that complexity was not justified.

### Why this mattered more than native Stripe support

Invoice Ninja's Stripe integration was technically richer than Kimai's.  However, achieving it would weaken a more important constraint: the internal application would need a public customer surface or equivalent ingress architecture.

Kimai's weaker native Stripe story was acceptable because Stripe Payment Links permit Stripe itself to remain the public payment surface.

### Reconsideration

Invoice Ninja becomes relevant again if either:

- its supported Stripe workflow no longer requires public access to the internal application; or
- the project's network policy changes so a public client portal is acceptable.

Absent one of those changes, the original architectural mismatch remains decisive.

## Dolibarr

### Status

Rejected as excessive for the use case.

### Why it was considered

Dolibarr is open source and self-hostable and offers business functionality well beyond simple invoicing.

### Why it was rejected

The broader functionality was a liability for this particular problem.

The requirement was essentially:

```text
track small amounts of time
+ produce occasional invoices
+ collect payment
```

Operating an ERP/CRM-style platform for that narrow workflow would increase configuration, maintenance, upgrade surface, and cognitive load without providing proportionate value.

The project consistently preferred the smallest mature tool that satisfied the real requirement.

### Reconsideration

Dolibarr would become more appropriate if the business genuinely needed the broader modules it provides, rather than adopting those modules speculatively.

## SolidInvoice

### Status

Rejected.

### Why it was considered

SolidInvoice offered a more focused open-source invoicing approach than a full ERP platform.

### Why it was rejected

The desired replacement had to replace Harvest's time-tracking role as well as its invoice-generation role.

SolidInvoice did not provide the required native time-tracking workflow, so selecting it would have required another system or custom integration for time capture.

That violated the preference for reducing rather than multiplying small internal systems.

### Reconsideration

SolidInvoice could be reconsidered if time tracking were moved deliberately to another authoritative system and the project wanted an invoicing-only application.

## ERPNext

### Status

Rejected as excessive for the use case.

### Why it was considered

ERPNext is a capable open-source, self-hostable business platform and can cover invoicing along with many adjacent workflows.

### Why it was rejected

Its breadth was substantially larger than the problem being solved.

The project did not need a broad ERP deployment to support two users and historically only a handful of invoices.  The operational and conceptual surface area would be disproportionate to the benefit.

This was the same general failure mode as Dolibarr, at an even broader platform level: capability beyond the requirement can become maintenance burden rather than value.

### Reconsideration

ERPNext would deserve reconsideration only if the organization develops real ERP requirements that make the additional platform surface useful independently of this billing workflow.

## Why Kimai Plus a Small Connector Won

The final comparison was not:

```text
Which product has the most features?
```

It was closer to:

```text
Which product gives us the smallest mature self-hosted core
that solves the actual time/invoice problem
without violating the network boundary?
```

Kimai won that comparison.

The remaining Stripe gap was unusually suitable for a small connector because Stripe Payment Links provide a durable public payment surface without requiring Kimai itself to be public.

The resulting architecture is intentionally asymmetric:

```text
Kimai
  owns time + invoices
  remains private

Stripe
  owns payment processing
  remains public-facing

kimai-stripe-connector
  joins the two conservatively
```

The manual workflow remains available if the connector disappears completely.

That property reduced the risk of choosing Kimai despite its weaker native Stripe integration.

## Reconsideration Rules

A rejected tool should not be reconsidered merely because a new development session is unfamiliar with the original comparison.

Reconsideration is appropriate when one or more of the following changes:

- a hard project requirement changes;
- the rejected product materially changes;
- Kimai's maintenance cost becomes unexpectedly high;
- the connector becomes substantially larger or more fragile than intended;
- billing volume changes enough to alter the cost/complexity tradeoff;
- the network policy changes;
- a previously missing capability becomes available through a supported plugin or native feature;
- the historical facts in this document are no longer current.

When reconsidering an alternative, verify current upstream facts and record the new evidence.  Do not silently overwrite this historical rationale; preserve why the earlier decision made sense at the time.

## Related Documentation

- [`doc/project-handoff.md`](project-handoff.md) — overall project history and continuity
- [`doc/decisions.md`](decisions.md) — compact ADR decision index
- [`doc/adr/ADR-001-single-plugin-architecture.md`](adr/ADR-001-single-plugin-architecture.md) — current proposed connector architecture
- [`doc/testing/preimplementation-mock-exercise.md`](testing/preimplementation-mock-exercise.md) — historical synthetic architecture exercise
- [`doc/testing/e2e-stripe-sandbox.md`](testing/e2e-stripe-sandbox.md) — planned real Kimai + Stripe sandbox validation
