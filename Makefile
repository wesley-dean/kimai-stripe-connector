.PHONY: analyse check docs docs-clean e2e format format-check lint package package-check test

REFERENCE_DOC_DIR := doc/reference
VERSION ?= dev

## @brief Run the PHPUnit test suite.
test:
	@command -v composer >/dev/null 2>&1 || { \
		echo 'error: composer is required to run tests' >&2; \
		exit 1; \
	}
	composer test

## @brief Run PHPStan static analysis.
analyse:
	@command -v composer >/dev/null 2>&1 || { \
		echo 'error: composer is required to run static analysis' >&2; \
		exit 1; \
	}
	composer analyse

## @brief Check PHP source against the configured PHPCS standard.
lint:
	@command -v composer >/dev/null 2>&1 || { \
		echo 'error: composer is required to run PHP coding-standard checks' >&2; \
		exit 1; \
	}
	composer lint

## @brief Verify PHP-CS-Fixer would make no formatting changes.
format-check:
	@command -v composer >/dev/null 2>&1 || { \
		echo 'error: composer is required to run PHP formatting checks' >&2; \
		exit 1; \
	}
	composer format:check

## @brief Apply the project PHP-CS-Fixer rules locally.
##
## This is an explicit developer command.  CI and MegaLinter do not apply fixes
## automatically.
format:
	@command -v composer >/dev/null 2>&1 || { \
		echo 'error: composer is required to format PHP source' >&2; \
		exit 1; \
	}
	composer format

## @brief Run the ordinary local PHP validation suite.
check:
	@command -v composer >/dev/null 2>&1 || { \
		echo 'error: composer is required to run PHP validation' >&2; \
		exit 1; \
	}
	composer check

## @brief Build the self-contained production plugin ZIP under dist/.
package:
	VERSION="$(VERSION)" bash tools/package.sh

## @brief Verify package contents and byte-for-byte reproducibility.
package-check:
	bash tests/package/run.sh

## @brief Run the disposable Kimai plugin-lifecycle E2E exercise.
e2e:
	bash tests/e2e/run.sh

## @brief Generate Doxygen reference documentation from maintained PHP source.
##
## The target deliberately performs no dependency installation.  Developers and
## CI are responsible for providing Doxygen before invoking it, preserving a clear
## boundary between dependency preparation and documentation generation.
docs: docs-clean
	@command -v doxygen >/dev/null 2>&1 || { \
		echo 'error: doxygen is required to generate reference documentation' >&2; \
		exit 1; \
	}
	@php_source="$$(find . -type f -name '*.php' \
		-not -path './vendor/*' \
		-not -path './tests/*' \
		-not -path './doc/*' \
		-print -quit)"; \
	if [ -z "$${php_source}" ]; then \
		echo 'No maintained PHP source exists yet; Doxygen generation is deferred.'; \
	else \
		doxygen Doxyfile; \
		test -f "$(REFERENCE_DOC_DIR)/index.html"; \
	fi

## @brief Remove generated Doxygen reference documentation.
docs-clean:
	rm -rf "$(REFERENCE_DOC_DIR)"
