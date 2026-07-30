## MODIFIED Requirements

### Requirement: Resultado de recuperação converge de forma observável
Cada retry SHALL evoluir por estados allowlisted e SHALL convergir
idempotentemente para attachment disponível ou falha explícita. O comando
durável `MEDIA_RETRY_REQUEST` SHALL sair de `PROCESSING` somente por finalização
fenced da tentativa corrente, publicando apenas eventos e métricas sanitizados.

#### Scenario: Solicitação aceita
- **WHEN** o outbox é aceito pelo gateway
- **THEN** a mensagem passa a `MEDIA_REQUESTED` sem declarar sucesso antecipado

#### Scenario: Evento solicitado ainda não possui spool
- **WHEN** `MESSAGE_RECEIVED` informa `media_state=REQUESTED` antes do download
- **THEN** OpenAPI e DTO aceitam o evento sem `spool_id`, tamanho, digest ou MIME, sem tratá-lo como `READY`

#### Scenario: Provider devolve mídia válida
- **WHEN** Wazync valida, baixa e entrega spool com tamanho/digest confirmados
- **THEN** Laravel cria ou atualiza um único attachment, marca `AVAILABLE` e a SPA atualiza a timeline

#### Scenario: Comando técnico concluído
- **WHEN** a tentativa corrente de `MEDIA_RETRY_REQUEST` conclui o processamento
- **THEN** o store PostgreSQL muda o comando de `PROCESSING` para `PROCESSED` usando o tipo canônico e ele não volta à fila após expirar o lock

#### Scenario: Falha retryável do comando técnico
- **WHEN** a tentativa corrente falha por indisponibilidade temporária do provider, decrypt ou download antes de esgotar o orçamento
- **THEN** o store PostgreSQL muda o comando para `RETRY`, libera o lock e preserva o backoff calculado

#### Scenario: Finalização de tentativa obsoleta
- **WHEN** um worker atrasado tenta finalizar um comando já reclamado por outra tentativa
- **THEN** o store rejeita a finalização por conflito de estado e não altera a tentativa corrente

#### Scenario: Comando desaparece antes da falha terminal
- **WHEN** a finalização fenced de falha não encontra o comando reclamado
- **THEN** Memory e PostgreSQL retornam conflito de estado e o worker converge como tentativa obsoleta

#### Scenario: Descriptor ou request é deterministicamente inválido
- **WHEN** media retry detecta estado ausente ou request de recovery inválida
- **THEN** a primeira tentativa termina em `ERROR`, publica o código allowlisted correspondente e não consome backoff adicional

#### Scenario: Falha terminal
- **WHEN** uma falha não determinística atinge o máximo de tentativas ou uma recusa determinística allowlisted encerra o comando
- **THEN** Laravel marca `MEDIA_FAILED`/`UNAVAILABLE` com código allowlisted e a UI permite nova tentativa somente quando a política disser recuperável

#### Scenario: Evento READY duplicado
- **WHEN** o mesmo resultado READY é entregue novamente
- **THEN** attachment e disponibilidade permanecem únicos e nenhum objeto órfão ou evento de negócio duplicado é criado

#### Scenario: Observabilidade
- **WHEN** retry, falha ou resgate é registrado em log, evento ou métrica
- **THEN** aparecem no máximo IDs internos, direção, kind, estado, contagem e código sanitizado, nunca corpo, endereço, provider ID, media key, descriptor ou payload bruto
