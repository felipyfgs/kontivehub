## MODIFIED Requirements

### Requirement: Catálogo de contatos listável com filtros e paginação
O sistema SHALL expor a lista de contatos de comunicação em `/communication/contacts` para usuários com `communication.view`, usando chrome Shell de lista administrativa, busca `q`, filtros estruturados (situação, provisório, vínculo), ordenação allowlisted (`name`, `id`, `created_at`) e paginação offset com tamanhos 10/20/50. A coleção SHALL ocupar toda a largura e altura úteis do painel e ser apresentada como cards semânticos expansíveis, com somente um card aberto por vez e sem duplicar ações entre o resumo e o editor.

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
