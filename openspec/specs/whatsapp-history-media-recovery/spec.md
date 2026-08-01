# whatsapp-history-media-recovery Specification

## Purpose

Definir disponibilidade explícita de mídia histórica WhatsApp, media retry
inbound/outbound no contrato privado, recuperação autorizada e idempotente,
resgate administrativo dry-run e convergência observável sem expor media key,
descriptor, JID ou payload bruto.

## Requirements

### Requirement: Mídia histórica possui estado de disponibilidade explícito
Ao importar mídia por history sync sem download imediato, o Wazync SHALL preservar descriptor cifrado e limitado quando possível, e Laravel/API SHALL materializar um estado explícito sem expor media key, descriptor, JID ou payload bruto.

#### Scenario: Descriptor preservado
- **WHEN** uma imagem, áudio, vídeo, documento ou sticker histórico possui dados válidos para media retry
- **THEN** o gateway persiste o descriptor técnico com expiração e emite `MEDIA_RETRY_AVAILABLE` para a mensagem

#### Scenario: Descriptor não pode ser preservado
- **WHEN** o descriptor é inválido, excede limites ou o store técnico falha
- **THEN** o evento é rejeitado ou marcado `UNAVAILABLE` com código sanitizado, nunca como mídia normal disponível

#### Scenario: Mídia já possui stream
- **WHEN** a ingestão recebe spool, tamanho e digest confirmados
- **THEN** a API cria attachment idempotente e expõe `AVAILABLE`

### Requirement: Media retry suporta inbound e outbound sem identidade fabricada
O contrato privado SHALL permitir retry de mídia inbound e outbound usando chat, target e direção esperada, enquanto o Wazync SHALL derivar e validar sender/from-me a partir do descriptor técnico autoritativo.

#### Scenario: Retry inbound
- **WHEN** uma mensagem inbound recuperável é solicitada para a mesma session, chat e provider ID
- **THEN** o Wazync valida `expected_direction=INBOUND` e emite o receipt de media retry com a identidade do descriptor

#### Scenario: Retry outbound
- **WHEN** uma mensagem outbound recuperável é solicitada para a mesma session, chat e provider ID
- **THEN** o Wazync valida `expected_direction=OUTBOUND` e processa o retry sem exigir que Laravel conheça o JID da sessão

#### Scenario: Direção ou chat diverge
- **WHEN** direção esperada, session, chat ou target não coincide com o descriptor cifrado
- **THEN** o gateway falha fechado com código sanitizado e não emite receipt

#### Scenario: Rollout com payload anterior
- **WHEN** uma API ainda envia o shape inbound anterior durante a janela de compatibilidade
- **THEN** o Wazync novo continua validando e processando esse shape sem aceitar campos desconhecidos ou fallback permissivo

### Requirement: Solicitação de recuperação é autorizada, explícita e idempotente
Laravel SHALL autorizar recuperação pela inbox/conversa tenant-scoped, exigir disponibilidade outbound e enfileirar efeito idempotente somente após ação explícita de usuário ou operador.

#### Scenario: Usuário recupera uma mensagem
- **WHEN** membership autorizada solicita uma mídia marcada como recuperável em conversa acessível
- **THEN** a API enfileira no máximo um retry ativo para a mensagem e responde de forma assíncrona

#### Scenario: Usuário tenta outra inbox ou tenant
- **WHEN** mensagem, conversa e inbox não pertencem ao mesmo tenant autorizado
- **THEN** a API falha fechada sem criar outbox ou revelar existência do alvo

#### Scenario: Deploy ou migration
- **WHEN** código, migration, seed, scheduler ou worker é iniciado
- **THEN** nenhuma mídia histórica é solicitada automaticamente

#### Scenario: Retry duplicado
- **WHEN** a mesma mensagem/tentativa é solicitada novamente enquanto o efeito está pendente ou aceito
- **THEN** a effect key reutiliza o efeito existente e não envia segundo receipt

### Requirement: Resgate administrativo é dry-run, limitado e governado
O sistema SHALL oferecer operação administrativa tenant-safe que selecione somente mídia histórica elegível, use dry-run por padrão e exija execução explícita, limite de lote e contexto privilegiado auditável.

#### Scenario: Inventário dry-run
- **WHEN** o operador informa tenant/inbox confiáveis sem `--execute`
- **THEN** a operação reporta contagens por direção/kind e IDs internos, sem consultar diretamente o store Wazync, criar outbox ou imprimir conteúdo

#### Scenario: Lote executado
- **WHEN** há autorização operacional explícita, inbox operacional e `--execute` dentro do limite
- **THEN** a operação enfileira candidatos em ordem determinística, respeita kill switch e registra contagens solicitadas/ignoradas/falhas

#### Scenario: Próximo lote
- **WHEN** o lote anterior ainda possui resultados pendentes ou o limite por sessão foi alcançado
- **THEN** o sistema não amplia egress e exige aguardar/backoff antes de prosseguir

#### Scenario: Descriptor expirado
- **WHEN** o Wazync não encontra descriptor válido para um candidato
- **THEN** o resultado terminal é `FAILED`/`UNAVAILABLE`, sem retry infinito nem remoção da mensagem

### Requirement: Resultado de recuperação converge de forma observável
Cada retry SHALL evoluir por estados allowlisted e SHALL convergir idempotentemente para attachment disponível ou falha explícita. O comando durável `MEDIA_RETRY_REQUEST` SHALL sair de `PROCESSING` somente por finalização fenced da tentativa corrente, publicando apenas eventos e métricas sanitizados.

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
