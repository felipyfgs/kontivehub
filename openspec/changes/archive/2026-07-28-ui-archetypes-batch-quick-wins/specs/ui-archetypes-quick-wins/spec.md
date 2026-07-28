## ADDED Requirements

### Requirement: Erro de carga inicial via ShellLoadError

Telas de lista/settings deste lote SHALL exibir falhas de carga inicial (ou
recarga sem dados utilizáveis) por meio de `ShellLoadError`, com ação
«Tentar novamente» que re-executa a carga de forma idempotente. Erros com
dados preservados (stale), erros de sub-região parcial e alertas de negócio
MUST permanecer em componentes locais (`UAlert` ou equivalente).

#### Scenario: Falha inicial sem dados em sincronizações

- **WHEN** a lista de execuções de sincronização falha e não há itens em
  memória
- **THEN** a página exibe `ShellLoadError` com a mensagem da falha e, ao
  acionar «Tentar novamente», a carga é refeita

#### Scenario: Stale do calendário preservado

- **WHEN** o intervalo do calendário operacional falha mas há snapshot stale
  exibível
- **THEN** o aviso de última carga válida permanece local (não substitui a
  grade por um erro de página inteira)

### Requirement: Toolbar de fechamento canônica

A página de fechamento de saídas (`pages/closing.vue`) SHALL compor a faixa
de filtros via `ShellListFilterToolbar`, com a competência no slot de ações
e presets na surface `closing.list`, sem montar chips/presets de filtros
salvos de forma duplicada fora do Shell.

#### Scenario: Filtros e competência

- **WHEN** o usuário abre o fechamento
- **THEN** a toolbar canônica é renderizada, a competência permanece editável
  e os filtros estruturados/presets usam o Shell de lista

### Requirement: Cards de seção drop-in

Onde um `UPageCard` variant subtle sem overrides de layout for o card de
seção de settings/detalhe, a superfície SHALL preferir `ShellSectionCard`
como drop-in, preservando título, descrição e conteúdo.

#### Scenario: Assinatura e certificado

- **WHEN** o usuário visualiza assinatura ou certificado nas settings do
  escritório
- **THEN** o card de seção canônico é usado sem alteração de campos ou
  ações de negócio

### Requirement: Contratos e gates preservados

A migração MUST preservar rotas, permissões, fluxos de negócio e
identificadores de teste relevantes, e MUST não ampliar allowlists do gate
de fidelidade nem unificar forçadamente `ShellKpiStrip` e
`MonitoringKpiStrip`.

#### Scenario: KPI strips inalterados em monitoring

- **WHEN** as rotas de carteira fiscal via `MonitoringModuleTable` são
  exercitadas
- **THEN** `MonitoringKpiStrip` continua sendo o componente de contadores
  fiscais, sem troca por `ShellKpiStrip`
