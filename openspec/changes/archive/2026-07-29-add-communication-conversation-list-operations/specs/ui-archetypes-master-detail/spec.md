## ADDED Requirements

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
