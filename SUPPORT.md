# Getting Support

Before opening a support issue, please check:

1. the Kimai version against the compatibility guidance in `doc/decisions.md` and the relevant ADRs;
2. the connector version/commit being used;
3. `doc/configuration.md` for configuration and secret-delivery requirements;
4. `doc/project-handoff.md` and the README for current implementation status;
5. `doc/threat-model.md` before sharing logs or diagnostic material that may contain security-sensitive information; and
6. whether the problem still occurs with the connector disabled when that comparison is safe and relevant.

When reporting a problem, include enough non-secret context to reproduce it where practical:

- Kimai version;
- PHP version;
- connector version/commit;
- deployment/runtime type;
- exact command or workflow being exercised;
- operator result/error category, if available;
- relevant sanitized log excerpts; and
- steps to reproduce.

Do not include Stripe API keys, secret-file contents, full environment dumps, live payment credentials, or unnecessary customer/payment data.

Please open support/bug reports in this repository's issue tracker.  Security vulnerabilities should follow `SECURITY.md` instead of being disclosed first in a public issue.
