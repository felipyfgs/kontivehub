## MODIFIED Requirements

### Requirement: Resultado e realtime reconciliam a lista sem sucesso antecipado

`GET /api/v1/communication/conversation-bulk-operations/{operation}` SHALL expor estado e contadores; o endpoint `/items` SHALL paginar resultados filtráveis por estado. Somente o solicitante ou usuário autorizado a administrar Communication no tenant SHALL consultá-los.

A SPA SHALL manter a seleção se a submissão falhar, SHALL limpá-la somente após o `202` e SHALL apresentar esse momento como “ação agendada”, não como concluída. Conclusão total, parcial ou falha SHALL gerar feedback distinto e refresh autoritativo; eventos por conversa SHALL conter apenas IDs internos, contagens, versões e códigos allowlisted. Timeout ou erro de polling SHALL executar reconciliação best-effort antes de liberar o estado pendente e SHALL NOT afirmar que a operação falhou ou concluiu sem evidência. Ações unitárias de status SHALL reutilizar a mesma mutação/reconciliação central do workspace.

#### Scenario: Operação termina parcialmente
- **WHEN** alguns itens têm sucesso e outros falham
- **THEN** a UI informa ambos os totais, permite consultar os itens falhos e reconcilia a lista com a API/realtime

#### Scenario: Erro inicial de listagem ou bulk
- **WHEN** carregar a lista ou criar a operação falha
- **THEN** a UI mostra erro e retry sem transformar a falha em lista vazia ou sucesso

#### Scenario: Polling termina sem estado terminal
- **WHEN** a consulta da operação falha ou excede o tempo local
- **THEN** a SPA recarrega a lista autoritativa em best-effort, libera o indicador e informa resultado desconhecido sem reverter mudanças remotas

#### Scenario: Status unitário altera filtros
- **WHEN** a ação de uma linha muda status, snooze ou versão da conversa
- **THEN** o workspace aplica o método central, trata conflito e reconcilia seleção, detalhe, filtro e lista uma única vez
