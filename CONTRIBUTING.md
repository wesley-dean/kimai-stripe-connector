# Contributing

Thank you for considering a contribution to `kimai-stripe-connector`.

Before making a substantive change, please read:

1. `README.md`;
2. `AGENTS.md`;
3. `doc/implementation-playbook.md`;
4. `doc/decisions.md` and the ADRs relevant to the change;
5. `doc/documentation.md`; and
6. `CODE_OF_CONDUCT.md`.

## Engineering Expectations

This project favors small, conservative, auditable changes.

Do not patch or fork Kimai core to implement connector behavior.  Preserve the accepted authority boundaries, manual fallback, no-required-public-ingress model, and fail-closed financial behavior unless a new Accepted ADR explicitly changes them.

### Tests accompany behavior

ADR-003 requires appropriate automated tests for executable behavior additions, changes, fixes, patches, API-call changes, compatibility changes, and other reasonably testable behavior.

Examples:

- a Stripe API request change should have tests;
- reconciliation/cleanup logic changes should have tests;
- configuration behavior should have tests;
- a bug fix should normally have a regression test;
- documentation-only, comment-only, typo, or formatting changes do not require artificial unit tests.

Use `doc/testing/test-matrix.md` to choose the cheapest deterministic layer capable of proving the behavior.

## Local Validation

After installing Composer dependencies, the ordinary PHP validation entry point is:

```text
make check
```

That runs:

- PHPUnit;
- PHPStan;
- PHPCS; and
- PHP-CS-Fixer in check-only mode.

Use:

```text
make e2e
```

for the disposable real-Kimai plugin lifecycle exercise when the change affects plugin/runtime behavior.

Use:

```text
make docs
```

for generated Doxygen reference documentation when source documentation changes.

MegaLinter is a blocking repository quality gate.  Its accepted completion state is zero reported errors and zero reported warnings.  CI auto-fixes are disabled; fix findings deliberately in source/configuration.

## Documentation

Keep implementation, tests, ADRs, configuration, threat model, operator guidance, and handoff context synchronized with the behavior they describe.

Source PHP follows the documentation-first Doxygen/PHPDoc standard in `doc/documentation.md`.

## Pull Requests and Commits

Use focused branches and Conventional Commit messages.

A pull request should explain:

- what changed;
- why;
- relevant ADRs/contracts;
- tests/validation performed;
- security implications;
- compatibility implications; and
- unresolved questions.

Do not include Stripe credentials, `.env` files containing secrets, customer/payment datasets, or generated/dependency/build artifacts that repository policy intentionally ignores.

## License

The repository currently contains the inherited [`LICENSE`](LICENSE) and CC0 contribution terms while the project remains private.  The final license for public distribution/contributions has not yet been deliberately finalized.

Before the repository is made public or public contributions are solicited, the project owner will resolve the intended public license and this section must be updated to match it.
