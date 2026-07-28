# ui-archetypes-docs-chrome Specification

## Purpose

Padronizar o chrome interno de `DocsWorkspace` com Shells canônicos, sem
redesenhar o posto documental, alterar rotas/testids ou ampliar matriz e
allowlist.

## Requirements

### Requirement: Chrome documental usa Shells canônicos

`DocsWorkspace` SHALL usar `ShellPageNavbar` e `ShellNavbarRefresh` no chrome
interno compartilhado pelas rotas documentais, preservando título, contagem,
permissões e ações existentes.

#### Scenario: Usuário abre uma rota documental

- **WHEN** o usuário acessa `/docs`, `/docs/catalog` ou um detalhe por chave
- **THEN** a navbar canônica mantém as ações disponíveis para a identidade e a
  recarga da visão ativa

### Requirement: Erro inicial é distinto de conteúdo preservado

`DocsWorkspace` SHALL usar `ShellLoadError` quando a carga inicial falhar sem
linhas válidas e MUST preservar o conteúdo anterior com feedback contextual
quando ainda houver dados utilizáveis.

#### Scenario: Primeira carga falha

- **WHEN** a API falha antes de entregar linhas para a visão ativa
- **THEN** o erro canônico apresenta mensagem e retry sem inventar dados

#### Scenario: Atualização falha após uma carga válida

- **WHEN** a API falha e a visão ativa ainda possui conteúdo utilizável
- **THEN** o conteúdo permanece visível com feedback contextual e retry

### Requirement: Posto documental permanece estável

A migração MUST preservar tabela densa, filtros, insights, importação,
exportação, paginação, seleção, `min-w-0`, detalhe em modal, rotas e testids.

#### Scenario: Usuário navega e abre um documento

- **WHEN** o usuário filtra, pagina, seleciona e abre um documento
- **THEN** a rota e o modal de detalhe mantêm o comportamento, foco e layout
  responsivo existentes

### Requirement: Matriz e allowlist não são ampliadas

O Lote 5 MUST manter a matriz de paridade e a allowlist atuais e MUST NOT criar
um componente Shell público.

#### Scenario: Gates do lote são executados

- **WHEN** testes focados e os seis gates Web são executados
- **THEN** todos passam sem página nova, mudança de matriz ou allowlist ampliada
