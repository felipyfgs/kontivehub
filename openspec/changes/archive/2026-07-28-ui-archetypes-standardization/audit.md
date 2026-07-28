# Auditoria de fidelidade UI — ui-archetypes

Auditoria contra a skill **ui-archetypes** e a referência local
`.local/references/dashboard`. Executada em 2026-07-28.

Este artefato é o relatório canônico da mudança guarda-chuva
`ui-archetypes-standardization`. Não implementa código; serve de baseline
para os lotes de padronização.

## Sumário executivo

- Referência validada:
  `node .agents/skills/ui-archetypes/scripts/check-dashboard-reference.mjs`
  → **PASS** (revisão `31970177d818`, 8 páginas, 14 componentes, 8 estruturais).
- Inventário: **74 páginas** em `apps/web/app/pages/` e **29 componentes
  Shell\*** em `apps/web/app/components/shell/`.
- Classificação: **26 conformes (~35%)**, **39 parciais (~53%)**,
  **4 divergentes (~5%)**.
- Casca global (`app.vue`, `layouts/default.vue`), as 5 telas auth, tema
  `green`/`zinc`, ícones `i-lucide-*` e locale pt-BR estão alinhados.
- Gap principal: **chrome duplicado** — ~22 page-equivalents montam
  `UDashboardNavbar`/`UDashboardPanel` à mão onde já existem
  `ShellPagePanel`/`ShellPageNavbar`/`ShellSettingsShell`.
- O gate `test:fidelity` já aceita as cascas de produto; a padronização é
  **deduplicação/fidelidade**, não conformidade com o gate. Nenhuma
  allowlist precisa ser ampliada neste ciclo.

## Identidade da referência

| Item | Valor |
|---|---|
| Caminho | `.local/references/dashboard` |
| Origem | `nuxt-ui-templates/dashboard` |
| Revisão mapeada (skill) | `31970177d818eae501c142f4d6c17489cfad5b5a` |
| Inventário | 8 páginas, 14 componentes, 8 arquivos estruturais |
| Stack observada | Nuxt 4, Vue 3, Nuxt UI 4, Tailwind CSS 4 |
| Script de verificação | `.agents/skills/ui-archetypes/scripts/check-dashboard-reference.mjs` |
| Resultado na auditoria | PASS |

## Inventário Shell\* (`apps/web/app/components/shell/`)

| Componente | Papel |
|---|---|
| `PagePanel.vue` | Casca canônica `UDashboardPanel` com slots `#header`, `#toolbar` (no header) e `#body`. |
| `PageNavbar.vue` | Navbar canônica: `SidebarCollapse` + título + slots leading/right/trailing sobre `UDashboardNavbar`. |
| `SettingsShell.vue` | Casca settings: `PagePanel` + `PageNavbar` + toolbar opcional + `DashboardContent` (arquétipo `settings.vue`). |
| `DataTable.vue` | Lista admin: `UTable` + presets de UI + footer + cards mobile (`MobileCards`) + empty tipado. |
| `ListFilterToolbar.vue` | Toolbar de lista completa: busca + chips `DataTableFilter` + presets salvos + refresh + slots. |
| `FilterToolbarLite.vue` | Toolbar leve: só busca + refresh (sem chips estruturados). |
| `TableFooter.vue` | Rodapé de lista: contagem + seletor per-page + paginação (`customers.vue`). |
| `MobileCards.vue` | Cards responsivos (`< md`) para linhas de tabela admin, com seleção e ações. |
| `ListEmpty.vue` | Empty tipado de lista: empty / filtered / error (+ retry). |
| `LoadError.vue` | Erro de carga de página/lista com botão «Tentar novamente» (`UAlert`). |
| `KpiStrip.vue` | Faixa KPI dashboard: `UPageGrid` + `UPageCard` subtle (arquétipo `HomeStats`). |
| `PanelAccordion.vue` | Acordeão de painéis secundários empilhados (`UAccordion`). |
| `ScrollableTabs.vue` | `UTabs` com scroll horizontal touch (KPIs, submódulos, views). |
| `SectionHeader.vue` | Cabeçalho settings naked horizontal + crumbs/back + slot de ações. |
| `SectionCard.vue` | Card de seção `UPageCard` variant subtle. |
| `SectionHub.vue` | Hub de atalhos settings: título + grid de cards com link. |
| `NavbarBack.vue` | Botão voltar responsivo (label desktop / square mobile). |
| `NavbarRefresh.vue` | Botão refresh ghost padronizado para navbar/toolbar. |
| `RowActions.vue` | Menu ellipsis de ações por linha (`customers.vue`). |
| `BulkActionBar.vue` | Barra «N selecionados» com dropdown de ações em massa. |
| `SortableHeader.vue` | Header de coluna sortável com ícone asc/desc/neutral. |
| `StickyTableFilters.vue` | Wrapper neutro para faixa de filtros no body (sem sticky). Chrome proibido pelo gate. |
| `StatusBadge.vue` | Badge semântico de status operacional/fiscal para células de tabela. |
| `FormModal.vue` | Casca modal de formulário: `UModal` + body + footer Cancel/Submit. |
| `ScrollableModal.vue` | Modal denso com body scroll + footer sticky (detalhe/histórico). |
| `LoadingModalBody.vue` | Skeletons padrão no body de modal durante fetch. |
| `ModalFooter.vue` | Faixa Cancelar + Submit reutilizável em modais. |
| `ConfirmModal.vue` | Confirmação simples com tom neutral/danger. |
| `InfiniteTableLoader.vue` | Sentinel `IntersectionObserver` para cursor/«carregar mais». Chrome proibido; sem consumidor ativo. |

### Cascas de domínio que encapsulam Shell

| Componente | Papel |
|---|---|
| `monitoring/ModuleTable.vue` | Lista fiscal completa: `UDashboardPanel` + navbar + KPIs + toolbar + `ModuleDataTable`. Usado por **10** rotas monitoring. |
| `monitoring/ModuleDataTable.vue` | Wrapper fiscal sobre `ShellDataTable` (+ seleção, visibilidade de colunas). |
| `monitoring/ModuleToolbar.vue` | Adapter monitoring → `ShellListFilterToolbar`. |
| `monitoring/KpiStrip.vue` | KPIs fiscais via `ShellScrollableTabs` (distinto de `ShellKpiStrip`). |
| `docs/Workspace.vue` | Posto documentos: painel + navbar + tabela densa (`UDashboardPanel` direto). |
| `communication/CommunicationWorkspacePage.vue` | Master-detail atendimento (`inbox.vue`). |
| `work/WorkQueueWorkspace.vue` + `WorkQueueChrome.vue` | Fila master-detail tarefas. |
| `clients/ClientCatalogList.vue` | Lista clientes no `#body` de `clients.vue` (Shell completo). |

## Páginas por arquétipo

Legenda:

- **Shell** — usa componentes `Shell*` canônicos.
- **Domínio** — wrapper de produto (já na allowlist do gate).
- **UDirect** — `UDashboardPanel`/`UDashboardNavbar` montados à mão.
- **Herda** — casca do pai.
- **Auth** — layout `auth`.

«Parcial» significa funcionalmente alinhado ao arquétipo, mas com chrome
montado fora dos componentes Shell\* canônicos.

### Dashboard analítico (5)

| Página | Casca | Conformidade |
|---|---|---|
| `pages/index.vue` | UDirect | **Divergente** — navbar/toolbar duplicados; não usa `ShellPagePanel`/`ShellPageNavbar`; refresh manual em vez de `ShellNavbarRefresh`. |
| `pages/monitoring/index.vue` | UDirect + `ShellKpiStrip`, `ShellPanelAccordion` | **Parcial** — KPIs/accordion ok; navbar/erro custom. |
| `pages/work/index.vue` | Shell | **Conforme** |
| `pages/clients/dashboard.vue` | Herda `clients.vue` → `ClientsClientListDashboard` | **Conforme** |

### Lista administrativa (27)

| Página | Casca | Conformidade |
|---|---|---|
| `pages/closing.vue` | Shell + filtros `DataTableFilterRoot` inline | **Parcial** — toolbar parcialmente fora de `ShellListFilterToolbar` |
| `pages/exports.vue`, `syncs.vue`, `health.vue` | Shell completo | **Conforme** (`syncs` usa `UAlert` para erros, não `ShellLoadError`) |
| `pages/docs/imports/index.vue`, `[id].vue` | Shell | **Conforme** |
| `pages/docs/index.vue`, `catalog.vue`, `[accessKey].vue` | Domínio `DocsWorkspace` (UDirect) | **Parcial** — navbar/tabela montados à mão |
| `pages/admin/tenants/index.vue`, `fiscal-modules.vue` | Shell | **Conforme** |
| `pages/work/processes/index.vue`, `templates/index.vue` | Shell | **Conforme** |
| `pages/communication/flows/index.vue`, `quick-responses/index.vue` | Shell | **Conforme** |
| `pages/communication/contacts/index.vue` | Domínio `CommunicationContactsCatalog` (Shell interno) | **Conforme** |
| `pages/clients/index.vue` | Herda → `ClientCatalogList` (Shell) | **Conforme** |
| `pages/monitoring/declarations.vue`, `installments.vue`, `fgts.vue`, `sitfis.vue`, `guides.vue`, `registrations.vue`, `tax-processes.vue`, `dctfweb/index.vue` | Domínio `MonitoringModuleTable` (UDirect navbar) | **Parcial** — padrão consistente entre si, mas não via Shell\* |
| `pages/monitoring/simples/index.vue`, `mei/index.vue` | Domínio `MonitoringSimplesMeiPortfolio` → `MonitoringModuleTable` | **Parcial** (idem) |

### Master-detail (13)

| Página | Casca | Conformidade |
|---|---|---|
| `pages/communication/index.vue`, `conversations/[id].vue` | Domínio `CommunicationWorkspacePage` (UDirect) | **Parcial** — painéis redimensionáveis ok; navbar/filtros duplicados |
| `pages/monitoring/mailbox.vue` + `mailbox/index.vue` + `mailbox/[id].vue` | UDirect navbar + painéis irmãos + `ShellTableFooter` | **Parcial** — estrutura inbox correta; chrome não-Shell |
| `pages/monitoring/clients/[clientId].vue` | Híbrido `ShellPageNavbar` + `UDashboardPanel` interno + `ShellDataTable` | **Parcial** — sidebar vertical + múltiplas tabelas ok; casca mista |
| `pages/clients/[id].vue` | Shell (`ShellPagePanel`, `ShellPageNavbar`, `ShellNavbarBack`) | **Conforme** |
| `pages/clients/[id]/*.vue` (7 filhas) | Herda + `ShellSectionHeader` | **Conforme** |
| `pages/work/tasks/index.vue`, `[id].vue` | Domínio `WorkQueueWorkspace` + `WorkQueueChrome` (UDirect) | **Parcial** — master-detail funcional; navbar duplicada |

### Configurações / formulários (18)

| Página | Casca | Conformidade |
|---|---|---|
| `pages/conta.vue` | Shell `ShellSettingsShell` | **Conforme** |
| `pages/conta/index.vue`, `escritorio.vue`, `equipe.vue`, `departamentos.vue`, `consumo.vue`, `assinatura.vue` | Herda → `settings/*` com `ShellSectionHeader` | **Parcial** — mix `ShellSectionCard` vs `UPageCard` direto |
| `pages/admin/serpro.vue` | UDirect (cópia manual de settings shell) | **Divergente** — deveria ser `ShellSettingsShell` |
| `pages/admin/serpro/*.vue` (7 filhas) | Herda serpro shell; `ShellDataTable`/`ShellFormModal` pontuais | **Parcial** — seções em `UPageCard` naked/subtle |
| `pages/work/processes/[id].vue` | Shell `ShellSettingsShell` + `ShellSectionHeader` | **Conforme** |
| `pages/communication/contacts/[id].vue`, `flows/[id]/index.vue`, `flows/[id]/editor.vue` | Shell PagePanel + Section\* + modais Shell | **Parcial** — detalhe rico, mas não usa `SettingsShell` |
| `pages/admin/tenants/[id].vue`, `new.vue` | UDirect settings-like | **Divergente** — navbar/back inline (`UButton arrow-left`), erros via `UAlert` |

### Auth (5)

| Página | Casca | Conformidade |
|---|---|---|
| `login.vue`, `activate.vue`, `first-access.vue`, `reset-password.vue`, `onboarding.vue` | Auth (`layout: auth`) | **Conforme** |

### Híbrido / outlier (1)

| Página | Casca | Conformidade |
|---|---|---|
| `pages/work/calendar.vue` | UDirect + `ShellScrollableTabs` | **Parcial** — toolbar/navbar manuais; erro via `UAlert` inline |

### Casca de roteamento (1)

| Página | Papel |
|---|---|
| `pages/clients.vue` | Shell lista (`ShellPagePanel`); roteia lista vs detalhe |

### Resumo quantitativo

| Arquétipo | Total | Conformes | Parciais | Divergentes |
|---|---:|---:|---:|---:|
| Dashboard | 5 | 2 | 2 | 1 |
| Lista | 27 | 14 | 13 | 0 |
| Master-detail | 13 | 2 | 11 | 0 |
| Settings | 18 | 3 | 12 | 3 |
| Auth | 5 | 5 | 0 | 0 |
| Híbrido | 1 | 0 | 1 | 0 |
| **Total** | **74** | **26 (~35%)** | **39 (~53%)** | **4 (~5%)** |

As 4 páginas **divergentes**:

1. `pages/index.vue`
2. `pages/admin/serpro.vue`
3. `pages/admin/tenants/[id].vue`
4. `pages/admin/tenants/new.vue`

## Top 10 divergências

| # | Divergência | Escopo | Exemplos |
|---|---|---|---|
| 1 | `UDashboardNavbar` montado à mão em vez de `ShellPageNavbar` | ~22 page-equivalents | `pages/index.vue`, `monitoring/index.vue`, `work/calendar.vue`, `components/monitoring/ModuleTable.vue` (10 rotas), `components/docs/Workspace.vue` (3), `CommunicationWorkspacePage.vue` (2), `WorkQueueChrome.vue` (2), `monitoring/mailbox.vue`, `admin/serpro.vue`, `admin/tenants/[id].vue`, `new.vue` |
| 2 | Settings shell duplicado (`UDashboardPanel` + navbar + toolbar + `DashboardContent`) | 8 rotas | `pages/admin/serpro.vue` vs canônico em `pages/conta.vue` (`ShellSettingsShell`) |
| 3 | Erro de carga ad hoc (`UAlert`/`toast`) vs `ShellLoadError`/`ShellListEmpty` | 15+ telas | `ModuleTable.vue`, `pages/index.vue`, `monitoring/index.vue`, `work/calendar.vue`, `admin/tenants/[id].vue`, `syncs.vue`, `admin/serpro/configuration.vue` |
| 4 | Lista fiscal encapsulada em domínio, não em Shell\*, embora reproduza `customers` | 10 rotas | carteiras via `MonitoringModuleTable` |
| 5 | Master-detail com chrome custom (painéis ok, navbar/toolbar não-Shell) | 5–7 rotas | `CommunicationWorkspacePage`, `monitoring/mailbox.vue`, `WorkQueueWorkspace` |
| 6 | Botão voltar inline vs `ShellNavbarBack` | 2 rotas | `admin/tenants/[id].vue`, `new.vue` |
| 7 | Refresh inline vs `ShellNavbarRefresh` | 8+ telas | `index.vue`, `monitoring/index.vue`, `docs/Workspace.vue`, `ModuleTable` |
| 8 | Toolbar de filtros parcialmente duplicada | 1 rota | `closing.vue` monta `DataTableFilterRoot`/`SavedFiltersMenu` fora de `ShellListFilterToolbar` |
| 9 | Settings filhas: `UPageCard` direto vs `ShellSectionCard` | 6+ componentes | `settings/TenantTeamPage.vue`, `TenantDepartmentsPage.vue`, `TenantUsagePage.vue`, páginas SERPRO filhas |
| 10 | Dois KPI strips paralelos (`ShellKpiStrip` vs `MonitoringKpiStrip`) | Fiscal (10) + operacional (3) | `ModuleTable.vue` vs `work/index.vue` / `monitoring/index.vue` |

### Observação positiva

Não há uso de cores cruas (`text-gray-*`, `bg-white`, `emerald-*`) nem ícones
não-Lucide nas páginas inventariadas. Tokens semânticos e `i-lucide-*`
estão consistentes.

## Casca global

### `apps/web/app/app.vue`

| Aspecto | Referência | KontiveHub | Fidelidade |
|---|---|---|---|
| Estrutura | `UApp` → `NuxtLoadingIndicator` → `NuxtLayout` → `NuxtPage` | Idêntica + `:locale="pt_br"` | **Conforme** |
| Metadados | EN, viewport básico | pt-BR, PWA, theme-color dinâmico | **Conforme** (extensão produto) |

### `apps/web/app/layouts/default.vue`

| Aspecto | Referência | KontiveHub | Fidelidade |
|---|---|---|---|
| `UDashboardGroup unit="rem"` | Sim | Sim | **Conforme** |
| Sidebar colapsável/redimensionável | Sim | Sim + `TenantIdentity` | **Conforme** |
| Busca global (`UDashboardSearch`) | Sim | Sim + destinos reais | **Conforme** |
| Navegação vertical dupla | Sim | Sim (primária + secundária) | **Conforme** |
| Footer sidebar | TeamsMenu + UserMenu | `AssistantTriggerButton` + `UserMenu` | **Conforme** (adaptação produto) |
| Slot direto no group | Sim | Sim (comentário explícito para master-detail) | **Conforme** |
| Overlays globais | Notifications | `NotificationsSlideover` + `AssistantSlideover` | **Conforme** |

**Conclusão:** as divergências concentram-se nas **páginas filhas**, não na
casca autenticada.

## Tema, locale e ícones

| Item | Estado | Evidência |
|---|---|---|
| Primary / neutral | `green` / `zinc` | `apps/web/app/app.config.ts` |
| Escala verde canônica + Public Sans | Alinhada | `apps/web/app/assets/css/main.css`; `tests/unit/dashboard-theme-selector.test.ts` |
| Locale pt-BR | Alinhado | `app.vue` (`pt_br`), `htmlAttrs.lang`, PWA `lang` |
| Ícones Lucide | Alinhados | `@iconify-json/lucide`; convenção `i-lucide-*` |
| PWA theme | `theme_color: '#00C16A'`, `background_color: '#09090b'` | `nuxt.config.ts` |

## Cobertura dos gates

### `pnpm run test:fidelity`

- Script: `apps/web/scripts/template-fidelity-gate.mjs`
- Inventário: `apps/web/tests/fixtures/template-parity-matrix.md`
- Foco: estrutura (matriz ↔ páginas, presença de `UDashboardPanel` ou casca
  embutida, chrome  proibido, `FiscalDemoBanner`).
- Cascas embutidas já allowlisted: `ShellPagePanel`, `ShellSettingsShell`,
  `MonitoringModuleTable`, `<ModuleTable`, `MonitoringSimplesMeiPortfolio`,
  `DocsWorkspace`, `WorkQueueWorkspace`, `NotesWorkspace`,
  `CommunicationWorkspacePage`.
- Chrome proibido: `ShellListShell`, `ShellStickyTableFilters`,
  `ShellInfiniteTableLoader`.
- **Não verifica:** cores Tailwind cruas, espaçamento fino, a11y, hash da
  referência visual.
- Bundle `LIST` existe no código do gate, mas a matriz atual só usa
  `SHELL` / `CHILD` / `AUTH` (lógica morta).

### `pnpm run test:artifacts`

- Script: `apps/web/tests/security/scan-artifacts.mjs`
- Foco: segredos, XML fiscal, tokens e marcas obsoletas em artefatos gerados.
- Não é gate de layout.

### Testes Vitest relacionados a UI

Cobertura parcial por superfície: `shell-list-migration-gate.test.ts`,
`shell-modals-migration-gate.test.ts`, `painel-responsivo-mobile-gate.test.ts`,
`monitoring-workspace-ui-gate.test.ts`, `communication-workspace-ui-gate.test.ts`,
`work-surface-composition.test.ts`, `dashboard-theme-selector.test.ts`, etc.

### Script da skill (fora dos gates npm)

`.agents/skills/ui-archetypes/scripts/check-dashboard-reference.mjs` valida
hashes/conteúdo da referência local. **Não** entra em `pnpm test` /
`test:fidelity`. Na auditoria: PASS.

## Oportunidades de reforço Shell\*

| Oportunidade | Evidência | Proposta |
|---|---|---|
| Migrar chrome UDirect → `ShellPagePanel` + `ShellPageNavbar` | ~22 page-equivalents | Refatoração mecânica |
| `ShellSettingsShell` para consoles aninhados | 8 (`admin/serpro*`) | Eliminar duplicação em `admin/serpro.vue` |
| `ShellMasterDetailWorkspace` | 5–7 rotas | Extrair de communication, mailbox, work/tasks |
| Refator interna de `ModuleTable` | 10 rotas monitoring | Trocar navbar UDirect por Shell |
| Shell interno em `DocsWorkspace` | 3 rotas docs | Navbar + badge + ações |
| `ShellLoadError` como padrão único | 15+ telas | Padronizar erro inicial |
| Avaliar `ShellAnalyticsPage` | 2 dashboards | `index.vue`, `monitoring/index.vue` |
| Unificar KPI strips | Fiscal (10) + operacional (3) | Contrato compartilhado |
| `WorkQueueChrome` → `ShellPageNavbar` | 2 rotas tasks | Navbar + badge + toggles |
| Toolbar de `closing.vue` | 1 rota | Slot documentado em `ShellListFilterToolbar` |

## Achados secundários (sem ação neste change)

1. O gate `template-fidelity-gate.mjs` referencia o commit `0f30c09` do
   template; a skill mapeia `31970177d818`. Drift de comentário a alinhar
   em lote futuro.
2. Comentários s apontam para `.local/reference/nuxt-dashboard-template`;
   o caminho canônico da skill é `.local/references/dashboard` (presente e
   validado nesta auditoria).
3. Lógica do bundle `LIST` no gate está morta (matriz só usa
   `SHELL`/`CHILD`/`AUTH`).
4. `ShellInfiniteTableLoader` e `ShellStickyTableFilters` constam como chrome
   proibido; o primeiro não tem consumidor ativo. Remoção futura exigiria
   autorização explícita.
5. Não há change OpenSpec ativo prévio de UI/padronização; a dívida estava
   apenas operacional (skill + testes `*-gate.test.ts`).

## Roadmap de lotes (resumo)

Detalhamento operacional em `tasks.md`. Ordem proposta:

1. Admin divergentes (`serpro` → `ShellSettingsShell`; tenants new/id → Shell navbar/back/erro).
2. Listas fiscais monitoring (`ModuleTable` e adapters → Shell interno).
3. Dashboards analíticos (`index.vue`, `monitoring/index.vue`).
4. Master-detail compartilhado (communication, mailbox, work/tasks).
5. Docs (`DocsWorkspace` → Shell interno).
6. Quick wins transversais (erro/refresh/back, closing, calendar, SectionCard, KPI strips).

Cada lote exige change OpenSpec próprio, menor delta possível e gates web
completos antes do handoff.
