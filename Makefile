.DEFAULT_GOAL := help

.PHONY: help init-env setup up down build logs shell shell-php migrate seed \
	api-test composer-install frontend-prepare-generated frontend-install frontend-generate \
	wazync-test nginx-upstream-test verify-api verify-web verify-wazync verify seed-pilot

export LOCAL_UID := $(shell id -u)
export LOCAL_GID := $(shell id -g)

help:
	@echo "  make setup         Primeira execução: ambiente, build, dependências e migrations"
	@echo "  make up            Sobe toda a stack com Nuxt HMR (:3000) e API (:8080)"
	@echo "  make down          Para a stack"
	@echo "  make build         Reconstrói as imagens"
	@echo "  make logs          Acompanha os logs"
	@echo "  make shell         Abre um shell no PHP"
	@echo "  make migrate       Executa migrations"
	@echo "  make seed          Carrega dados locais"
	@echo "  make api-test      Executa a suíte Laravel em PostgreSQL isolado"
	@echo "  make verify-api    Valida a API"
	@echo "  make verify-web    Valida o frontend"
	@echo "  make verify-wazync Valida o gateway Wazync"
	@echo "  make nginx-upstream-test Valida recuperação do Nginx após recriar a API"
	@echo "  make verify        Executa todos os gates"

init-env:
	@set -eu; umask 077; \
	command -v openssl >/dev/null 2>&1 || { echo "openssl é obrigatório" >&2; exit 1; }; \
	if [ ! -e .env ]; then install -m 600 .env.example .env; fi; \
	if [ ! -e apps/api/.env ]; then install -m 600 apps/api/.env.example apps/api/.env; fi; \
	chmod 600 .env apps/api/.env; \
	if grep -q '^APP_KEY=$$' apps/api/.env; then \
		key=$$(openssl rand -base64 32); sed -i "s|^APP_KEY=$$|APP_KEY=base64:$$key|" apps/api/.env; \
	fi; \
	if grep -q '^VAULT_MASTER_KEY=$$' apps/api/.env; then \
		key=$$(openssl rand -base64 32); sed -i "s|^VAULT_MASTER_KEY=$$|VAULT_MASTER_KEY=$$key|" apps/api/.env; \
	fi

setup: init-env build composer-install frontend-install
	docker compose up -d postgres redis
	docker compose run --rm php php artisan migrate --force
	$(MAKE) up

up: frontend-prepare-generated
	LOCAL_UID=$(LOCAL_UID) LOCAL_GID=$(LOCAL_GID) docker compose up -d --remove-orphans nginx php postgres redis horizon scheduler reverb wazync frontend

down:
	docker compose --profile test down --remove-orphans

build:
	docker compose build nginx php horizon frontend wazync

logs:
	docker compose logs -f

shell shell-php:
	docker compose exec --user www-data php sh

migrate:
	docker compose exec --user www-data php php artisan migrate

seed:
	docker compose exec --user www-data php php artisan db:seed --force

api-test:
	docker compose --profile test up -d --wait postgres-test
	docker compose --profile test run --rm --no-deps \
		--user "$(LOCAL_UID):$(LOCAL_GID)" \
		-e APP_ENV=testing \
		-e LOG_CHANNEL=stderr \
		-e CACHE_STORE=array \
		-e SESSION_DRIVER=array \
		-e QUEUE_CONNECTION=sync \
		php php artisan test

verify-api:
	docker compose exec --user www-data php composer validate --strict --no-check-publish
	docker compose exec --user www-data php vendor/bin/pint --test
	docker compose exec --user www-data php php artisan test

verify-web:
	docker compose exec frontend app-entrypoint test-gate

verify-wazync: wazync-test

nginx-upstream-test:
	./infra/docker/nginx/verify-upstream-recovery.sh

verify: verify-api verify-web verify-wazync nginx-upstream-test

composer-install:
	docker compose run --rm --no-deps php composer install --no-interaction --prefer-dist

frontend-prepare-generated:
	@set -eu; \
	for path in apps/web/.nuxt apps/web/.output apps/web/test-results apps/web/playwright-report; do \
		if [ -e "$$path" ] && ! git check-ignore -q "$$path"; then \
			echo "Recusando ajustar artefato não ignorado: $$path" >&2; exit 1; \
		fi; \
	done
	@LOCAL_UID=$(LOCAL_UID) LOCAL_GID=$(LOCAL_GID) \
		docker compose run --rm --no-deps frontend prepare

frontend-install: frontend-prepare-generated
	LOCAL_UID=$(LOCAL_UID) LOCAL_GID=$(LOCAL_GID) \
		docker compose run --rm --no-deps frontend install

frontend-generate: frontend-prepare-generated
	LOCAL_UID=$(LOCAL_UID) LOCAL_GID=$(LOCAL_GID) \
		docker compose run --rm --no-deps frontend generate

wazync-test:
	docker run --rm -v $(CURDIR):/workspace -w /workspace/apps/wazync golang:1.25-alpine go test ./...
	docker run --rm -v $(CURDIR):/workspace -w /workspace/apps/wazync golang:1.25-alpine go vet ./...

seed-pilot:
	docker compose exec --user www-data php php artisan db:seed --class=PilotSeeder --force
