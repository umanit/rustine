.PHONY: tests static_analyze cs cs_fix rector rector_fix full_static_analyze phar

tests:
	vendor/bin/phpunit

static_analyze:
	vendor/bin/phpstan analyse --memory-limit=1G

cs:
	vendor/bin/phpcs

cs_fix:
	vendor/bin/phpcbf

rector:
	vendor/bin/rector process --dry-run

rector_fix:
	vendor/bin/rector process

full_static_analyze: cs rector static_analyze tests

# Builds build/rustine.phar (production dependencies only) and smoke-tests it. Usage: make phar [VERSION=1.0.0]
phar:
	php -d phar.readonly=0 tools/build-phar.php $(if $(VERSION),--version=$(VERSION))
