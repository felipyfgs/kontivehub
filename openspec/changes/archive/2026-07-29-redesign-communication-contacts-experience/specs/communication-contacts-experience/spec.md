## ADDED Requirements

### Requirement: Telefone completo é apresentado somente como E.164 seguro

A API SHALL adicionar `phone: string|null` a cada item de `identities` do contato e SHALL preencher o campo somente com um número E.164 que corresponda a `^\+[1-9]\d{7,14}$`. A API MUST retornar `null` para LID, JID não telefônico, identidade ou contato expurgado e valor inválido, e MUST NOT expor JID, LID, hash, ciphertext ou endereço técnico. O campo legado `address_masked` SHALL permanecer compatível.

#### Scenario: Usuário autorizado consulta identidade telefônica

- **WHEN** um usuário com `communication.view` consulta um contato ativo cuja identidade armazena um número normalizado válido
- **THEN** a resposta contém o telefone completo em `identities[].phone` e mantém `address_masked`

#### Scenario: Identidade não apresentável

- **WHEN** a identidade é LID, contém identificador técnico, está expurgada ou não satisfaz E.164
- **THEN** `identities[].phone` é `null` e nenhum identificador bruto aparece na resposta

#### Scenario: Contrato anterior continua válido

- **WHEN** um consumidor antigo ignora `phone` e lê `address_masked`
- **THEN** o envelope, a paginação e o campo mascarado preservam formato e semântica anteriores

### Requirement: Busca por telefone usa correlação hash

A listagem de contatos SHALL aceitar em `POST /communication/contacts/search` um telefone completo no body, normalizá-lo pela mesma regra usada na escrita e comparar seu SHA-256 com `identities.address_hash`, sem descriptografar a coleção de identidades. A SPA MUST NOT colocar buscas com oito ou mais dígitos na URL.

#### Scenario: Número nacional é encontrado

- **WHEN** o usuário busca um número nacional válido que normaliza para a identidade de um contato do tenant
- **THEN** o contato canônico correspondente aparece na página de resultados

#### Scenario: Número estrangeiro não vaza

- **WHEN** o hash pesquisado corresponde somente a uma identidade de outro tenant
- **THEN** nenhum contato é retornado

#### Scenario: Texto comum preserva a busca existente

- **WHEN** `q` não pode ser normalizado como telefone
- **THEN** a listagem continua pesquisando os campos textuais permitidos sem decrypt scan

### Requirement: Lista operacional de contatos

A SPA SHALL apresentar `/communication/contacts` como lista compacta de largura total, com avatar de iniciais, nome, indicação provisória, telefone completo ou “Número indisponível”, vínculos, situação e ações. A busca SHALL usar debounce de 300 ms; busca textual, filtros, ordenação e paginação SHALL permanecer restauráveis pela URL, enquanto busca potencialmente telefônica SHALL ser efêmera e enviada somente por POST/body. A interface MUST NOT mascarar o telefone com asteriscos ou introduzir bulk selection, segments, infinite scroll ou dados sintéticos.

#### Scenario: Contato com telefone

- **WHEN** a lista recebe uma identidade com `phone`
- **THEN** a linha exibe o número completo, oferece ação acessível para copiá-lo e não exibe `address_masked`

#### Scenario: Telefone indisponível

- **WHEN** `phone` está ausente ou é `null`
- **THEN** a linha exibe “Número indisponível” sem reconstruir máscara ou identificador

#### Scenario: Estado não sensível sobrevive ao reload

- **WHEN** o usuário altera busca textual, filtros, ordenação, página ou tamanho e recarrega a rota
- **THEN** o estado é restaurado a partir da query string e a API recebe os mesmos parâmetros válidos

#### Scenario: Telefone pesquisado não entra na URL

- **WHEN** a busca contém oito ou mais dígitos
- **THEN** a SPA envia o valor em POST/body, omite o valor da URL e não o restaura após reload

#### Scenario: Lista em viewport móvel

- **WHEN** a rota é aberta em 390×844
- **THEN** identidade, telefone e ações permanecem operáveis sem overflow horizontal

### Requirement: Detalhe separado com contexto responsivo

A SPA SHALL apresentar `/communication/contacts/:id` como rota separada com perfil principal plano e contexto em abas “Conversas”, “Identidades”, “Vínculos” e “Privacidade”. Em `lg+`, o contexto SHALL ficar em painel direito; abaixo de `lg`, o mesmo conteúdo SHALL abrir em `USlideover` com fechamento por `Esc`, contenção de foco e restauração do foco ao gatilho.

#### Scenario: Detalhe desktop

- **WHEN** um contato é aberto em 1440×900
- **THEN** o perfil de largura confortável e o painel contextual ficam simultaneamente visíveis sem grandes vazios ou cards decorativos empilhados

#### Scenario: Detalhe móvel

- **WHEN** um contato é aberto abaixo de `lg`
- **THEN** o perfil continua legível e um controle nomeado abre o contexto em slideover

#### Scenario: Fechar contexto por teclado

- **WHEN** o usuário abre o slideover pelo teclado e pressiona `Esc`
- **THEN** o overlay fecha e o foco retorna ao controle que o abriu

### Requirement: Ações são consistentes e autorizadas

A lista, a navbar e o contexto SHALL consumir uma definição reutilizável das ações de contato. Usuários com `communication.view` SHALL poder ler, copiar telefone e navegar; somente usuários com `communication.manage_contacts` SHALL ver ou executar criação, edição, identidade, vínculo, exportação e expurgo. Contatos expurgados SHALL permanecer somente leitura.

#### Scenario: Operador somente leitura

- **WHEN** o usuário possui `communication.view` sem `communication.manage_contacts`
- **THEN** telefone e navegação estão disponíveis, mas nenhuma mutação, exportação ou expurgo é oferecido

#### Scenario: Gestor de contatos

- **WHEN** o usuário possui `communication.manage_contacts` e o contato não foi expurgado
- **THEN** as superfícies oferecem o mesmo conjunto aplicável de ações com rótulos e semântica consistentes

#### Scenario: Tombstone

- **WHEN** `purged_at` está preenchido
- **THEN** ações mutáveis são removidas ou desabilitadas e o estado expurgado é anunciado

### Requirement: Estados reais e retorno preservam contexto

Lista e detalhe SHALL representar loading, stale, erro, vazio inicial e vazio filtrado com ações adequadas, sem inventar dados. O retorno do detalhe SHALL preservar a query anterior da lista quando ela for conhecida e SHALL usar `/communication/contacts` como fallback seguro.

#### Scenario: Falha de API

- **WHEN** carregar lista, detalhe ou seção contextual falha
- **THEN** a SPA mantém somente dados previamente válidos, quando existirem, e oferece retry explícito

#### Scenario: Retorno à lista filtrada

- **WHEN** o usuário abre um contato a partir de uma lista filtrada e aciona “Voltar”
- **THEN** a navegação restaura busca, filtros, ordenação e página anteriores
