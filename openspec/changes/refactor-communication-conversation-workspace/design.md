## Context

Communication já materializa contacts, identities, conversations, messages, eventos realtime e outbox idempotente no Laravel; o Wazync projeta eventos normalizados do WhatsMeow e o Nuxt mantém um workspace master–detail responsivo. Hoje, porém, eventos Contact/PushName/BusinessName convergem em `display_name`, o perfil parcial substitui o snapshot anterior, `read_at` representa receipt remoto e o detalhe carrega a timeline inteira.

O WhatsMeow fixado oferece `ContactStore.GetContact` e `ContactInfo` com `FirstName`, `FullName`, `PushName` e `BusinessName`, mas não define precedência nem garante ordenação global entre tipos de evento. O Laravel continua dono da precedência, estado operacional, autorização e comunicação de negócio.

## Goals / Non-Goals

**Goals:**

- Preservar fontes de nome por inbox/identity, conciliá-las fora de ordem e resolver título público sem sobrescrever dados curados.
- Implementar não lidas compartilhadas e exatas, inclusive após merge PN↔LID, sem confundir leitura humana com receipt do provider.
- Paginar a timeline por cursor e apresentar primeira não lida, lista compacta e realtime sem romper rotas, seleção, foco ou responsividade.
- Evoluir contratos de forma aditiva, tenant-safe, idempotente e compatível durante rollout.

**Non-Goals:**

- Importar toda a agenda, consultar nomes remotamente em massa ou colocar regras de atendimento no Wazync.
- Alterar administração de contatos, respostas rápidas, flows, configurações, grupos ou avatar remoto.
- Remover campos públicos legados, substituir a casca master–detail ou introduzir SSR/Pinia.

## Decisions

### Perfis são observações tipadas por inbox e identity

`communication_inbox_identity_profiles` será tenant-scoped e única por `(tenant_id, inbox_id, identity_id)`. Manterá agenda first/full, verified, business, push, `picture_id` e `about`, mais `(observed_at, event_id)` por fonte. Campo ausente preserva estado; `cleared_fields` remove só a fonte declarada; observação anterior é ignorada.

`CONTACT_PROFILE_CHANGED` manterá `display_name` legado e ganhará fontes explícitas, origem, clears e `from_full_sync`. `CONTACT_PROFILES`, limitada a 100 endereços 1:1 já conhecidos, lerá somente `ContactStore.GetContact`; não usará `GetAllContacts`, URL de avatar, JID cru nem egress remoto. Verified só será persistido quando já observado por `USER_INFO`.

Alternativa rejeitada: um único `display_name`, pois perde proveniência, mistura agendas e permite overwrite por evento parcial.

### A precedência de título pertence ao Laravel

Um resolver único produzirá `display_name`/`display_name_source` na ordem: contact manual; único `ClientContact.name` distinto; agenda full/first; verified; business; push; nome provisório legado; endereço mascarado; identificador opaco. Empresas fiscais continuam em `clients` como contexto secundário. A SPA não replica a regra.

Perfis acompanham a identity canônica. Merge escolhe a observação mais recente por fonte; purge limpa dados recuperáveis e export inclui somente projeção autorizada. Reconciliação retomável consulta em lotes identities existentes e nunca limpa dados em unknown/failure.

### O ledger é a fonte autoritativa de não lidas

`communication_conversation_unread_messages` terá uma row por mensagem atualmente não lida, única por `(tenant_id, conversation_id, message_id)`. `communication_conversation_read_states` manterá `version`, `last_read_through_message_id`, `updated_by_user_id` e membership opcional para auditoria.

A primeira materialização de inbound live insere o ledger na mesma transação; history não insere e duplicate/retry nunca recria row removida. READ bloqueia conversation/read-state, valida o snapshot e remove rows até `through_message_id`; IDs maiores permanecem. UNREAD exige `expected_version`, só age com ledger vazio e insere a inbound mais recente, inclusive histórica. Revoke/purge removem a row.

Merge move mensagens e a união exata do ledger para a conversation sobrevivente e consolida read-state com nova versão. Um cursor único foi rejeitado porque não representa pendências não contíguas após unir timelines independentes.

### Timeline e APIs evoluem de forma aditiva

`GET /conversations` aceitará `unread` e projetará título, contador e read-state por query/indexes tenant-scoped. `GET /conversations/{id}` manterá mensagens por padrão e aceitará `include_messages=false`.

`GET /conversations/{id}/messages` usará cursor opaco sobre `(occurred_at,id)`, limite 1..100 e âncora `latest` ou `first_unread`. A resposta cronológica trará cursores older/newer, primeira não lida, versão e `snapshot_through_message_id`, o maior ID existente no snapshot. Read-state recebe somente IDs internos e resolve a conversation canônica.

### Receipt externo é um efeito posterior e separado

`receipt_message_id` será opcional no envio humano externo e apontará para inbound da mesma conversation. O outbox liberará um efeito idempotente somente após aceite de `MESSAGE_SEND`; falha/retry do receipt não desfaz a mensagem. Nota interna não cria o efeito. A rota manual continua e a UI passa a nomeá-la como confirmação WhatsApp.

### O Nuxt aplica o menor delta sobre o arquétipo Inbox

Panels, navbars, detalhe desktop, contexto e slideovers permanecem. A lista usa linha compacta, tabs Todas/Não lidas, preview semântico e contador. A timeline captura a primeira não lida antes de READ, pagina nas duas direções e só lê inbound nova automaticamente quando documento está visível e viewport no fim. Sob filtro unread, a seleção fica pinada até fechar/trocar.

Eventos `conversation.read_state.updated` atualizam contador/versão sem payload sensível. Conflito de UNREAD força refresh; logs de conteúdo são removidos.

## Risks / Trade-offs

- [Contrato estrito rejeita campos novos] → API/validator primeiro, `display_name` legado e testes nos dois consumidores.
- [Eventos de perfil fora de ordem] → comparar `(observed_at,event_id)` por fonte e exigir clears explícitos.
- [Ledger cresce] → remover em READ/revoke/purge e indexar tenant/conversation/message; vazio representa rollout lido.
- [Merge concorre com READ/inbound] → reutilizar locks/canonicalizers e mover estado na mesma transação.
- [Cursor com timestamps iguais] → desempate por ID e testes bidirecionais.
- [Receipt posterior falha] → outbox idempotente, retry independente e sem rollback da mensagem.
- [Filtro unread remove linha aberta] → pin local apenas da seleção atual.
- [Novos nomes ampliam PII] → não expor fontes brutas/JID/URL; integrar export/purge e logs sanitizados.

## Migration Plan

1. Aplicar migrations aditivas; ledger/read-state começam vazios, então histórico existente permanece lido.
2. Publicar Laravel aceitando perfil antigo/novo e mantendo respostas legadas.
3. Publicar Wazync com eventos tipados e `CONTACT_PROFILES`; reconciliar só identities conhecidas.
4. Publicar Nuxt com detalhe sem mensagens embutidas, timeline cursorizada e read-state.
5. Monitorar reconciliação, conflitos, ledger e outbox com labels de baixa cardinalidade.

Rollback antes de uso pode remover as tabelas novas. Depois de estado real, a recuperação segura é roll-forward; Wazync pode voltar a emitir só `display_name` enquanto a API compatível permanecer.

## Open Questions

Nenhuma bloqueante.
