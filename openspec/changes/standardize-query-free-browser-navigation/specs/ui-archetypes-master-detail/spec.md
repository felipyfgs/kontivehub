## MODIFIED Requirements

### Requirement: O delta visual preserva a composição master–detail
Communication SHALL manter navbar Shell, painel mestre redimensionável no desktop, timeline adjacente, contexto em telas largas e detalhe em `USlideover` no mobile. Conversa selecionada SHALL permanecer no path e filtros SHALL permanecer no estado de sessão sem query.

#### Scenario: Lista compacta no desktop
- **WHEN** a lista recebe título, contexto, preview, horário e unread
- **THEN** o resize, a seleção, a timeline e o painel de contexto continuam operacionais

#### Scenario: Detalhe mobile
- **WHEN** uma conversa é aberta em viewport mobile
- **THEN** timeline, divisor, composer e ações aparecem no slideover existente e `Esc` restaura o foco

#### Scenario: Filtro e teclado
- **WHEN** “Não lidas” está ativo e o usuário navega com setas
- **THEN** path↔seleção, filtros de sessão, scroll da linha e fixação da conversa selecionada permanecem coerentes sem query

#### Scenario: Gates do arquétipo
- **WHEN** `pnpm run test:fidelity` e os testes `communication-workspace-ui-gate.test.ts`, `communication-conversation-focus.nuxt.test.ts` e `communication-conversation-selection.test.ts` são executados contra a matriz `tests/fixtures/template-parity-matrix.md`
- **THEN** as asserções comprovam resize desktop, foco/teclado, `USlideover` mobile, path↔seleção e coexistência da seleção bulk com o detalhe, sem introduzir shell genérico nem alterar allowlists fora do escopo

### Requirement: Seleção múltipla preserva o master–detail de Communication

Communication SHALL acomodar filtros, seleção múltipla e barra contextual dentro do painel mestre existente, preservando `ShellPageNavbar`, resize desktop, timeline adjacente, painel de contexto, deep-link por path e `USlideover` mobile. A seleção operacional SHALL NOT substituir a conversa aberta nem criar um novo wrapper master–detail.

#### Scenario: Barra bulk aparece no desktop
- **WHEN** uma ou mais conversas são selecionadas no painel mestre
- **THEN** a barra contextual permanece contida na lista sem deslocar, cobrir ou desmontar a timeline adjacente

#### Scenario: Seleção e detalhe coexistem
- **WHEN** o usuário seleciona várias linhas e abre uma delas
- **THEN** a coleção bulk, o path canônico e o detalhe mantêm estados independentes e coerentes

#### Scenario: Operação em viewport mobile
- **WHEN** o usuário seleciona e opera conversas por touch em viewport mobile
- **THEN** checkboxes e barra permanecem acessíveis, e abrir uma conversa continua usando o slideover canônico

#### Scenario: Filtro remove o item focado
- **WHEN** uma mudança de consulta limpa a seleção ou remove a linha que possuía foco
- **THEN** o foco retorna ao controle de filtro/lista previsto sem abrir outro detalhe implicitamente

#### Scenario: Gates do arquétipo são executados
- **WHEN** os testes de fidelidade e master–detail são executados
- **THEN** passam sem nova página, wrapper genérico, alteração indevida da matriz ou ampliação não autorizada de allowlists
