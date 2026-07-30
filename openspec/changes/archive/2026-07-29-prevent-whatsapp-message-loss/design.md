## Context

O fluxo atual aceita qualquer `events.Message` 1:1 no `EventBridge`, trata apenas `ProtocolMessage_REVOKE` e `ProtocolMessage_MESSAGE_EDIT` como ações e deixa os demais `ProtocolMessage` chegarem ao normalizador. Como todo campo não `PROJECTED` vira `UNSUPPORTED`, esses controles são emitidos como `MESSAGE_RECEIVED`; o Laravel aceita `family=ACTION` sem conteúdo, cria `CommunicationMessage` e o Nuxt monta o cabeçalho do balão mesmo sem corpo ou anexo.

No history sync, o Wazync preserva um descriptor cifrado de media-retry por sete dias, mas não baixa a mídia para o spool. O evento histórico pode, portanto, criar `IMAGE`/`DOCUMENT` sem anexo e sem estado público que permita ao operador distinguir conteúdo aguardando retry de mensagem vazia. A recuperação Laravel exige inbound e fabrica `sender/from_me`, embora o descriptor técnico do Wazync já seja a fonte autoritativa desses valores.

A ocorrência diagnosticada materializou 21 controles e 179 mídias históricas sem representação. Todos os descriptors observados ainda estavam válidos durante a análise, mas a disponibilidade final continua pertencendo ao WhatsApp. A solução atravessa Wazync, contrato privado, domínio Laravel e Nuxt, sem mover regras de atendimento para o gateway nem permitir que Laravel/Web consultem o store técnico.

## Goals / Non-Goals

**Goals:**

- Garantir que ações, controles e escopos não conversacionais nunca sejam materializados como mensagem.
- Exigir uma representação semântica ou um estado explícito de indisponibilidade em todo `MESSAGE_RECEIVED` aceito.
- Enriquecer projeções incompletas de modo idempotente, sem apagar conteúdo conhecido nem duplicar unread/activity.
- Recuperar mídia histórica inbound e outbound usando apenas IDs estáveis no contrato e o descriptor cifrado no Wazync.
- Expor disponibilidade aditiva na API e placeholders/ações reais no Nuxt, sem balões vazios.
- Disponibilizar auditoria, quarentena e resgate operacionais explícitos, tenant-safe, limitados e observáveis sem conteúdo sensível.

**Non-Goals:**

- Garantir que o provider ainda retenha cada mídia ou reconstruir conteúdo cujo descriptor expirou.
- Importar histórico ilimitado, grupos, status, newsletters, chamadas ou payload protobuf bruto.
- Apagar automaticamente rows contaminadas, executar retry durante migration/deploy ou habilitar egress por default.
- Fazer o Laravel ou o Nuxt lerem `wazync.*`, media keys, JIDs da sessão ou spool diretamente.
- Redesenhar o workspace master–detail, alterar correlação PN↔LID ou introduzir SSR/Pinia.

## Decisions

### O EventBridge encerra toda disposição não conversacional antes do normalizador

`prepareMessage` passará a decidir cada `ProtocolMessage` por subtipo allowlisted: `REVOKE` e `MESSAGE_EDIT` continuam virando `MESSAGE_ACTION`; controles com projeção de produto existente podem virar evento técnico tipado; history/app-state/security/unknown serão consumidos ou rejeitados com contador sanitizado. Nenhum `ProtocolMessage`, `ACTION`, `CONTROL` ou `OUT_OF_SCOPE` poderá chegar a `normalizedMessagePayload` como mensagem.

A mesma regra será aplicada a live e history, e testes cobrirão a matriz de subtipos do protobuf fixado. Métricas poderão usar somente subtipo allowlisted e disposição de baixa cardinalidade; payload, IDs remotos, texto e protobuf nunca serão logados.

Alternativa rejeitada: continuar emitindo `UNSUPPORTED` e ocultar somente no frontend. Isso mantém o ledger contaminado, interfere em paginação/preview e deixa outros consumidores sujeitos ao mesmo erro.

### O contrato privado terá invariantes semânticas fail-closed

`MESSAGE_RECEIVED` continuará compatível para mensagens projetadas, mas passará a exigir coerência entre `kind`, `family` e conteúdo:

- `TEXT` exige `text` não vazio;
- mídia exige stream confirmado ou `media_state` allowlisted que explique ausência do anexo;
- tipos ricos exigem seu objeto semântico;
- `UNSUPPORTED` exige `family=UNSUPPORTED`, `content_present=true` e provider type que não seja ação/controle;
- `ACTION`, `CONTROL` e `OUT_OF_SCOPE` são inválidos nesse evento.

History media cujo descriptor foi persistido será emitido com `media_state=RETRY_AVAILABLE`; falha em preservar o descriptor produzirá estado indisponível ou rejeição, nunca uma mensagem aparentemente normal e vazia. O Laravel validará as mesmas invariantes antes de correlacionar/persistir.

Alternativa rejeitada: tornar `text/caption/spool_id` globalmente obrigatórios. Áudio, sticker e conteúdo realmente não suportado não possuem corpo textual legítimo e precisam de contratos próprios.

### Reentrega enriquece somente campos ausentes sob lock

Ao encontrar `(inbox_id, provider_message_id)` existente, o ingestor não retornará antes de avaliar conteúdo. Sob advisory lock e row lock, ele poderá preencher corpo, conteúdo semântico, reply e attachment ausentes, promover `UNSUPPORTED` para um kind projetado quando a evidência for coerente e atualizar o estado de disponibilidade.

Valores vazios nunca sobrescreverão valores existentes. Peer/conversation, direction ou conteúdo não vazio conflitante causarão conflito fail-closed. O enriquecimento não recriará unread, não repetirá handoff/automação e só avançará `last_message_at` pelo maior timestamp. Attachment/media-ready reutilizará a mesma regra idempotente e removerá objeto substituído somente após commit.

Alternativa rejeitada: apagar a row incompleta e reinserir. Isso quebraria IDs internos, replies, eventos, unread e referências de automação.

### Controles legados são quarentenados, não deletados

Uma migration reversível adicionará `quarantined_at` e `quarantine_reason` a `communication_messages`, com índice tenant/conversation adequado. Queries de timeline, preview e unread considerarão apenas rows não quarentenadas. Um comando de auditoria será dry-run por padrão e poderá marcar explicitamente como `WHATSAPP_PROTOCOL_CONTROL` somente rows com assinatura determinística de controle do gateway; ele reportará IDs internos e contagens, nunca conteúdo ou endereço.

Quarentena preserva evidência, permite rollback e evita uma migration destrutiva. O comando não será agendado nem executado automaticamente.

Alternativa rejeitada: `DELETE` ou regra temporal específica para os 21 casos observados. Ambas dificultam auditoria e não generalizam com segurança.

### Disponibilidade pública é aditiva e derivada da projeção autoritativa

`CommunicationMessageResource` ganhará `availability` com estado allowlisted (`AVAILABLE`, `UNSUPPORTED`, `MEDIA_RETRY_AVAILABLE`, `MEDIA_REQUESTED`, `MEDIA_FAILED`, `UNAVAILABLE`) e `recoverable`; campos existentes permanecem. Rows quarentenadas não entram na coleção pública.

O Nuxt usará `body` como apresentação canônica, com fallback compatível para `content.text/caption`, e exibirá placeholder específico quando não houver conteúdo disponível. O merge realtime/timeline não poderá substituir corpo, conteúdo ou attachment conhecido por atualização parcial vazia. A ação de recovery aparecerá somente com autorização de reply, inbox outbound operacional e estado recuperável.

Alternativa rejeitada: inventar um texto genérico no cliente sem estado da API. Isso mascara contratos incompletos e diverge entre lista, timeline e outros consumidores.

### Media retry v2 deriva identidade e direção do descriptor técnico

O payload privado evoluirá de forma compatível para aceitar `{to, target_message_id, expected_direction}`. O Wazync resolverá `sender` e `from_me` exclusivamente do descriptor cifrado persistido e verificará chat, session, target e direção esperada antes de emitir qualquer receipt. Durante rollout, o Wazync aceitará também o shape legado inbound; o Laravel só passará ao shape novo depois que essa compatibilidade estiver publicada.

O Laravel nunca recebe media key ou identidade da sessão. Inbound e outbound usam o mesmo port/outbox e effect key por mensagem/tentativa. Falta/expiração/mismatch do descriptor termina em `MEDIA_RETRY_UPDATED=FAILED` com código sanitizado; sucesso cria ou atualiza attachment idempotentemente e publica `READY`.

Alternativa rejeitada: ensinar o Laravel a fabricar o sender outbound ou consultar `wazync.media_retry_states`. Isso viola ownership do gateway e pode confundir peer remoto com identidade da sessão.

### Resgate é explícito, limitado e separado do deploy

Uma Action/Service tenant-safe selecionará mensagens históricas de mídia sem attachment, não purgadas/revogadas/quarentenadas e com provider ID. A superfície administrativa terá dry-run default, exigirá tenant/inbox confiáveis e `--execute`, limitará lote, deduplicará outbox e auditará ator/contexto privilegiado tipado sem fabricar membership. A operação não consultará se o descriptor existe: o Wazync decide e devolve resultado terminal.

Nenhuma schedule, migration, seed ou default acionará retry. A execução real permanece sujeita às flags/capabilities e disponibilidade da inbox já existentes, além de autorização operacional separada.

## Risks / Trade-offs

- [Descriptors expiram antes do rollout] → priorizar implementação/resgate, reportar `UNAVAILABLE` de forma honesta e nunca prometer recuperação.
- [Novo shape privado encontra Wazync antigo] → publicar primeiro API receptora, depois Wazync dual-shape e só então Laravel emissor; testes de compatibilidade cobrem ambos.
- [Quarentena remove item usado como cursor] → toda query/snapshot aplica o mesmo predicado e testes cobrem gaps, quotes e first-unread.
- [Enriquecimento conflita com eventos fora de ordem] → locks, preenchimento apenas de ausentes, digest semântico e conflito em valores não vazios divergentes.
- [Resgate em massa causa egress ou throttling] → dry-run, `--execute`, lote pequeno, effect key, backoff por sessão e kill switch fail-closed.
- [Retry READY chega duplicado] → upsert de attachment por mensagem sob lock e deleção de objeto substituído após commit.
- [Placeholder esconde regressão] → métricas/alertas distinguem `UNSUPPORTED`, `UNAVAILABLE` e `MEDIA_FAILED`; testes exigem que mensagem projetável nunca caia nesses estados.
- [Dados sensíveis escapam na observabilidade] → somente IDs internos, contagens, direção e códigos allowlisted; `LogSanitizer` e testes negativos de payload.

## Migration Plan

1. Aplicar migration aditiva de quarentena e publicar Laravel aceitando novos estados/eventos, projetando `availability` e mantendo payload legado de retry.
2. Publicar Wazync que filtra todos os controles e aceita media-retry legado e v2, sem habilitar execução automática.
3. Publicar Laravel emissor do retry v2, enriquecimento idempotente, comandos dry-run/execute e eventos de resultado.
4. Publicar Nuxt com placeholders, merge preservador e recovery direction-aware.
5. Executar auditoria dry-run; validar contagens por tenant/inbox; quarentenar controles somente após aprovação operacional.
6. Executar dry-run de mídia e, enquanto descriptors forem válidos, solicitar autorização separada para lotes reais, monitorando READY/FAILED antes do lote seguinte.
7. Manter rows sem recuperação como `UNAVAILABLE`; não removê-las.

Rollback é roll-forward para attachments já recuperados. Antes de uso, a migration pode ser revertida; após quarentena, o comando inverso remove apenas as marcas auditadas. Para voltar Wazync, primeiro reverter o Laravel emissor ao shape legado. A API pública aditiva pode permanecer durante rollback.

## Open Questions

Nenhuma decisão arquitetural bloqueante. A autorização para executar quarentena e egress de resgate em um ambiente real continua sendo uma etapa operacional explícita, fora do simples deploy da implementação.
