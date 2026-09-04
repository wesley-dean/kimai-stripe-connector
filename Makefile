.PHONY: docs docs-clean

REFERENCE_DOC_DIR := doc/reference

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
