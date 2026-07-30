# communication-contacts-catalog Specification

## Purpose

TBD — catálogo listável e ficha de contatos de comunicação.

## Requirements

### Requirement: Catálogo de contatos listável com filtros e paginação
O sistema SHALL expor a lista de contatos de comunicação em `/communication/contacts` para usuários com `communication.view`, usando chrome Shell de lista administrativa, busca `q`, filtros estruturados (situação, provisório, vínculo), ordenação allowlisted (`name`, `id`, `created_at`) e paginação offset com tamanhos 10/20/50. A coleção SHALL ocupar toda a largura e altura úteis do painel e ser apresentada como cards semânticos expansíveis, com somente um card aberto por vez e sem duplicar ações entre o resumo e o editor. Após uma edição, a SPA SHALL recarregar a página autoritativa sob epoch/sequence e SHALL NOT remover, duplicar ou reintroduzir outro contato por merge local concorrente.

#### Scenario: Usuário visualiza a lista
- **WHEN** um membro com `communication.view` abre `/communication/contacts`
- **THEN** a SPA carrega contatos do endpoint `/api/v1/communication/contacts` e exibe cards com estados de loading, erro e vazio reais

#### Scenario: Card expandido
- **WHEN** o usuário expande um contato
- **THEN** os demais cards recolhem e o card ativo apresenta formulário resumido, telefone somente leitura, identidades/vínculos e ações autorizadas

#### Scenario: Filtros sincronizam com a URL
- **WHEN** o usuário altera busca, filtros, página, `per_page` ou ordenação
- **THEN** a query string é atualizada e um reload com os mesmos parâmetros restaura o estado da lista

#### Scenario: Contatos doadores de merge não aparecem
- **WHEN** a API omite contatos com `merged_into_contact_id` preenchido
- **THEN** a lista SPA não inventa nem reintroduz esses contatos

#### Scenario: Edição concorre com reload
- **WHEN** a resposta do PATCH e uma listagem em voo terminam em ordem diferente
- **THEN** a última listagem autoritativa válida por epoch vence, preservando demais itens, totais e paginação

#### Scenario: Controle de expansão colapsado
- **WHEN** o editor inline não está montado
- **THEN** o gatilho preserva `aria-expanded=false` e omite `aria-controls` para um alvo inexistente

### Requirement: Célula de identidade legível
Cada card da lista SHALL apresentar identidade scannable: foto de perfil autorizada quando disponível, iniciais como fallback, nome de exibição, indicação de provisório quando aplicável, telefone primário permitido, clientes vinculados e badge de situação. A foto SHALL preservar o avatar de 42 px `rounded-lg`. A UI SHALL NOT exibir JID, URL remota, `picture_id` ou dados do gateway.

#### Scenario: Contato nomeado com foto
- **WHEN** o contato possui `name` e `profile_picture_url`
- **THEN** o card mostra a foto de 42 px, o nome, o telefone permitido e o status

#### Scenario: Contato nomeado sem foto
- **WHEN** o contato possui nome, mas a foto está ausente ou falha
- **THEN** o card mostra as iniciais do nome sem iniciar fetch direto

#### Scenario: Contato provisório
- **WHEN** o contato é provisório sem nome definitivo
- **THEN** o card usa a foto autorizada ou `?` como fallback e sinal visual de “Sem nome definitivo” sem inventar dados

### Requirement: Ações de linha e criação
Usuários com `communication.manage_contacts` SHALL poder criar e editar o resumo do contato. Qualquer usuário com `communication.view` SHALL poder abrir Detalhes. Usuários com `communication.reply` SHALL poder iniciar uma conversa pelo card usando o fluxo outbound canônico, sem a SPA escolher implicitamente inbox ou identidade quando houver alternativas.

#### Scenario: Criação com vínculo
- **WHEN** o operador com manage_contacts cria contato com telefone e `client_id` opcional
- **THEN** a SPA envia o body suportado pela API e navega para os detalhes do contato criado

#### Scenario: Nova conversa pelo card
- **WHEN** o operador autorizado aciona “Nova conversa”
- **THEN** a SPA abre o modal compartilhado com o contato preenchido e exige identidade e inbox válidas antes do envio

#### Scenario: Empty states
- **WHEN** não há contatos sem filtros
- **THEN** a UI exibe empty de zero data com CTA de criação quando autorizado
- **WHEN** busca ou filtros não retornam itens
- **THEN** a UI exibe empty de filtros com ação de limpar

### Requirement: Ficha seccionada de contato
A rota `/communication/contacts/:id` SHALL apresentar ficha seccionada com perfil editável, identidades WhatsApp, vínculos com clientes fiscais e ações de privacidade (export/purge) gated por `communication.manage_contacts`. Contatos com `purged_at` permanecem somente leitura nas mutações de perfil/identidade. A rota SHALL encerrar fail-closed antes de instanciar composables de domínio quando faltar `communication.view`, e todo modal acionável SHALL estar montado em um slot efetivamente renderizado pelo Shell.

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

#### Scenario: Usuário sem visualização
- **WHEN** a rota é avaliada sem `communication.view`
- **THEN** a SPA redireciona sem carregar contato, inboxes, histórico ou conteúdo compartilhado

#### Scenario: Nova conversa no detalhe
- **WHEN** um usuário autorizado aciona “Nova conversa” na ficha
- **THEN** o modal compartilhado está presente no DOM, carrega inboxes reais e mantém foco/fechamento do overlay

### Requirement: Deep-link entre workspace e ficha
O workspace de conversas SHALL oferecer navegação para a ficha do contato associado quando `conversation.contact.id` existir. A ficha e a lista SHALL poder navegar para o workspace de conversas pela rota canônica de atendimento. Export e purge no painel de contexto SHALL exigir `communication.manage_contacts`.

#### Scenario: Abrir ficha a partir da conversa
- **WHEN** o usuário visualiza o painel de contexto de uma conversa com contact id
- **THEN** há ação “Abrir ficha” que navega para `/communication/contacts/{id}`

#### Scenario: Permissão de privacidade no workspace
- **WHEN** o usuário possui view/inboxes mas não `manage_contacts`
- **THEN** export e purge não são oferecidos no painel de contexto
