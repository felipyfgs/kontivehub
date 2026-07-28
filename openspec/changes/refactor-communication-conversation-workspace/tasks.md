## 1. OpenSpec e contratos de mudança

- [x] 1.1 Criar o change `refactor-communication-conversation-workspace` com proposal, design e tasks
- [x] 1.2 Especificar `communication-contact-profile-resolution`, `communication-conversation-read-state` e `communication-conversation-workspace`
- [x] 1.3 Especificar os deltas de `whatsapp-peer-identity-correlation` e `ui-archetypes-master-detail`
- [x] 1.4 Validar os artefatos em modo strict antes da implementação

## 2. Perfis e identidade de contato

- [ ] 2.1 Evoluir o OpenAPI e os dois consumidores com `CONTACT_PROFILES` e campos separados compatíveis
- [ ] 2.2 Implementar a consulta Wazync limitada a 100 identities usando somente `ContactStore.GetContact`
- [ ] 2.3 Projetar eventos WhatsMeow de perfil, clears, `JIDAlt` e `READ_SELF` sem conflar proveniência
- [ ] 2.4 Criar schema/modelo tenant-scoped de perfil único por inbox e identidade canônica
- [ ] 2.5 Implementar merge parcial ordenado por `(observed_at,event_id)` e reconciliação idempotente/retomável
- [ ] 2.6 Implementar a precedência completa de nomes no Laravel sem N+1 nem overwrite de contato curado
- [ ] 2.7 Integrar perfis em ingestão, merge PN↔LID, export e purge sem avatar remoto

## 3. Ledger e estado compartilhado de leitura

- [ ] 3.1 Criar ledger por mensagem e read-state versionado, iniciando o rollout vazio
- [ ] 3.2 Materializar unread apenas para inbound live inédita na mesma transação
- [ ] 3.3 Implementar READ monotônico por snapshot sem alterar receipts do provider
- [ ] 3.4 Implementar UNREAD otimista com a inbound mais recente e conflito `READ_STATE_VERSION_CONFLICT`
- [ ] 3.5 Remover pendências em revoke/purge e rejeitar escrita em conversa purgada
- [ ] 3.6 Consolidar exatamente ledger, perfil e read-state em merges/movimentos PN↔LID concorrentes
- [ ] 3.7 Publicar realtime sanitizado após commit para mudanças do ledger

## 4. APIs de conversa, timeline e receipt

- [ ] 4.1 Adicionar filtro unread e projeções aditivas de nome, preview, contador e versão na listagem
- [ ] 4.2 Preservar o detalhe legado e permitir `include_messages=false`
- [ ] 4.3 Implementar timeline keyset opaca por `(occurred_at,id)` com snapshots e âncoras coerentes
- [ ] 4.4 Expor somente `PUT /conversations/{id}/read-state` com Form Request e autorização existentes
- [ ] 4.5 Persistir o follow-up `receipt_message_id` como efeito idempotente e durável após aceite do outbound

## 5. Workspace Nuxt

- [ ] 5.1 Atualizar tipos e cliente HTTP para filtro, read-state e timeline cursorizada
- [ ] 5.2 Compactar lista, previews semânticos, iniciais locais, filtro e paginação com estados reais
- [ ] 5.3 Carregar/renderizar timeline antes de READ e manter divisor/âncora da sessão aberta
- [ ] 5.4 Implementar auto-read por visibilidade+fim, conflito UNREAD e pin no filtro não lido
- [ ] 5.5 Preservar master–detail, deep-link, teclado, foco, contexto e slideover sem logs de conteúdo

## 6. Testes, rollout e aceite

- [ ] 6.1 Cobrir contratos/WhatsMeow/Wazync, incluindo store local, clears, ordem, LID, `READ_SELF` e receipts
- [ ] 6.2 Cobrir API: nomes, tenancy, ledger, concorrência, merge, purge, cursores, realtime e follow-up
- [ ] 6.3 Cobrir Web em runtime e Playwright desktop/mobile para lista, timeline, foco, filtros e falhas
- [ ] 6.4 Executar gates completos API/Web/Wazync, validar OpenSpec strict e documentar a ordem de rollout
