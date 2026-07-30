# communication-contacts-experience Specification

## Purpose

TBD — experiência operacional do catálogo e detalhe de contatos de comunicação.

## Requirements

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

### Requirement: Foto do contato é consistente entre catálogo e detalhes

Catálogo e perfil principal de `/communication/contacts/:id` SHALL consumir o mesmo `profile_picture_url` resolvido pelo Laravel. A SPA SHALL manter iniciais/`?` quando o campo estiver ausente, nulo ou quando a imagem falhar e MUST NOT consultar Wazync ou CDN do WhatsApp.

#### Scenario: Detalhe aberto a partir do catálogo
- **WHEN** um contato com foto é aberto a partir de um card
- **THEN** o perfil de Detalhes mostra a mesma URL/version da lista sem novo endpoint de descoberta

#### Scenario: Asset deixa de existir
- **WHEN** a imagem responde 404 após purge, clear ou troca de versão
- **THEN** o avatar volta ao fallback e o restante dos dados válidos permanece visível

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
