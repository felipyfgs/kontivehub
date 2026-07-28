## Why

Uma única conversa WhatsApp pode ser materializada como chats distintos para o LID remoto, o telefone remoto e até o telefone da própria sessão. Isso fragmenta o histórico de atendimento, cria self-chats e permite que novos eventos continuem alimentando registros diferentes para o mesmo peer.

## What Changes

- Fixar `MessageSource.Chat` como peer da conversa no Wazync e projetar somente a PN remota como alias de um LID, distinguindo eventos inbound e outbound.
- Fazer a API resolver `source_identity` mesmo quando o campo legado `from` está presente, excluindo sempre o endereço da própria inbox dos aliases do contato remoto.
- Reconciliar identidades LID/PN preexistentes que estejam em contatos distintos e consolidar as conversas equivalentes da mesma inbox sem perder mensagens, vínculos ou metadados operacionais.
- Serializar a correlação de aliases para impedir que eventos concorrentes recriem contatos ou conversas paralelas.
- Cobrir o contrato privado Laravel-Wazync e o fluxo de ingestão com regressões usando as ordens LID→PN e PN→LID, inbound, outbound, histórico e dados já fragmentados.
- Manter fora do escopo agrupamento heurístico na SPA, chats de grupo e inferência de equivalência quando o gateway não fornece evidência LID↔PN.

## Capabilities

### New Capabilities

- `whatsapp-peer-identity-correlation`: Define a seleção segura do peer WhatsApp, a correlação LID/PN, a exclusão da identidade da sessão e a unicidade/consolidação do fio de atendimento por inbox.

### Modified Capabilities

Nenhuma capability canônica existente tem requisitos alterados.

## Impact

- `apps/wazync`: projeção de `MessageSource`, eventos live/history/action e testes do bridge.
- `apps/api`: contrato privado Wazync, resolução de peer, ingestão transacional, reconciliação de contatos/identidades/conversas e testes de integração PostgreSQL.
- Persistência de comunicação: registros fragmentados passam a ser reconciliados quando uma associação LID↔PN confiável é observada; qualquer migration nova será reversível e não alterará migrations compartilhadas.
- `apps/web`: sem mudança de agrupamento; continuará renderizando as conversas canônicas entregues pela API.
- Sem novo egress, flag de rollout ou exposição pública de JIDs crus.
