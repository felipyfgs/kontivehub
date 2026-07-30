## Why

O review completo do worktree encontrou falhas independentes de convergência,
reconciliação e acessibilidade no fluxo de Communication. A mais crítica deixa
comandos `MEDIA_RETRY_REQUEST` presos em `PROCESSING` no Wazync por divergência
entre enum e predicado SQL. Outros caminhos podem repetir falhas determinísticas,
manter projeções antigas na SPA, perder cursores conhecidos em eventos parciais
ou agendar consultas de foto sem o limiter já disponível.

## What Changes

- Corrigir e completar as finalizações fenced do Wazync, terminalizando estados
  determinísticos de media retry e expondo a concorrência das consultas longas
  de foto sem labels sensíveis.
- Aplicar o limiter tenant/ator/IP ao agendamento manual de foto, alinhar seus
  testes à normalização de configuração e evitar varredura anterior ao cursor
  no storage de mídia.
- Alinhar o OpenAPI privado ao estado `REQUESTED` já aceito pelo consumidor,
  excluir mensagens quarentenadas do ledger visível e explicitar tenant/enum
  nos caminhos de consulta revisados.
- Reconciliar catálogo, bulk operations, status e read-state da SPA com a API
  autoritativa, inclusive sob respostas concorrentes, timeout e eventos
  parciais.
- Corrigir guarda de permissão, composição de modal, foco, semântica ARIA e
  altura acessível da lista virtualizada sem alterar o arquétipo master-detail.
- Executar até três ciclos review→fix, os gates integrais e commits atômicos,
  sem push, egress real ou abertura de flags/kill switches.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `whatsapp-history-media-recovery`: convergência fenced e terminal do comando
  `MEDIA_RETRY_REQUEST`.
- `communication-whatsapp-profile-pictures`: scheduling limitado e
  observabilidade segura da consulta longa.
- `communication-contacts-catalog`: reload autoritativo, modal alcançável,
  autorização fail-closed e controles semânticos.
- `communication-conversation-workspace`: read-state parcial, retry por snapshot,
  foco consumível e lista virtualizada acessível.
- `communication-conversation-read-state`: merge monotônico de eventos e
  acknowledgement seguro por snapshot.
- `communication-conversation-list-operations`: reconciliação autoritativa de
  ações unitárias e bulk inclusive após timeout/erro.

## Impact

- **Wazync:** stores Memory/PostgreSQL, worker de comandos, protocolo de queries,
  métricas HTTP e testes Go.
- **Laravel:** rota/limiter de profile picture, storage de mídia e testes de
  rate limiting/contrato.
- **Nuxt:** composables de catálogo/workspace, detalhe de contato, lista e
  modais de Communication, com testes Vitest/Nuxt.
- **Contratos:** nenhum endpoint ou shape público é removido; deltas tornam
  explícita a reconciliação dos campos opcionais já publicados. O OpenAPI
  privado passa a aceitar `REQUESTED` sem metadata de spool, como o DTO atual.
- **Operação:** reduz backlog, reexecução e UI stale; não consulta WhatsApp real,
  não habilita capabilities e não expõe JID, URL, conteúdo ou payload bruto.
