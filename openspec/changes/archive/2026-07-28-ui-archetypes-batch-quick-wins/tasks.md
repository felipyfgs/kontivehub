# Tasks — ui-archetypes-batch-quick-wins

Implementa o Lote 6 do roadmap `ui-archetypes-standardization`.

## 1. Preparação

- [x] 1.1 Rodar `node .agents/skills/ui-archetypes/scripts/check-dashboard-reference.mjs` (PASS)
- [x] 1.2 Inventariar residual do `audit.md` (top-10 #3/#8/#9/#10) vs lotes 1–5 — ver `design.md`
- [x] 1.3 Criar este change (`.openspec.yaml`, proposal, design, tasks, delta spec)

## 2. Erro de carga → ShellLoadError

- [x] 2.1 `pages/syncs.vue` — `loadError` e `cteError` com retry; detail/blocked ficam UAlert
- [x] 2.2 `pages/closing.vue` — `loadError` inicial
- [x] 2.3 `pages/work/calendar.vue` — intervalo sem stale; stale e dayError locais
- [x] 2.4 `admin/serpro/configuration.vue` e `contracts.vue` — loadError de página

## 3. Toolbar closing

- [x] 3.1 Substituir montagem manual por `ShellListFilterToolbar` (`surface="closing.list"`, sem busca, competência em `#actions`)
- [x] 3.2 Remover `useSavedListPresets` e modais de filtro duplicados da página
- [x] 3.3 Preservar `closing-filter-toolbar` / `closing-competence` e handlers de payload

## 4. UPageCard → ShellSectionCard (drop-in)

- [x] 4.1 Settings: `TenantCredentialSection`, `TenantSubscriptionPage`, seções de `TenantUsagePage`
- [x] 4.2 Clientes detalhe: `contato`, `departamento`, `observacoes`
- [x] 4.3 `syncs.vue` cards de saúde CT-e
- [x] 4.4 Não tocar team/departments (exceção header p-0) nem naked SERPRO

## 5. Dívida KPI strips

- [x] 5.1 Documentar no design (e neste tasks) que merge `ShellKpiStrip`/`MonitoringKpiStrip` fica fora — contratos distintos

## 6. Validação

- [x] 6.1 Testes focados (calendar composition, shell-list-migration, painel-responsivo, navigation, serpro-navigation) — 44 passed
- [x] 6.2 Gates completos (`lint`, `typecheck`, `generate`, `test`, `test:fidelity`, `test:artifacts`) — PASS (sessão 2026-07-28; 118 files / 581 tests; fidelity pages=74)
- [x] 6.3 Atualizar tasks do guarda-chuva (6.1–6.3; 6.4 só com gates completos)
