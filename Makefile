# deploy.me — local stack helpers. `make help` lists targets.
# macOS, Linux and Windows (inside WSL2). ./.env, written by bin/configure, says
# which compose files this machine uses (COMPOSE_FILE); a checkout without one
# is configured on first use.
export HOME USER
DC := docker compose
# The host user's ids on Linux/WSL (0 on macOS), read when a recipe runs.
OWNER = $(shell grep -s '^DEPLOYME_UID=' .env | cut -d= -f2 || echo 0):$(shell grep -s '^DEPLOYME_GID=' .env | cut -d= -f2 || echo 0)
WEB := $(DC) exec deployme-web
WORKER := $(DC) exec deployme-worker

.DEFAULT_GOAL := help
.PHONY: help install configure trust-ca init db-create build up down restart ps logs sh sh-worker artisan composer assets dev setup enroll test sandbox test-ssh migrate fresh doctor workers

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

install: ## Guided first install: checks, configure, init, trust the local CA, a passkey link
	bin/install

configure: ## Describe this machine: stack mode, user ids, project roots (writes .env)
	bin/configure

# A checkout that was never configured is, the first time any target needs compose.
.env:
	bin/configure

trust-ca: .env ## Trust the edge's local CA on this machine (browser and curl)
	bin/trust-ca

init: .env db-create build up assets setup doctor ## First run: database, image, containers, assets, setup

db-create: .env ## Create the deployme role and databases (shared or own Postgres)
	bin/db-create.sh

build: .env ## Build the PHP image
	$(DC) build

up: .env ## Start the stack
	$(DC) up -d

down: .env ## Stop the stack
	$(DC) down

restart: .env ## Restart the stack
	$(DC) restart

ps: .env ## Container status
	$(DC) ps

logs: .env ## Follow logs (s=<service> for one)
	$(DC) logs -f $(s)

sh: .env ## Shell in the web container
	$(WEB) bash

sh-worker: .env ## Shell in the worker container
	$(WORKER) bash

artisan: .env ## Run artisan (c="migrate:status")
	$(WEB) php artisan $(c)

composer: .env ## Run composer (c="require vendor/pkg")
	$(WEB) composer $(c)

assets: .env ## Build frontend assets into public/build
	$(DC) run --rm --no-deps deployme-vite sh -c "npm install --no-audit --no-fund && npm run build && chown -R $(OWNER) public/build"

dev: .env ## Start the Vite dev server with HMR
	$(DC) --profile dev up -d deployme-vite

setup: .env ## Generate keys and run migrations
	$(WEB) php artisan deployme:setup

enroll: .env ## Print a one-time link to set up a passkey or password (first run, or a lost way in)
	$(WEB) php artisan deployme:enroll

test: .env ## Run the test suite
	$(WEB) php artisan test

sandbox: .env ## Start the local SSH sandbox server (alias "sandbox" in Add server)
	$(WEB) php artisan deployme:sandbox
	$(DC) --profile sandbox up -d --build deployme-sshd-target

test-ssh: .env ## Run the SSH integration tests against the sandbox
	$(WEB) php artisan test --group=ssh-integration

migrate: .env ## Run migrations
	$(WEB) php artisan migrate

fresh: .env ## Drop and re-run all migrations
	$(WEB) php artisan migrate:fresh

workers: .env ## Supervisor program status
	$(WORKER) supervisorctl -c /etc/supervisor/supervisord.conf status

doctor: .env ## Check infra, containers, TLS and websockets
	bin/doctor.sh

backup: ## Back up the database and APP_KEY into ~/Backups/deployme
	bin/backup.sh

backup-install: ## Run that backup nightly at 03:20 (launchd on macOS, systemd or cron on Linux/WSL)
	bin/backup.sh install

backup-status: ## When the backup last ran, and what is kept
	bin/backup.sh status
