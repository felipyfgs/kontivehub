## MODIFIED Requirements

### Requirement: Workspace mantém filtro de contato visível e removível

Ao abrir “Ver todas”, a SPA SHALL navegar ao contexto `/communication/contacts/:contactId/conversations`, aplicar `contact_id` na consulta HTTP e exibir um chip `Contato: <nome>` removível. O filtro SHALL combinar com o estado de sessão existente e SHALL NOT afetar seleção ou deep-link além do necessário para recarregar os resultados.

#### Scenario: Abrir todas as conversas
- **WHEN** o usuário aciona “Ver todas” no detalhe de um contato
- **THEN** o workspace abre pelo path do contato e lista apenas conversas autorizadas desse contato

#### Scenario: Remover filtro
- **WHEN** o usuário remove o chip de contato
- **THEN** a SPA navega ao path canônico do Atendimento, preserva os demais filtros de sessão e recarrega a lista

#### Scenario: Nome indisponível
- **WHEN** o resource do contato não pode ser carregado
- **THEN** a SPA não inventa um nome, mantém o filtro funcional por ID e apresenta um rótulo neutro

### Requirement: Workspace pode ancorar uma mensagem de origem
A timeline SHALL aceitar uma âncora por `message_id` pertencente à conversa visível e SHALL retornar uma página que contenha a mensagem quando ela estiver disponível. A SPA SHALL representar conversa e mensagem no path `/communication/conversations/:conversationId/messages/:messageId` até selecionar, carregar, rolar e destacar a origem.

#### Scenario: Mensagem disponível
- **WHEN** o usuário abre um item de conteúdo compartilhado com origem válida
- **THEN** o workspace seleciona a conversa, carrega a página ancorada, rola até a mensagem e a destaca

#### Scenario: Mensagem indisponível
- **WHEN** a mensagem foi expurgada, revogada ou não pertence à conversa
- **THEN** a API não revela seu conteúdo e a SPA informa indisponibilidade, mantém a conversa selecionada e canonicaliza para o path da conversa
