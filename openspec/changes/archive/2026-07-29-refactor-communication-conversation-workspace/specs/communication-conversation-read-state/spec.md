## ADDED Requirements

### Requirement: Ledger compartilhado é a fonte autoritativa
`communication_conversation_unread_messages` SHALL conter uma linha por mensagem atualmente não lida, única por tenant, conversation e message. `communication_conversation_read_states` SHALL manter `version`, `last_read_through_message_id` e autoria/auditoria.

O ledger SHALL iniciar vazio no rollout; portanto conversas já existentes começam lidas. `CommunicationMessage.read_at`, `delivered_at` e `played_at` SHALL continuar sendo exclusivamente receipts do provider.

#### Scenario: Inbound live cria pendência
- **WHEN** a primeira materialização de uma inbound live é commitada
- **THEN** a linha do ledger é criada na mesma transação e a versão avança

#### Scenario: Histórico não cria pendência
- **WHEN** uma inbound é materializada por history sync
- **THEN** nenhuma linha de unread é criada

#### Scenario: Retry não recria pendência removida
- **WHEN** uma mensagem já materializada é reprocessada após sua pendência ter sido removida
- **THEN** o retry não recria a linha do ledger

### Requirement: READ remove somente o snapshot consumido
`PUT /conversations/{id}/read-state` com `state=READ` SHALL remover do ledger apenas mensagens até `through_message_id`, avançar `last_read_through_message_id` monotonicamente e incrementar a versão somente quando o estado mudar.

#### Scenario: Inbound concorrente permanece não lida
- **WHEN** o cliente lê até o snapshot 1842 e uma inbound com ID maior é commitada concorrentemente
- **THEN** a pendência de ID maior permanece no ledger

#### Scenario: Retry idempotente
- **WHEN** o mesmo READ é repetido
- **THEN** nenhuma pendência reaparece, o cursor não regride e o resultado permanece estável

#### Scenario: Receipt do provider não é alterado
- **WHEN** READ local é aplicado
- **THEN** nenhum `read_at`, `played_at`, status de outbound ou comando `MESSAGE_MARK` é alterado

### Requirement: UNREAD é otimista e insere somente a última inbound
`UNREAD` SHALL exigir `expected_version`. Se a conversa estiver lida, SHALL inserir somente a inbound disponível mais recente, inclusive histórica. Se a versão divergir, a API SHALL retornar `409 READ_STATE_VERSION_CONFLICT`.

#### Scenario: Marcar conversa lida como não lida
- **WHEN** o ledger está vazio e existe inbound histórica ou live
- **THEN** somente a inbound mais recente é inserida e a versão avança

#### Scenario: Conflito de versão
- **WHEN** `expected_version` difere da versão atual
- **THEN** a operação não muda o ledger e retorna `409 READ_STATE_VERSION_CONFLICT`

#### Scenario: Conversa já não lida
- **WHEN** o ledger já contém pendências e a versão esperada é atual
- **THEN** UNREAD é idempotente e não substitui nem reduz o conjunto existente

### Requirement: Revoke, purge e merge preservam exatidão
Revoke e purge SHALL remover as respectivas pendências. Merge PN↔LID SHALL mover a união exata do ledger para a conversa sobrevivente e consolidar o read-state com nova versão.

#### Scenario: Revoke da única pendência
- **WHEN** a única mensagem não lida é revogada
- **THEN** sua linha é removida e `unread_count` passa a zero

#### Scenario: Merge com lacunas
- **WHEN** duas conversas possuem subconjuntos não contíguos de mensagens pendentes
- **THEN** a sobrevivente mantém exatamente a união desses IDs, sem inferir pendências por watermark

### Requirement: Realtime publica estado sanitizado após commit
Cada mudança do ledger SHALL publicar `conversation.read_state.updated` após commit no canal da inbox para usuários autorizados, contendo somente IDs internos, contador e versão.

#### Scenario: Evento seguro
- **WHEN** o read-state muda
- **THEN** o evento não contém nome, telefone, JID, endereço nem corpo de mensagem

### Requirement: Confirmação remota após outbound é efeito independente
Um outbound humano externo MAY informar `receipt_message_id` de uma inbound exibida da mesma conversa. Somente após o outbound ser aceito SHALL um efeito idempotente de receipt WhatsApp ser liberado. Falha posterior SHALL NOT desfazer o outbound e SHALL ser retentada/observada separadamente. Nota interna SHALL NOT liberar receipt.

#### Scenario: Outbound aceito libera receipt uma vez
- **WHEN** o gateway aceita um outbound humano com `receipt_message_id` válido
- **THEN** exatamente um efeito de confirmação remota é enfileirado

#### Scenario: Falha do receipt não reverte envio
- **WHEN** o receipt falha após o outbound aceito
- **THEN** o outbound permanece aceito e somente o efeito de receipt entra em retry/falha observável

#### Scenario: Ação manual permanece separada
- **WHEN** o operador usa a ação “Enviar confirmação de leitura no WhatsApp”
- **THEN** a rota manual existente envia o receipt sem alterar o read-state local
