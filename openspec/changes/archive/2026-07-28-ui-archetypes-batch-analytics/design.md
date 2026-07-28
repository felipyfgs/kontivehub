## Context

`pages/index.vue` e `pages/monitoring/index.vue` seguem o arquétipo analítico
do dashboard, mas ainda montam `UDashboardPanel`, `UDashboardNavbar`, collapse
e refresh diretamente. As duas superfícies compartilham apenas o chrome: o
início agrega operações do escritório e o monitoramento apresenta read models
fiscais locais, com CTAs, componentes e estratégias de erro diferentes.

`ShellPagePanel`, `ShellPageNavbar` e `ShellNavbarRefresh` já encapsulam a
hierarquia necessária. O body de monitoramento ainda exige
`overflow-x-hidden`, enquanto o início possui uma toolbar própria e mantém
última carga válida por `Promise.allSettled`.

## Goals / Non-Goals

**Goals:**

- Reusar os três Shells existentes nas duas páginas analíticas.
- Preservar ações, toolbar, CTAs, componentes, dados válidos e erros parciais.
- Manter o menor delta possível e provar a ausência de chrome duplicado.
- Conservar responsividade, acessibilidade e todos os identificadores atuais.

**Non-Goals:**

- Não criar `ShellAnalyticsPage` nem ampliar a API pública dos Shells.
- Não unificar as duas páginas ou seus KPI strips.
- Não editar `components/home/*`, cards de insights, composables ou clientes
  HTTP.
- Não alterar rotas, matriz, allowlists, API, flags ou integrações.

## Decisions

### Compor Shells existentes por página

Cada página usará `ShellPagePanel` e `ShellPageNavbar`, removendo somente o
collapse manual. Um wrapper analítico novo não é necessário porque as páginas
não compartilham body, toolbar, carregamento ou tratamento de falhas.

Alternativa: criar `ShellAnalyticsPage`. Rejeitada por esconder diferenças de
domínio e introduzir uma abstração pública sem segundo contrato estável.

### Preservar a toolbar do início como slot próprio

O início manterá `UDashboardToolbar` e seus slots, movendo-a de dentro do
`#header` para o `#toolbar` de `ShellPagePanel`. Somente o botão de refresh será
substituído por `ShellNavbarRefresh`, preservando `-ms-1`, loading, label e
evento `load`.

Alternativa: mover refresh para a navbar. Rejeitada porque alteraria a
hierarquia e a distribuição responsiva já publicada pelo cockpit.

### Mapear o overflow do monitoramento pela API do Shell

O `ui.body` direto será substituído por
`body-class="overflow-x-hidden"`, propriedade já suportada por
`ShellPagePanel`. O refresh permanece no slot direito da navbar com sua label
específica.

Alternativa: remover a classe. Rejeitada porque pode reintroduzir overflow nos
grids e cards analíticos.

### Não generalizar estados de erro distintos

Os alerts atuais permanecem: falha inicial sem estimativa, refresh com última
leitura confirmada e erro parcial por seção. O início conserva
`lastGoodSummary`, `lastValidAt`, `Promise.allSettled` e os retries internos.

Alternativa: trocar tudo por `ShellLoadError`. Rejeitada porque perderia a
semântica de dados parciais e da última carga válida.

## Risks / Trade-offs

- [Toolbar deixa de renderizar] → usar explicitamente o slot `#toolbar` do
  `ShellPagePanel` e cobri-lo no gate focado.
- [Collapse duplicado] → remover apenas os dois collapses diretos e verificar
  ausência de `UDashboardSidebarCollapse` nas páginas.
- [Overflow móvel no monitoramento] → preservar `overflow-x-hidden` via
  `body-class` e inspecionar desktop/mobile.
- [Ações ou erros são achatados] → não mover o body nem alterar componentes,
  testids, CTAs e branches de erro.

## Migration Plan

1. Adicionar o gate focado das duas páginas.
2. Migrar somente painel, navbar, toolbar/refresh e fechamento das tags.
3. Rodar testes focados, gates Web e inspeção desktop/mobile.
4. Sincronizar a capability, arquivar o filho e marcar o Lote 3 no guarda-chuva.

Rollback restaura somente o chrome direto das duas páginas; não há estado,
migration, flag, API ou dado a reverter.

## Open Questions

Nenhuma bloqueante.
