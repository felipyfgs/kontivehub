## ADDED Requirements

### Requirement: Conversas podem ser filtradas por contato

`GET /api/v1/communication/conversations` SHALL aceitar `contact_id` como inteiro positivo e combiná-lo com todos os filtros, ordenação e paginação existentes. A resposta SHALL conter somente conversas canônicas em inboxes visíveis ao ator e associadas ao contato canônico ou a contatos doadores consolidados nele.

#### Scenario: Filtro válido

- **WHEN** o usuário autorizado informa o ID de um contato do tenant
- **THEN** a resposta paginada contém somente conversas canônicas desse contato que também satisfazem os demais filtros e inboxes visíveis

#### Scenario: Contato doador

- **WHEN** `contact_id` identifica um contato fundido em outro ou o contato canônico possui doadores com histórico
- **THEN** a API resolve o contato canônico e inclui seu histórico consolidado sem duplicar conversas doadoras

#### Scenario: Formato inválido

- **WHEN** `contact_id` não é um inteiro positivo
- **THEN** a API responde `422` no envelope de validação vigente

#### Scenario: Contato ausente ou estrangeiro

- **WHEN** `contact_id` não existe no tenant corrente ou pertence a outro tenant
- **THEN** a API retorna uma página vazia sem revelar se o contato existe

#### Scenario: Inbox não visível

- **WHEN** o contato possui conversa em inbox que o ator não pode visualizar
- **THEN** essa conversa não aparece nos resultados

### Requirement: Detalhe apresenta histórico recente navegável

A aba “Conversas” do detalhe SHALL carregar até dez conversas recentes por `contact_id`, representar loading, erro com retry e vazio real, e permitir abrir cada conversa pela rota canônica.

#### Scenario: Histórico disponível

- **WHEN** o contato possui conversas visíveis
- **THEN** a aba exibe até dez itens ordenados por atividade recente com status, contexto, preview e horário, e cada item abre sua conversa

#### Scenario: Histórico vazio

- **WHEN** nenhuma conversa visível corresponde ao contato
- **THEN** a aba informa que ainda não há conversas sem criar exemplos sintéticos

### Requirement: Workspace mantém filtro de contato visível e removível

Ao abrir “Ver todas”, a SPA SHALL navegar ao workspace com `contact_id` preservado na URL, aplicar o filtro na API e exibir um chip `Contato: <nome>` removível. O filtro SHALL combinar com os filtros existentes e SHALL NOT afetar seleção ou deep-link além do necessário para recarregar os resultados.

#### Scenario: Abrir todas as conversas

- **WHEN** o usuário aciona “Ver todas” no detalhe de um contato
- **THEN** o workspace abre com `contact_id` na query e lista apenas conversas autorizadas desse contato

#### Scenario: Remover filtro

- **WHEN** o usuário remove o chip de contato
- **THEN** apenas `contact_id` é removido da URL, os demais filtros são preservados e a lista é recarregada

#### Scenario: Nome indisponível

- **WHEN** o resource do contato não pode ser carregado
- **THEN** a SPA não inventa um nome, mantém o filtro funcional por ID e apresenta um rótulo neutro
