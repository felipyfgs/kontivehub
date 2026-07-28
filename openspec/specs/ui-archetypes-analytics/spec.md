# UI Archetypes Analytics

## Purpose

Definir o chrome canônico das superfícies analíticas de início e monitoramento,
com estabilidade de ações, dados válidos e estados de falha parcial.

## Requirements

### Requirement: Chrome canônico das superfícies analíticas

As páginas de início e monitoramento SHALL usar painel e navbar Shell
canônicos sem duplicar o collapse da sidebar e SHALL preservar o identificador,
título e distribuição responsiva de cada superfície.

#### Scenario: Usuário abre o início

- **WHEN** o usuário autenticado abre `/`
- **THEN** o cockpit exibe `ShellPagePanel` e `ShellPageNavbar` com a toolbar,
  alertas e ações rápidas nas posições existentes

#### Scenario: Usuário abre o dashboard fiscal

- **WHEN** o usuário autenticado abre `/monitoring`
- **THEN** a visão fiscal exibe os Shells canônicos e mantém o body sem overflow
  horizontal

### Requirement: Refresh preserva identidade e acesso

Cada superfície SHALL usar `ShellNavbarRefresh` com loading, nome acessível e
o mesmo callback de atualização publicado antes da migração.

#### Scenario: Cockpit é atualizado

- **WHEN** o usuário aciona o refresh na toolbar do início
- **THEN** a ação chama `load`, apresenta loading e mantém o alinhamento e a
  indicação da última atualização válida

#### Scenario: Visão fiscal é atualizada

- **WHEN** o usuário aciona o refresh da navbar de monitoramento
- **THEN** a ação chama `load`, apresenta loading e conserva o nome acessível
  específico da visão fiscal

### Requirement: Dados válidos e erros parciais permanecem distintos

A migração MUST preservar a última carga válida, os erros iniciais, os erros de
refresh e os erros parciais por seção sem inventar dados ou ocultar conteúdo
confirmado.

#### Scenario: Atualização do início falha após sucesso

- **WHEN** uma das consultas do cockpit falha após uma carga confirmada
- **THEN** os dados válidos permanecem visíveis e o retry continua disponível

#### Scenario: Visão fiscal falha inicialmente

- **WHEN** o dashboard fiscal não possui leitura confirmada e a API falha
- **THEN** o erro inicial informa que nenhum indicador foi estimado e oferece
  nova tentativa

#### Scenario: Visão fiscal recebe resultado parcial

- **WHEN** uma ou mais seções estão indisponíveis e as demais têm dados locais
- **THEN** o erro parcial identifica as seções e preserva os demais números

### Requirement: Conteúdo analítico permanece especializado

O Lote 3 MUST preservar componentes, CTAs, deep links, testids e KPI strips de
cada página e MUST NOT criar um Shell analítico público ou unificar superfícies
com semânticas diferentes.

#### Scenario: Gate do lote é executado

- **WHEN** os testes focados e os seis gates Web são executados
- **THEN** as duas páginas mantêm seus contratos sem página nova, mudança de
  matriz ou ampliação de allowlists
