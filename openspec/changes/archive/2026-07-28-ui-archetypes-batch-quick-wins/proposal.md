## Why

A auditoria `ui-archetypes-standardization` e os lotes 1–5 já eliminaram as
divergências de chrome estrutural (admin, monitoring, analytics, master-detail
e docs). Restam quick wins transversais de baixo risco: erros de carga inicial
ainda em `UAlert` ad hoc, toolbar de `closing.vue` fora de
`ShellListFilterToolbar`, e `UPageCard` subtle onde `ShellSectionCard` é
drop-in. É o Lote 6 do roadmap guarda-chuva: padronizações pontuais sem novo
arquétipo nem redesign.

## What Changes

- Migrar falhas de carga **inicial sem dados** para `ShellLoadError` em
  `syncs.vue`, `closing.vue`, `work/calendar.vue` (intervalo sem stale) e
  superfícies settings/SERPRO onde couber; stale/parcial permanece local.
- Migrar a toolbar de filtros de `pages/closing.vue` para
  `ShellListFilterToolbar` (competência no slot `#actions`), removendo a
  montagem duplicada de chips/presets.
- Trocar `UPageCard` variant subtle por `ShellSectionCard` em settings filhas
  e seções drop-in (conta, clientes detalhe, cards de saúde CT-e em syncs).
- Documentar a dívida dos dois KPI strips (`ShellKpiStrip` vs
  `MonitoringKpiStrip`) sem forçar merge contratual.
- Preservar rotas, permissões, `data-testid` relevantes, matriz de paridade e
  allowlists do gate de fidelidade. Sem Shell novo, sem SSR/Pinia, sem deps.

## Capabilities

### New Capabilities

- `ui-archetypes-quick-wins`: padronização pontual de erro de carga, toolbar
  de lista e cards de seção via Shells já existentes, sem novo arquétipo.

### Modified Capabilities

Nenhuma. Specs principais de UI não existem em `openspec/specs`; o
guarda-chuva só define auditoria/roadmap.

## Impact

- Código: páginas e componentes em `apps/web` listados no inventário do
  `design.md` / `tasks.md` deste change.
- Apps: somente `apps/web`. Sem impacto em API, Wazync, contratos ou flags.
- Testes: gates de composição existentes e testes unitários que leem fontes
  (`work-calendar-composition`, `shell-list-migration-gate`, etc.).
- Gates: focados primeiro; completos (`lint`, `typecheck`, `generate`, `test`,
  `test:fidelity`, `test:artifacts`) quando o container permitir.
- Fora de escopo: `DocsWorkspace` (Lote 5), `MonitoringModuleTable` já
  migrado, unificação forçada de KPI strips, allowlists, redesign.
