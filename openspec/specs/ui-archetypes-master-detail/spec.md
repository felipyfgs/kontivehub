# ui-archetypes-master-detail Specification

## Purpose

Padronizar a navbar dos painéis mestre de Communication, Work e Caixa Postal
sem alterar a composição mestre–detalhe, a responsividade ou as interações de
seleção e foco próprias de cada domínio.

## Requirements

### Requirement: Navbar canônica no painel mestre

Communication, Work e Caixa Postal SHALL usar `ShellPageNavbar` somente no
chrome mestre equivalente e SHALL NOT duplicar o collapse da sidebar.

#### Scenario: Communication é aberta

- **WHEN** o usuário acessa o workspace autorizado de atendimento
- **THEN** o painel mestre mantém título, contagem, realtime e administração
  na navbar Shell

#### Scenario: Fila de trabalho é aberta

- **WHEN** o usuário acessa Fila, Lista ou Kanban
- **THEN** o mesmo chrome mestre mantém título, contagem, toolbar e controles
  de visão

#### Scenario: Caixa Postal é aberta

- **WHEN** o usuário acessa a carteira de Caixa Postal
- **THEN** a navbar full-width mantém contagem, toggle de detalhe e alertas
  acima do split

### Requirement: Composição mestre–detalhe permanece estável

A migração MUST preservar painéis redimensionáveis, detalhes desktop,
slideovers mobile, views Fila/Lista/Kanban e toda seleção publicada.

#### Scenario: Usuário abre e fecha um detalhe

- **WHEN** um item é selecionado e o detalhe é fechado no desktop ou mobile
- **THEN** a composição adequada ao breakpoint permanece e o item mestre
  continua selecionável

#### Scenario: Usuário alterna a visão de trabalho

- **WHEN** o usuário troca entre Fila, Lista e Kanban
- **THEN** os controles, seleção e detalhe específicos de cada visão permanecem
  funcionais

### Requirement: Teclado e foco permanecem preservados

As três superfícies SHALL manter nomes acessíveis, estados pressed/disabled,
atalhos e restauração de foco existentes após a migração do navbar.

#### Scenario: Usuário navega por teclado

- **WHEN** o usuário move a seleção, fecha um detalhe ou aciona uma ação do
  navbar pelo teclado
- **THEN** o foco retorna ao alvo previsto e nenhum atalho atua dentro de um
  editor incompatível

### Requirement: Nenhum wrapper mestre–detalhe novo é criado

O Lote 4 MUST compor Shells existentes, manter matriz e allowlists e MUST NOT
criar `ShellMasterDetailWorkspace`.

#### Scenario: Gates do lote são executados

- **WHEN** testes focados e os seis gates Web são executados
- **THEN** todos passam sem página nova, mudança de matriz ou allowlist ampliada

### Requirement: Contexto de Detalhes adapta-se ao breakpoint

Detalhes SHALL preservar a rota independente e compor perfil e contexto secundário full-width/full-height em `lg+`, com proporção flexível, divisória até o rodapé e scroll independente, usando `USlideover` abaixo de `lg` e reutilizando conversas, identidades, vínculos, conteúdo compartilhado e privacidade. O contexto da conversa SHALL reutilizar o mesmo módulo de conteúdo compartilhado em rail/slideover. A composição MUST NOT criar wrapper Shell mestre-detalhe novo.

#### Scenario: Painel contextual desktop
- **WHEN** a largura da viewport satisfaz `lg`
- **THEN** perfil e contexto são exibidos lado a lado com scrolls previsíveis e borda semântica

#### Scenario: Slideover contextual
- **WHEN** a largura está abaixo de `lg` e o usuário abre o contexto
- **THEN** o conteúdo aparece em `USlideover`, pode ser fechado por `Esc` e devolve foco ao gatilho

#### Scenario: Conteúdo em contato e conversa
- **WHEN** o operador abre o contexto de um contato ou conversa
- **THEN** encontra teaser e vista completa semanticamente equivalentes para Mídias, Links e Documentos

#### Scenario: Mesma informação nos dois modos
- **WHEN** o breakpoint muda entre desktop e mobile
- **THEN** estados e ações disponíveis permanecem semanticamente equivalentes
### Requirement: O delta visual preserva a composição master–detail
Communication SHALL manter navbar Shell, painel mestre redimensionável no desktop, timeline adjacente, contexto em telas largas e detalhe em `USlideover` no mobile.

#### Scenario: Lista compacta no desktop
- **WHEN** a lista recebe título, contexto, preview, horário e unread
- **THEN** o resize, a seleção, a timeline e o painel de contexto continuam operacionais

#### Scenario: Detalhe mobile
- **WHEN** uma conversa é aberta em viewport mobile
- **THEN** timeline, divisor, composer e ações aparecem no slideover existente e `Esc` restaura o foco

#### Scenario: Filtro e teclado
- **WHEN** “Não lidas” está ativo e o usuário navega com setas
- **THEN** deep-link, URL↔seleção, scroll da linha e fixação da conversa selecionada permanecem coerentes

#### Scenario: Gates do arquétipo
- **WHEN** `pnpm run test:fidelity` e os testes `communication-workspace-ui-gate.test.ts`, `communication-conversation-focus.nuxt.test.ts` e `communication-conversation-selection.test.ts` são executados contra a matriz `tests/fixtures/template-parity-matrix.md`
- **THEN** as asserções comprovam resize desktop, foco/teclado, `USlideover` mobile, URL↔seleção e coexistência da seleção bulk com o detalhe, sem introduzir shell genérico nem alterar allowlists fora do escopo
### Requirement: Seleção múltipla preserva o master–detail de Communication

Communication SHALL acomodar filtros, seleção múltipla e barra contextual dentro do painel mestre existente, preservando `ShellPageNavbar`, resize desktop, timeline adjacente, painel de contexto, deep-link e `USlideover` mobile. A seleção operacional SHALL NOT substituir a conversa aberta nem criar um novo wrapper master–detail.

#### Scenario: Barra bulk aparece no desktop
- **WHEN** uma ou mais conversas são selecionadas no painel mestre
- **THEN** a barra contextual permanece contida na lista sem deslocar, cobrir ou desmontar a timeline adjacente

#### Scenario: Seleção e detalhe coexistem
- **WHEN** o usuário seleciona várias linhas e abre uma delas
- **THEN** a coleção bulk, a URL e o detalhe mantêm estados independentes e coerentes

#### Scenario: Operação em viewport mobile
- **WHEN** o usuário seleciona e opera conversas por touch em viewport mobile
- **THEN** checkboxes e barra permanecem acessíveis, e abrir uma conversa continua usando o slideover canônico

#### Scenario: Filtro remove o item focado
- **WHEN** uma mudança de consulta limpa a seleção ou remove a linha que possuía foco
- **THEN** o foco retorna ao controle de filtro/lista previsto sem abrir outro detalhe implicitamente

#### Scenario: Gates do arquétipo são executados
- **WHEN** os testes de fidelidade e master–detail são executados
- **THEN** passam sem nova página, wrapper genérico, alteração da matriz ou ampliação não autorizada de allowlists
