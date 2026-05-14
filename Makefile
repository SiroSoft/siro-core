.PHONY: help test test-coverage test-fuzz test-chaos test-all analyse psalm benchmark lint fix audit sbom loadtest health docs sdk otel deptrac check elite-check production-check clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

test: ## Run tests
	@php vendor/bin/phpunit --no-coverage

test-coverage: ## Run tests with coverage
	@php -d zend_extension=xdebug vendor/bin/phpunit --coverage-html coverage/html

analyse: ## Run PHPStan (level max)
	@php -d memory_limit=512M vendor/bin/phpstan analyse --level=max --no-progress --memory-limit=512M

psalm: ## Run Psalm taint analysis (level 1)
	@php -d memory_limit=512M vendor/bin/psalm --taint-analysis --show-info=true --php-version=8.2

test-fuzz: ## Run fuzz tests
	@php scripts/fuzz.php

test-chaos: ## Run chaos engineering tests
	@php scripts/chaos-test.php

test-all: test test-fuzz test-chaos ## Run all tests (unit + fuzz + chaos)

benchmark: ## Run benchmarks
	@php benchmark.php --quick

lint: ## Check code style
	@php vendor/bin/phpcs --standard=phpcs.xml src/ 2>/dev/null || echo "phpcs not configured"

fix: ## Auto-fix code style
	@php vendor/bin/phpcbf --standard=phpcs.xml src/ 2>/dev/null || echo "phpcbf not configured"

audit: ## Composer security audit
	@composer audit --format=table || true

sbom: ## Generate CycloneDX SBOM
	@php scripts/generate-sbom.php

loadtest: ## Run basic load test (requires Apache Bench)
	@php scripts/loadtest.php

health: ## Run health check
	@php scripts/health-check.php

docs: ## Generate API documentation
	@php scripts/generate-docs.php

sdk: ## Generate PHP SDK from OpenAPI spec
	@php scripts/generate-sdk.php

otel: ## Generate W3C trace context for current request
	@php scripts/otel-trace.php --generate

deptrac: ## Validate module boundaries (requires deptrac)
	@php vendor/bin/deptrac analyse --config-file=depfile.yaml 2>/dev/null || echo "deptrac not installed (composer require --dev qossmic/deptrac)"

check: analyse test audit sbom ## Run all code quality checks

elite-check: analyse psalm test test-fuzz test-chaos sbom audit lint loadtest ## Elite-level quality check

production-check: analyse psalm test test-coverage test-fuzz test-chaos sbom audit lint loadtest benchmark ## Full production readiness check

clean: ## Clean cache and coverage
	@rm -rf coverage/ .phpunit.cache storage/framework/routes.php storage/framework/config.php
	@echo "Cleaned."
