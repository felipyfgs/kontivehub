# AGENTS.md

## Escopo e precedência

Este é o guia canônico do monorepo KontiveHub. Antes de editar, leia também o
`AGENTS.md` mais próximo: `apps/api/AGENTS.md`, `apps/web/AGENTS.md` ou
`apps/wazync/AGENTS.md`. A instrução mais específica prevalece; o pedido do
usuário prevalece sobre todas.

## Contexto do produto

KontiveHub é uma plataforma multi-tenant para escritórios contábeis; o tenant é
`Tenant`. Domínios: fiscal/documental, SERPRO/Integra Contador/SEFAZ/ADN/FGTS/MEI,
trabalho operacional e comunicação.
A API Laravel é dona do domínio e das políticas. A SPA Nuxt apresenta esses
fluxos. Wazync é apenas o gateway técnico de WhatsApp.

## Stack

- API: PHP 8.4, Laravel 13, Fortify, Sanctum, Horizon, Reverb e PHPUnit 12.
- Dados: PostgreSQL 17 em runtime e testes; Redis 8 para cache/filas/sessão.
- Web: Nuxt 4/Vue 3 SPA estática, Nuxt UI 4, TypeScript 6, pnpm 11, Vitest e Playwright.
- Gateway/runtime: Go 1.25, WhatsMeow, pgx e Docker Compose; Nginx local, Nginx/Traefik em produção.

## Layout

- `apps/api`: API `/api/v1`, domínio, serviços, contratos, jobs, migrations e integrações.
- `apps/web`: SPA/PWA, páginas, componentes e clientes HTTP em `app/composables/api`.
- `apps/wazync`: protocolo WhatsApp, sessões, spool e persistência técnica; sem negócio contábil.
- `apps/api/resources/contracts/wazync.openapi.yaml`: contrato privado Laravel↔Wazync.
- `infra/docker`, `docker-compose*.yml` e `Makefile`: ambientes e operação.
- `openspec`: propostas, delta specs, tarefas e ADRs. Não recrie o layout
  `/docs`, que é ignorado deliberadamente.

## Ambiente local

Use Make e Compose a partir da raiz. Não instale Composer, pnpm ou módulos Go
diretamente no host quando houver fluxo containerizado equivalente.

```bash
make setup        # primeira execução: env, imagens, deps, migration e stack
make dev          # API :8080 + Nuxt HMR :3000
make up           # stack com SPA estática
make logs
make shell
make migrate
make seed
make down
```

`make init-env` cria `.env` e `apps/api/.env` com permissão `600` e chaves locais.
Documente apenas os exemplos. Como `COMPOSE_PROJECT_NAME` pode coincidir entre
checkouts, recrie com `make dev` antes de usar `exec` em uma stack de outro diretório.

## Gates de qualidade

Rode testes focados primeiro e todos os gates do app alterado antes do handoff.
Não há CI versionado; estes comandos e os nested `AGENTS.md` são a fonte de verdade.

```bash
# API — com a stack deste checkout em execução
docker compose exec php composer validate --strict --no-check-publish
docker compose exec php vendor/bin/pint --test
docker compose exec php php artisan test

# Web — após make dev
docker compose --profile dev exec frontend-dev corepack pnpm run lint
docker compose --profile dev exec frontend-dev corepack pnpm run typecheck
docker compose --profile dev exec frontend-dev corepack pnpm run generate
docker compose --profile dev exec frontend-dev corepack pnpm run test
docker compose --profile dev exec frontend-dev corepack pnpm run test:fidelity
docker compose --profile dev exec frontend-dev corepack pnpm run test:artifacts

# Wazync
make wazync-test
```

Playwright E2E é validação local adicional, não gate padrão. Use
`apps/web/tests/e2e/run-local.mjs` e não publique os artefatos gerados.

## Orquestração de subagentes

- Em tarefas não triviais com frentes independentes, delegue até três
  subtarefas em paralelo.
- Em mudanças entre apps, use no máximo um explorador somente leitura por app
  afetado; dentro de um app, divida por responsabilidades independentes.
- O agente principal mantém requisitos, decisões arquiteturais, integração e
  resposta final. Aguarde a exploração antes de decidir a implementação.
- Mantenha um único agente escritor por padrão. Só paralelize escritas quando
  os arquivos e contratos forem comprovadamente disjuntos.
- Cada subagente retorna conclusão, evidências com arquivos e símbolos, riscos
  e testes recomendados. Não despeje logs ou notas brutas no contexto principal.
- Reserve o agente `expert` para arquitetura crítica, segurança, concorrência,
  investigação ambígua ou falhas persistentes. Se ele assumir a implementação,
  mantenha os demais subagentes somente leitura.
- Não delegue tarefas simples, estritamente sequenciais ou cujo custo de
  coordenação supere o ganho de velocidade.

## Convenções

- Sempre se comunique com o usuário em português do Brasil (pt-BR), salvo
  solicitação explícita em outro idioma.
- Identificadores de código seguem o inglês e termos oficiais do domínio;
  interface e documentação de produto usam pt-BR.
- PHP usa PSR-4, Pint e 4 espaços. Controllers orquestram; validação/autorização
  ficam em Form Requests/Policies e lógica em Actions/Services/DTOs.
- Vue/TypeScript usa 2 espaços, sem ponto e vírgula e sem vírgula final. Reuse
  componentes, composables e tipos existentes.
- Formate Go com `gofmt`; `make wazync-test` executa `go test ./...` e `go vet ./...`.
- Preserve contratos públicos e respostas existentes. Mudanças de `/api/v1` ou
  do OpenAPI Wazync exigem testes de compatibilidade nos dois consumidores.
- Para mudança não trivial, crie/atualize um change em `openspec/changes` antes
  da implementação e mantenha testes junto ao comportamento, salvo dispensa
  explícita do usuário.

## Limites arquiteturais

- Resolva tenant por `CurrentTenant` e membership. Nunca confie em `tenant_id`
  arbitrário recebido por HTTP nem remova scopes fail-closed de
  `BelongsToTenant`.
- Acesso global de plataforma usa contexto privilegiado tipado, flag explícita
  e auditoria; não fabrique membership para contornar autorização.
- Integrações externas entram por contratos/adapters e configuração no container.
  Não espalhe SDKs, HTTP ou credenciais por controllers/models.
- Operações com múltiplas escritas usam transação. Jobs/eventos externos saem
  após commit e devem ser idempotentes, observáveis e seguros para retry.
- Migrations já compartilhadas são imutáveis; crie uma nova migration reversível.
  Use recursos compatíveis com PostgreSQL e mantenha chaves/índices com `tenant_id`.
- Mutações fiscais, egress real, automações e rollouts são fail-closed. Nunca
  ligue `*_ENABLED`, `ALLOW_ALL_*`, capabilities reais ou desligue kill switches
  em exemplos/testes sem autorização e plano de rollout explícitos.
- O domínio `Work` não chama SERPRO, ADN ou SEFAZ. Comunicação de negócio fica
  no Laravel; Wazync limita-se a protocolo, sessão, transporte e spool.
- O frontend usa Sanctum por cookie e clientes em `composables/api`. Nunca chama
  Wazync/sidecars diretamente, não inventa dados em falha de API e não introduz
  SSR ou Pinia sem decisão arquitetural explícita.
- O gateway Wazync e suas rotas/metrics são internos. Não exponha JIDs crus,
  protobufs, QR, conteúdo de mensagens ou endpoints do gateway no proxy público.

## Segurança e dados sensíveis

- Nunca versione ou imprima `.env`, `.env.prod`, `auth.json`, tokens, PFX/PEM,
  certificados, chaves, vault, backups, spool/sessões WhatsApp ou
  `storage/app/private`.
- Nunca grave XML fiscal, corpo de mensagem, chave de acesso, CNPJ completo,
  credenciais ou payload bruto em logs/métricas. Use `App\Support\LogSanitizer`
  e referências opacas.
- Preserve HMAC, timestamp, nonce, idempotência e rotação de chave no contrato
  Laravel↔Wazync. Não crie fallback permissivo em falha de assinatura/provider.
- Mantenha CORS, Sanctum e Reverb com allowlists explícitas; nunca use wildcard
  para corrigir integração.
- Não execute comandos de produção (`make prod-up`, `prod-down`, restore,
  go-live/smoke real) sem autorização explícita e ambiente validado. Os targets
  de backup/restore do Makefile ainda estão indisponíveis.

## Git e artefatos

- Preserve mudanças e arquivos não relacionados do usuário; não faça limpeza,
  reset, stage, commit ou push sem pedido.
- Não versione `vendor`, `node_modules`, `.nuxt`, `.output`, caches, logs,
  resultados Playwright ou ferramentas locais em `.agents`.
- Não há convenção de commit confiável no histórico atual; siga o formato pedido
  pelo usuário quando houver.

## Fontes adicionais

- Regras locais: `apps/*/AGENTS.md`.
- Comportamento executável: `apps/api/tests`, `apps/web/tests` e testes Go.
- Flags e boundaries: `apps/api/config`, `.env.example` e `apps/api/.env.example`.
- Especificações de mudança: `openspec/changes` e `openspec/specs`.
- Referências externas (somente local, não versionado): `.local/references`.
  Fonte de verdade para exploração, padrões e esclarecimento de dúvidas — não
  é código do produto. Consulte antes de inventar UX, inbox/comunicação ou
  gateway WhatsApp. Não edite, version ou copie em massa para `apps/*` sem
  adaptação aos boundaries do monorepo. Subpastas atuais:
  - `dashboard` — arquétipos UI Nuxt (skill `ui-archetypes`)
  - `chatwoot` — domínio de inbox/conversas/contatos
  - `evolution-go` e `go-whatsapp-web-multidevice` — gateways WhatsApp em Go
