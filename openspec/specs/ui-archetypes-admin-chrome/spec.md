# ui-archetypes-admin-chrome Specification

## Purpose

Definir o chrome canônico das superfícies administrativas da plataforma.

## Requirements

### Requirement: Casca canônica do console SERPRO

O console SERPRO (`pages/admin/serpro.vue` e suas rotas filhas) SHALL
apresentar o chrome do arquétipo settings por meio de `ShellSettingsShell`:
painel do dashboard, navbar com colapso da sidebar, toolbar de navegação das
seções e conteúdo centralizado em largura confortável.

#### Scenario: Navegação para administrador da plataforma

- **WHEN** um usuário com acesso ao console SERPRO da plataforma abre
  `/admin/serpro` ou uma seção filha
- **THEN** a navbar canônica com colapso da sidebar e a toolbar com a
  navegação de seções são exibidas, e o conteúdo da seção é renderizado na
  casca compartilhada

#### Scenario: Acesso restrito fail-closed

- **WHEN** um usuário sem permissão de plataforma acessa o console SERPRO
- **THEN** a toolbar de navegação não é renderizada e o corpo exibe apenas o
  alerta de acesso restrito à plataforma, sem conteúdo de seção

### Requirement: Chrome canônico na gestão de escritórios

As páginas de detalhe e criação de escritório
(`pages/admin/tenants/[id].vue` e `pages/admin/tenants/new.vue`) SHALL usar
`ShellPagePanel` e `ShellPageNavbar`, com retorno à lista de escritórios via
`ShellNavbarBack` responsivo.

#### Scenario: Voltar responsivo

- **WHEN** o usuário visualiza o detalhe ou a criação de escritório em
  viewport móvel
- **THEN** o botão de retorno à lista é exibido em formato compacto com nome
  acessível; em desktop, com rótulo visível

#### Scenario: Contexto de lifecycle preservado

- **WHEN** o detalhe de um escritório é carregado
- **THEN** o badge de lifecycle permanece visível na área direita da navbar e
  o painel mantém sua identidade de teste

### Requirement: Erro de carga padronizado no detalhe do escritório

O detalhe do escritório SHALL exibir falhas de carga — incluindo acesso
negado e identificador inválido — por meio de `ShellLoadError`, com ação
«Tentar novamente» que re-executa a carga de forma idempotente e fail-closed.

#### Scenario: Falha de API com retry

- **WHEN** a carga do escritório falha por erro de API
- **THEN** o erro padronizado é exibido com a mensagem da falha e, ao acionar
  «Tentar novamente», a carga é refeita e o estado de erro é limpo

#### Scenario: Acesso negado sem vazar dados

- **WHEN** um usuário sem permissão de administração da plataforma acessa o
  detalhe de um escritório
- **THEN** apenas a mensagem de acesso restrito é exibida e nenhum dado do
  escritório é carregado ou renderizado

### Requirement: Contratos de superfície preservados

A migração SHALL preservar rotas, permissões, fluxos de negócio e os
identificadores de teste (`data-testid`) existentes, e MUST passar nos gates
web sem ampliar allowlists do gate de fidelidade nem alterar a matriz de
paridade.

#### Scenario: Gates de qualidade web

- **WHEN** os gates `lint`, `typecheck`, `generate`, `test`, `test:fidelity`
  e `test:artifacts` são executados no container `frontend-dev`
- **THEN** todos passam com a matriz de paridade e as allowlists vigentes,
  sem exceções novas

#### Scenario: Identidade de teste estável

- **WHEN** testes existentes localizam elementos pelos `data-testid`
  documentados das três páginas (painéis, navbar, alertas, navegação,
  ações do wizard e do detalhe)
- **THEN** todos os identificadores continuam presentes após a migração
