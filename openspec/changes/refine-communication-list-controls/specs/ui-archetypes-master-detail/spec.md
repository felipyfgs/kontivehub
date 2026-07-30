## ADDED Requirements

### Requirement: Controles contextuais preservam o painel mestre responsivo

Communication SHALL manter busca, gatilhos de filtro e a faixa de seleção na largura integral do painel mestre redimensionável, sem alterar a timeline adjacente, o contexto largo ou o `USlideover` mobile. Dropdowns e o popover avançado SHALL ser portalizados e limitados ao viewport; a faixa bulk SHALL permanecer no fluxo e restrita ao painel da lista.

#### Scenario: Painel desktop é redimensionado
- **WHEN** o operador redimensiona a lista entre 20% e 32% do grupo, com tamanho inicial de 24%
- **THEN** filtros, resumo e faixa contextual permanecem utilizáveis sem overflow horizontal, empurrar ou sobrepor timeline e contexto

#### Scenario: Workspace é usado no mobile
- **WHEN** o viewport está abaixo de `lg`, inclusive na largura suportada de 320 px
- **THEN** a lista mantém filtros e seleção acessíveis, as regras do popover avançado reorganizam-se sem overflow e abrir conversa continua usando o slideover canônico

#### Scenario: Overlay encontra a borda do viewport
- **WHEN** um dropdown ou popover ancorado por `start` ou `end` não possui espaço integral na direção preferida
- **THEN** o posicionador desloca o overlay apenas até o collision padding, preserva seu conteúdo acessível e não cria overflow horizontal no master-detail

#### Scenario: Seleção é limpa por mudança de consulta
- **WHEN** busca, visão ou filtro altera a coleção e limpa a seleção operacional
- **THEN** a barra contextual desaparece sem desmontar o painel mestre e o foco permanece em alvo previsível da lista ou dos filtros
