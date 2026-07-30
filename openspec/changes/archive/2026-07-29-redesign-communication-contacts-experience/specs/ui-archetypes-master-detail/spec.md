## ADDED Requirements

### Requirement: Contexto do detalhe de contato adapta-se ao breakpoint

O detalhe de contato SHALL preservar a rota independente e compor o contexto secundário como painel lateral em `lg+` e `USlideover` abaixo de `lg`, reutilizando o mesmo conteúdo e os padrões de foco do arquétipo master-detail. A composição MUST NOT criar um wrapper Shell mestre-detalhe novo.

#### Scenario: Painel contextual desktop

- **WHEN** a largura da viewport satisfaz `lg`
- **THEN** perfil e contexto são exibidos lado a lado com scrolls previsíveis e borda semântica

#### Scenario: Slideover contextual

- **WHEN** a largura está abaixo de `lg` e o usuário abre o contexto
- **THEN** o conteúdo aparece em `USlideover`, pode ser fechado por `Esc` e devolve foco ao gatilho

#### Scenario: Mesma informação nos dois modos

- **WHEN** o breakpoint muda entre desktop e mobile
- **THEN** as abas, estados e ações disponíveis permanecem semanticamente equivalentes
