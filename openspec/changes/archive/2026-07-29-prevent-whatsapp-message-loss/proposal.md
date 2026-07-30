## Why

O workspace de Communication está projetando frames técnicos `protocolMessage` como mensagens vazias e importando mídias históricas sem corpo, anexo ou estado recuperável visível. A ocorrência já contaminou timelines reais e existe uma janela curta, iniciando em 03/08/2026, para tentar recuperar 179 mídias cujos descritores técnicos ainda não expiraram.

## What Changes

- Fazer o Wazync consumir, traduzir ou rejeitar de forma fail-closed todo frame de controle/ação do WhatsApp antes de `MESSAGE_RECEIVED`; somente conteúdo semântico 1:1 poderá entrar no ledger de mensagens.
- Endurecer o contrato Laravel–Wazync e a ingestão Laravel para impedir mensagens renderizáveis sem representação semântica, preservando `UNSUPPORTED` apenas para conteúdo real não suportado e enriquecendo projeções incompletas quando um evento posterior trouxer conteúdo ou mídia.
- Representar explicitamente mensagem não suportada, indisponível e mídia histórica pendente/falha, para que a API e a SPA nunca apresentem balões vazios como se fossem mensagens enviadas ou recebidas normais.
- Permitir retry de mídia histórica inbound e outbound com direção validada pelo descriptor mantido no Wazync, idempotência, limites, backoff, telemetria sanitizada e sem acesso direto do Laravel ao banco técnico do gateway.
- Adicionar uma operação administrativa tenant-safe, dry-run por padrão e com execução explícita, para identificar os registros contaminados e tentar resgatar mídias ainda elegíveis sem apagar conteúdo ou disparar egress automaticamente durante deploy/migration.
- Compatibilizar os contratos privado e público de forma aditiva e cobrir rollout API → Wazync → Web, mantendo HMAC, nonce, payload cifrado, tenancy e kill switches existentes.
- Manter fora do escopo grupos, status/newsletters, conteúdo fiscal, importação irrestrita de histórico e exclusão automática dos registros já persistidos.

## Capabilities

### New Capabilities

- `whatsapp-message-projection-integrity`: Classificação fail-closed de frames WhatsApp, admissão semântica no Laravel, enriquecimento idempotente e apresentação explícita de mensagens indisponíveis/não suportadas.
- `whatsapp-history-media-recovery`: Estado e retry seguro de mídia histórica inbound/outbound, operação administrativa limitada, observabilidade sanitizada e janela de recuperação.

### Modified Capabilities

Nenhuma capability canônica existente muda seus requisitos; a composição master–detail e a correlação PN↔LID permanecem invariantes.

## Impact

- `apps/wazync`: classificação de `ProtocolMessage`, projeção `MESSAGE_RECEIVED`, media-retry direction-aware, store técnico e testes do event bridge/worker.
- `apps/api`: contrato privado `wazync.openapi.yaml`, validação/ingestão de eventos, atualização idempotente de mensagens, outbox de recuperação, comando administrativo, Resources e contrato público `/api/v1/communication`.
- `apps/web`: tipos/clientes gerados, conteúdo da timeline, placeholders e ações de recuperação inbound/outbound sem acesso direto ao gateway.
- Dados: os 21 frames técnicos já materializados devem ser identificados de forma determinística e não destrutiva; as 179 mídias observadas são candidatas a retry, sem promessa de disponibilidade pelo provider.
- Operação: nenhum retry real será habilitado por migration, seed, deploy ou default. A execução exige ator autorizado, inbox operacional, opção explícita, lote limitado e auditoria; logs/métricas não incluem corpo, JID, telefone, media key ou payload bruto.
- Compatibilidade: mudanças privadas exigem testes nos dois consumidores e rollout compatível; a API pública evolui apenas de forma aditiva.
