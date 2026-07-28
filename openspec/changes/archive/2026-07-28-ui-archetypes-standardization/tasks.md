# Tasks — ui-archetypes-standardization

Change guarda-chuva: auditoria + roadmap. **Não implementa código.**
Cada lote abaixo exige um change OpenSpec filho antes de qualquer edição em
`apps/web`.

Gates web completos por lote (container `frontend-dev`, após `make dev`):

```bash
docker compose --profile dev exec frontend-dev corepack pnpm run lint
docker compose --profile dev exec frontend-dev corepack pnpm run typecheck
docker compose --profile dev exec frontend-dev corepack pnpm run generate
docker compose --profile dev exec frontend-dev corepack pnpm run test
docker compose --profile dev exec frontend-dev corepack pnpm run test:fidelity
docker compose --profile dev exec frontend-dev corepack pnpm run test:artifacts
```

Pré-condição de todo lote de UI: referência local válida.

```bash
node .agents/skills/ui-archetypes/scripts/check-dashboard-reference.mjs
```

Atualizar `apps/web/tests/fixtures/template-parity-matrix.md` no mesmo change
filho se houver criação, renomeação ou remoção de página. Não ampliar
allowlists do gate de fidelidade sem autorização explícita.

## 0. Artefatos desta change (guarda-chuva)

- [x] 0.1 Criar `.openspec.yaml` (`schema: spec-driven`, `created: 2026-07-28`)
- [x] 0.2 Escrever `proposal.md` (Why / What / Capabilities / Impact)
- [x] 0.3 Escrever `audit.md` (inventário, classificação, top-10, gates)
- [x] 0.4 Escrever este `tasks.md` com roadmap dos 6 lotes
- [x] 0.5 Revisar com o usuário a ordem e a granularidade dos lotes antes do primeiro change de implementação (apresentado em 2026-07-28; ordem padrão mantida até confirmação explícita)

## 1. Lote 1 — Admin divergentes

Escopo: eliminar as divergências admin (fora do dashboard home).

Arquivos-alvo:

- `apps/web/app/pages/admin/serpro.vue` → `ShellSettingsShell` (cobre 8 rotas filhas)
- `apps/web/app/pages/admin/tenants/[id].vue` → `ShellPageNavbar` + `ShellNavbarBack` + `ShellLoadError`
- `apps/web/app/pages/admin/tenants/new.vue` → idem

- [x] 1.1 Criar change OpenSpec do lote (ex.: `ui-archetypes-batch-admin-shell`)
- [x] 1.2 Aplicar skill `ui-archetypes` (check-dashboard-reference + arquétipo settings/lista)
- [x] 1.3 Migrar `admin/serpro.vue` para `ShellSettingsShell` preservando toolbar/navegação e filhas
- [x] 1.4 Migrar `admin/tenants/[id].vue` e `new.vue` para Shell navbar/back/erro
- [x] 1.5 Testes focados das superfícies admin tocadas
- [x] 1.6 Gates web completos

## 2. Lote 2 — Listas fiscais monitoring

Escopo: refator interno de `MonitoringModuleTable` e adapters para compor Shell.

Arquivos-alvo:

- `apps/web/app/components/monitoring/ModuleTable.vue`
- `apps/web/app/components/monitoring/ModuleToolbar.vue`
- `apps/web/app/components/monitoring/KpiStrip.vue` (se necessário ao contrato)
- Rotas consumidoras (~10): declarations, installments, fgts, sitfis, guides,
  registrations, tax-processes, dctfweb, simples, mei

- [x] 2.1 Criar change OpenSpec do lote (ex.: `ui-archetypes-batch-monitoring-lists`)
- [x] 2.2 Aplicar skill `ui-archetypes` (arquétipo lista/`customers.vue`)
- [x] 2.3 Trocar navbar UDirect por `ShellPageNavbar` / `ShellNavbarRefresh` internamente
- [x] 2.4 Padronizar erro inicial com `ShellLoadError` / empty tipado
- [x] 2.5 Preservar contratos já cobertos por `monitoring-workspace-*-gate` e allowlist do fidelity
- [x] 2.6 Testes focados monitoring + gates web completos

## 3. Lote 3 — Dashboards analíticos

Escopo: home e hub de monitoring.

Arquivos-alvo:

- `apps/web/app/pages/index.vue`
- `apps/web/app/pages/monitoring/index.vue`
- Avaliar (sem obrigação) extração de `ShellAnalyticsPage` se o delta for menor

- [x] 3.1 Criar change OpenSpec do lote (ex.: `ui-archetypes-batch-analytics`)
- [x] 3.2 Aplicar skill `ui-archetypes` (arquétipo dashboard/`index.vue` + `home/*`)
- [x] 3.3 Migrar chrome para `ShellPagePanel` / `ShellPageNavbar` / refresh e erro padronizados
- [x] 3.4 Decidir explicitamente se extrai `ShellAnalyticsPage` ou só reusa Shell existente
- [x] 3.5 Testes focados + gates web completos

## 4. Lote 4 — Master-detail compartilhado

Escopo: extrair padrão comum dos workspaces inbox-like.

Arquivos-alvo:

- `apps/web/app/components/communication/CommunicationWorkspacePage.vue`
- `apps/web/app/pages/monitoring/mailbox.vue` (+ filhas)
- `apps/web/app/components/work/WorkQueueWorkspace.vue` /
  `WorkQueueChrome.vue`
- Candidato: `ShellMasterDetailWorkspace` (somente se encapsular a mesma
  hierarquia/slots; senão, compor Shell existente)

- [x] 4.1 Criar change OpenSpec do lote (ex.: `ui-archetypes-batch-master-detail`)
- [x] 4.2 Aplicar skill `ui-archetypes` (arquétipo inbox)
- [x] 4.3 Comparar as três superfícies e propor o menor delta (compor vs novo Shell)
- [x] 4.4 Migrar chrome (navbar/toolbar) para Shell; preservar painéis redimensionáveis e slideover mobile
- [x] 4.5 Preservar gates `communication-workspace-ui-gate` e `work-surface-composition`
- [x] 4.6 Testes focados + gates web completos

## 5. Lote 5 — Docs workspace

Escopo: Shell interno em `DocsWorkspace`.

Arquivos-alvo:

- `apps/web/app/components/docs/Workspace.vue` (~930 linhas)
- Rotas: `docs/index`, `docs/catalog`, `docs/[accessKey]`

- [x] 5.1 Criar change OpenSpec do lote (ex.: `ui-archetypes-batch-docs`)
- [x] 5.2 Aplicar skill `ui-archetypes` (arquétipo lista)
- [x] 5.3 Migrar navbar/refresh/erro para Shell sem redesign do posto documental
- [x] 5.4 Manter `DocsWorkspace` na allowlist existente (não ampliar)
- [x] 5.5 Testes focados + gates web completos

## 6. Lote 6 — Quick wins transversais

Escopo: padronizações pontuais sem novo arquétipo.

Arquivos-alvo (indicativos; inventário final no change filho):

- Erro/refresh/back inline restantes (`syncs.vue`, calendar, settings filhas, etc.)
- `apps/web/app/pages/closing.vue` (toolbar → `ShellListFilterToolbar` ou slot)
- `apps/web/app/pages/work/calendar.vue`
- `UPageCard` → `ShellSectionCard` em settings filhas (conta/SERPRO) onde couber
- Unificação contratual dos KPI strips (`ShellKpiStrip` vs `MonitoringKpiStrip`)

- [x] 6.1 Criar change OpenSpec do lote (`ui-archetypes-batch-quick-wins`)
- [x] 6.2 Inventariar ocorrências restantes a partir de `audit.md` (ver `design.md` do filho)
- [x] 6.3 Aplicar skill e migrar com menor delta por arquivo (erro carga, closing toolbar, SectionCard drop-in; KPI strips = dívida)
- [x] 6.4 Testes focados + gates web completos — focados OK (44); gates completos PASS

## 7. Encerramento do guarda-chuva

- [x] 7.1 Confirmar que todos os lotes planejados têm change filho criado ou
      foram explicitamente descartados/reordenados pelo usuário
      (Lotes 1–6 arquivados em `openspec/changes/archive/2026-07-28-ui-archetypes-batch-*`.
      Dívida documentada: KPI strips; UPageCard team/departments/SERPRO densos; calendar UDirect.)
- [x] 7.2 Arquivar esta change (`openspec-archive-change`) após os lotes
      acordados estarem concluídos ou a dívida remanescente documentada
      — filho `ui-archetypes-batch-quick-wins` já arquivado; guarda-chuva arquivado
      com sync consciente de `ui-archetypes-audit` e `ui-archetypes-batch-roadmap`
- [x] 7.3 Não sincronizar specs principais até haver comportamento
      implementado nos lotes (delta-first; sem backfill) — cumprido: specs main dos lotes
      só após implementação; capabilities do guarda-chuva sincronizadas no archive consciente

## Achados secundários (fora dos lotes; só sob pedido)

- Alinhar comentário de commit do template no `template-fidelity-gate.mjs`
  (`0f30c09` → revisão da skill).
- Atualizar comentários antigos de `.local/reference/nuxt-dashboard-template`.
- Remover ou documentar lógica morta do bundle `LIST` no gate.
- Remover `ShellInfiniteTableLoader` (chrome proibido, sem consumidor) —
  exige autorização explícita.
