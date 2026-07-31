## ADDED Requirements

### Requirement: Controles contextuais preservam o painel mestre responsivo

Communication SHALL manter navbar, busca e uma única faixa alternável de tabs/controles ou seleção na largura integral do painel mestre redimensionável, sem alterar a timeline adjacente, o contexto largo ou o `USlideover` mobile. Dropdowns, submenus e os dois popovers finais SHALL ser portalizados e limitados ao viewport; a faixa bulk SHALL permanecer no fluxo e restrita ao painel da lista.

#### Scenario: Painel desktop é redimensionado
- **WHEN** o operador redimensiona a lista entre 20% e 32% do grupo, com tamanho inicial de 24%
- **THEN** busca e a faixa alternável de três tabs fixas/dois ícones ou seleção permanecem utilizáveis sem overflow horizontal, empurrar ou desmontar timeline e contexto

#### Scenario: Workspace é usado no mobile
- **WHEN** o viewport está abaixo de `lg`, inclusive na largura suportada de 320 px
- **THEN** a lista mantém busca, presets, filtros e seleção acessíveis, menus/submenus colidem dentro da tela, regras avançadas reorganizam-se sem overflow e abrir conversa continua usando o slideover canônico

#### Scenario: Hierarquia do chat é percorrida por teclado
- **WHEN** o operador navega por navbar, busca, tabs, dropdown, submenus, linhas e timeline
- **THEN** a ordem acompanha a hierarquia visual, setas operam tabs/submenus, Escape fecha somente o overlay atual e o foco retorna ao gatilho correspondente

#### Scenario: Overlay encontra a borda do viewport
- **WHEN** um dropdown ou popover ancorado por `start` ou `end` não possui espaço integral na direção preferida
- **THEN** o posicionador desloca o overlay apenas até o collision padding, preserva seu conteúdo acessível e não cria overflow horizontal no master-detail

#### Scenario: Seleção é limpa por mudança de consulta
- **WHEN** busca, visão ou filtro altera a coleção e limpa a seleção operacional
- **THEN** a barra contextual desaparece sem desmontar o painel mestre e o foco permanece em alvo previsível da lista ou dos filtros
