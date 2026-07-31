## Context

`CommunicationTimelinePanel` separa navbar, contexto, viewport rolável e compositor conforme o arquétipo master-detail. O viewport chama `applyScrollToLatest()` na abertura e ao acompanhar novas mensagens, mas seu conteúdo interno usa apenas `min-h-full`; em históricos curtos, `scrollHeight` e `clientHeight` são equivalentes e não existe deslocamento capaz de aproximar as bolhas do compositor.

A trajetória principal do operador é ler a sequência cronológica até a mensagem mais recente e continuar pelo compositor. Cabeçalho e contexto apoiam essa tarefa, enquanto o grupo de mensagens recentes deve permanecer visualmente contíguo ao compositor. A densidade segue compacta e idêntica no painel desktop e no slideover mobile.

## Goals / Non-Goals

**Goals:**

- Ancorar o grupo cronológico no fim do viewport quando houver espaço vertical livre.
- Preservar o fluxo natural do documento, a paginação e o acesso ao topo quando o conteúdo ultrapassar o viewport.
- Preservar a política atual de acompanhamento, deep-link, divisor de não lidas e posição ao carregar histórico anterior.
- Aplicar a mesma estrutura nos modos desktop e mobile sem criar uma segunda implementação.

**Non-Goals:**

- Redesenhar bolhas, agrupar mensagens consecutivas ou adicionar separadores de data.
- Alterar contratos, ordenação, leitura, paginação, realtime, composer ou regras de autorização.
- Virtualizar páginas históricas ou mudar a quantidade de mensagens mantidas no DOM.

## Decisions

### Usar margem automática no agrupamento cronológico

O conteúdo interno será uma coluna flexível com altura mínima igual ao viewport, e o agrupamento de mensagens receberá margem automática antes dele. O espaço excedente fica acima das mensagens quando o histórico é curto; quando o conteúdo cresce, a margem colapsa para zero e o fluxo continua rolável desde o topo.

A alternativa `justify-content: flex-end` foi rejeitada porque alinhar um flex container inteiro ao fim pode produzir overflow inicial inacessível em históricos longos. Um espaçador absoluto também foi rejeitado por depender de medições e competir com paginação, alertas e mudanças de altura de mídia.

### Manter a política de scroll existente

`shouldFollowCommunicationTimeline`, `followingLatest`, o `ResizeObserver` e a restauração de posição na paginação permanecem como autoridade de acompanhamento. A mudança estrutural resolve somente a ausência de deslocamento em conteúdo curto; não cria watchers nem rolagens adicionais.

Mensagens enviadas continuam levando o operador ao fim. Mensagens recebidas acompanham somente quando ele já está próximo do fim; caso contrário, preservam a leitura e exibem o controle de novas mensagens.

### Cobrir o contrato estrutural e os casos de acompanhamento

O teste de composição do workspace verificará explicitamente a coluna de altura mínima e a margem automática do agrupamento. Os testes puros existentes continuam cobrindo troca de conversa, envio próprio, inbound no fim e inbound enquanto o operador consulta histórico. A validação visual comparará conversa curta e longa em desktop e mobile.

## Risks / Trade-offs

- **[Controles de paginação podem ocupar parte do rodapé]** → manter “mensagens posteriores” depois do agrupamento; quando existe `newer_cursor`, esse controle representa corretamente que o fim real ainda não foi carregado.
- **[Alertas e carregamento podem disputar o espaço flexível]** → aplicar a margem automática somente ao agrupamento de mensagens; skeleton, erro e estado vazio preservam sua composição atual.
- **[Mudança de altura de mídia pode deslocar o fim]** → preservar o `ResizeObserver`, que só acompanha o crescimento quando `followingLatest` está ativo.
- **[Regressão em histórico longo]** → não usar alinhamento flex no contêiner inteiro e validar que a margem automática colapsa quando não há espaço livre.

## Migration Plan

A alteração é somente de apresentação e pode ser liberada junto ao bundle web sem flag ou migração. O rollback consiste em remover as duas classes estruturais e o teste correspondente; APIs, dados e estado persistido permanecem compatíveis.

## Open Questions

Nenhuma.
