PHP ?= 8.5
IMAGE := netsuite-php-test:$(PHP)
LINT_PATHS := src/Rest src/NetSuiteClient.php tests
COVERAGE_DIR ?= src/Rest

RUN := docker run --rm --user $$(id -u):$$(id -g) \
	-v "$(CURDIR)":/app \
	-v netsuite-php-vendor-$(PHP):/app/vendor \
	-v netsuite-php-composer:/composer \
	$(IMAGE)

.PHONY: image deps test lint coverage

image:
	docker build -q --build-arg PHP=$(PHP) -t $(IMAGE) docker

deps: image
	$(RUN) composer update --no-interaction --prefer-dist --no-progress -q

test: deps
	$(RUN) sh -c 'vendor/bin/phpunit && vendor/bin/phpspec run'

lint: image
	$(RUN) docker/lint.sh $(LINT_PATHS)

coverage: deps
	$(RUN) php -d pcov.enabled=1 -d pcov.directory=$(COVERAGE_DIR) vendor/bin/phpunit \
		--coverage-filter $(COVERAGE_DIR) --coverage-text
