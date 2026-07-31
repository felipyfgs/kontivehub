## Why

Conversas curtas são renderizadas no topo da timeline, longe do compositor, porque o autoscroll não produz deslocamento quando o conteúdo é menor que o viewport. Isso quebra o modelo mental de chat e torna mensagens recém-enviadas ou recebidas visualmente desconectadas da próxima ação.

## What Changes

- Ancorar o agrupamento cronológico de mensagens ao rodapé da área rolável quando o histórico não preencher o viewport.
- Preservar a rolagem normal e o acesso ao início quando o histórico exceder a altura disponível.
- Manter o acompanhamento atual de mensagens novas: envio próprio segue o fim; inbound segue somente quando o operador já está próximo do fim.
- Cobrir o contrato de layout e a política de acompanhamento com testes focados em desktop e mobile.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `communication-conversation-workspace`: explicitar a ancoragem inferior de conversas curtas e a preservação da posição ao consultar histórico.

## Impact

- Afeta somente `apps/web`, em especial a estrutura flexível de `CommunicationTimelinePanel` e seus testes.
- Não altera API pública, contratos Laravel↔Wazync, dados, migrations, filas, flags, dependências ou egress.
- A mudança é compatível com o comportamento existente de timeline cursorizada, deep-link, divisor de não lidas e aviso de novas mensagens.
