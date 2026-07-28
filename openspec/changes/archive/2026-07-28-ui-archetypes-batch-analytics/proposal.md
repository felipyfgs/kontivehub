## Why

As páginas analíticas de início e monitoramento ainda montam painel, navbar e
refresh diretamente, embora os Shells canônicos já representem a mesma
hierarquia do arquétipo `index.vue`. O Lote 3 elimina essa duplicação sem
misturar os dois dashboards nem alterar seus dados, ações e estados próprios.

## What Changes

- Migrar `pages/index.vue` e `pages/monitoring/index.vue` para
  `ShellPagePanel`, `ShellPageNavbar` e `ShellNavbarRefresh`.
- Preservar a toolbar do início, alertas, ações rápidas, CTAs, deep links,
  `lastGood`/última carga válida, erros iniciais, erros parciais e retries.
- Preservar o `body` e todos os componentes analíticos existentes, inclusive
  os dois KPI strips com semânticas diferentes.
- Não criar `ShellAnalyticsPage`, página, rota, prop pública ou dependência.
- Não alterar matriz de paridade nem ampliar allowlists de fidelidade.

## Capabilities

### New Capabilities

- `ui-archetypes-analytics`: chrome canônico das superfícies analíticas de
  início e monitoramento, com estabilidade de ações, dados válidos e estados
  de falha parcial.

### Modified Capabilities

Nenhuma.

## Impact

- Código: `apps/web/app/pages/index.vue`,
  `apps/web/app/pages/monitoring/index.vue` e um gate focado.
- Consumidores: dashboard inicial e dashboard fiscal, sem mudança em seus
  composables, componentes `home/*`, clientes HTTP ou contratos de dados.
- Contratos: nenhuma mudança em `/api/v1`, rotas Nuxt, testids, matriz,
  allowlists, flags ou egress.
