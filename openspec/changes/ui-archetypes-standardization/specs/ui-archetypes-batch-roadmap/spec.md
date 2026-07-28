## ADDED Requirements

### Requirement: Padronização ocorre por changes filhos

A padronização SHALL ser implementada em changes filhos independentes para
admin, listas monitoring, dashboards, master-detail, docs e quick wins, cada um
com artefatos completos, menor delta, testes focados e gates web antes do
encerramento.

#### Scenario: Início de um lote
- **WHEN** uma frente do roadmap estiver pronta para implementação
- **THEN** seu change filho contém proposal, design, delta spec e tasks antes da primeira edição em `apps/web`

#### Scenario: Encerramento de um lote
- **WHEN** o comportamento do lote estiver implementado
- **THEN** testes focados, gates completos e inspeção visual/acessível passam antes de sincronizar a delta spec, marcar o roadmap e arquivar o filho

### Requirement: Reutilização Shell exige equivalência estrutural

Um componente Shell SHALL ser reutilizado somente quando representar a mesma
hierarquia, slots e comportamento responsivo do arquétipo; particularidades de
domínio SHALL permanecer nos componentes consumidores.

#### Scenario: Workspaces master-detail distintos
- **WHEN** superfícies compartilham apenas parte do chrome mas diferem em panels, rails, slideovers ou políticas de estado
- **THEN** elas compõem Shells existentes apenas nos trechos equivalentes e não ganham uma abstração comum prematura

