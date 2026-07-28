## Context

`MonitoringModuleTable.vue` é o wrapper compartilhado das carteiras de
declarations, installments, FGTS, guides, registrations, tax-processes,
DCTFWeb, SITFIS, Simples e MEI. Ele já delega filtros a `ModuleToolbar.vue`,
tabela/paginação a `ModuleDataTable.vue` e KPIs a `MonitoringKpiStrip`, mas
ainda monta `UDashboardPanel`, `UDashboardNavbar` e sidebar collapse
diretamente.

O arquétipo `customers.vue` e os Shells existentes definem a composição
canônica de painel, navbar e refresh. A mudança deve ser estritamente interna
ao wrapper: consumidores e adapters têm variações de slots que não podem ser
achatadas.

## Goals / Non-Goals

**Goals:**

- Usar `ShellPagePanel`, `ShellPageNavbar` e `ShellNavbarRefresh` no wrapper.
- Padronizar apenas o erro inicial sem linhas com `ShellLoadError`.
- Preservar integralmente os contratos compartilhados pelas dez superfícies.
- Manter a última carga válida visível quando um refresh falha.

**Non-Goals:**

- Não editar `ModuleToolbar`, `ModuleDataTable`, `KpiStrip` ou consumidores.
- Não unificar o KPI strip fiscal com qualquer outra faixa analítica.
- Não criar Shell público, página, rota, prop, emit ou slot.
- Não alterar API, filtros, paginação, seleção, matriz ou allowlists.

## Decisions

### Trocar somente o chrome do wrapper

`UDashboardPanel` será substituído por `ShellPagePanel` com o mesmo `panelId`.
`UDashboardNavbar` será substituído por `ShellPageNavbar`, removendo apenas o
collapse manual que o Shell já fornece. A árvore completa do `#body` permanece
na mesma ordem e com as mesmas classes.

Alternativa: migrar cada rota consumidora. Rejeitada porque duplicaria o delta
dez vezes e criaria risco de divergência entre adapters.

### Manter consulta pendente e acrescentar refresh no mesmo slot

O slot `#right` da navbar será sempre declarado. O
`MonitoringPendingSearchButton` conserva a condição atual e todas as props; ao
lado dele, `ShellNavbarRefresh` reflete `loading || refreshing`, emite o mesmo
evento `refresh` e recebe um testid próprio. O refresh já existente no toolbar
continua disponível e mantém todos os identificadores atuais.

Alternativa: mover o botão do toolbar. Rejeitada porque alteraria o contrato de
filtros e os hábitos das superfícies consumidoras.

### Diferenciar erro inicial de refresh com dados preservados

Quando existe `error`, não há linhas e a carga inicial terminou,
`ShellLoadError` ocupa o estado inicial com retry. Quando existem linhas ou a
carga está em andamento, o alerta contextual atual permanece, preservando a
última carga válida, `lastGoodAt` e o retry sem esconder a tabela.

Alternativa: usar somente o empty state da tabela. Rejeitada porque o roadmap
exige chrome de erro inicial consistente e o wrapper já distingue dados
preservados de ausência total.

### Provar contratos por source gate e regressões existentes

Um teste focado verificará Shells, condição do pending search, refresh, erros,
slots e testids. Os testes atuais continuam responsáveis por filtros, tabela,
paginação, sorting e adapters específicos.

## Risks / Trade-offs

- [Refresh duplicado na navbar e toolbar] → são pontos de acesso intencionais
  para contextos diferentes e compartilham o mesmo evento idempotente.
- [Erro stale esconder dados] → `ShellLoadError` só entra sem linhas e fora de
  loading; o alerta contextual permanece nos demais casos.
- [Quebra de slot em uma das dez superfícies] → nenhuma assinatura ou árvore
  de slots será movida, e o gate focado enumera todos os contratos.
- [Regressão visual responsiva] → Shells já cobertos pelo lote admin e gates
  completos, sem mudança de página/matriz.

## Migration Plan

1. Adicionar o gate focado do wrapper.
2. Migrar o chrome e o branch de erro em `ModuleTable.vue`.
3. Rodar testes focados e os seis gates Web.
4. Sincronizar a capability e arquivar o filho; marcar o Lote 2 no guarda-chuva.

Rollback é a restauração do template do único componente; não há estado,
migration, flag ou contrato externo a reverter.

## Open Questions

Nenhuma bloqueante.
