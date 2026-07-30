## MODIFIED Requirements

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
