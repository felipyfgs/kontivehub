## ADDED Requirements

### Requirement: Merge PN↔LID consolida perfil e ledger exatamente
Ao consolidar peers LID↔PN com evidência válida, a API SHALL transferir perfis por inbox e a união exata do ledger para a identity/conversation sobreviventes.

#### Scenario: Perfis escolhem a fonte mais recente
- **WHEN** survivor e donor possuem observações diferentes da mesma fonte
- **THEN** vence o maior `(observed_at,event_id)` daquela fonte, preservando fontes independentes

#### Scenario: Ledger contém lacunas
- **WHEN** os fragmentos possuem pendências não contíguas
- **THEN** cada `message_id` pendente é movido uma única vez para a sobrevivente

#### Scenario: Read-state é consolidado
- **WHEN** fragmentos possuem read-states diferentes
- **THEN** a sobrevivente recebe cursor compatível, autoria preservada quando aplicável e uma nova versão

#### Scenario: Writer recebe ID donor
- **WHEN** READ ou UNREAD recebe uma conversa redirecionada
- **THEN** a operação aplica-se à sobrevivente e mantém a regra de conflito de versão

### Requirement: Correlação não inventa PN
O sistema SHALL usar somente correlação LID↔PN comprovada pelo contrato (`source_identity`/`JIDAlt` válido) e SHALL NOT inferir PN para LID desconhecido.

#### Scenario: LID sem alternate
- **WHEN** um perfil/evento contém somente LID
- **THEN** ele permanece LID até existir evidência válida de PN
