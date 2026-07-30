## MODIFIED Requirements

### Requirement: Lista operacional de contatos

A SPA SHALL apresentar `/communication/contacts` como coleção full-width e full-height de cards `rounded-xl`, separados por 16 px, com avatar de 42 px, nome, indicação provisória, telefone completo ou “Número indisponível”, vínculos, situação e ações. O avatar SHALL usar `profile_picture_url` quando disponível e iniciais/`?` como fallback. Um único card SHALL poder expandir para edição resumida sem substituir os detalhes completos. Detalhes, nova conversa e expansão SHALL possuir gatilhos distintos e nenhuma ação SHALL aparecer duas vezes no mesmo card. A busca SHALL usar debounce de 300 ms; estado não sensível SHALL permanecer na URL e busca telefônica SHALL ser enviada somente por POST/body. A interface MUST NOT introduzir bulk selection, segments, infinite scroll ou dados sintéticos.

#### Scenario: Contato com telefone
- **WHEN** a lista recebe uma identidade com `phone`
- **THEN** o card exibe o número completo, oferece ação acessível para copiá-lo e não exibe `address_masked`

#### Scenario: Contato com foto
- **WHEN** a API devolve `profile_picture_url`
- **THEN** o card usa a imagem same-origin mantendo tamanho, formato e ações existentes

#### Scenario: Expansão exclusiva
- **WHEN** um card é aberto e o usuário abre outro
- **THEN** o primeiro é recolhido e alterações não salvas são descartadas somente após cancelamento explícito ou confirmação aplicável

#### Scenario: Lista em viewport móvel
- **WHEN** a rota é aberta em 390×844
- **THEN** foto/fallback, identidade, telefone, expansão e ações permanecem operáveis sem overflow horizontal

## ADDED Requirements

### Requirement: Foto do contato é consistente entre catálogo e detalhes

Catálogo e perfil principal de `/communication/contacts/:id` SHALL consumir o mesmo `profile_picture_url` resolvido pelo Laravel. A SPA SHALL manter iniciais/`?` quando o campo estiver ausente, nulo ou quando a imagem falhar e MUST NOT consultar Wazync ou CDN do WhatsApp.

#### Scenario: Detalhe aberto a partir do catálogo
- **WHEN** um contato com foto é aberto a partir de um card
- **THEN** o perfil de Detalhes mostra a mesma URL/version da lista sem novo endpoint de descoberta

#### Scenario: Asset deixa de existir
- **WHEN** a imagem responde 404 após purge, clear ou troca de versão
- **THEN** o avatar volta ao fallback e o restante dos dados válidos permanece visível
