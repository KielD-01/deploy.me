# deploy.me — local stack helpers. `make help` lists targets.
export HOME USER
DC := docker compose -f docker-compose.local.yml
WEB := $(DC) exec deployme-web
WORKER := $(DC) exec deployme-worker

.DEFAULT_GOAL := help
.PHONY: help init db-create build up down restart ps logs sh sh-worker artisan composer assets dev setup enroll test sandbox test-ssh migrate fresh doctor workers

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

init: db-create build up assets setup doctor ## First run: database, image, containers, assets, setup

db-create: ## Create the deployme role and databases on the shared Postgres
	bin/db-create.sh

build: ## Build the PHP image
	$(DC) build

up: ## Start the stack
	$(DC) up -d

down: ## Stop the stack
	$(DC) down

restart: ## Restart the stack
	$(DC) restart

ps: ## Container status
	$(DC) ps

logs: ## Follow logs (s=<service> for one)
	$(DC) logs -f $(s)

sh: ## Shell in the web container
	$(WEB) bash

sh-worker: ## Shell in the worker container
	$(WORKER) bash

artisan: ## Run artisan (c="migrate:status")
	$(WEB) php artisan $(c)

composer: ## Run composer (c="require vendor/pkg")
	$(WEB) composer $(c)

assets: ## Build frontend assets into public/build
	$(DC) run --rm --no-deps deployme-vite sh -c "npm install --no-audit --no-fund && npm run build"

dev: ## Start the Vite dev server with HMR
	$(DC) --profile dev up -d deployme-vite

setup: ## Generate keys and run migrations
	$(WEB) php artisan deployme:setup

enroll: ## Print a one-time link to register a passkey (first run, or a lost passkey)
	$(WEB) php artisan deployme:enroll

test: ## Run the test suite
	$(WEB) php artisan test

sandbox: ## Start the local SSH sandbox server (alias "sandbox" in Add server)
	$(WEB) php artisan deployme:sandbox
	$(DC) --profile sandbox up -d --build deployme-sshd-target

test-ssh: ## Run the SSH integration tests against the sandbox
	$(WEB) php artisan test --group=ssh-integration

migrate: ## Run migrations
	$(WEB) php artisan migrate

fresh: ## Drop and re-run all migrations
	$(WEB) php artisan migrate:fresh

workers: ## Supervisor program status
	$(WORKER) supervisorctl -c /etc/supervisor/supervisord.conf status

doctor: ## Check infra, containers, TLS and websockets
	bin/doctor.sh

backup: ## Back up the database and APP_KEY into ~/Backups/deployme
	bin/backup.sh

backup-install: ## Run that backup nightly at 03:20 (LaunchAgent)
	bin/backup.sh install

backup-status: ## When the backup last ran, and what is kept
	bin/backup.sh status
