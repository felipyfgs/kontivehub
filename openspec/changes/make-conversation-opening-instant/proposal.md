## Why

Abrir uma conversa hoje bloqueia a seleção em buscas de detalhe e timeline e, ao sair de `/communication` para o deep-link, desmonta o workspace e descarta o cache recém-criado. O resultado é uma espera perceptível, skeleton de mensagens e movimento de slideover, mesmo quando o operador apenas alterna entre itens de uma lista já carregada.

## What Changes

- Manter o shell de Communication montado entre a lista, o deep-link da conversa e o deep-link de mensagem, preservando estado, cache, scroll e seleção durante a navegação interna.
- Pré-carregar de forma limitada as timelines das conversas visíveis, deduplicar requisições de detalhe/timeline e reutilizar a última timeline válida com atualização silenciosa em segundo plano.
- Trocar a conversa de forma atômica somente quando houver detalhe real disponível, sem exibir skeleton ou texto transitório de abertura no caminho de seleção.
- Abrir e fechar a timeline mobile sem a transição do `USlideover`, preservando `Esc`, foco e equivalência funcional com o painel desktop.
- Manter explícitos os estados reais de falha, vazio e paginação; a remoção de loading visual aplica-se à troca entre conversas, não ao carregamento inicial da lista nem a ações solicitadas pelo usuário.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `communication-conversation-workspace`: a navegação interna passa a preservar o workspace e a abertura de conversa passa a consumir timeline pré-carregada/cacheada de forma atômica, sem skeleton ou transição de entrada.

## Impact

- Afeta somente a SPA em `apps/web`: estrutura das páginas de Communication, `CommunicationWorkspacePage`, lista, timeline, `useCommunicationWorkspace` e testes Vitest/Playwright relacionados.
- Não altera `/api/v1`, OpenAPI, banco, migrations, filas, flags, Laravel, Wazync, autorização ou egress.
- Aumenta leituras antecipadas apenas para uma janela pequena de conversas visíveis, com concorrência limitada e isolamento pelo epoch da sessão/tenant.
- Preserva deep-links, histórico do navegador, leitura somente após renderização visível, realtime, virtualização e restauração de foco.
