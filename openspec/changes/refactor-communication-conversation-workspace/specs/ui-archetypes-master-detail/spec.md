## ADDED Requirements

### Requirement: O delta visual preserva a composição master–detail
Communication SHALL manter navbar Shell, painel mestre redimensionável no desktop, timeline adjacente, contexto em telas largas e detalhe em `USlideover` no mobile.

#### Scenario: Lista compacta no desktop
- **WHEN** a lista recebe título, contexto, preview, horário e unread
- **THEN** o resize, a seleção, a timeline e o painel de contexto continuam operacionais

#### Scenario: Detalhe mobile
- **WHEN** uma conversa é aberta em viewport mobile
- **THEN** timeline, divisor, composer e ações aparecem no slideover existente e `Esc` restaura o foco

#### Scenario: Filtro e teclado
- **WHEN** “Não lidas” está ativo e o usuário navega com setas
- **THEN** deep-link, URL↔seleção, scroll da linha e fixação da conversa selecionada permanecem coerentes

#### Scenario: Gates do arquétipo
- **WHEN** os gates de fidelidade/master–detail são executados
- **THEN** passam sem introduzir shell genérico ou alterar allowlists fora do escopo
