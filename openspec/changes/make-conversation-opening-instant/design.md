## Context

`ConversationList.vue` emite a seleção e um prefetch de metadados, mas `useCommunicationWorkspace.selectConversation()` ainda busca detalhe e a primeira página da timeline antes de concluir a navegação. Durante essa busca, `TimelinePanel.vue` monta um skeleton. Ao navegar da página `communication/index.vue` para `communication/conversations/[id].vue`, cada página possui sua própria instância de `CommunicationWorkspacePage`; o `onBeforeUnmount` chama `dispose()`, reinicia `initialized` e força a nova instância a carregar catálogo, lista e sincronização novamente. No mobile, a mesma seleção abre o `USlideover` com a transição padrão do Nuxt UI.

O workspace já possui cache por ID, epochs de sessão/seleção, paginação cursorizada e deduplicação do detalhe. A leitura só é confirmada após a timeline renderizar visível, portanto o prefetch pode buscar mensagens sem produzir efeito de leitura. A implementação deve preservar esse boundary, os deep-links, a virtualização, o foco e as alterações paralelas do change `refine-communication-list-controls`.

## Goals / Non-Goals

**Goals:**

- Preservar uma única instância do workspace ao navegar entre lista, conversa e mensagem.
- Preparar detalhe e primeira página de mensagens das linhas visíveis com concorrência limitada.
- Deduplicar prefetch e seleção, reutilizar timelines inicializadas e atualizar cache silenciosamente.
- Trocar o painel de forma atômica, sem skeleton de timeline nem estado textual “Abrindo”.
- Remover o movimento de entrada/saída somente do slideover da timeline mobile e manter foco/teclado.
- Antecipar a seleção de deep-link após lista/catálogos mínimos, sem bloquear no backlog de sincronização.

**Non-Goals:**

- Remover o skeleton honesto do primeiro carregamento da lista, indicadores de paginação ou loading de mutações explícitas.
- Pré-carregar todo o histórico cursorizado, anexos ou mídia; somente a página inicial já usada pelo detalhe será preparada.
- Alterar endpoints, limites, contratos, autorização, marcação de leitura, realtime ou estado persistido.
- Introduzir Pinia, SSR, service worker adicional, dependência ou wrapper master-detail novo.

## Decisions

### Tornar `communication.vue` o outlet persistente da área

Uma página pai em `app/pages/communication.vue` identifica as rotas que usam o workspace (`/communication`, conversas diretas e histórico por contato), mantém `CommunicationWorkspacePage` montado enquanto a navegação permanecer nesse conjunto e renderiza o `NuxtPage` filho para validação/deep-links. As páginas filhas deixam de duplicar o workspace. Rotas de contatos, respostas rápidas e fluxos continuam renderizando apenas seus filhos; ao sair do conjunto de conversas, o `v-if` desmonta o workspace e o `dispose()` existente encerra subscriptions e timers.

Isso preserva o layout dashboard canônico e evita criar um layout alternativo que duplicaria `UDashboardGroup`. Manter as páginas irmãs atuais foi descartado porque cada troca executa `onBeforeUnmount`; `KeepAlive` foi descartado porque manteria duas páginas com instâncias visuais concorrentes do mesmo composable compartilhado.

### Preparar somente a janela visível com fila limitada

`ConversationList` calcula a faixa estritamente visível a partir do mesmo `scrollTop`, altura medida e altura de linha usados pela virtualização. Ao mudar essa faixa, emite IDs para uma fila não reativa no workspace. A fila usa concorrência fixa baixa e ignora IDs já inicializados, enfileirados ou ativos. Hover, foco e pointerdown continuam sendo sinais prioritários e chamam o mesmo prefetch deduplicado.

O prefetch passa a carregar `include_messages=false` e a primeira página cursorizada da timeline. Ele não monta `TimelinePanel`, não confirma READ e não busca anexos. Pré-carregar todas as 50 linhas ou todo o histórico foi descartado por custo de rede/memória e por competir com a conversa escolhida.

### Deduplicar a timeline inicial e promover cache antes de refresh

Uma coleção de promises ativas, isolada por conversa/âncora, complementa `detailRequests`. Seleção e prefetch aguardam a mesma promise quando coincidem. Uma timeline `initialized` satisfaz imediatamente a seleção; depois do commit visual, `refreshConversationTimeline()` busca novidades sem apagar mensagens nem ativar loading inicial.

O epoch da sessão continua invalidando respostas de outro usuário/tenant. Mudança de tenant e dispose limpam fila e registros de requests. O estado de timeline permanece em memória somente dentro da sessão compartilhada atual.

### Comitar a seleção em uma troca atômica

Enquanto detalhe/timeline frios são preparados, o painel real anterior permanece montado no desktop e o slideover não abre no mobile. Após sucesso, o ID selecionado muda uma única vez. Assim `TimelinePanel` nunca recebe uma timeline em loading inicial durante a abertura/troca; um fallback honesto pode continuar disponível para estados excepcionais sem aparecer nesse caminho. Em falha fria, o painel anterior permanece e o erro autoritativo é apresentado por toast, sem mensagens sintéticas; um deep-link sem painel anterior retorna à lista.

O estado `openingConversationId` permanece apenas como trava/semântica acessível da linha; badge e placeholder textual de abertura são removidos. Atualizar o ID antes da resposta foi descartado porque reintroduziria loading visual ou mostraria mensagens da conversa errada.

### Desativar a transição do slideover de timeline

O `USlideover` mobile recebe `:transition="false"`. O slideover de contexto e outros overlays mantêm seus movimentos, pois não fazem parte da seleção. A restauração de foco usa `after:leave` e fallback no próximo ciclo, sem a espera fixa de 750 ms associada à animação removida.

### Não bloquear inicialização na sincronização histórica

Após catálogos, preferências e primeira página da lista estarem prontos, `initialized` é publicado e a sincronização cursorizada segue em background. Seus erros já são expostos em `syncError`, e realtime continua hidratando lista/timeline. Isso permite aplicar o deep-link e iniciar o prefetch sem aguardar milhares de eventos antigos.

## Risks / Trade-offs

- **[Prefetch aumenta leituras HTTP]** → restringir à faixa visível, limitar concorrência e reutilizar promises/cache.
- **[Timeline cacheada pode estar alguns segundos defasada]** → renderizar a última leitura real e fazer refresh silencioso imediatamente após a seleção.
- **[Troca fria sem spinner pode parecer um clique não reconhecido]** → manter estado tonal/`aria-busy` da linha e tornar o caminho comum quente por prefetch; falhas produzem feedback real.
- **[Página pai pode montar workspace em rotas erradas]** → centralizar e testar o predicado de path para conversas diretas e por contato, excluindo catálogo, detalhe de contato, respostas rápidas e fluxos.
- **[Sync em background pode concorrer com seleção]** → conservar epochs, merges autoritativos e deduplicação já existentes; a seleção não depende do cursor de eventos.
- **[Foco mobile pode regressar sem a duração da animação]** → testar fechamento por botão e `Esc` com `after:leave` e fallback imediato.
- **[Cache sobrevive entre filhos]** → essa é a intenção dentro do mesmo usuário/tenant; `sessionEpoch` limpa detalhes, timelines, requests e fila no switch de contexto.

## Migration Plan

Mudança somente frontend, sem migration, flag ou alteração de contrato. Implementar primeiro o outlet persistente e testes de roteamento, depois cache/prefetch e troca atômica, por fim remover movimento/skeleton. Rollback consiste em restaurar o workspace nas páginas filhas e o fluxo anterior do composable; nenhum dado persistido precisa ser convertido.

## Open Questions

Nenhuma.
