## Why

As conversas e os contatos de Communication hoje exibem apenas iniciais, embora o Wazync já consiga consultar a foto de perfil do WhatsApp e a API já preserve `picture_id` como versão por inbox e identidade. A foto precisa virar uma projeção segura e assíncrona do Laravel, sem N+1 no gateway, sem URL efêmera do provider no navegador e sem romper a seleção/virtualização inspirada no Chatwoot.

## What Changes

- Adicionar aquisição assíncrona e fail-closed da foto preview do WhatsApp para identities já materializadas, com cache cifrado, invalidação por `picture_id`, retry limitado e backfill gradual.
- Projetar `profile_picture_url` de forma aditiva nos contatos e no resumo de contato das conversas, servindo os bytes somente por rota Laravel autenticada e autorizada.
- Resolver a foto da conversa pela inbox+identity exatas e a foto do contato pela conversa visível mais recente, sem cruzar inboxes inacessíveis ao ator.
- Exibir foto com fallback de iniciais na lista, navbar/timeline e contexto da conversa, além do catálogo e detalhe do contato.
- Centralizar o checkbox de seleção da conversa sobre o avatar, como na referência Chatwoot, preservando controles irmãos, teclado, touch e virtualização.
- Integrar assets ao merge PN↔LID, export de metadados e purge, mantendo URL remota, JID e `picture_id` fora do contrato público e dos logs.
- Manter egress e exposição desligados por padrão, com kill switch e allowlists explícitas para rollout.

## Capabilities

### New Capabilities

- `communication-whatsapp-profile-pictures`: aquisição, armazenamento cifrado, invalidação, resolução autorizada e entrega HTTP de fotos de perfil WhatsApp.

### Modified Capabilities

- `communication-contact-profile-resolution`: `picture_id` passa de marcador sem fetch para fonte de invalidação de um asset privado sujeito a merge, export e purge.
- `communication-conversation-workspace`: o workspace passa a consumir foto same-origin quando disponível, preservando iniciais como fallback.
- `communication-conversation-list-operations`: a seleção continua separada da conversa aberta, mas o checkbox da linha passa a ocupar o centro do avatar com estados acessíveis.
- `communication-contacts-catalog`: a identidade visual do catálogo passa a preferir a foto autorizada e mantém iniciais quando ausente.
- `communication-contacts-experience`: catálogo e detalhe passam a apresentar a mesma foto resolvida sem expor dados técnicos do gateway.

## Impact

- **API Laravel:** nova migration reversível, estado do asset no perfil por inbox/identity, adapter de download seguro, jobs e schedule na fila `communication`, rota de imagem, Resources/queries, lifecycle e testes.
- **Contrato público `/api/v1`:** campos opcionais `profile_picture_url` e novo GET autenticado de imagem; consumidores anteriores permanecem compatíveis.
- **Wazync:** reutiliza `PROFILE_PICTURE` com `preview=true`; não há novo endpoint nem mudança de payload privado, mas seus testes de compatibilidade continuam obrigatórios.
- **SPA Nuxt:** tipos aditivos, avatares reais com fallback e overlay de seleção dentro dos componentes atuais, sem alterar a casca master–detail.
- **Operação:** novos flags, allowlists, limites de download/backfill e observabilidade sanitizada; nenhum egress real será habilitado pela implementação.
- **Dependências:** a aplicação pressupõe estabilizados os contratos de `refactor-communication-conversation-workspace`, `add-communication-conversation-list-operations` e `align-communication-contacts-with-chatwoot` e preserva suas mudanças locais.
