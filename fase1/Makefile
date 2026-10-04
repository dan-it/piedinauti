-include .env

.PHONY: help bootstrap up down logs shell artisan composer npm test

help: ## Show this help
	@grep -E '^[a-z]+:.*##' Makefile | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

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
	-docker compose exec -T db createdb -U $(DB_USERNAME) piedinauti_test
	docker compose exec app php artisan test
