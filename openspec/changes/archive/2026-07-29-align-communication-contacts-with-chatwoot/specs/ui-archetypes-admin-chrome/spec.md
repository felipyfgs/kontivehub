## MODIFIED Requirements

### Requirement: Catálogo de contatos usa chrome administrativo responsivo

O catálogo de contatos SHALL manter `ShellPagePanel`, uma única navbar/header no desktop, toolbar móvel responsiva, corpo rolável e paginação do arquétipo administrativo, adaptando o corpo para cards semânticos expansíveis em toda a largura e altura úteis. A mudança MUST usar componentes, tokens semânticos e ícones Lucide disponíveis e MUST NOT ampliar allowlists do gate de fidelidade.

#### Scenario: Chrome desktop
- **WHEN** `/communication/contacts` é aberta em viewport desktop
- **THEN** header único com busca/filtros/ordenação, coleção ocupando a largura e altura úteis e paginação aparecem na ordem canônica, com cards espaçados em 16 px

#### Scenario: Chrome móvel
- **WHEN** a mesma rota é aberta em viewport móvel
- **THEN** controles refluem, cards expandem verticalmente sem scroll horizontal e ações continuam nomeadas e alcançáveis por teclado

#### Scenario: Gates de fidelidade
- **WHEN** `test:fidelity` e `test:artifacts` são executados
- **THEN** ambos passam com a matriz de paridade atualizada e sem exceção visual nova
