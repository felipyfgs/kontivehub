## Why

Communication, fila de trabalho e Caixa Postal compartilham o padrão
mestre–detalhe, mas ainda repetem a navbar principal e o collapse já
encapsulados pelo Shell canônico. O Lote 4 padroniza somente esse chrome sem
achatar layouts, painéis e interações materialmente diferentes.

## What Changes

- Usar `ShellPageNavbar` apenas no painel mestre de
  `CommunicationWorkspacePage`, no chrome de `WorkQueueChrome` e no navbar
  principal de `monitoring/mailbox.vue`.
- Remover somente os três collapses manuais duplicados.
- Preservar badges, ações, toolbars, filtros, painéis redimensionáveis,
  detalhes desktop, slideovers mobile, views Fila/Lista/Kanban, seleção, foco e
  atalhos.
- Não criar `ShellMasterDetailWorkspace` nem trocar os painéis estruturais.
- Não alterar rotas, matriz, allowlists, API, flags ou dados.

## Capabilities

### New Capabilities

- `ui-archetypes-master-detail`: navbar canônica dos painéis mestre de
  Communication, Work e Caixa Postal, preservando composição responsiva,
  seleção e foco.

### Modified Capabilities

Nenhuma.

## Impact

- Código: três componentes/páginas Web e gates focados.
- Consumidores: `/communication`, superfícies da fila de trabalho e
  `/monitoring/mailbox`, sem mudança em seus composables ou clientes HTTP.
- Contratos: nenhuma mudança em `/api/v1`, props, emits, rotas, testids,
  dependências, egress ou persistência.
