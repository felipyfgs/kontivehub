## ADDED Requirements

### Requirement: Catálogo de contatos usa chrome administrativo responsivo

O catálogo de contatos SHALL manter `ShellPagePanel`, navbar, toolbar persistente, corpo rolável e paginação do arquétipo administrativo, adaptando a apresentação para linhas semânticas compactas inspiradas na referência Chatwoot. A mudança MUST usar componentes, tokens semânticos e ícones Lucide disponíveis no Nuxt UI/template instalado e MUST NOT ampliar allowlists do gate de fidelidade.

#### Scenario: Chrome desktop

- **WHEN** `/communication/contacts` é aberta em viewport desktop
- **THEN** navbar, toolbar, lista rolável e paginação aparecem na ordem canônica do dashboard

#### Scenario: Chrome móvel

- **WHEN** a mesma rota é aberta em viewport móvel
- **THEN** os controles refluem, a lista não cria scroll horizontal e ações continuam nomeadas e alcançáveis por teclado

#### Scenario: Gates de fidelidade

- **WHEN** `test:fidelity` e `test:artifacts` são executados
- **THEN** ambos passam com a matriz de paridade atualizada e sem exceção visual nova
