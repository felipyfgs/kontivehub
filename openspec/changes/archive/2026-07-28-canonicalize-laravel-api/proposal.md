## Why

A API cresceu para mais de 500 rotas e hoje mistura padrões canônicos com
validação inline, respostas manuais, controllers extensos, schedules sem
coordenação e jobs com políticas operacionais incompletas. A auditoria pelas 18
referências canônicas Laravel também encontrou findings de segurança, consistência e
observabilidade que impedem afirmar que `apps/api` está integralmente alinhada ao
Laravel 13 e aos limites multi-tenant do KontiveHub.

## What Changes

- Tornar os boundaries HTTP canônicos: Form Requests para
  autorização/validação, Resources ou transformers versionados para respostas,
  Policies/Gates para decisões de acesso, exceptions renderizáveis e rate
  limiters nomeados.
- Reduzir controllers à orquestração, movendo regras, escritas e integrações para
  Actions/Services e DTOs testáveis, sem alterar os contratos públicos de
  `/api/v1`.
- Padronizar persistência e leitura: eager loading verificável, processamento
  limitado ou em chunks, factories para models concretos, migrations
  forward-only, caches com escopo/invalidação explícitos e side-effects somente
  após commit.
- Padronizar runtime assíncrono: ports/adapters para egress, timeouts e retries
  seletivos, jobs idempotentes com controles e tags, filas nomeadas, métricas
  Horizon e schedules protegidos contra sobreposição e execução multinó.
- Corrigir os findings de qualidade vigentes, incluindo correlação SERPRO,
  consumo atômico de token, exposição de capability e sanitização de logs.
- Endurecer a execução de mutações fiscais para exigir a capability emitida pelo
  preflight e a chave de idempotência, atualizando o consumidor Nuxt local.
- Ampliar os testes de arquitetura, contratos e regressão usando a infraestrutura
  de qualidade já existente, sem acoplar o código às referências usadas na
  revisão.
- O hardening fiscal é deliberadamente restritivo: o token deixa serializers
  gerais e `execute` passa a exigir token e idempotência. Fora desse boundary,
  envelopes, campos, status HTTP e semântica publicados permanecem compatíveis.

## Capabilities

### New Capabilities

- `laravel-http-boundaries`: Validação, autorização, serialização, evolução de
  contrato, rate limiting, CSRF e tratamento de exceptions nos endpoints.
- `laravel-data-integrity`: Relacionamentos, consultas em volume,
  migrations/factories, cache e consistência transacional multi-tenant.
- `laravel-runtime-operations`: Ports/adapters, clientes HTTP, filas/Horizon,
  scheduling, métricas e logging seguro.

### Modified Capabilities

Nenhuma. Ainda não há specs principais registradas em `openspec/specs`.

## Impact

- Código afetado: `apps/api/app`, `apps/api/routes`, `apps/api/bootstrap`,
  `apps/api/config`, `apps/api/database`, `apps/api/tests`, o consumidor fiscal
  mínimo em `apps/web` e os artefatos canônicos em
  `apps/api/resources/code-quality`.
- Contratos afetados: o execute fiscal exige o round-trip de
  `preflight_token` e `idempotency_key`, coberto nos dois consumidores locais.
  As demais evoluções públicas seguirão aditivas e terão testes de
  compatibilidade de `/api/v1` e OpenAPI.
- Runtime afetado: PHP-FPM, scheduler, Horizon/Redis, PostgreSQL e adapters de
  integrações externas. Nenhuma capability real, kill switch ou egress de
  produção será habilitado por esta change.
