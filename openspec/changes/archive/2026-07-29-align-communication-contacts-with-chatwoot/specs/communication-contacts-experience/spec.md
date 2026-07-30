## MODIFIED Requirements

### Requirement: Lista operacional de contatos

A SPA SHALL apresentar `/communication/contacts` como coleção full-width e full-height de cards `rounded-xl`, separados por 16 px, com avatar de 42 px, nome, indicação provisória, telefone completo ou “Número indisponível”, vínculos, situação e ações. Um único card SHALL poder expandir para edição resumida sem substituir os detalhes completos. Detalhes, nova conversa e expansão SHALL possuir gatilhos distintos e nenhuma ação SHALL aparecer duas vezes no mesmo card. A busca SHALL usar debounce de 300 ms; estado não sensível SHALL permanecer na URL e busca telefônica SHALL ser enviada somente por POST/body. A interface MUST NOT introduzir bulk selection, segments, infinite scroll ou dados sintéticos.

#### Scenario: Contato com telefone
- **WHEN** a lista recebe uma identidade com `phone`
- **THEN** o card exibe o número completo, oferece ação acessível para copiá-lo e não exibe `address_masked`

#### Scenario: Expansão exclusiva
- **WHEN** um card é aberto e o usuário abre outro
- **THEN** o primeiro é recolhido e alterações não salvas são descartadas somente após cancelamento explícito ou confirmação aplicável

#### Scenario: Lista em viewport móvel
- **WHEN** a rota é aberta em 390×844
- **THEN** identidade, telefone, expansão e ações permanecem operáveis sem overflow horizontal

### Requirement: Detalhes do contato usam contexto responsivo

A SPA SHALL apresentar `/communication/contacts/:id` como rota separada denominada Detalhes. Em `lg+`, perfil e contexto SHALL formar duas zonas flexíveis que ocupam toda a largura e altura úteis do body, com divisória até o rodapé e scroll independente; abaixo de `lg`, o perfil SHALL ocupar a largura completa e o mesmo contexto SHALL abrir em `USlideover` com fechamento por `Esc`, contenção de foco e restauração do foco ao gatilho.

#### Scenario: Detalhes no desktop
- **WHEN** um contato é aberto em 1440×900
- **THEN** perfil e rail ficam simultaneamente visíveis com hierarquia e densidade comparáveis à referência Chatwoot

#### Scenario: Detalhes no móvel
- **WHEN** um contato é aberto abaixo de `lg`
- **THEN** o perfil continua legível e um controle nomeado abre o mesmo contexto em slideover

#### Scenario: Conteúdo compartilhado
- **WHEN** o contato possui anexos ou links visíveis
- **THEN** o contexto apresenta teaser e acesso às abas Mídias, Links e Documentos

#### Scenario: Fechar contexto por teclado
- **WHEN** o usuário abre o slideover pelo teclado e pressiona `Esc`
- **THEN** o overlay fecha e o foco retorna ao controle que o abriu
