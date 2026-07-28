## Why

O workspace de Communication já possui uma casca master–detail funcional, mas a lista não dispõe de nome resolvido com proveniência, preview compacto nem estado operacional compartilhado de não lidas; além disso, o detalhe carrega toda a timeline e a ação de leitura mistura semântica local com receipt do WhatsApp. A refatoração precisa fechar esses contratos antes de evoluir a interface inspirada no Chatwoot, preservando tenancy, canonicalização PN↔LID e os limites entre Laravel, Nuxt e Wazync.

## What Changes

- Preservar separadamente nomes de agenda, verificado, business e push observados pelo WhatsMeow, com consulta privada limitada aos contatos já conhecidos e resolução determinística no Laravel sem sobrescrever nomes curados.
- Introduzir um ledger de mensagens não lidas compartilhado por conversa, leitura local idempotente, ação explícita de tornar a última inbound não lida e atualização realtime para todos os usuários autorizados da inbox.
- Adicionar timeline cursorizada ancorável na primeira não lida, mantendo o detalhe legado compatível e separando integralmente o estado local dos receipts do provider.
- Tornar a lista de conversas compacta, navegável e responsiva, com título da pessoa, contexto empresarial secundário, preview semântico, filtro de não lidas e divisor na timeline, sem substituir a casca master–detail atual.
- Remover logging de corpo de mensagem e manter eventos, métricas e erros restritos a IDs internos, contagens e códigos sanitizados.
- Manter fora do escopo a administração de contatos, respostas rápidas, flows, configurações, grupos e avatar remoto.

## Capabilities

### New Capabilities

- `communication-contact-profile-resolution`: Observação, reconciliação, persistência por inbox/identity e precedência de nomes de contato vindos do WhatsApp e dos vínculos de cliente.
- `communication-conversation-read-state`: Ledger compartilhado de não lidas, leitura local, marcação explícita de não lida, concorrência, merge e realtime.
- `communication-conversation-workspace`: Contratos de listagem/timeline e comportamento incremental da lista e conversa no Nuxt.

### Modified Capabilities

- `whatsapp-peer-identity-correlation`: Perfis e estados de não lidas passam a acompanhar a identidade e a conversa sobreviventes em merges PN↔LID.
- `ui-archetypes-master-detail`: O master–detail de Communication passa a incluir lista compacta, filtro não lido, timeline cursorizada e divisor sem alterar a composição responsiva canônica.

## Impact

- `apps/wazync`: contrato/query `CONTACT_PROFILES`, projeção tipada de eventos de perfil e testes WhatsMeow, sem domínio de atendimento nem egress remoto novo.
- `apps/api`: novas migrations/modelos tenant-scoped, resolução de nome, ingestão/reconciliação, endpoints aditivos de lista, timeline e read-state, eventos Reverb e integração idempotente com o outbox de receipts.
- `apps/web`: tipos e cliente HTTP aditivos, composable do workspace, lista, timeline e testes runtime desktop/mobile.
- Contratos afetados: `/api/v1/communication`, `apps/api/resources/contracts/wazync.openapi.yaml` e seus dois consumidores; o rollout deve publicar API compatível antes do Wazync novo e do frontend.
- Dados sensíveis novos permanecem internos, sujeitos a canonicalização, export/purge e logs sanitizados. Nenhuma flag ou egress real será habilitado pela mudança.
