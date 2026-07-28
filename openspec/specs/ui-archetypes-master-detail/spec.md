# ui-archetypes-master-detail Specification

## Purpose

Padronizar a navbar dos painéis mestre de Communication, Work e Caixa Postal
sem alterar a composição mestre–detalhe, a responsividade ou as interações de
seleção e foco próprias de cada domínio.

## Requirements

### Requirement: Navbar canônica no painel mestre

Communication, Work e Caixa Postal SHALL usar `ShellPageNavbar` somente no
chrome mestre equivalente e SHALL NOT duplicar o collapse da sidebar.

#### Scenario: Communication é aberta

- **WHEN** o usuário acessa o workspace autorizado de atendimento
- **THEN** o painel mestre mantém título, contagem, realtime e administração
  na navbar Shell

#### Scenario: Fila de trabalho é aberta

- **WHEN** o usuário acessa Fila, Lista ou Kanban
- **THEN** o mesmo chrome mestre mantém título, contagem, toolbar e controles
  de visão

#### Scenario: Caixa Postal é aberta

- **WHEN** o usuário acessa a carteira de Caixa Postal
- **THEN** a navbar full-width mantém contagem, toggle de detalhe e alertas
  acima do split

### Requirement: Composição mestre–detalhe permanece estável

A migração MUST preservar painéis redimensionáveis, detalhes desktop,
slideovers mobile, views Fila/Lista/Kanban e toda seleção publicada.

#### Scenario: Usuário abre e fecha um detalhe

- **WHEN** um item é selecionado e o detalhe é fechado no desktop ou mobile
- **THEN** a composição adequada ao breakpoint permanece e o item mestre
  continua selecionável

#### Scenario: Usuário alterna a visão de trabalho

- **WHEN** o usuário troca entre Fila, Lista e Kanban
- **THEN** os controles, seleção e detalhe específicos de cada visão permanecem
  funcionais

### Requirement: Teclado e foco permanecem preservados

As três superfícies SHALL manter nomes acessíveis, estados pressed/disabled,
atalhos e restauração de foco existentes após a migração do navbar.

#### Scenario: Usuário navega por teclado

- **WHEN** o usuário move a seleção, fecha um detalhe ou aciona uma ação do
  navbar pelo teclado
- **THEN** o foco retorna ao alvo previsto e nenhum atalho atua dentro de um
  editor incompatível

### Requirement: Nenhum wrapper mestre–detalhe novo é criado

O Lote 4 MUST compor Shells existentes, manter matriz e allowlists e MUST NOT
criar `ShellMasterDetailWorkspace`.

#### Scenario: Gates do lote são executados

- **WHEN** testes focados e os seis gates Web são executados
- **THEN** todos passam sem página nova, mudança de matriz ou allowlist ampliada
