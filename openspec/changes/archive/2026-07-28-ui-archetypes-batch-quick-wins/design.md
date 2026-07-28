## Context

Lotes 1–5 migraram chrome estrutural. O inventário residual (audit top-10
#3, #8, #9, #10 e seções parciais) aponta para:

| Alvo (audit) | Estado pós lotes 1–5 | Decisão Lote 6 |
|---|---|---|
| Erro de carga ad hoc (`UAlert`) | Admin/tenants e monitoring já em Shell; restam `syncs`, `closing`, calendar, SERPRO filhas | Migrar só falha inicial sem dados |
| Toolbar `closing.vue` | Ainda monta `DataTableFilterRoot`/presets à mão | → `ShellListFilterToolbar` |
| Settings `UPageCard` vs `ShellSectionCard` | Mix; listas team/departments com ui custom documentada | Drop-in apenas; exceções ficam |
| KPI strips paralelos | `ShellKpiStrip` (grid) vs `MonitoringKpiStrip` (tabs fiscais) | **Dívida documentada** — não merge |

Referência local validada: `check-dashboard-reference.mjs` → PASS
(`31970177d818`).

### Inventário executável (tocar vs skip)

**Migrar (menor delta):**

| Arquivo | Mudança |
|---|---|
| `pages/syncs.vue` | `loadError` → `ShellLoadError` (warning se há itens); `cteError` → `ShellLoadError` warning; health cards → `ShellSectionCard` |
| `pages/closing.vue` | Toolbar → `ShellListFilterToolbar`; `loadError` → `ShellLoadError` |
| `pages/work/calendar.vue` | Erro de intervalo **sem** stale → `ShellLoadError`; stale e dayError ficam `UAlert` |
| `pages/admin/serpro/configuration.vue` | `loadError` → `ShellLoadError` |
| `pages/admin/serpro/contracts.vue` | `loadError` → `ShellLoadError` |
| `components/settings/TenantCredentialSection.vue` | `UPageCard` subtle → `ShellSectionCard` |
| `components/settings/TenantSubscriptionPage.vue` | card de plano → `ShellSectionCard` |
| `components/settings/TenantUsagePage.vue` | cards de seção (não KPI grid) → `ShellSectionCard` |
| `pages/clients/[id]/contato.vue` | subtle → `ShellSectionCard` |
| `pages/clients/[id]/departamento.vue` | subtle → `ShellSectionCard` |
| `pages/clients/[id]/observacoes.vue` | subtle → `ShellSectionCard` |

**Skip (motivo):**

| Arquivo / padrão | Motivo |
|---|---|
| `TenantTeamPage` / `TenantDepartmentsPage` | `UPageCard` com `ui` header p-0 + toolbar no header — exceção documentada vs SectionCard |
| SERPRO naked `orientation="horizontal"` | Papel de `ShellSectionHeader`, não SectionCard; redesenho fora de escopo |
| SERPRO cards densos (index, dte-canary, rollout, usage multi-card) | Não drop-in; risco visual sem ganho de contrato |
| `DteCanaryTenantCard`, auth `UPageCard` | Domínio/auth, não settings body |
| `MonitoringKpiStrip` ↔ `ShellKpiStrip` | Contratos distintos (tabs fiscais filtráveis vs HomeStats grid); merge quebra monitoring |
| `DocsWorkspace`, ModuleTable | Lotes 5 e 2 |
| Calendar chrome UDirect (navbar/panel) | Fora de “erro/refresh/back” pontual; redesign de casca = lote próprio |
| Alertas de negócio (capacidade, blocked cursor, form errors) | Não são erro de carga inicial |

## Goals / Non-Goals

**Goals:**

- Padronizar erro de carga inicial com `ShellLoadError` + retry.
- Fechar o gap da toolbar de `closing.vue`.
- Drop-in `ShellSectionCard` onde `UPageCard variant="subtle"` é equivalente.
- Registrar dívida de KPI strips sem alterar monitoring.

**Non-Goals:**

- Novo Shell, allowlist, redesign, unificação de KPI, migração de calendar
  para `ShellPagePanel`, troca de naked SERPRO por SectionHeader.

## Decisions

1. **`ShellLoadError` só para falha inicial (ou refresh total) sem conteúdo
   útil, e para erros de canal com retry explícito.** Em `syncs`, se a lista
   já tem itens, usa `color="warning"` (padrão `exports.vue`). Stale do
   calendário e dayError permanecem `UAlert` local.
2. **`closing` → `ShellListFilterToolbar` com `show-search=false`, surface
   `closing.list`, competência no `#actions`.** Presets migram para o
   composable interno do Shell; página só trata `@apply-preset` e estado de
   filtros. Refresh da toolbar complementa o da navbar (como outras listas).
3. **`ShellSectionCard` drop-in** apenas em subtle sem override de `ui` nem
   orientation naked. Team/departments e naked SERPRO ficam documentados.
4. **KPI strips: dívida.** `MonitoringKpiStrip` é adapter de contadores
   fiscais via `ShellScrollableTabs` com filtro de situação; `ShellKpiStrip` é
   HomeStats. Unificar exigiria contrato novo e regressão nos 10 módulos —
   adiar.

## Risks / Trade-offs

- [Toolbar closing muda layout (refresh extra, testids de chips)] → wrapper
  `closing-filter-toolbar`; prefix `closing`; testes de composição leem
  presença de Shell, não layout pixel.
- [ShellLoadError muda ícone/margem vs UAlert ad hoc] → padrão já em exports e
  settings; aceito.
- [Double useSavedListPresets se não remover o da página] → remover bloco
  externo ao migrar toolbar.
- [Rollback] → reverter arquivos tocados; sem migration/flag.

## Open Questions

Nenhuma bloqueante. KPI strips e calendar shell completo ficam para o
orquestrador se priorizar lotes futuros.
