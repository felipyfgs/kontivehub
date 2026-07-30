## Context

O workspace de Communication já preserva status e ordenação como preferências por usuário/tenant, enquanto inbox, responsável, fila, marcadores, não lidas, sem responsável e contato vivem na URL e no estado do workspace. A UI atual não reflete essa distinção: cinco tabs criam scroll horizontal, controles largos comprimem a busca e a seleção bulk mantém uma faixa permanente acima da lista. A lista é virtualizada e ocupa um `UDashboardPanel` redimensionável de 20–32%, portanto a solução deve responder à largura do painel sem alterar API ou paginação.

## Goals / Non-Goals

**Goals:**

- Tornar busca, visão persistida e filtros de escopo legíveis em painel estreito, sem rolagem horizontal.
- Aplicar múltiplos filtros de escopo de forma atômica em um construtor de regras compacto, reutilizando o debounce autoritativo existente.
- Centralizar a seleção sobre o avatar e exibir ações bulk apenas quando houver seleção.
- Preservar virtualização, foco, touch, master-detail, preferências, deep-links e contratos públicos.

**Non-Goals:**

- Alterar filtros, ordenação, paginação ou operações bulk na API Laravel.
- Introduzir negação, grupos `OU`, operadores sem suporte autoritativo, views salvas, Pinia, SSR ou novos Shells globais.
- Modificar `ShellScrollableTabs`, `ShellBulkActionBar`, OpenAPI, Wazync ou regras de autorização.

## Decisions

### Separar busca, visão persistida e escopo operacional

Busca permanece direta e divide uma única faixa com três botões quadrados: status e ordenação abrem `UDropdownMenu` independentes; filtros avançados abrem um `UPopover` ancorado e portalizado. Nenhum gatilho exibe um select largo ou rótulo visual permanente; nomes acessíveis, estado tonal e tooltips nativos preservam descoberta e contexto.

O popover avançado usa rascunho: abrir copia o estado aplicado, mudança externa da URL/consulta ressincroniza o editor aberto, fechar por Escape/clique externo descarta, Limpar zera o rascunho e Aplicar emite todas as mudanças no mesmo tick. Cada regra possui campo, operador explícito e valor; regras incompletas mantêm o rascunho e bloqueiam Aplicar com feedback local. As regras válidas são combinadas por `E`. O contrato atual permite `Igual a` para inbox, responsável, fila e contato, `Contém qualquer` para marcadores e condições booleanas afirmativas para não lidas/sem responsável. Operadores de negação e grupos `OU` ficam indisponíveis até existirem na API, evitando paginação e totais localmente falsos.

Alternativas descartadas: manter tabs em duas linhas aumenta altura e ruído; trocar tabs por um select largo continua comprimindo o painel; usar modal interrompe uma tarefa contextual curta; aplicar cada campo imediatamente impede cancelamento previsível.

### Resumir estado aplicado sem autoabrir o editor

Filtros de escopo ativos aparecem em no máximo dois chips truncáveis e `+N`, priorizando contato, não lidas, responsável/sem responsável, inbox, fila e marcadores. O editor não abre automaticamente em deep-link; o resumo mantém o estado visível sem bloquear a lista.

### Sobrepor seleção no centro do avatar

O `UCheckbox` permanece irmão do botão que abre a conversa, mas ganha um wrapper circular `inset-0` centralizado sobre o avatar. Clique, toque, Space e Enter ficam isolados da abertura da linha e alteram somente a seleção. Hover do avatar, foco da linha, seleção e ponteiro coarse tornam o overlay operável; touch não depende de hover. A camada usa tokens semânticos e transparência suficiente para preservar a identidade visual da foto.

Alternativa descartada: manter o checkbox no canto preserva a foto, porém viola o contrato de centralização e cria desalinhamento visual; uma coluna permanente reduz espaço útil e diverge da referência de inbox.

### Tornar seleção bulk contextual no topo da lista

A faixa permanente “Selecionar carregadas” é removida. Após a primeira seleção, uma faixa contextual entra no fluxo logo abaixo dos filtros e reúne checkbox tri-state, contagem única, menus de ação por ícone e limpeza. Estado parcial seleciona todas as linhas carregadas; estado total limpa a seleção, preservando a semântica atual. Como a faixa ocupa espaço real acima da lista, não exige padding artificial nem pode cobrir a última linha ou o carregador incremental.

As ações não usam `ShellBulkActionBar` neste workspace. Quatro gatilhos iconográficos tornam as categorias reconhecíveis sem um menu genérico: leitura, status, responsável e organização (fila/marcadores). Cada gatilho mantém nome acessível, título e submenu próprio; permissões existentes controlam sua presença.

Ao limpar pela barra, o foco retorna à primeira conversa antes selecionada que ainda esteja carregada; se não existir, retorna ao contêiner focável da lista. A entrada/saída usa uma transição curta e respeita movimento reduzido.

### Ancorar overlays conforme a posição do gatilho

Dropdowns em grupos compactos não compartilham uma ancoragem única. Em LTR, o primeiro trecho do grupo abre por `start` para manter relação espacial com o ícone; o trecho final abre por `end` para preservar a borda direita. Nos filtros, status usa `start`, ordenação usa `end` e o popover avançado, por ser o último e mais largo, usa `end`. Na faixa bulk, leitura e status usam `start`; responsável e organização usam `end`. O menu de reticências de cada conversa continua em `end`, pois seu gatilho já ocupa a borda da linha.

Todos os overlays permanecem portalizados, abaixo do gatilho e com `collisionPadding` de 8 px. A detecção de colisão do Nuxt UI pode deslocar a posição ideal em painéis estreitos, mas não altera a intenção de ancoragem nem permite que o menu saia do viewport. Isso reproduz a lógica posicional observada na referência sem copiar offsets absolutos frágeis.

### Manter a mudança local ao workspace

`ConversationList` deixa de receber estado de seleção total e de emitir `toggle-select-all`; o pai já possui esses valores e controla a barra. O filtro de contato passa para o resumo/construtor de filtros. O toolbar e o componente removem padding lateral, ocupando a mesma largura das linhas de conversa sem alterar temas globais.

## Risks / Trade-offs

- **[Checkbox central pode ocultar parte da foto em touch]** → usar overlay tonal translúcido, controle de 16 px e validar foto/fallback nos dois temas.
- **[Ações iconográficas podem apertar o painel estreito]** → manter contador compacto, rótulo visual responsivo, botões quadrados e testar overflow da faixa em 320 px.
- **[Emissões múltiplas ao aplicar filtros podem recarregar várias vezes]** → emitir sincronamente; o `useDebounceFn` existente consolida a recarga em 250 ms e será coberto por teste de componente/workspace.
- **[Foco pode se perder quando a barra desaparece]** → capturar um ID selecionado antes de limpar, aguardar o DOM e usar `focusConversation`, com fallback no contêiner da lista.
- **[Popover avançado pode exceder o painel estreito]** → portalizar, limitar ao viewport com collision padding e reorganizar cada regra verticalmente abaixo de `sm`.
- **[Um alinhamento fixo pode afastar o menu de seu ícone ou cortar conteúdo]** → definir `start`/`end` por posição no grupo e manter a correção automática de colisão em 320 px.

## Migration Plan

Implementação somente frontend, sem migração de dados ou rollout por flag. Rollback consiste em reverter os componentes e testes deste change. As chaves existentes `inbox_id`, `assignee_membership_id`, `work_department_id`, `label_ids`, `unassigned`, `unread` e `contact_id` não mudam; o round-trip URL → estado → URL preserva valores e semântica, enquanto status e ordenação continuam fora da query e nas preferências atuais.

## Open Questions

Nenhuma.
