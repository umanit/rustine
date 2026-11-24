.PHONY: tests static_analyze full_static_analyze

tests:
	vendor/bin/phpunit

static_analyze:
	vendor/bin/phpstan analyse --memory-limit=1G

full_static_analyze: static_analyze tests
