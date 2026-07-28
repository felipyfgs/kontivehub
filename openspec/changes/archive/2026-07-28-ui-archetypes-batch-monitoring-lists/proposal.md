## Why

As dez carteiras fiscais de monitoramento compartilham `MonitoringModuleTable`,
mas o wrapper ainda monta `UDashboardPanel` e `UDashboardNavbar` diretamente,
duplicando o chrome já encapsulado pelos Shells canônicos. O Lote 2 elimina
essa divergência em um único ponto sem alterar adapters, filtros, KPIs ou as
rotas consumidoras.

## What Changes

- Migrar somente `components/monitoring/ModuleTable.vue` para
  `ShellPagePanel`, `ShellPageNavbar` e `ShellNavbarRefresh`.
- Manter o botão de consulta pendente, o refresh do toolbar, todos os slots,
  emits, testids, filtros, seleção e paginação existentes.
- Usar `ShellLoadError` apenas no erro inicial sem linhas e preservar o alerta
  contextual quando a última carga válida continua visível.
- Preservar `ModuleToolbar`, `ModuleDataTable`, os adapters das dez rotas e o
  KPI strip fiscal distinto; nenhuma faixa KPI será unificada.
- Não criar componente Shell público, página ou rota e não alterar matriz ou
  allowlists de fidelidade.

## Capabilities

### New Capabilities

- `ui-archetypes-monitoring-lists`: chrome canônico e estados de carga das
  listas fiscais compartilhadas por `MonitoringModuleTable`, preservando os
  contratos de composição das superfícies consumidoras.

### Modified Capabilities

Nenhuma.

## Impact

- Código: um componente em `apps/web/app/components/monitoring/ModuleTable.vue`
  e um teste de gate focado.
- Consumidores: declarations, installments, FGTS, guides, registrations,
  tax-processes, DCTFWeb, SITFIS, Simples e MEI, sem edição direta.
- Contratos: nenhuma mudança em `/api/v1`, rotas Nuxt, props, emits, slots,
  filtros, paginação ou `data-testid` existentes.
- Operação: sem API, migration, egress, flag, rollout ou dependência nova.
