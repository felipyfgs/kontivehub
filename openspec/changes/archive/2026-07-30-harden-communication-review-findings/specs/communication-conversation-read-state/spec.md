## MODIFIED Requirements

### Requirement: UNREAD é otimista e insere somente a última inbound
`UNREAD` SHALL exigir `expected_version`. Se a conversa estiver lida, SHALL inserir somente a inbound disponível mais recente, inclusive histórica, que permaneça visível no workspace. Mensagens ausentes, expurgadas, revogadas ou quarentenadas SHALL NOT manter nem recriar pendência visível. Se a versão divergir, a API SHALL retornar `409 READ_STATE_VERSION_CONFLICT`.

#### Scenario: Marcar conversa lida como não lida
- **WHEN** o ledger está vazio e existe inbound histórica ou live
- **THEN** somente a inbound visível mais recente é inserida e a versão avança

#### Scenario: Conflito de versão
- **WHEN** `expected_version` difere da versão atual
- **THEN** a operação não muda o ledger e retorna `409 READ_STATE_VERSION_CONFLICT`

#### Scenario: Conversa já não lida
- **WHEN** o ledger já contém pendências visíveis e a versão esperada é atual
- **THEN** UNREAD é idempotente e não substitui nem reduz o conjunto existente

#### Scenario: Ledger stale aponta para quarentena
- **WHEN** a única pendência existente referencia mensagem quarentenada e há inbound visível elegível
- **THEN** a pendência stale não impede UNREAD de reconstruir o estado pela última inbound visível

#### Scenario: Nenhuma inbound permanece elegível
- **WHEN** a conversa não possui inbound visível porque as mensagens estão ausentes, expurgadas, revogadas ou em quarentena
- **THEN** UNREAD não cria ledger, mantém versão e cursores atuais e responde com o mesmo read-state autoritativo

### Requirement: Realtime publica estado sanitizado após commit
Cada mudança do ledger SHALL publicar `conversation.read_state.updated` após commit no canal da inbox para usuários autorizados, contendo somente IDs internos, contador e versão. Consumidores SHALL aplicar somente versões mais novas e SHALL tratar campos opcionais ausentes como não projetados, preservando cursores já conhecidos. A SPA SHALL associar falha de acknowledgement ao snapshot tentado e executar no máximo uma tentativa automática por `(conversation_id, snapshot_through_message_id)` enquanto a timeline atual permanecer aberta; snapshot posterior SHALL liberar uma nova tentativa sem recolocar o anterior em loop.

#### Scenario: Evento seguro
- **WHEN** o read-state muda
- **THEN** o evento não contém nome, telefone, JID, endereço nem corpo de mensagem

#### Scenario: Evento parcial mais novo
- **WHEN** um evento com versão maior omite `last_read_through_message_id`
- **THEN** contador e versão avançam sem apagar o cursor conhecido da conversa

#### Scenario: Acknowledgement falha e snapshot avança
- **WHEN** READ falha para um snapshot e uma inbound posterior cria snapshot maior elegível
- **THEN** a SPA não repete automaticamente o snapshot falho, mas permite uma tentativa para o snapshot maior durante a timeline aberta
