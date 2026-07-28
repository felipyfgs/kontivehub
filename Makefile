.PHONY: help init-env setup up dev down build logs shell migrate seed \
	api-test composer-install frontend-generate wazync-test nginx-upstream-test \
	code-review \
	prod-config prod-build prod-up prod-down \
	backup restore prod-backup prod-restore \
	frontend-prepare-generated frontend-install frontend-dev seed-dev seed-pilot \
	prod-check backup-verify prod-backup-verify prod-restore-smoke prod-readiness prod-release-manifest

LOCAL_UID := $(shell id -u)
LOCAL_GID := $(shell id -g)
PROD_ENV ?= .env
RELEASE_SHA ?= $(shell git rev-parse HEAD 2>/dev/null)
RELEASE_TAG ?= sha-$(shell printf '%s' '$(RELEASE_SHA)' | cut -c1-12)
BUILD_DATE ?= $(shell date -u +%Y-%m-%dT%H:%M:%SZ)
PROD_COMPOSE = RELEASE_SHA=$(RELEASE_SHA) RELEASE_TAG=$(RELEASE_TAG) BUILD_DATE=$(BUILD_DATE) PROD_ENV_FILE=$(PROD_ENV) docker compose --env-file $(PROD_ENV) -f docker-compose.prod.yml -p fiscal-hub

OPS_UNAVAILABLE = @echo "Indisponível até a fase de ops." >&2; exit 2

# -----------------------------------------------------------------------------
# Dia a dia — o que você realmente usa
# -----------------------------------------------------------------------------

help:
	@echo "Local"
	@echo "  make setup              Primeira vez: env + build + deps + migrate + up"
	@echo "  make dev                Stack + Nuxt HMR (:3000) e API (:8080)"
	@echo "  make up                 Stack sem HMR (SPA estática no nginx)"
	@echo "  make down               Para a stack local"
	@echo "  make build              Rebuild imagens locais"
	@echo "  make logs               Logs (follow)"
	@echo "  make shell              Shell no PHP"
	@echo "  make migrate            Migrations"
	@echo "  make seed               Seed de desenvolvimento"
	@echo "  make api-test           Suíte Laravel no PostgreSQL isolado"
	@echo "  make nginx-upstream-test Verifica recuperação do edge após recriar o PHP"
	@echo "  make code-review        CodeRabbit no diff local (--agent; use ARGS='--base main')"
	@echo ""
	@echo "Produção"
	@echo "  make prod-config        Valida .env + compose prod"
	@echo "  make prod-build         Build imagens imutáveis (tag SHA)"
	@echo "  make prod-up            Sobe produção (HTTPS)"
	@echo "  make prod-down          Para produção (mantém volumes)"

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

setup: init-env build composer-install frontend-generate
	docker compose up -d postgres redis
	docker compose run --rm php php artisan migrate --force
	docker compose up -d nginx php horizon scheduler reverb wazync

up:
	docker compose up -d --remove-orphans nginx php postgres redis horizon scheduler reverb wazync

dev: frontend-prepare-generated
	LOCAL_UID=$(LOCAL_UID) LOCAL_GID=$(LOCAL_GID) docker compose --profile dev up -d --remove-orphans nginx php postgres redis horizon scheduler reverb wazync frontend-dev

down:
	docker compose --profile dev down --remove-orphans

build:
	docker compose --profile dev build nginx php frontend-dev wazync

logs:
	docker compose logs -f

shell shell-php:
	docker compose exec php sh

migrate:
	docker compose exec php php artisan migrate

seed seed-dev:
	docker compose exec php php artisan db:seed --force

# Code review local (CodeRabbit CLI). Exemplos:
#   make code-review
#   make code-review ARGS='--base main'
#   make code-review ARGS='--human'
code-review:
	./scripts/code-review.sh $(ARGS)

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

nginx-upstream-test:
	./infra/docker/nginx/verify-upstream-recovery.sh

# -----------------------------------------------------------------------------
# Produção
# -----------------------------------------------------------------------------

prod-check:
	@test -f "$(PROD_ENV)" || { echo "Crie $(PROD_ENV) a partir de .env.example" >&2; exit 2; }
	@test "$$(stat -c '%a' "$(PROD_ENV)")" = "600" || { echo "$(PROD_ENV) deve usar permissão 600" >&2; exit 2; }
	@grep -Eq '^ACME_EMAIL=[^[:space:]@]+@[^[:space:]@]+$$' "$(PROD_ENV)" || { echo "Defina ACME_EMAIL válido em $(PROD_ENV)" >&2; exit 2; }
	@grep -Eq '^APP_KEY=base64:.{32,}$$' "$(PROD_ENV)" || { echo "Defina APP_KEY válida em $(PROD_ENV)" >&2; exit 2; }
	@grep -Eq '^VAULT_MASTER_KEY=.{32,}$$' "$(PROD_ENV)" || { echo "Defina VAULT_MASTER_KEY em $(PROD_ENV)" >&2; exit 2; }
	@grep -Eq '^MEI_AUTOMATION_HMAC_SECRET=.{32,}$$' "$(PROD_ENV)" || { echo "Defina MEI_AUTOMATION_HMAC_SECRET em $(PROD_ENV)" >&2; exit 2; }
	@grep -Eq '^DB_PASSWORD=.{16,}$$' "$(PROD_ENV)" || { echo "DB_PASSWORD deve ter ao menos 16 caracteres" >&2; exit 2; }
	@grep -Eq '^WAZYNC_DB_PASSWORD=.{32,}$$' "$(PROD_ENV)" || { echo "WAZYNC_DB_PASSWORD deve ter ao menos 32 caracteres" >&2; exit 2; }
	@db_user=$$(sed -n 's/^DB_USERNAME=//p' "$(PROD_ENV)" | tail -n 1); \
	wazync_user=$$(sed -n 's/^WAZYNC_DB_USER=//p' "$(PROD_ENV)" | tail -n 1); \
	db_user=$${db_user:-nfse}; wazync_user=$${wazync_user:-wazync}; \
	db_user=$${db_user#\"}; db_user=$${db_user%\"}; \
	wazync_user=$${wazync_user#\"}; wazync_user=$${wazync_user%\"}; \
	test "$$db_user" != "$$wazync_user" || { echo "WAZYNC_DB_USER deve ser diferente de DB_USERNAME" >&2; exit 2; }
	@grep -Fqx 'LOG_CHANNEL=stderr' "$(PROD_ENV)" || { echo "Produção exige LOG_CHANNEL=stderr" >&2; exit 2; }
	@grep -Fqx 'MAIL_MAILER=smtp' "$(PROD_ENV)" || { echo "Produção exige MAIL_MAILER=smtp" >&2; exit 2; }
	@grep -Eq '^MAIL_HOST=.+$$' "$(PROD_ENV)" || { echo "Defina MAIL_HOST" >&2; exit 2; }
	@grep -Eq '^MAIL_FROM_ADDRESS=[^[:space:]@]+@[^[:space:]@]+$$' "$(PROD_ENV)" || { echo "Defina MAIL_FROM_ADDRESS válido" >&2; exit 2; }
	@grep -Fqx 'APP_NAME="KontiveHub"' "$(PROD_ENV)" || { echo 'Produção exige APP_NAME="KontiveHub"' >&2; exit 2; }
	@grep -Fqx 'APP_URL=https://api.kontivehub.com.br' "$(PROD_ENV)" || { echo "Produção exige APP_URL=https://api.kontivehub.com.br" >&2; exit 2; }
	@grep -Fqx 'FRONTEND_URL=https://app.kontivehub.com.br' "$(PROD_ENV)" || { echo "Produção exige FRONTEND_URL=https://app.kontivehub.com.br" >&2; exit 2; }
	@grep -Fqx 'PORTAL_URL=https://portal.kontivehub.com.br' "$(PROD_ENV)" || { echo "Produção exige PORTAL_URL=https://portal.kontivehub.com.br" >&2; exit 2; }
	@grep -Fqx 'SESSION_DOMAIN=.kontivehub.com.br' "$(PROD_ENV)" || { echo "Produção exige SESSION_DOMAIN=.kontivehub.com.br" >&2; exit 2; }
	@grep -Fqx 'SESSION_SECURE_COOKIE=true' "$(PROD_ENV)" || { echo "Produção exige SESSION_SECURE_COOKIE=true" >&2; exit 2; }
	@grep -Fqx 'SESSION_HTTP_ONLY=true' "$(PROD_ENV)" || { echo "Produção exige SESSION_HTTP_ONLY=true" >&2; exit 2; }
	@grep -Fqx 'SESSION_SAME_SITE=lax' "$(PROD_ENV)" || { echo "Produção exige SESSION_SAME_SITE=lax" >&2; exit 2; }
	@grep -Fqx 'SANCTUM_STATEFUL_DOMAINS=app.kontivehub.com.br,portal.kontivehub.com.br,api.kontivehub.com.br' "$(PROD_ENV)" || { echo "Produção exige os três domínios Sanctum canônicos" >&2; exit 2; }
	@grep -Fqx 'CORS_ALLOWED_ORIGINS=https://app.kontivehub.com.br,https://portal.kontivehub.com.br' "$(PROD_ENV)" || { echo "Produção exige CORS canônico sem wildcard" >&2; exit 2; }
	@grep -Fqx 'REVERB_ALLOWED_ORIGINS=https://app.kontivehub.com.br,https://portal.kontivehub.com.br' "$(PROD_ENV)" || { echo "Produção exige origens Reverb canônicas" >&2; exit 2; }
	@! grep -Eqi 'substitua|example\.com|change-me|changeme|placeholder' "$(PROD_ENV)" || { echo "Remova placeholders de $(PROD_ENV)" >&2; exit 2; }
	@! grep -Eq '^SERPRO_USE_FAKE_CLIENTS=true$$' "$(PROD_ENV)" || { echo "Produção exige SERPRO_USE_FAKE_CLIENTS=false (ou ausente)" >&2; exit 2; }
	@! grep -Eq '^SERPRO_CAPABILITY_[A-Z_]+=real$$' "$(PROD_ENV)" || { echo "SERPRO_CAPABILITY_*=real bloqueado até go-live controlado" >&2; exit 2; }
	@! grep -Eq '^FEATURES_GLOBAL_ENABLED=true$$' "$(PROD_ENV)" || { echo "FEATURES_GLOBAL_ENABLED deve permanecer false até promoção explícita" >&2; exit 2; }
	@! grep -Eq '^FEATURES_MUTATING_ENABLED=true$$' "$(PROD_ENV)" || { echo "FEATURES_MUTATING_ENABLED deve permanecer false" >&2; exit 2; }
	@grep -Eq '^SERPRO_KILL_SWITCH=' "$(PROD_ENV)" || { echo "Defina SERPRO_KILL_SWITCH em $(PROD_ENV)" >&2; exit 2; }
	@! grep -Eq '^SERPRO_KILL_SWITCH=false$$' "$(PROD_ENV)" || { echo "Go-live inicial exige SERPRO_KILL_SWITCH=true" >&2; exit 2; }
	@! grep -Eq '^SERPRO_SMOKE_ENABLED=true$$' "$(PROD_ENV)" || { echo "SERPRO_SMOKE_ENABLED deve permanecer false" >&2; exit 2; }
	@! grep -Eq '^MEI_AUTOMATION_ENABLED=true$$' "$(PROD_ENV)" || { echo "MEI_AUTOMATION_ENABLED deve permanecer false (sem sidecar)" >&2; exit 2; }
	@! grep -Eq '^MEI_AUTOMATION_LIVE_EGRESS_ENABLED=true$$' "$(PROD_ENV)" || { echo "MEI_AUTOMATION_LIVE_EGRESS_ENABLED deve permanecer false" >&2; exit 2; }
	@if grep -qx 'COMMUNICATION_ENABLED=true' "$(PROD_ENV)"; then \
		grep -qx 'WAZYNC_ENABLED=true' "$(PROD_ENV)" || { echo "Comunicação exige WAZYNC_ENABLED=true" >&2; exit 2; }; \
		grep -qx 'BROADCAST_CONNECTION=reverb' "$(PROD_ENV)" || { echo "Comunicação exige BROADCAST_CONNECTION=reverb" >&2; exit 2; }; \
		grep -Eq '^WAZYNC_DATABASE_URL=.+$$' "$(PROD_ENV)" || { echo "Defina WAZYNC_DATABASE_URL" >&2; exit 2; }; \
		grep -Eq '^WAZYNC_HMAC_KEY_ID=.+$$' "$(PROD_ENV)" || { echo "Defina WAZYNC_HMAC_KEY_ID" >&2; exit 2; }; \
		grep -Eq '^WAZYNC_HMAC_SECRET=.{32,}$$' "$(PROD_ENV)" || { echo "WAZYNC_HMAC_SECRET deve ter ao menos 32 caracteres" >&2; exit 2; }; \
		grep -Eq '^WAZYNC_DATA_KEY=.{43,}$$' "$(PROD_ENV)" || { echo "Defina WAZYNC_DATA_KEY base64" >&2; exit 2; }; \
		grep -Eq '^REVERB_APP_ID=.+$$' "$(PROD_ENV)" || { echo "Defina REVERB_APP_ID" >&2; exit 2; }; \
		grep -Eq '^REVERB_APP_KEY=.{16,}$$' "$(PROD_ENV)" || { echo "Defina REVERB_APP_KEY" >&2; exit 2; }; \
		grep -Eq '^REVERB_APP_SECRET=.{32,}$$' "$(PROD_ENV)" || { echo "Defina REVERB_APP_SECRET" >&2; exit 2; }; \
	fi

prod-config: prod-check
	$(PROD_COMPOSE) config --quiet

prod-build: prod-check
	@test -n "$(RELEASE_SHA)" || { echo "RELEASE_SHA vazio" >&2; exit 2; }
	@echo "==> build RELEASE_SHA=$(RELEASE_SHA) RELEASE_TAG=$(RELEASE_TAG)"
	$(PROD_COMPOSE) build acme-init traefik web php wazync
	$(PROD_COMPOSE) pull socket-proxy
	docker tag fiscal-hub-php:$(RELEASE_TAG) fiscal-hub-php:prod
	docker tag fiscal-hub-web:$(RELEASE_TAG) fiscal-hub-web:prod
	docker tag fiscal-hub-wazync:$(RELEASE_TAG) fiscal-hub-wazync:prod
	@php_rev=$$(docker image inspect fiscal-hub-php:$(RELEASE_TAG) --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'); \
	web_rev=$$(docker image inspect fiscal-hub-web:$(RELEASE_TAG) --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'); \
	test "$$php_rev" = "$(RELEASE_SHA)" || { echo "OCI revision PHP=$$php_rev != $(RELEASE_SHA)" >&2; exit 2; }; \
	test "$$web_rev" = "$(RELEASE_SHA)" || { echo "OCI revision web=$$web_rev != $(RELEASE_SHA)" >&2; exit 2; }; \
	wazync_rev=$$(docker image inspect fiscal-hub-wazync:$(RELEASE_TAG) --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'); \
	test "$$wazync_rev" = "$(RELEASE_SHA)" || { echo "OCI revision Wazync=$$wazync_rev != $(RELEASE_SHA)" >&2; exit 2; }; \
	echo "OCI revision ok em php, web e Wazync"

# Ordem fail-closed: build → dados/php → migrate (sem web/workers) → edge + app.
prod-up: prod-check
	@test -n "$(RELEASE_SHA)" || { echo "RELEASE_SHA vazio" >&2; exit 2; }
	$(PROD_COMPOSE) build acme-init traefik web php wazync
	$(PROD_COMPOSE) pull socket-proxy
	docker tag fiscal-hub-php:$(RELEASE_TAG) fiscal-hub-php:prod
	docker tag fiscal-hub-web:$(RELEASE_TAG) fiscal-hub-web:prod
	docker tag fiscal-hub-wazync:$(RELEASE_TAG) fiscal-hub-wazync:prod
	$(PROD_COMPOSE) up -d --remove-orphans acme-init socket-proxy traefik postgres redis php
	$(PROD_COMPOSE) stop web horizon scheduler reverb wazync 2>/dev/null || true
	$(PROD_COMPOSE) run --rm --no-deps php php artisan migrate --force
	$(PROD_COMPOSE) up -d web horizon scheduler reverb wazync

prod-down:
	@test -f "$(PROD_ENV)" || { echo "Arquivo $(PROD_ENV) ausente" >&2; exit 2; }
	$(PROD_COMPOSE) down

# -----------------------------------------------------------------------------
# Internos / raros (não aparecem no help)
# -----------------------------------------------------------------------------

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
		docker compose --profile dev run --rm --no-deps frontend-dev prepare

frontend-install: frontend-prepare-generated
	LOCAL_UID=$(LOCAL_UID) LOCAL_GID=$(LOCAL_GID) \
		docker compose --profile dev run --rm --no-deps frontend-dev install

frontend-generate: frontend-prepare-generated
	LOCAL_UID=$(LOCAL_UID) LOCAL_GID=$(LOCAL_GID) \
		docker compose --profile dev run --rm --no-deps frontend-dev generate

frontend-dev: dev

wazync-test:
	docker run --rm -v $(CURDIR):/workspace -w /workspace/apps/wazync golang:1.25-alpine go test ./...
	docker run --rm -v $(CURDIR):/workspace -w /workspace/apps/wazync golang:1.25-alpine go vet ./...

seed-pilot:
	docker compose exec php php artisan db:seed --class=PilotSeeder --force

backup backup-verify restore \
prod-backup prod-backup-verify prod-restore prod-restore-smoke \
prod-readiness prod-release-manifest:
	$(OPS_UNAVAILABLE)
