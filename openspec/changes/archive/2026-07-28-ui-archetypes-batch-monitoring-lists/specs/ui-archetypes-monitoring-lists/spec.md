## ADDED Requirements

### Requirement: Chrome canônico das listas fiscais

As carteiras fiscais que compõem `MonitoringModuleTable` SHALL usar o painel e
a navbar Shell canônicos, com collapse da sidebar, título, consulta pendente
condicional e refresh da navbar, sem exigir chrome duplicado nos consumidores.

#### Scenario: Carteira fiscal é aberta

- **WHEN** o usuário abre qualquer uma das dez superfícies consumidoras
- **THEN** a página exibe `ShellPagePanel` e `ShellPageNavbar` com o mesmo id,
  título e conteúdo já publicados pelo wrapper compartilhado

#### Scenario: Consulta pendente é elegível

- **WHEN** a superfície possui módulo, cliente selecionável e operação
  produtiva elegível
- **THEN** a ação de consulta pendente permanece na navbar ao lado do refresh

#### Scenario: Consulta pendente não é elegível

- **WHEN** qualquer condição fail-closed da consulta pendente não é satisfeita
- **THEN** a ação não é renderizada, mas o refresh canônico continua disponível

### Requirement: Erros preservam a última carga válida

O wrapper SHALL distinguir falha inicial sem linhas de falha durante refresh e
SHALL NOT ocultar dados válidos anteriores por causa de uma falha posterior.

#### Scenario: Carga inicial falha sem linhas

- **WHEN** a carga termina com erro e nenhuma linha válida está disponível
- **THEN** `ShellLoadError` exibe a mensagem e oferece retry pelo evento
  `refresh`

#### Scenario: Refresh falha com linhas válidas

- **WHEN** um refresh falha depois de uma carga válida
- **THEN** o alerta contextual, a indicação de última atualização válida e as
  linhas existentes permanecem visíveis com retry

### Requirement: Contratos das superfícies permanecem estáveis

A migração SHALL preservar props, emits, slots, filtros, seleção, paginação,
sorting, testids e adapters de todas as superfícies consumidoras e MUST NOT
unificar o KPI strip fiscal com faixas de outra finalidade.

#### Scenario: Consumidor usa composição especializada

- **WHEN** uma rota fornece `submodules`, `kpis`, `utilities`, `bulk-actions`
  ou `detail`
- **THEN** o slot mantém a assinatura e a posição funcional existentes após a
  migração do chrome

#### Scenario: Usuário filtra e pagina

- **WHEN** o usuário altera filtros, sorting, seleção, página ou quantidade por
  página
- **THEN** os mesmos emits, adapters e identificadores processam a interação
  sem mudança de contrato

### Requirement: Paridade e gates permanecem fechados

A mudança MUST passar nos seis gates Web sem criar página, alterar a matriz de
paridade ou ampliar allowlists do gate de fidelidade.

#### Scenario: Lote é validado

- **WHEN** lint, typecheck, generate, test, fidelity e artifacts são executados
- **THEN** todos passam com a matriz e as allowlists vigentes inalteradas
