.PHONY: tests static_analyze full_static_analyze phar

tests:
	vendor/bin/phpunit

static_analyze:
	vendor/bin/phpstan analyse --memory-limit=1G

full_static_analyze: static_analyze tests

# Builds build/rustine.phar (production dependencies only) and smoke-tests it. Usage: make phar [VERSION=1.0.0]
phar:
	php -d phar.readonly=0 tools/build-phar.php $(if $(VERSION),--version=$(VERSION))
