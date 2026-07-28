## Context

`apps/api` possui cerca de 2.060 arquivos inventariados, 538 declarações de rota,
108 controllers, 212 models, 36 jobs e 53 commands. A baseline levantada em
2026-07-27 encontrou:

- 80 controllers com validação inline, 79 com acesso direto a persistência e
  controllers entre 500 e 921 linhas;
- dezenas de endpoints paginados, mas apenas 8 Resources e 6 usos de Resources
  em controllers;
- mais de 70 throttles numéricos em rotas e apenas o login com limiter nomeado;
- 26 schedules sem `withoutOverlapping` ou `onOneServer`;
- jobs sem uma política uniforme de backoff, timeout, tags e falha;
- 104 dispatches e somente 6 usos explícitos de after-commit;
- nenhum strict mode de lazy loading, uso limitado de chunking e 178 models sem
  factory pelo basename;
- quatro findings versionados, três deles associados a uma change inexistente.

A API já possui boas fundações: URI `/api/v1`, contratos OpenAPI e testes de
superfície, `CurrentTenant`, Policies nos domínios principais, 42 ports,
transações extensivas, cache com prefixo e `serializable_classes=false`. O
refactor deve ampliar essas fundações, preservar contratos e não habilitar
egress ou capabilities reais.

## Goals / Non-Goals

**Goals:**

- Tornar os padrões canônicos Laravel verificáveis pelos testes normais do
  projeto, sem ensinar o código sobre as referências usadas na auditoria.
- Remover regras de domínio, validação e serialização dos controllers.
- Preservar os contratos públicos existentes enquanto se introduzem Resources,
  DTOs, Policies e exceptions canônicas, exceto pelo hardening deliberado do
  `execute` fiscal, que passa a exigir `preflight_token` e `idempotency_key`.
- Tornar consultas grandes, transações, filas, schedules, HTTP e observabilidade
  previsíveis, idempotentes e seguros para múltiplas réplicas.
- Resolver e atualizar o ledger de findings com testes que provem cada correção.

**Non-Goals:**

- Redesenhar o produto, mudar envelopes públicos ou introduzir `/api/v2`.
- Habilitar integrações, flags, allowlists ou kill switches de produção.
- Redesenhar o frontend Nuxt, alterar seu bootstrap Sanctum ou mover negócio
  para Wazync; permanece no escopo somente o ajuste mínimo do consumidor fiscal
  exigido pelo hardening do contrato.
- Substituir ports, DTOs e services corretos apenas por preferência estética.
- Fazer reset, limpeza ou migração destrutiva do banco local.

## Decisions

### 1. Evidência integrada aos gates existentes

Os padrões serão provados por feature tests, unit tests, contract tests e pelo
ledger/findings já existente. O código de aplicação e as ferramentas de
qualidade não conhecerão a lista de referências usada nesta auditoria.
Exceções arquiteturais serão estreitas, justificadas no design do boundary e
cobertas pelo teste correspondente.

Alternativa considerada: criar um manifesto e validator específico da auditoria.
Rejeitada porque acoplaria o código a instruções de trabalho externas ao produto.

### 2. Refactor modular preservando contratos

O trabalho seguirá lotes por boundary de domínio: plataforma/autenticação,
clientes/tenant, Work, Communication, fiscal/outbound e endpoints internos. Cada
lote começa com testes de compatibilidade, extrai Form Requests, Resources,
Actions e Policies e termina removendo a exceção correspondente do gate.

Alternativa considerada: conversão mecânica de todos os controllers de uma vez.
Rejeitada porque dificultaria revisar autorização, tenancy e response shapes.

### 3. Boundary HTTP padrão

O caminho padrão será:

`FormRequest -> Policy -> Action/Service(DTO) -> Resource`

Endpoints HMAC que precisam assinar o raw body usarão verificação antes do parse
e um validator dedicado. Timestamp e nonce/idempotência terão janela limitada e
claim atômico contra replay antes de qualquer mutação; essa é uma exceção de
protocolo, não permissão para validação inline genérica. Exceptions esperadas
serão tipadas e renderizadas no bootstrap, com códigos estáveis.

Os limiters nomeados representam budgets independentes por classe de risco; por
isso, status/completion do onboarding e acesso/chat do assistente não compartilham
contador. O push CT-e aplica o budget configurado a um HMAC-SHA-256 opaco do
Bearer, preservado como case-sensitive após o parse/trim do framework, e um teto
agregado pelo IP já sanitizado pela cadeia de proxies confiáveis. A chave HMAC é
estável e dedicada quando configurada, com `APP_KEY` como fallback; sua rotação
invalida somente os buckets de um minuto. O primeiro budget impede bypass
distribuído do token; o segundo preserva a proteção contra Bearers inválidos
rotativos, mesmo que integrações atrás do mesmo NAT ainda compartilhem esse teto.

Adapters que já transformavam falhas de domínio podem preservar seu
status/envelope publicado apenas com catches das exceptions concretas esperadas.
O envio PGDAS-D mantém temporariamente o `422` anterior; o controller de flows
mantém o código lowercase de disponibilidade. Nenhum deles captura a classe base,
evitando neutralizar novas exceptions tipadas silenciosamente.

A capability de preflight fiscal será retornada somente no envelope elegível e,
junto da chave de idempotência emitida, será obrigatória no `execute`. O
consumidor Nuxt deve reenviar ambos; serializers gerais de operação não podem
expor a capability.

### 4. Compatibilidade antes de Resources

Resources serão introduzidos com snapshots/contract tests do envelope atual.
Paginação preservará `data`, links e metadata já consumidos pela SPA. Métodos
`toPublicArray` de Models serão migrados para Resources ou transformers sem
mudança silenciosa de campo. Fora do hardening explícito do `execute` fiscal,
compatibilidade permanece obrigatória.

### 5. Persistência e volume

Strict lazy loading será habilitado em desenvolvimento/testes. Consultas
unbounded em jobs, commands e scans serão trocadas por `chunkById`, `lazy`,
`cursor` ou claims limitados conforme a mutabilidade. Novas migrations serão
forward-safe, PostgreSQL-compatible e reversíveis com `down` que preserve os
dados necessários. Uma mudança que exigiria rollback destrutivo deverá ser
redesenhada; a recuperação operacional seguirá por correção aditiva. Factories
serão adicionadas aos concrete models conforme os lotes tocam seus grafos, até o
gate não encontrar lacunas.

### 6. Side-effects depois do commit

Multi-write workflows continuarão usando `DB::transaction`. Invariantes que
dependem de estado mutável serão rechecadas dentro da transação, sob
`lockForUpdate`, claim atômico ou constraint. Dispatches e broadcasts observáveis
serão feitos com `dispatchAfterCommit`, `$afterCommit` ou `DB::afterCommit`.
Testes cobrirão rollback e concorrência.

### 7. Políticas operacionais centralizadas

Rate limiters serão nomeados e registrados por classe de risco. Jobs terão
roteamento de fila centralizado quando for política, controles explícitos,
idempotência e tags seguras. Schedules terão locks com
`releaseOnTerminationSignals: true` e `onOneServer` quando singleton. Eventos terminais não
serão selecionados pelo dispatcher normal.

### 8. Egress e observabilidade

Clientes externos permanecerão atrás de ports. Cada adapter documentará timeout,
retryability e classificação de erro. Logs e operational error fields passarão
por `LogSanitizer`; métricas aceitarão somente labels allowlisted.

## Risks / Trade-offs

- **[Risco] Resources alterarem envelopes existentes** → testes de contrato antes
  e depois de cada endpoint, sem alteração breaking.
- **[Risco] Strict lazy loading revelar N+1 em muitos testes** → habilitar em
  desenvolvimento/teste e corrigir por lote, sem habilitar eager loading
  automático opaco.
- **[Risco] Locks de scheduler impedirem recuperação rápida** → TTL explícito,
  `releaseOnTerminationSignals` e testes de segunda réplica.
- **[Risco] Retry reduzido perder falhas recuperáveis** → classificação
  transitória explícita, backoff configurado e recovery manual auditável.
- **[Risco] Escopo amplo produzir diffs difíceis de revisar** → lotes pequenos,
  testes focados, tasks marcadas imediatamente e gates completos ao final.
- **[Risco] Conflito com alterações locais do usuário** → preservar arquivos
  modificados fora do lote e não sobrescrever o trabalho Sanctum/Nuxt existente.

## Migration Plan

1. Revalidar o ledger/findings existente e capturar a baseline dos gaps.
2. Corrigir findings P1/P2 e políticas de logging/consistência.
3. Refatorar boundaries por domínio, ativando as regras correspondentes no gate.
4. Canonicalizar volume, factories, jobs, Horizon, HTTP e scheduler.
5. Regenerar os artefatos de qualidade e fechar todos os gaps confirmados.
6. Rodar testes focados, Pint, suite completa e CodeRabbit no diff de
   `apps/api`.

Rollback é por lote e por código; migrations novas devem ser reversíveis com
`down` seguro e preservação dos dados necessários. Nenhum rollback dependerá de
editar migrations históricas, apagar dados ou executar um `down` destrutivo; a
recuperação operacional avançará por nova migration aditiva.

## Open Questions

- Quais models concretos são deliberadamente somente leitura e podem usar uma
  factory base compartilhada em vez de factory própria?
- Quais filas têm SLA crítico distinto de `default` e precisam de supervisores
  separados no primeiro rollout?
- Quais respostas manuais representam projeções não-Eloquent e devem permanecer
  em transformers versionados em vez de Resources?
