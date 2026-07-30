## MODIFIED Requirements

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
