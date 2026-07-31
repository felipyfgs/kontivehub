## MODIFIED Requirements

### Requirement: Catálogo de contatos listável com filtros e paginação
O sistema SHALL expor a lista de contatos de comunicação em `/communication/contacts` para usuários com `communication.view`, usando chrome Shell de lista administrativa, busca `q`, filtros estruturados (situação, provisório, vínculo), ordenação allowlisted (`name`, `id`, `created_at`) e paginação offset com tamanhos 10/20/50. A coleção SHALL ocupar toda a largura e altura úteis do painel e ser apresentada como cards semânticos expansíveis, com somente um card aberto por vez e sem duplicar ações entre o resumo e o editor. Após uma edição, a SPA SHALL recarregar a página autoritativa sob epoch/sequence e SHALL NOT remover, duplicar ou reintroduzir outro contato por merge local concorrente.

#### Scenario: Usuário visualiza a lista
- **WHEN** um membro com `communication.view` abre `/communication/contacts`
- **THEN** a SPA carrega contatos do endpoint `/api/v1/communication/contacts` e exibe cards com estados de loading, erro e vazio reais

#### Scenario: Card expandido
- **WHEN** o usuário expande um contato
- **THEN** os demais cards recolhem e o card ativo apresenta formulário resumido, telefone somente leitura, identidades/vínculos e ações autorizadas

#### Scenario: Filtros permanecem na sessão
- **WHEN** o usuário altera busca, filtros, página, `per_page` ou ordenação e abre um detalhe
- **THEN** a URL permanece sem query e o retorno restaura o estado do catálogo durante a mesma sessão

#### Scenario: Contatos doadores de merge não aparecem
- **WHEN** a API omite contatos com `merged_into_contact_id` preenchido
- **THEN** a lista SPA não inventa nem reintroduz esses contatos

#### Scenario: Edição concorre com reload
- **WHEN** a resposta do PATCH e uma listagem em voo terminam em ordem diferente
- **THEN** a última listagem autoritativa válida por epoch vence, preservando demais itens, totais e paginação

#### Scenario: Controle de expansão colapsado
- **WHEN** o editor inline não está montado
- **THEN** o gatilho preserva `aria-expanded=false` e omite `aria-controls` para um alvo inexistente
