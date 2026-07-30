## Context

`refactor-communication-conversation-workspace` está construindo a base autoritativa de nomes, não lidas, timeline cursorizada e lista compacta. A SPA já mantém um master–detail com deep-link, painel redimensionável, detalhe desktop e `USlideover` mobile; a API já filtra por inbox, status, responsável, departamento, sem responsável, não lidas e busca, e oferece mutações unitárias versionadas para triagem, read-state e rótulos.

Faltam seleção múltipla, exposição completa desses filtros, ordenação escolhida pelo operador e um contrato de lote. A referência Chatwoot separa consulta, seleção e bulk action, seleciona somente itens carregados, virtualiza a lista e executa lotes em background. O KontiveHub adotará essas interações, mas não copiará o `200` sem resultado, o skip silencioso de itens sem acesso nem a ausência de idempotência e observabilidade.

## Goals / Non-Goals

**Goals:**

- Permitir seleção acessível de uma ou várias conversas carregadas sem conflar essa coleção com a conversa aberta no detalhe.
- Oferecer filtros e ordenações operacionais estáveis, preservados durante a navegação, com preferência de status/ordenação por usuário e tenant.
- Executar ações em lote sem limite funcional de seleção, em chunks tenant-safe, idempotentes, observáveis e seguras para retry.
- Reutilizar as invariantes das ações unitárias e manter read-state local separado de receipts WhatsApp.
- Preservar o arquétipo Inbox, responsividade, deep-link, teclado, foco e estados reais da lista.

**Non-Goals:**

- Selecionar todas as conversas que correspondem a uma consulta ainda não carregada.
- Criar filtros compostos AND/OR, views salvas, layout expandido alternativo, exclusão ou prioridade em massa.
- Alterar timeline, composer, administração de contatos, flows, Wazync ou o contrato privado Laravel–Wazync.
- Enviar receipts, mensagens ou qualquer outro egress WhatsApp como efeito de uma ação de lista.

## Decisions

### O change depende da base do workspace e não a redefine

A implementação começará depois de concluídas as partes relevantes de `refactor-communication-conversation-workspace`. Este change consumirá a projeção compacta, o `lock_version`, o read-state versionado, a canonicalização e os eventos existentes; não manterá uma segunda regra de nome, preview, unread ou timeline.

Alternativa considerada: ampliar o change ativo. Foi rejeitada para não mover a fronteira de uma implementação já iniciada nem misturar identidade/read-state com operações de fila.

### Filtros evoluem de forma aditiva e a preferência é tenant-scoped

`GET /api/v1/communication/conversations` manterá parâmetros, envelopes e ordenação default existentes e ganhará:

- `label_ids[]`, com semântica OR entre rótulos válidos do tenant;
- `sort_by=last_activity_desc|last_activity_asc|created_desc|created_asc|unread_desc|priority_desc|priority_asc`, sempre com desempate determinístico por `id`.

O frontend continuará enviando inbox, status, responsável, departamento, `unassigned`, `unread`, `q` e paginação pelo cliente canônico. Filtros de escopo não sensíveis permanecerão na query da rota ao abrir/fechar o detalhe; `q` ficará somente em memória para não registrar nomes, telefones ou conteúdo no histórico do navegador.

`GET` e `PUT /api/v1/communication/conversation-list-preferences` persistirão somente `status` (`ALL` ou um status válido) e `sort_by` por `(tenant_id,user_id)`. Ausência de registro produzirá `OPEN` e `last_activity_desc` para a UI, enquanto clientes que não enviam `sort_by` conservarão a resposta anterior da API. Last-write-wins é suficiente para essa preferência não crítica.

Alternativa considerada: armazenar toda a consulta na URL ou em `saved_list_filters`. Foi rejeitada porque busca pode conter PII e views salvas/AND-OR ficaram fora do escopo.

### Seleção operacional é um snapshot explícito dos itens carregados

O composable manterá `selectedConversationId` separado de um `Set<number>` de IDs operacionais. O checkbox da linha não abrirá o detalhe; o restante da linha continuará abrindo a rota da conversa. “Selecionar carregadas” substituirá o conjunto pelos IDs presentes na coleção carregada naquele momento. Uma página carregada depois não será selecionada automaticamente.

Busca, filtro, ordenação, tenant ou escopo de inbox limparão a seleção; refresh/realtime e carregamento de nova página preservarão apenas IDs que ainda pertencem à coleção atual. Ações unitárias ficarão no menu da linha e usarão os endpoints existentes. A barra bulk ficará dentro do painel mestre, com contagem, limpar e ações permitidas.

A lista usará linha de altura estável, virtualização e o sentinel incremental existente, mantendo controle de retry acessível. Checkbox, botão de abertura e menu serão controles irmãos, evitando elementos interativos aninhados; touch não dependerá de hover.

### Bulk actions são operações persistidas e não jobs fire-and-forget

`POST /api/v1/communication/conversation-bulk-operations`, protegido por `Idempotency-Key`, aceitará uma ação por operação:

- `SET_STATUS`, com `status` e `snoozed_until` quando necessário;
- `SET_ASSIGNEE` ou `SET_DEPARTMENT`, aceitando `null` para remover;
- `ADD_LABELS` ou `REMOVE_LABELS`, com IDs allowlisted;
- `MARK_READ` ou `MARK_UNREAD`.

O body conterá `items[]` distintos com `conversation_id` e os snapshots exigidos pela ação: `lock_version` para triagem, `through_message_id` para READ e `read_state_version` para UNREAD. Não haverá cap funcional no cliente ou na validação de quantidade; limites normais de body e rate limit continuam valendo. O backend materializará os itens em chunks antes de responder `202` com uma Resource da operação.

`communication_conversation_bulk_operations` guardará tenant, ator real, membership opcional, modo de acesso, chave/digest idempotente, ação, parâmetros sanitizados, estado, contadores e timestamps. `communication_conversation_bulk_operation_items` guardará inbox/conversation, snapshots, estado, tentativas e código seguro de resultado. Repetição da mesma chave e digest retornará a operação existente; a mesma chave com outro digest retornará `409 IDEMPOTENCY_KEY_REUSED`.

Estados da operação serão `QUEUED`, `RUNNING`, `COMPLETED`, `COMPLETED_WITH_ERRORS` e `FAILED`; itens usarão `QUEUED`, `PROCESSING`, `SUCCEEDED`, `SKIPPED` e `FAILED`. `GET /conversation-bulk-operations/{operation}` exporá os agregados e `GET /conversation-bulk-operations/{operation}/items` paginará resultados filtráveis por estado. Somente o solicitante ou usuário com administração de Communication no tenant poderá consultá-los.

Alternativa considerada: chamar o endpoint unitário em loop na SPA. Foi rejeitada por produzir rajadas HTTP, retries ambíguos, autorização divergente e ausência de resultado durável.

### A execução é parcial explícita, transacional por item e segura para retry

A criação validará que todos os IDs pertencem ao tenant, são canônicos ou resolvíveis e estão visíveis ao ator; qualquer ID inválido rejeitará a submissão inteira com código genérico, sem indicar recursos de outro tenant. A operação e seus itens serão commitados antes de dispatch para a fila `communication`.

O job receberá somente o ID da operação, ligará `CurrentTenant` ao ator e modo de acesso registrados, revalidará usuário/membership/permissão e processará até 100 itens por execução. Havendo itens pendentes, redispatchará a continuação após commit. Cada item será bloqueado e aplicado em sua própria transação junto com estado e evento, de modo que retry nunca repita um item terminal.

- Triagem exigirá o `lock_version` capturado e reutilizará as mesmas validações de responsável, departamento, status e snooze da ação unitária.
- READ removerá somente pendências até `through_message_id`; inbound posterior permanecerá não lida.
- UNREAD exigirá o `read_state_version` capturado e preservará a semântica otimista da capability base.
- Add/remove label será idempotente e passará a emitir atualização realtime também no caminho unitário.
- Merge ocorrido depois da submissão resolverá o survivor; itens que convergirem para o mesmo survivor serão deduplicados dentro da operação. Conversa purgada, versão divergente ou permissão revogada produzirá falha/skip explícito, nunca alteração silenciosa.

Não haverá transação abrangendo o lote: um erro permanente em uma conversa não desfará itens anteriores. Falha transitória relançará o job com backoff; exaustão marcará a operação `FAILED` e manterá o resultado já confirmado.

### Autorização e observabilidade pertencem ao Laravel

MARK_READ/MARK_UNREAD exigirão `CommunicationView`; status, snooze, responsável, departamento e rótulos exigirão `CommunicationReply`. O frontend apenas oculta/desabilita controles; o Laravel reautoriza na submissão e imediatamente antes de cada mutação. Contexto privilegiado continuará tipado, com ator real e auditoria, sem membership fabricada.

Jobs terão tags Horizon de baixa cardinalidade, retry/backoff/timeout finitos e logs sanitizados com operation ID, contagens e códigos. Eventos por conversa sairão após commit e farão a lista reconciliar pelo sync/Reverb existente. A SPA acompanhará a Resource da operação: erro de submissão mantém a seleção; `202` limpa a seleção e mostra “ação agendada”; conclusão total, parcial ou falha produz feedback final e refresh autoritativo.

Operações terminais e itens serão retidos por 30 dias. Um comando diário, protegido por `withoutOverlapping` e `onOneServer`, removerá somente registros expirados; `CommunicationEvent` de auditoria não será apagado por esse GC.

## Risks / Trade-offs

- [Lote muito grande excede o request HTTP] → a UI não impõe cap funcional, mas informa o total e conserva o erro real; o job processa o snapshot aceito em chunks sem transação longa.
- [Mudança concorrente após a seleção] → versões capturadas convertem overwrite em falha explícita por item e a UI recarrega o estado autoritativo.
- [Permissão é revogada após o enqueue] → cada item é reautorizado no contexto registrado e termina sem mutação.
- [Realtime remove linhas durante seleção] → consulta e seleção ficam separadas; somente IDs ainda carregados/compatíveis permanecem selecionados antes da submissão.
- [Virtualização quebra foco ou deep-link] → linhas têm chave/altura estáveis, o item ativo é rolado para a viewport e testes cobrem teclado, retorno de foco e mobile.
- [Preferência falha ao salvar] → o filtro atual continua válido na sessão, a UI informa a falha e não inventa persistência.
- [Operação parcial surpreende o usuário] → `202` não é apresentado como conclusão; contadores e itens falhos permanecem consultáveis e o toast final distingue sucesso parcial.

## Migration Plan

1. Concluir e validar a base necessária de `refactor-communication-conversation-workspace`.
2. Aplicar migrations aditivas de preferências, operações e itens; publicar a API, OpenAPI e job com o frontend antigo ainda compatível.
3. Confirmar que Horizon consome a fila `communication` e publicar o scheduler de retenção.
4. Publicar o Nuxt com filtros, seleção, barra bulk, virtualização e acompanhamento de operações.
5. Monitorar duração, falhas, retries, conflitos de versão, idade da fila e eventos usando somente IDs/códigos sanitizados.

Rollback do frontend remove os novos controles sem afetar os endpoints. Operações aceitas continuam até estado terminal. Após uso real, tabelas e contratos permanecem para roll-forward; o rollback não apaga estado operacional nem eventos de auditoria.

## Open Questions

Nenhuma bloqueante.
