.DEFAULT_GOAL := help

DEV   = docker compose -f compose.dev.yaml
EXEC  = $(DEV) exec app
PY    = $(DEV) run --rm refresh
IMAGE ?= ghcr.io/eazenli/go-figure-php
TAG   ?= latest

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

.PHONY: help up down logs app-logs sh console composer test stan layout-schema cassette key-check image demo demo-down mcp-stdio inspector refresh weather data-test data-sh

help: ## List the targets
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

vendor/autoload.php: composer.json composer.lock
	$(DEV) run --rm --no-deps app composer install --no-interaction
	@touch $@

up: vendor/autoload.php ## Start the development container (http://localhost:8080)
	$(DEV) up -d --wait

down: ## Stop the development container
	$(DEV) down

logs: ## Follow the container's output (web server, startup)
	$(DEV) logs -f

app-logs: ## Follow the application log: requests, MCP, refused figures (var/log/dev.log)
	$(EXEC) tail -n 50 -F var/log/dev.log

sh: ## Open a shell in the development container
	$(EXEC) sh

console: ## Run a Symfony command, e.g. make console c="debug:router"
	$(EXEC) php bin/console $(c)

composer: ## Run Composer, e.g. make composer c="require symfony/uid"
	$(EXEC) composer $(c)

test: ## Run PHPUnit, e.g. make test c="--filter Layout"
	$(EXEC) php bin/phpunit $(c)

stan: ## Run PHPStan (level 8) on the code and the tests
	$(EXEC) php bin/console cache:warmup --env=test --quiet
	$(EXEC) php vendor/bin/phpstan analyse --memory-limit=1G

layout-schema: ## Regenerate src/Layout/LayoutSchema.php after changing a class in src/Layout/
	$(EXEC) php bin/console app:dump-layout-schema

cassette: ## Record an example question: make cassette q=<id> (a key in .env.local), or scripted=1
	$(EXEC) php bin/console app:cassette $(q) $(if $(scripted),--scripted)

key-check: ## With a key in .env.local: a live question; c=--agent tries the import agent, c=--record records the example questions
	$(EXEC) php bin/console app:key-check $(c)

image: ## Build the production image used by compose.yaml
	docker build --target prod -t $(IMAGE):$(TAG) .

demo: ## Run the demo exactly as a visitor would (compose.yaml)
	docker compose up -d --wait

demo-down: ## Stop the demo
	docker compose down

mcp-stdio: ## Serve MCP over STDIO from the running container (what Claude Desktop runs)
	docker exec -i go-figure php bin/console mcp:server

inspector: ## Open the MCP Inspector (needs Node.js), then connect to http://localhost:8080/mcp/go-figure
	npx @modelcontextprotocol/inspector

# Data pipeline (Python, in its own container). The virtualenv lives in data/.venv and is rebuilt
# when data/requirements.txt changes; the stamp file tracks that, since .venv/bin/python is a
# symlink that only resolves inside the container.
data/.venv/.installed: data/requirements.txt
	$(PY) sh -c 'python -m venv .venv && .venv/bin/pip install --quiet --upgrade pip && .venv/bin/pip install --quiet -r requirements.txt'
	@touch $@

refresh: data/.venv/.installed ## Rebuild data/facts.sqlite and data/facts.csv (Eurostat, and the weather from its cache): make refresh c="<entry ids>"
	$(PY) .venv/bin/python refresh.py $(c)

weather: data/.venv/.installed ## Download the daily weather of the cities of data/cities.csv not cached yet (Open-Meteo quota: about 12 a day), c="<codes>" for some
	$(PY) .venv/bin/python weather.py $(c)

data-test: data/.venv/.installed ## Run the data pipeline tests, e.g. make data-test c="-k draft"
	$(PY) .venv/bin/python -m pytest -q $(c)

data-sh: data/.venv/.installed ## Open a shell in the Python container (then: .venv/bin/python …)
	$(PY) bash
