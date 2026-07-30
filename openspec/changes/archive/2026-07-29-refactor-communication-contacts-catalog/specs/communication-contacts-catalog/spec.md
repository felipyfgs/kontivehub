## ADDED Requirements

### Requirement: Catálogo de contatos listável com filtros e paginação
O sistema SHALL expor a lista de contatos de comunicação em `/communication/contacts` para usuários com `communication.view`, usando chrome Shell de lista administrativa, busca `q`, filtros estruturados (situação, provisório, vínculo), ordenação allowlisted (`name`, `id`, `created_at`) e paginação offset com tamanhos 10/20/50.

#### Scenario: Usuário visualiza a lista
- **WHEN** um membro com `communication.view` abre `/communication/contacts`
- **THEN** a SPA carrega contatos do endpoint `/api/v1/communication/contacts` e exibe a tabela com estados de loading, erro e vazio reais

#### Scenario: Filtros sincronizam com a URL
- **WHEN** o usuário altera busca, filtros, página, `per_page` ou ordenação
- **THEN** a query string é atualizada e um reload com os mesmos parâmetros restaura o estado da lista

#### Scenario: Contatos doadores de merge não aparecem
- **WHEN** a API omite contatos com `merged_into_contact_id` preenchido
- **THEN** a lista SPA não inventa nem reintroduz esses contatos

### Requirement: Célula de identidade legível
Cada linha da lista SHALL apresentar identidade scannable: avatar com iniciais (ou fallback para provisório), nome de exibição, indicação de provisório quando aplicável, WhatsApp mascarado primário, clientes vinculados e badge de situação. A UI SHALL NOT exibir JID, telefone plaintext ou dados de gateway.

#### Scenario: Contato nomeado
- **WHEN** o contato possui `name` e ao menos uma identity
- **THEN** a linha mostra iniciais do nome, o nome, o `address_masked` primário e o status

#### Scenario: Contato provisório
- **WHEN** o contato é provisório sem nome definitivo
- **THEN** a linha usa rótulo de exibição provisório e sinal visual de “Sem nome definitivo” sem inventar dados

### Requirement: Ações de linha e criação
Usuários com `communication.manage_contacts` SHALL poder criar contato (nome opcional, telefone obrigatório, vínculo fiscal opcional completo). Qualquer usuário com view SHALL poder abrir a ficha. A lista SHALL oferecer ação de linha para abrir a ficha e, quando fizer sentido de produto, atalho para o workspace de conversas sem inventar filtros de API.

#### Scenario: Criação com vínculo
- **WHEN** o operador com manage_contacts cria contato com telefone e `client_id` opcional
- **THEN** a SPA envia o body suportado pela API e navega para a ficha do contato criado

#### Scenario: Empty states
- **WHEN** não há contatos sem filtros
- **THEN** a UI exibe empty de zero data com CTA de criação (se autorizado)
- **WHEN** busca/filtros não retornam itens
- **THEN** a UI exibe empty de filtros com ação de limpar

### Requirement: Ficha seccionada de contato
A rota `/communication/contacts/:id` SHALL apresentar ficha seccionada com perfil editável, identidades WhatsApp, vínculos com clientes fiscais e ações de privacidade (export/purge) gated por `communication.manage_contacts`. Contatos com `purged_at` permanecem somente leitura nas mutações de perfil/identidade.

#### Scenario: Salvar perfil
- **WHEN** o operador com manage_contacts altera nome e/ou `is_active` e salva
- **THEN** a SPA chama PATCH e reflete o resource retornado

#### Scenario: Identidade e vínculo
- **WHEN** o operador adiciona identidade ou vincula/desvincula cliente
- **THEN** a SPA usa os endpoints de identities/links e recarrega a ficha em sucesso

#### Scenario: Export e purge
- **WHEN** o operador com manage_contacts exporta
- **THEN** a SPA inicia download autenticado do JSON de exportação
- **WHEN** o operador confirma purge
- **THEN** a SPA chama DELETE de personal-data e recarrega o tombstone

### Requirement: Deep-link entre workspace e ficha
O workspace de conversas SHALL oferecer navegação para a ficha do contato associado quando `conversation.contact.id` existir. A ficha e a lista SHALL poder navegar para o workspace de conversas pela rota canônica de atendimento. Export e purge no painel de contexto SHALL exigir `communication.manage_contacts`.

#### Scenario: Abrir ficha a partir da conversa
- **WHEN** o usuário visualiza o painel de contexto de uma conversa com contact id
- **THEN** há ação “Abrir ficha” que navega para `/communication/contacts/{id}`

#### Scenario: Permissão de privacidade no workspace
- **WHEN** o usuário possui view/inboxes mas não `manage_contacts`
- **THEN** export e purge não são oferecidos no painel de contexto
