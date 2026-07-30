## ADDED Requirements

### Requirement: Workspace pode ancorar uma mensagem de origem

A timeline SHALL aceitar uma âncora por `message_id` pertencente à conversa visível e SHALL retornar uma página que contenha a mensagem quando ela estiver disponível. A SPA SHALL preservar `conversation_id` e `message_id` no deep-link até selecionar, carregar, rolar e destacar a origem.

#### Scenario: Mensagem disponível
- **WHEN** o usuário abre um item de conteúdo compartilhado com origem válida
- **THEN** o workspace seleciona a conversa, carrega a página ancorada, rola até a mensagem e a destaca

#### Scenario: Mensagem indisponível
- **WHEN** a mensagem foi expurgada, revogada ou não pertence à conversa
- **THEN** a API não revela seu conteúdo e a SPA informa indisponibilidade sem perder a conversa selecionada
