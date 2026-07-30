## 1. Contratos e regressões de base

- [x] 1.1 Adicionar testes Wazync que reproduzam `ProtocolMessage` live/history fora de REVOKE/EDIT e provem que nenhum `MESSAGE_RECEIVED` conversacional é emitido.
- [x] 1.2 Adicionar fixtures/testes integrados que reproduzam mídia histórica inbound/outbound sem spool, duplicate enriquecedor e o balão vazio observado.
- [x] 1.3 Evoluir `wazync.openapi.yaml`, validators PHP/Go e testes de compatibilidade para invariantes semânticas, `media_state` allowlisted e media-retry legado/v2 dual-shape.
- [x] 1.4 Evoluir aditivamente o contrato público de mensagem com `availability`, regenerar os tipos Nuxt e atualizar os testes de OpenAPI sem remover campos existentes.

## 2. Projeção e recovery no Wazync

- [x] 2.1 Implementar a decisão allowlisted de todos os subtipos `ProtocolMessage`, encerrando ACTION/CONTROL/OUT_OF_SCOPE antes do normalizador em live e history.
- [x] 2.2 Adicionar métricas sanitizadas de disposição/rejeição de protocolo e testes negativos que impeçam logs, eventos ou métricas com conteúdo, IDs remotos ou protobuf bruto.
- [x] 2.3 Marcar mídia histórica com `RETRY_AVAILABLE` somente após persistir com sucesso o descriptor cifrado, limitado e expirável; produzir indisponibilidade explícita nas falhas.
- [x] 2.4 Implementar media-retry v2 derivando sender/from-me do descriptor e validando session/chat/target/expected_direction, mantendo compatibilidade inbound legada.
- [x] 2.5 Fazer falhas terminais do comando convergirem para `MEDIA_RETRY_UPDATED=FAILED` com códigos allowlisted e preservar READY idempotente.
- [x] 2.6 Cobrir event bridge, history, store persistente, worker, retry inbound/outbound, mismatch e restart com testes Go focados.

## 3. Integridade da mensagem no Laravel

- [x] 3.1 Criar migration PostgreSQL reversível para `quarantined_at`/`quarantine_reason` e índices tenant/conversation, sem backfill, quarentena ou egress automáticos.
- [x] 3.2 Endurecer `GatewayContractPayload` e a admissão do ingestor para coerência kind/family/conteúdo, rejeitando provider types de ação/controle antes de qualquer mutação.
- [x] 3.3 Refatorar a reentrega por provider ID para enriquecer apenas campos ausentes sob advisory/row lock, detectar conflitos e preservar IDs, conteúdo, status, unread, automação e atividade.
- [x] 3.4 Unificar o upsert idempotente de attachment entre MESSAGE_RECEIVED e MEDIA_RETRY_UPDATED, com deleção de objeto substituído somente após commit.
- [x] 3.5 Projetar `availability` no Resource e excluir quarentenadas de timeline, preview, snapshot, cursores e contagens usando o mesmo predicado autoritativo.
- [x] 3.6 Atualizar recovery/outbox para inbound e outbound com payload v2, effect key, estados REQUESTED/READY/FAILED e eventos Reverb sanitizados.
- [x] 3.7 Cobrir sucesso, duplicate/enrichment, conflito, paginação com gaps, autorização, membership ausente, cross-tenant e ausência de conteúdo sensível em testes Unit/Feature.

## 4. Quarentena e resgate governados

- [x] 4.1 Implementar serviço e comando de auditoria/quarentena dry-run por padrão, com tenant/inbox confiáveis, assinatura determinística, contexto privilegiado tipado, auditoria e reversão por operação.
- [x] 4.2 Implementar seleção de mídia histórica elegível e comando de resgate dry-run por padrão, com `--execute`, limite rígido, ordem determinística, kill switch, backoff e sem consulta direta a `wazync.*`.
- [x] 4.3 Garantir idempotência entre execuções/lotes e impedir novo lote quando houver resultado pendente ou limite de sessão alcançado.
- [x] 4.4 Testar dry-run sem mutação/egress, quarentena/reversão, candidatos inbound/outbound, descriptor expirado, isolamento tenant e saídas restritas a IDs internos/contagens/códigos.
- [x] 4.5 Documentar a sequência operacional API receptora → Wazync dual-shape → API emissora → Web e os comandos de dry-run; não incluir execução real, habilitação de flag ou limpeza destrutiva no handoff.

## 5. Timeline Nuxt sem balões vazios

- [x] 5.1 Tipar e normalizar `content`/`availability`, mantendo `body` canônico e fallback compatível para `content.text`/`content.caption`.
- [x] 5.2 Renderizar placeholders distintos para UNSUPPORTED, MEDIA_RETRY_AVAILABLE, MEDIA_REQUESTED, MEDIA_FAILED e UNAVAILABLE sem alterar a composição master–detail.
- [x] 5.3 Habilitar ação de recovery inbound/outbound somente para membership autorizada, inbox operacional e estado recuperável, preservando feedback assíncrono real.
- [x] 5.4 Tornar o merge timeline/realtime preservador de body, content, attachments e disponibilidade quando a atualização for parcial vazia.
- [x] 5.5 Adicionar testes de componente/composable para texto, caption, mídia, unsupported, unavailable, recovery, merge e regressão visual do balão vazio.

## 6. Verificação e readiness operacional

- [x] 6.1 Rodar testes focados dos três apps e validar o change com `openspec validate prevent-whatsapp-message-loss --type change --strict`.
- [x] 6.2 Rodar `make wazync-test` e corrigir falhas de `go test ./...`/`go vet ./...`.
- [x] 6.3 Rodar na stack do checkout `composer validate --strict --no-check-publish`, `vendor/bin/pint --test` e `php artisan test`.
- [x] 6.4 Rodar no `frontend-dev` lint, typecheck, generate, test, test:fidelity e test:artifacts.
- [x] 6.5 Registrar evidências sanitizadas de dry-run e contagens elegíveis, deixando quarentena e egress real pendentes de autorização operacional explícita.
