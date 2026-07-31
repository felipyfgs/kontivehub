## MODIFIED Requirements

### Requirement: Lista operacional de contatos

A SPA SHALL apresentar `/communication/contacts` como coleção full-width e full-height de cards `rounded-xl`, separados por 16 px, com avatar de 42 px, nome, indicação provisória, telefone completo ou “Número indisponível”, vínculos, situação e ações. O avatar SHALL usar `profile_picture_url` somente quando `profile_picture_state=READY` e a URL for não nula; todas as demais combinações SHALL manter iniciais/`?` como fallback. Um único card SHALL poder expandir para edição resumida sem substituir os detalhes completos. Detalhes, nova conversa e expansão SHALL possuir gatilhos distintos e nenhuma ação SHALL aparecer duas vezes no mesmo card. A busca SHALL usar debounce de 300 ms; estado não sensível SHALL permanecer na sessão isolada e busca telefônica SHALL ser enviada somente por POST/body. A interface MUST NOT introduzir bulk selection, segments, infinite scroll ou dados sintéticos.

#### Scenario: Contato com telefone
- **WHEN** a lista recebe uma identidade com `phone`
- **THEN** o card exibe o número completo, oferece ação acessível para copiá-lo e não exibe `address_masked`

#### Scenario: Contato com foto
- **WHEN** a API devolve `profile_picture_state=READY` e `profile_picture_url` não nula
- **THEN** o card usa a imagem same-origin mantendo tamanho, formato e ações existentes

#### Scenario: Expansão exclusiva
- **WHEN** um card é aberto e o usuário abre outro
- **THEN** o primeiro é recolhido e alterações não salvas são descartadas somente após cancelamento explícito ou confirmação aplicável

#### Scenario: Lista em viewport móvel
- **WHEN** a rota é aberta em 390×844
- **THEN** foto/fallback, identidade, telefone, expansão e ações permanecem operáveis sem overflow horizontal

#### Scenario: Estado do catálogo não aparece na URL
- **WHEN** busca textual ou filtros estruturados são aplicados
- **THEN** a consulta autoritativa recebe os valores e `window.location.search` permanece vazio

### Requirement: Estados reais e retorno preservam contexto

Lista e detalhe SHALL representar loading, stale, erro, vazio inicial e vazio filtrado com ações adequadas, sem inventar dados. O retorno do detalhe SHALL restaurar o estado de sessão anterior da lista quando ele existir e SHALL usar `/communication/contacts` com defaults como fallback seguro.

#### Scenario: Falha de API
- **WHEN** carregar lista, detalhe ou seção contextual falha
- **THEN** a SPA mantém somente dados previamente válidos, quando existirem, e oferece retry explícito

#### Scenario: Retorno à lista filtrada
- **WHEN** o usuário abre um contato a partir de uma lista filtrada e aciona “Voltar”
- **THEN** a navegação restaura busca, filtros, ordenação e página anteriores sem query string
