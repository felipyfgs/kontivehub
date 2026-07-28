# ui-archetypes-audit Specification

## Purpose

Manter inventário canônico de fidelidade visual do `apps/web` contra os
arquétipos do dashboard de referência, com classificação por página e critérios
de conformidade, parcial ou divergente.

## Requirements

### Requirement: Auditoria de fidelidade reproduzível

O frontend SHALL manter um inventário versionado das páginas e componentes
Shell comparado à referência local validada pela skill `ui-archetypes`, com
classificação conforme, parcial ou divergente e evidência dos gates aplicáveis.

#### Scenario: Referência válida
- **WHEN** a auditoria de UI é executada antes de um lote de padronização
- **THEN** o verificador confirma a revisão e os arquivos mapeados da referência antes de qualquer edição perceptível

#### Scenario: Divergência inventariada
- **WHEN** uma página monta chrome diferente do arquétipo ou dos componentes Shell equivalentes
- **THEN** a auditoria registra a superfície, o arquétipo, a classificação e o lote responsável sem alterar allowlists para ocultar a divergência

### Requirement: Contratos de fidelidade permanecem verificáveis

Cada lote SHALL preservar rotas, testids, matriz de paridade, allowlists,
responsividade e acessibilidade, salvo mudança explicitamente especificada e
testada no próprio change filho.

#### Scenario: Lote sem mudança de página
- **WHEN** um lote apenas refatora o chrome interno de páginas existentes
- **THEN** a matriz de paridade e as allowlists permanecem inalteradas e os gates web completos passam
