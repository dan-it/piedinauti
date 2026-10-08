-include .env

# Before the two-role setup (scripts/passa-a-rls.sh) there was a single database user.
DB_ADMIN_USER ?= $(DB_USERNAME)

.PHONY: help bootstrap up down logs shell artisan composer npm test test-js psql backup ripristina verifica-backup controlla

help: ## Show this help
	@grep -E '^[a-z-]+:.*##' Makefile | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

bootstrap: ## Create the Laravel project (run once)
	./scripts/bootstrap.sh

up: ## Build and start all services
	docker compose up -d --build

down: ## Stop all services
	docker compose down

logs: ## Follow the logs of all services
	docker compose logs -f

shell: ## Open a shell in the app container
	docker compose exec app bash

artisan: ## Run an artisan command, e.g. make artisan ARGS="migrate"
	docker compose exec app php artisan $(ARGS)

composer: ## Run composer, e.g. make composer ARGS="require vendor/package"
	docker compose exec app composer $(ARGS)

npm: ## Run npm, e.g. make npm ARGS="install"
	docker compose exec app npm $(ARGS)

test: ## Run the test suite (creates the piedinauti_test database if missing)
	-docker compose exec -T db createdb -U $(DB_ADMIN_USER) -O $(DB_USERNAME) piedinauti_test
	docker compose exec app php artisan test

test-js: ## Test the phone's outbox, local search and service worker (no browser needed)
	docker compose exec -T app bash tests/js/eseguire.sh

psql: ## Open a psql shell as the database administrator (maintenance only)
	docker compose exec db psql -U $(DB_ADMIN_USER) -d $(DB_DATABASE)

backup: ## Back up the database into backups/ (see scripts/backup.sh)
	./scripts/backup.sh

verifica-backup: ## Prove that the newest backup can be restored (the real database is not touched)
	./scripts/verifica-backup.sh

ripristina: ## Restore a backup, replacing the database: make ripristina FILE=backups/xxx.dump
	./scripts/ripristina.sh $(FILE)

controlla: ## Check a production server before launch: configuration, DNS, ports, Docker
	./scripts/controlla-produzione.sh
