# Security Policy

## Security Context

`kimai-stripe-connector` is security-sensitive integration software.  The connector is designed to use a Stripe restricted API credential, create or inspect Stripe payment objects, render a Stripe-hosted payment URL on Kimai invoices, and conservatively update Kimai invoice paid state.

The project deliberately keeps Kimai private, does not require Stripe webhooks or public callback routes, fails closed on ambiguous financial evidence, and treats secret handling, reconciliation, cleanup, and dependency/release integrity as security boundaries.

See `doc/threat-model.md` for the current detailed model and `doc/decisions.md` for the Accepted architectural decisions that constrain implementation.

## Reporting a Vulnerability

Please report suspected vulnerabilities privately by email to:

`security_vulnerability_disclosure@wesleydean.com`

Include enough information to understand and reproduce the issue where practical, but do not send live Stripe API keys, full customer/payment datasets, or unrelated secrets.

Good-faith attempts will be made to acknowledge, investigate, and remediate reports responsibly.  Please do not open a public issue for an uncoordinated disclosure while a vulnerability is still being assessed.

## Particularly Relevant Reports

Security reports are especially useful when they involve:

- Stripe credential disclosure or excessive privilege;
- payment-link substitution or miscorrelation;
- an incorrect automatic Kimai paid-state transition;
- bypass of the no-public-ingress boundary;
- unsafe cleanup/deactivation of legitimate Stripe Payment Links;
- metadata/instance-identity confusion;
- SSRF or attacker-controlled Stripe endpoint selection;
- secret leakage through logs, CLI output, generated invoices, CI artifacts, or exception text;
- dependency/release-artifact compromise; or
- behavior that defeats the connector's fail-closed/manual-fallback design.

## Supported Versions

Until the project begins publishing supported releases, security fixes apply to the current development line rather than a formal multi-version support matrix.

When releases begin, supported-version policy should be documented here alongside the project's compatibility/release policy.
