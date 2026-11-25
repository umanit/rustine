.PHONY: help tests static_analyze cs cs_fix rector rector_fix full_static_analyze phar docs docs_serve docs_serve_fr

.DEFAULT_GOAL := help

help: ## Lists the available targets
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  \033[33m%-22s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

tests: ## Runs PHPUnit
	vendor/bin/phpunit

static_analyze: ## Runs PHPStan (max level)
	vendor/bin/phpstan analyse --memory-limit=1G

cs: ## Checks the coding standard (PHP_CodeSniffer)
	vendor/bin/phpcs

cs_fix: ## Fixes the coding standard (phpcbf)
	vendor/bin/phpcbf

rector: ## Shows the Rector refactorings (dry run)
	vendor/bin/rector process --dry-run

rector_fix: ## Applies the Rector refactorings
	vendor/bin/rector process

full_static_analyze: cs rector static_analyze tests ## Runs cs, rector, static_analyze and tests: must pass after any PHP change

phar: ## Builds build/rustine.phar and smoke-tests it (VERSION=x.y.z optional)
	php -d phar.readonly=0 tools/build-phar.php $(if $(VERSION),--version=$(VERSION))

# Documentation (Zensical, English + French). Pages come from README.md.
# Nothing is installed: `zensical` must be on the PATH.
DOCS_TOOL = python3 tools/docs/docs_tool.py

docs: ## Builds the documentation: English in site/, French in site/fr/ (strict)
	$(DOCS_TOOL) check-tools
	$(DOCS_TOOL) stage
	zensical build --strict -f zensical.toml
	zensical build --strict -f zensical.fr.toml

docs_serve: ## Previews the English documentation on http://localhost:8000
	$(DOCS_TOOL) check-tools
	$(DOCS_TOOL) stage
	zensical serve -f zensical.toml

docs_serve_fr: ## Previews the French documentation on http://localhost:8001
	$(DOCS_TOOL) check-tools
	$(DOCS_TOOL) stage
	zensical serve -f zensical.fr.toml -a localhost:8001
