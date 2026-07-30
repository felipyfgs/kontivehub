## Context

O worktree reúne uma evolução grande de Communication entre Laravel, Nuxt e
Wazync. O review automatizado foi validado por especialistas por app porque
várias sugestões eram incompatíveis com decisões já documentadas: o bypass de
global scope do import é necessário no job sem `CurrentTenant`; a conversa
selecionada deve continuar pinada no filtro “Não lidas”; e `source_identity` já
distingue fallback legado de evidência PN↔LID e exclui a sessão própria.

Os achados confirmados formam quatro classes: fencing/convergência do Wazync,
limites e cursor de storage no Laravel, reconciliação autoritativa da SPA e
semântica/acessibilidade das superfícies master-detail.

## Goals / Non-Goals

**Goals:**

- Garantir que toda tentativa de `MEDIA_RETRY_REQUEST` conclua uma única vez
  como `PROCESSED`, `RETRY` ou `ERROR` sob CAS de status/tentativa.
- Evitar retries de condições determinísticas e tratar comando ausente como
  conflito fenced seguro.
- Limitar agendamento manual de foto e observar concorrência de queries longas
  sem aumentar cardinalidade ou registrar dados do WhatsApp.
- Fazer catálogo, lista, timeline e bulk convergirem para respostas autoritativas
  mesmo sob concorrência, evento parcial, timeout ou falha de acknowledgement.
- Preservar a composição Shell/Nuxt UI, o comportamento responsivo e a
  navegação por teclado/foco do arquétipo master-detail.

**Non-Goals:**

- Alterar shapes públicos, migrations compartilhadas ou o OpenAPI privado sem
  um finding contratual confirmado.
- Remover o pin da conversa selecionada no filtro unread.
- Inferir aliases WhatsApp, relaxar HMAC/nonce ou expor métricas com IDs/JIDs.
- Executar recovery, smoke ou profile picture reais, habilitar egress ou mudar
  flags de rollout.
- Fazer refactors estilísticos sem defeito ou ganho verificável.

## Decisions

### Usar o enum canônico e CAS em toda finalização

As finalizações PostgreSQL recebem `string(domain.CommandRetryMedia)` como
parâmetro e preservam `status=PROCESSING` e `attempt_count=expectedAttempts`.
Comando ausente em `FinalizeCommandFailureWithEvent` será normalizado para
`ErrStateConflict`, igual aos demais fences, sem ocultar conflito de digest.

### Terminalizar apenas falhas determinísticas de media retry

`ErrMediaRetryStateMissing` e `ErrHistoryRecoveryInvalid` terminam a tentativa
imediatamente e publicam os códigos allowlisted existentes. Claim perdido,
falha temporária do provider e erros genéricos continuam retryáveis.

### Observar query longa sem dados ou labels dinâmicos

`PROFILE_PICTURE` terá gauge atômico de in-flight incrementado depois da
validação e decrementado por `defer`, exportado em `/metrics`. Não haverá label
por query, sessão, tenant, endereço, URL ou resultado bruto.

### Recarregar a fonte autoritativa depois de mutações

Atualização de contato e ações de status usarão os métodos centrais do
workspace/catálogo e aguardarão reload protegido por epoch/sequence. Polling
bulk que termina por erro ou timeout fará reload best-effort antes de liberar o
estado local; não declarará sucesso quando o resultado remoto for desconhecido.

### Alinhar contrato e visibilidade sem abrir boundaries

O ramo de mídia sem spool do OpenAPI incluirá `REQUESTED`, estado que o DTO
Laravel já valida e que não exige `spool_id`, digest, tamanho ou MIME. A busca
telefônica continuará derivada do POST para manter PII fora da URL. Shared
content continuará com autorização contextual após canonicalização nos
controllers, preservando 404; não será movido mecanicamente ao Form Request.

`markUnread` e a projeção do ledger reutilizarão o constraint central de
visibilidade de mensagens para ignorar quarentena. Lookups de contato no filtro
de conversas receberão `tenant_id` explícito além do global scope fail-closed.
Literais de estado usarão enum com binding SQL, sem interpolação.

### Reutilizar inboxes visíveis sem mudar a precedência de motivos

O catálogo carregará as inboxes visíveis uma vez e derivará `canReply` e
disponibilidade operacional da mesma coleção, mantendo `permission_denied`
antes de `inbox_unavailable`. Isso remove query duplicada sem ampliar acesso.

### Mesclar eventos parciais sem apagar conhecimento

Ausência de `last_read_through_message_id` significa “campo não projetado”, não
`null`. A SPA preservará o cursor conhecido ao aplicar versão mais nova. Falhas
de acknowledgement serão associadas ao `snapshot_through_message_id`: o mesmo
snapshot não é martelado, mas um snapshot posterior pode tentar novamente.

### Consumir intenção de foco e dimensionar a linha por rem medido

A restauração de foco é uma intenção one-shot e será limpa mesmo se o alvo não
existir após filtro/rota. A lista mantém virtualização eficiente, mas deriva a
altura de `5.75rem` medida no runtime em vez de assumir 92 px imutáveis, mantendo
matemática e CSS sincronizados sob preferência de tamanho de fonte.

### Preservar slots e overlays canônicos

O modal de nova conversa ficará dentro do `#body` efetivamente renderizado pelo
`ShellPagePanel`. A página sem `communication.view` encerrará antes de criar os
composables de domínio. `aria-controls` só referenciará painel expandido que
existe no DOM.

## Risks / Trade-offs

- **[Reload adicional após mutação]** → priorizar exatidão de totais/filtros e
  manter loading silencioso/sequence para evitar flicker e resposta stale.
- **[Timeout bulk sem estado terminal conhecido]** → reload best-effort e copy
  honesta; realtime/poll posterior continua podendo convergir.
- **[Gauge fica incorreto por panic]** → `defer` imediatamente após incremento e
  teste concorrente bloqueante cobrindo subida e retorno a zero.
- **[Altura virtual diverge do CSS]** → uma única constante em rem, medição do
  elemento raiz e testes de materialização/foco com fonte ampliada.
- **[Mesmo limiter atende stream e scheduling]** → reutilizar o limiter já
  tenant/ator/IP conforme o finding, preservando headers e compatibilidade; uma
  política separada pode ser proposta depois se houver dados operacionais.
- **[Índice sugerido não cobre a expressão real]** → não criar migration sem
  `EXPLAIN (ANALYZE, BUFFERS)` representativo; o finding de índice fica
  rejeitado neste change porque a query usa identity canônica via join/COALESCE.

## Migration Plan

1. Publicar código sem mudança de schema nem flags.
2. Laravel/Web podem ser publicados em qualquer ordem porque os campos e rotas
   existentes permanecem aditivos.
3. Publicar Wazync corrigido; comandos presos podem ser reclamados pelo mecanismo
   atual e então convergir.
4. Monitorar backlog, conflitos fenced e gauge in-flight, todos sem labels
   sensíveis. Rollback é binário; nenhuma reversão de dados é necessária.

## Open Questions

Nenhuma para implementação. O smoke real de profile picture e media retry
continua fora deste change por exigir autorização operacional explícita.
