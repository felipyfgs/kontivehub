# communication-conversation-list-operations Specification

## Purpose

Definir filtros, seleção e operações em lote idempotentes para a lista de conversas.

## Requirements

### Requirement: A lista oferece filtros e ordenação operacionais aditivos

`GET /api/v1/communication/conversations` SHALL preservar os parâmetros, envelope, paginação e ordenação default existentes e SHALL aceitar `label_ids[]` e `sort_by` allowlisted. Múltiplos rótulos SHALL usar semântica OR; toda ordenação SHALL possuir desempate determinístico por ID.

Os filtros disponíveis na SPA SHALL cobrir busca, inbox, status, não lidas, responsável, sem responsável, departamento e rótulos, sem criar dados sintéticos quando a API falhar.

#### Scenario: Filtro por múltiplos rótulos
- **WHEN** o usuário filtra por dois rótulos válidos do tenant
- **THEN** a API retorna conversas autorizadas que possuam pelo menos um deles e não considera rótulos de outro tenant

#### Scenario: Ordenação estável
- **WHEN** várias conversas empatam no campo escolhido em `sort_by`
- **THEN** a paginação mantém ordem determinística por ID sem duplicar nem omitir linhas

#### Scenario: Cliente anterior não informa sort
- **WHEN** a listagem é chamada sem `label_ids` e sem `sort_by`
- **THEN** o contrato e a ordenação default anteriores permanecem compatíveis

### Requirement: Status e ordenação são preferências por usuário e tenant

`GET` e `PUT /api/v1/communication/conversation-list-preferences` SHALL expor e persistir somente status e ordenação por `(tenant_id,user_id)`. Na ausência de preferência, a SPA SHALL usar status `OPEN` e ordenação `last_activity_desc`.

Busca, seleção e identificadores de conversa SHALL NOT ser gravados nessa preferência. A API SHALL resolver o tenant por `CurrentTenant` e SHALL NOT aceitar `tenant_id` do cliente.

#### Scenario: Preferências independentes entre tenants
- **WHEN** o mesmo usuário escolhe preferências diferentes em dois tenants
- **THEN** cada workspace restaura somente a preferência do tenant corrente

#### Scenario: Falha ao salvar preferência
- **WHEN** o PUT da preferência falha
- **THEN** a SPA mantém o filtro válido na sessão, informa a falha e não afirma que ele foi persistido

### Requirement: Seleção operacional usa somente conversas carregadas

A SPA SHALL manter a conversa aberta no detalhe separada do conjunto de IDs selecionados para operações. “Selecionar carregadas” SHALL selecionar exatamente as conversas presentes na coleção carregada naquele instante e SHALL NOT representar o resultado filtrado inteiro.

Alterar busca, filtro, ordenação, inbox ou tenant SHALL limpar a seleção. Carregar outra página SHALL preservar os IDs anteriores, mas SHALL NOT selecionar automaticamente as novas linhas. O checkbox da linha SHALL ficar centralizado sobre o avatar e SHALL permanecer um controle separado do botão de abertura e do menu.

#### Scenario: Selecionar todas as carregadas
- **WHEN** existem 50 conversas carregadas e o usuário aciona “Selecionar carregadas”
- **THEN** exatamente esses 50 IDs entram na seleção, independentemente do total informado pela paginação

#### Scenario: Nova página é carregada
- **WHEN** uma página adicional chega depois de todas as linhas anteriores terem sido selecionadas
- **THEN** as novas linhas permanecem desmarcadas e o controle de seleção passa ao estado indeterminado

#### Scenario: Checkbox não abre o detalhe
- **WHEN** o usuário alterna o checkbox sobre o avatar por clique ou teclado
- **THEN** somente a seleção operacional muda e a rota/conversa aberta permanecem inalteradas

#### Scenario: Checkbox permanece descobrível
- **WHEN** a linha recebe hover/foco, está selecionada ou usa dispositivo sem hover
- **THEN** o overlay central fica visível com label acessível e alvo operável

### Requirement: Linhas e barra contextual oferecem ações autorizadas

Cada linha SHALL oferecer um menu contextual para ações unitárias e a lista SHALL mostrar uma barra contextual quando houver seleção. As ações disponíveis SHALL incluir status/reabertura/snooze, leitura local, responsável, departamento e adição/remoção de rótulos.

A SPA SHALL esconder ou desabilitar ações incompatíveis com a permissão e com o estado conhecido, mas o Laravel SHALL permanecer a autoridade final. Exclusão, prioridade em massa e efeitos remotos SHALL NOT fazer parte da barra.

#### Scenario: Seleção possui várias conversas
- **WHEN** ao menos duas conversas estão selecionadas
- **THEN** a barra exibe contagem, limpar seleção e somente as ações bulk autorizadas

#### Scenario: Usuário possui apenas permissão de leitura
- **WHEN** um usuário com `CommunicationView` e sem `CommunicationReply` seleciona conversas
- **THEN** ele pode solicitar READ/UNREAD, mas não status, responsável, departamento ou rótulos

### Requirement: Bulk action cria uma operação idempotente e rastreável

`POST /api/v1/communication/conversation-bulk-operations` SHALL aceitar uma única ação, parâmetros allowlisted e `items[]` distintos com snapshots de concorrência. A chamada SHALL exigir `Idempotency-Key`, persistir a operação e seus itens antes do dispatch e responder `202` com um identificador e contadores iniciais.

Repetir chave e payload idênticos SHALL retornar a mesma operação; reutilizar a chave com payload diferente SHALL retornar `409 IDEMPOTENCY_KEY_REUSED`. Não haverá cap funcional de quantidade, mas rate limit e limites normais do request permanecerão aplicáveis.

#### Scenario: Retry HTTP idempotente
- **WHEN** o cliente repete a mesma submissão após perder a resposta
- **THEN** a API retorna a operação já criada e nenhum item ou job adicional é materializado

#### Scenario: Lote excede cem itens
- **WHEN** uma seleção válida contém mais de cem conversas carregadas
- **THEN** a API aceita o snapshot e o processamento o divide em chunks sem exigir múltiplas operações do cliente

#### Scenario: Seleção contém ID não autorizado
- **WHEN** qualquer ID não pertence ao tenant ou não está visível ao ator
- **THEN** a submissão inteira falha com código genérico, sem criar operação nem revelar o recurso rejeitado

### Requirement: Execução bulk é parcial explícita e segura para retry

A operação SHALL processar cada item em transação própria, no contexto tenant/ator registrado, reautorizando imediatamente antes da mutação. Item terminal SHALL NOT ser reaplicado em retry. Falha permanente em um item SHALL NOT desfazer sucessos anteriores e SHALL aparecer nos contadores e resultados paginados.

Triagem SHALL respeitar `lock_version`; labels SHALL ser idempotentes; conversa purgada SHALL falhar; IDs redirecionados SHALL resolver a conversa canônica e alvos que convergirem para o mesmo survivor SHALL produzir no máximo uma mutação por operação.

#### Scenario: Membership é revogada após o enqueue
- **WHEN** o ator perde acesso à inbox antes de o job processar um item
- **THEN** o item termina sem mutação com código seguro de autorização e os demais itens continuam conforme suas próprias permissões

#### Scenario: Versão de triagem diverge
- **WHEN** uma conversa muda depois da seleção e seu `lock_version` não corresponde ao snapshot
- **THEN** o item falha com conflito de versão e o estado concorrente não é sobrescrito

#### Scenario: Job é repetido após commit do item
- **WHEN** um retry encontra um item já `SUCCEEDED`, `SKIPPED` ou `FAILED` permanente
- **THEN** nenhum estado, evento ou contador desse item é duplicado

### Requirement: READ e UNREAD em lote preservam o ledger local

MARK_READ SHALL remover somente pendências até o `through_message_id` capturado. MARK_UNREAD SHALL exigir o `read_state_version` capturado e SHALL usar a mesma semântica otimista da ação unitária. Nenhuma das ações SHALL alterar receipts do provider, enfileirar `MESSAGE_MARK` ou produzir egress WhatsApp.

#### Scenario: Inbound chega durante MARK_READ
- **WHEN** uma nova inbound é commitada depois do snapshot usado pelo lote
- **THEN** a nova mensagem permanece no ledger como não lida

#### Scenario: Versão diverge em MARK_UNREAD
- **WHEN** o read-state muda antes do processamento do item
- **THEN** o item falha com conflito e a contagem autoritativa não é inventada pela SPA

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

### Requirement: Lista incremental permanece acessível e observável

A lista SHALL virtualizar linhas carregadas e carregar páginas adicionais por sentinel, preservando fallback/retry, fim da lista, item ativo e scroll. Avatar/checkbox, abertura e menu SHALL ser controles semanticamente separados, operáveis por teclado e touch; nenhuma seleção SHALL depender exclusivamente de hover. O overlay SHALL manter a altura estável da linha e não aninhar controles interativos.

Jobs SHALL executar na fila `communication`, com retry/backoff/timeout finitos e tags Horizon. Logs e métricas SHALL NOT conter nome, telefone, endereço, JID, busca ou conteúdo de mensagem. Operações terminais SHALL ser removidas após 30 dias por rotina singleton sem apagar `CommunicationEvent` de auditoria.

#### Scenario: Navegação por teclado em lista virtualizada
- **WHEN** o usuário navega, seleciona, abre e fecha conversas por teclado
- **THEN** o item correto entra na viewport, o foco retorna ao controle previsto e atalhos não atuam dentro do composer

#### Scenario: Touch não depende de hover
- **WHEN** a lista é usada com ponteiro coarse
- **THEN** o controle central de seleção permanece visível e não desloca avatar, texto ou ações

#### Scenario: Retenção é executada
- **WHEN** a rotina diária encontra operações terminais expiradas
- **THEN** remove operações e itens elegíveis uma única vez sem tocar operações ativas ou eventos de auditoria
