# communication-outbound-conversation-initiation Specification

## Purpose

Definir a iniciação outbound de conversas com destino explícito, autorização e rollout fail-closed.

## Requirements

### Requirement: Conversa outbound é iniciada com destino explícito

O sistema SHALL expor `POST /api/v1/communication/conversations` para usuários com `communication.reply`, exigindo `Idempotency-Key`, `contact_id`, `identity_id` e `inbox_id`, além de texto ou um arquivo suportado. A identidade MUST pertencer à classe canônica do contato no tenant corrente e a inbox MUST ser visível ao ator.

#### Scenario: Primeira mensagem de texto
- **WHEN** um ator autorizado informa contato, identidade, inbox e texto válidos com o rollout liberado
- **THEN** a API responde `202` com a conversa, a primeira mensagem e o indicador de reutilização

#### Scenario: Primeira mensagem com mídia
- **WHEN** a requisição contém imagem, áudio/PTT, vídeo, documento ou sticker válido e texto opcional
- **THEN** a mensagem e seu attachment são aceitos pelo mesmo contrato de mídia do composer existente

#### Scenario: Destino invisível ou estrangeiro
- **WHEN** contato, identidade ou inbox não pertence ao tenant ou não é visível ao ator
- **THEN** a API falha sem revelar a existência do recurso nem realizar egress

### Requirement: Conversa ativa é correlacionada deterministicamente

O sistema SHALL reutilizar a conversa ativa da inbox/identidade, SHALL reabrir a conversa resolvida mais recente quando não houver ativa e SHALL criar uma conversa `OPEN` somente quando nenhuma conversa correlacionada existir.

#### Scenario: Conversa ativa existente
- **WHEN** o destino já possui conversa ativa na inbox
- **THEN** a primeira mensagem é adicionada à conversa existente e `reused_conversation` é verdadeiro

#### Scenario: Nenhuma conversa existente
- **WHEN** não existe conversa correlacionada ativa ou resolvida
- **THEN** exatamente uma conversa `OPEN` é criada mesmo sob requisições concorrentes

### Requirement: Persistência e efeitos são atômicos e idempotentes

Conversa, mensagem, attachment, evento e outbox MUST ser persistidos atomicamente, e dispatch/realtime MUST ocorrer somente após commit. Replay com a mesma chave, destino e digest SHALL retornar os mesmos IDs com `200`; reutilização da chave com destino ou conteúdo diferente SHALL retornar `409`.

#### Scenario: Falha durante a escrita
- **WHEN** qualquer escrita falha após o staging do arquivo
- **THEN** nenhuma conversa vazia, mensagem parcial, outbox ou blob indevido permanece observável

#### Scenario: Replay idempotente
- **WHEN** a mesma requisição é repetida com a mesma chave
- **THEN** a API retorna os mesmos recursos sem novo envio

#### Scenario: Conflito idempotente
- **WHEN** a chave é repetida para inbox, identidade ou conteúdo diferente
- **THEN** a API responde `409 idempotency_conflict` sem alterar dados

### Requirement: Iniciação outbound é fail-closed

O sistema MUST negar novas iniciações por padrão e MUST exigir flag ligada, kill switch desligado, tenant allowlisted, comunicação/gateway/tenant/inbox operacionais, permissão `communication.reply` e destino diferente da identidade própria da inbox. O dispatcher MUST reavaliar o gate antes do transporte.

#### Scenario: Default seguro
- **WHEN** nenhuma configuração explícita de rollout foi aplicada
- **THEN** a iniciação é negada e nenhum comando é enfileirado

#### Scenario: Kill switch após enqueue
- **WHEN** o kill switch é ligado antes do dispatcher consumir a outbox
- **THEN** o transporte é bloqueado e o resultado permanece observável sem expor dados sensíveis
