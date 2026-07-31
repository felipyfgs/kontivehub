## Context

O workspace de Communication mantém busca, status, ordenação, inbox, responsável, fila, marcadores, não lidas, sem responsável e contato no estado transitório isolado de `COMMUNICATION_SURFACES.workspace`. Status e ordenação também são hidratados e sincronizados com as preferências por usuário/tenant; `navigationState.state.value.statusFilter` e `sortBy` permanecem a fonte reativa da sessão. A primeira compactação removeu a faixa permanente de seleção e centralizou o checkbox no avatar, mas ainda distribui busca, status, ordenação e filtros em gatilhos concorrentes. A lista é virtualizada e ocupa um `UDashboardPanel` redimensionável de 20–32%, portanto a solução deve responder à largura real do painel sem alterar API ou paginação.

## Goals / Non-Goals

**Goals:**

- Tornar busca, visão persistida e filtros de escopo legíveis em painel estreito, sem rolagem horizontal.
- Dar acesso direto às visões operacionais mais usadas sem transformar cada filtro em um controle permanente.
- Aplicar múltiplos filtros de escopo de forma atômica em um construtor de regras compacto, reutilizando o debounce autoritativo existente.
- Centralizar a seleção sobre o avatar e exibir ações bulk apenas quando houver seleção.
- Unificar ações de linha e timeline em menus hierárquicos previsíveis, usando somente capacidades já autorizadas.
- Preservar virtualização, foco, touch, master-detail, preferências, deep-links e contratos públicos.

**Non-Goals:**

- Alterar filtros, ordenação, paginação ou operações bulk na API Laravel.
- Introduzir negação, grupos `OU`, operadores sem suporte autoritativo, views salvas, Pinia, SSR ou novos Shells globais.
- Copiar branding, wallpaper, listas pessoais ou ações de arquivar, silenciar, favoritar, bloquear, limpar e apagar da referência visual.
- Modificar `ShellScrollableTabs`, `ShellBulkActionBar`, OpenAPI, Wazync ou regras de autorização.

## Decisions

### Recompor o cabeçalho da lista como uma superfície de chat

O navbar mantém título, total autoritativo e “Nova conversa” como ação primária. Estado/sincronização e administração continuam autorizados, mas ações secundárias podem ser agrupadas em reticências sem remover o indicador de realtime. A busca passa a ocupar sozinha a primeira faixa abaixo do navbar, evitando compressão por ícones e deixando claro que atua sobre contato, telefone e mensagem.

A faixa seguinte adota a estrutura estável observada no `ChatTypeTabs` do Chatwoot: três tabs fixas, sem fundo de pill, com tipografia compacta e indicador inferior de 2 px. `Em aberto` aplica `status=OPEN`, `Não lidas` aplica `status=OPEN&unread=true` e `Não atribuídas` aplica `status=OPEN&unassigned=true`. Cada preset substitui atomicamente somente status, não lidas e sem responsável. A ordem nunca muda; em 320 px apenas o texto visual da última tab compacta para `Não atrib.`, mantendo o nome acessível completo. Quando o estado não corresponde exatamente a um preset, nenhuma tab é falsamente marcada.

Após as tabs ficam dois controles quadrados. `Status e ordenação` (`sliders-horizontal`) abre um `UPopover` com dois `USelectMenu`, preservando essas preferências sem criar selects largos permanentes. `Filtros avançados` (`list-filter`) abre outro `UPopover` com o construtor de regras e recebe badge quando há escopos aplicados. Os dois gatilhos têm tooltip/nome acessível e não compartilham um chevron ambíguo; o primeiro ancora por `end` e o editor avançado por `start`, ambos com ajuste de colisão. O estado aplicado aparece em até dois resumos e `+N`; o total autoritativo permanece no navbar, sem contagens inventadas por tab.

```text
[ Atendimento  26 • ]                            [ nova ] [ ⋮ ]
[ Buscar contato, telefone ou mensagem                         ]
[ Em aberto | Não lidas | Não atribuídas ]       [ ajustes ] [ filtro• ]
[ Contato: Maria ] [ Marcador: Fiscal ] [ +2 ]        (se ativos)
────────────────────────────────────────────────────────────────
 (avatar)  Contato                         horário      [ ⋮ ]
           telefone/contexto · preview · estado

Com seleção, a mesma faixa das tabs/resumo é substituída:

[ ✓ 3 selecionadas ]              [ leitura ] [ status ] [ ⋮ ] [ × ]
```

O popover avançado usa rascunho: abrir copia o estado aplicado, mudança externa da intenção/consulta ressincroniza o editor aberto, fechar por Escape/clique externo descarta, Limpar zera o rascunho e Aplicar emite todas as mudanças no mesmo tick. Cada regra possui campo, operador explícito e valor; regras incompletas mantêm o rascunho e bloqueiam Aplicar com feedback local. As regras válidas são combinadas por `E`. O contrato atual permite `Igual a` para inbox, responsável, fila e contato, `Contém qualquer` para marcadores e condições booleanas afirmativas para não lidas/sem responsável. Operadores de negação e grupos `OU` ficam indisponíveis até existirem na API, evitando paginação e totais localmente falsos.

Alternativas descartadas: recolocar ícones dentro da busca volta a comprimi-la; promover uma visão oculta para a terceira tab quebra memória espacial; reunir tudo em um chevron torna filtros e preferências indistinguíveis; três botões separados para status, ordenação e filtro aumentam ruído; usar modal interrompe uma tarefa contextual curta; aplicar cada campo imediatamente impede cancelamento previsível.

### Resumir estado aplicado sem autoabrir o editor

Filtros de escopo ativos aparecem em no máximo dois chips truncáveis e `+N`, priorizando contato, responsável, inbox, fila e marcadores. Não lidas ou sem responsável deixam de ser repetidos no resumo quando já estão representados pela tab ativa. O editor não abre automaticamente ao restaurar uma intenção ou retornar do detalhe; o resumo mantém estado adicional visível sem bloquear a lista.

### Sobrepor seleção no centro do avatar

O `UCheckbox` permanece irmão do botão que abre a conversa, mas ganha um wrapper circular `inset-0` centralizado sobre o avatar. Clique, toque, Space e Enter ficam isolados da abertura da linha e alteram somente a seleção. Hover do avatar, foco da linha, seleção e ponteiro coarse tornam o overlay operável; touch não depende de hover. A camada usa tokens semânticos e transparência suficiente para preservar a identidade visual da foto.

Alternativa descartada: manter o checkbox no canto preserva a foto, porém viola o contrato de centralização e cria desalinhamento visual; uma coluna permanente reduz espaço útil e diverge da referência de inbox.

### Alternar visões rápidas e seleção no mesmo espaço

A faixa permanente “Selecionar carregadas” permanece removida. Após a primeira seleção, uma faixa contextual substitui as tabs e o resumo no mesmo slot, sem adicionar altura ou deslocar a lista desnecessariamente. Ela reúne checkbox tri-state, contagem única, duas ações frequentes por ícone, reticências e limpeza. Estado parcial seleciona todas as linhas carregadas; estado total limpa a seleção, preservando a semântica atual.

As ações não usam `ShellBulkActionBar` neste workspace. `Leitura` (`mail-check`) e `Status` (`circle-fading-arrow-up`) permanecem diretas; reticências agrupam `Responsável` (`user-round-check`), `Fila` (`list-tree`) e `Marcadores` (`tags`) como categorias separadas. Ações que seriam no-op para todas as conversas selecionadas são omitidas. Todos os gatilhos iconográficos possuem tooltip e nome acessível.

Ao limpar pela barra, o foco retorna à primeira conversa antes selecionada que ainda esteja carregada; se não existir, retorna ao contêiner focável da lista. A entrada/saída usa uma transição curta e respeita movimento reduzido.

### Ancorar overlays conforme o gatilho

Cada overlay declara sua intenção conforme o conteúdo e o gatilho. `Status e ordenação` abre por `end`; `Filtros avançados` abre por `start` para posicionar o construtor a partir do seu gatilho e usa colisão para permanecer no viewport. Na faixa bulk, leitura e status abrem por `start`; reticências abrem por `end`. Menus de reticências da linha, navbar e timeline também usam `end`, pois seus gatilhos ocupam a borda da superfície.

Todos os overlays permanecem portalizados, abaixo do gatilho e com `collisionPadding` de 8 px. A detecção de colisão do Nuxt UI pode deslocar a posição ideal em painéis estreitos, mas não altera a intenção de ancoragem nem permite que o menu saia do viewport. Isso reproduz a lógica posicional observada na referência sem copiar offsets absolutos frágeis.

### Reutilizar um menu hierárquico de ações da conversa

Linha e cabeçalho da timeline passam a consumir o mesmo builder/componente de `DropdownMenuItem`, variando apenas ações elegíveis conforme o alvo e a permissão. A raiz apresenta no máximo um nível de submenus: `Status`, `Responsável`, `Fila` e `Marcadores`; leitura aparece como ação direta. Snooze de uma hora e até amanhã permanece dentro de `Status`, sem criar um terceiro nível. A ação de leitura mostra somente a mudança aplicável, o status atual é omitido, responsável/fila atuais são identificados e marcadores usam checkboxes.

Mutações unitárias usam um payload discriminado interno e reutilizam `updateConversation` para status, responsável e fila. Marcadores ganham uma variante target-aware do helper atual para operar sobre a conversa da linha sem trocar a conversa aberta nem usar seleção bulk. Itens sem autorização não são renderizados; nenhuma operação do WhatsApp de referência é simulada. O cabeçalho da timeline mantém avatar, nome, badge de status e contexto como identidade, removendo o select largo de status e o botão isolado de adiar em favor do menu compartilhado. O contexto usa `panel-right-open/close`.

As linhas preservam três sinais essenciais — identidade, contexto/telefone e preview — mas limitam badges concorrentes e reservam reticências para ações secundárias. Seleção continua centralizada no avatar; o menu e o botão de abertura permanecem irmãos sem interativos aninhados.

### Manter a mudança local ao workspace

`ConversationList` deixa de receber estado de seleção total e de emitir `toggle-select-all`; o pai já possui esses valores e controla a barra. O filtro de contato passa para o resumo/construtor de filtros. O toolbar e o componente removem padding lateral, ocupando a mesma largura das linhas de conversa sem alterar temas globais.

## Risks / Trade-offs

- **[Checkbox central pode ocultar parte da foto em touch]** → usar overlay tonal translúcido, controle de 16 px e validar foto/fallback nos dois temas.
- **[Ações iconográficas podem apertar o painel estreito]** → manter contador compacto, apenas duas ações diretas, botões quadrados e testar overflow da faixa em 320 px.
- **[Emissões múltiplas ao aplicar filtros podem recarregar várias vezes]** → emitir sincronamente; o `useDebounceFn` existente consolida a recarga em 250 ms e será coberto por teste de componente/workspace.
- **[Foco pode se perder quando a barra desaparece]** → capturar um ID selecionado antes de limpar, aguardar o DOM e usar `focusConversation`, com fallback no contêiner da lista.
- **[Popover de filtros pode exceder o painel estreito]** → portalizar, limitar ao viewport com collision padding e reorganizar cada regra avançada verticalmente abaixo de `sm`.
- **[Um alinhamento fixo pode afastar o menu de seu ícone ou cortar conteúdo]** → definir `start`/`end` por posição no grupo e manter a correção automática de colisão em 320 px.
- **[Tabs e dois ícones podem criar overflow]** → manter ordem fixa, compactar somente o rótulo visual de `Não atribuídas`, reduzir padding responsivamente e medir 320/390 px sem scrollbar ou quebra de linha.
- **[A referência mostra contadores que a API não fornece por visão]** → manter apenas o total autoritativo da consulta no navbar e não derivar totais dos itens carregados.
- **[Submenus muito profundos pioram teclado e touch]** → limitar a um nível, manter ações de snooze no submenu de status e validar setas, Escape e restauração de foco.
- **[Menu compartilhado pode divergir entre linha e timeline]** → concentrar itens e autorização em um builder único, com adapters de mutação target-aware.

## Migration Plan

Implementação somente frontend, sem migração de dados ou rollout por flag. Rollback consiste em reverter os componentes e testes deste change. As chaves existentes `inbox_id`, `assignee_membership_id`, `work_department_id`, `label_ids`, `unassigned`, `unread` e `contact_id` não mudam; estado de sessão → interface → request HTTP preserva valores e semântica, enquanto a URL permanece canônica e status/ordenação continuam nas preferências atuais.

## Open Questions

Nenhuma.
