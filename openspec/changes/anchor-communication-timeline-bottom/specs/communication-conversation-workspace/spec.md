## ADDED Requirements

### Requirement: Timeline mantém o contexto recente junto ao compositor
A SPA SHALL posicionar o agrupamento cronológico de mensagens no fim da área rolável quando o histórico renderizado não preencher o viewport, preservando a mensagem mais recente visualmente próxima ao compositor em desktop e mobile.

Quando o histórico ultrapassar a altura disponível, a timeline MUST preservar o fluxo cronológico, o acesso ao início e a rolagem normal. Mensagens novas SHALL acompanhar o fim conforme a política existente: envio próprio acompanha sempre; inbound acompanha somente quando o operador já está próximo do fim.

#### Scenario: Conversa curta é aberta
- **WHEN** a conversa possui poucas mensagens e o histórico ocupa menos altura que o viewport
- **THEN** o espaço livre permanece acima do agrupamento e a mensagem mais recente fica junto ao rodapé da timeline

#### Scenario: Conversa longa é aberta
- **WHEN** o histórico renderizado ultrapassa a altura do viewport
- **THEN** a timeline continua rolável, com as mensagens mais antigas acessíveis no início e as recentes no fim

#### Scenario: Operador envia uma mensagem
- **WHEN** uma mensagem outbound ou nota interna do próprio operador é anexada à conversa
- **THEN** a timeline acompanha o fim e mantém a nova mensagem próxima ao compositor

#### Scenario: Mensagem chega enquanto o operador acompanha o fim
- **WHEN** uma mensagem inbound é anexada e o viewport já está próximo do fim
- **THEN** a timeline acompanha a nova mensagem no rodapé

#### Scenario: Mensagem chega durante consulta ao histórico
- **WHEN** uma mensagem inbound é anexada enquanto o operador está longe do fim
- **THEN** a posição de leitura é preservada e o controle de novas mensagens permite retornar ao rodapé

#### Scenario: Histórico anterior é carregado
- **WHEN** mensagens anteriores são inseridas acima do trecho visível
- **THEN** a posição de leitura permanece estável e o início recém-carregado continua acessível
