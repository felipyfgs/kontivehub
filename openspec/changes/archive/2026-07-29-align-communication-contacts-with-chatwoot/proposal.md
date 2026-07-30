## Why

O catálogo e os detalhes de contatos entregues nas mudanças anteriores continuam visual e operacionalmente distantes do Chatwoot 4.16.2 usado como referência. Além disso, anexos só aparecem dentro de mensagens e o operador não consegue iniciar uma conversa real a partir de um contato, o que fragmenta dois fluxos cotidianos do atendimento.

## What Changes

- Substituir a composição atual do catálogo por cards expansíveis que ocupem toda a largura e altura úteis do painel e refazer Detalhes em duas zonas flexíveis, também full-width/full-height, preservando o Shell, tokens Nuxt UI e comportamento responsivo do KontiveHub.
- Disponibilizar o mesmo fluxo de “Nova conversa” no workspace, em cada card e nos detalhes, com escolha explícita de inbox e identidade, primeira mensagem e um anexo opcional.
- Adicionar iniciação outbound tenant-safe e idempotente em `POST /api/v1/communication/conversations`, com persistência atômica, gate fail-closed e uso do transporte `MESSAGE_SEND` já existente.
- Adicionar catálogos cursorizados de conteúdo compartilhado por conversa e por contato, organizados em Mídias, Links e Documentos, com preview/download autenticados e navegação até a mensagem de origem.
- Evoluir a timeline de forma aditiva para ancorar uma página em uma mensagem específica.
- Atualizar OpenAPI público, tipos/clientes Nuxt, testes de contrato e validações visuais. Não alterar o Wazync nem seu contrato privado.
- Manter fora do escopo extração ou backfill de URLs no corpo criptografado, múltiplos anexos na primeira mensagem, busca remota de metadados de link, notes/segments/merge manual e mudanças de branding.

## Capabilities

### New Capabilities

- `communication-outbound-conversation-initiation`: Iniciação segura de conversa 1:1 a partir de contato/identidade/inbox com primeira mensagem, anexo opcional, idempotência e rollout fail-closed.
- `communication-shared-content`: Consulta e experiência agregada de Mídias, Links e Documentos por conversa ou contato, com autorização, paginação e origem navegável.

### Modified Capabilities

- `communication-contacts-catalog`: O catálogo passa a usar cards expansíveis e oferece ações contextuais consistentes, inclusive iniciar conversa.
- `communication-contacts-experience`: Detalhes adotam perfil e contexto full-width/full-height no desktop, com slideover responsivo alinhado ao Chatwoot, incluindo acesso a conteúdo compartilhado e nova conversa.
- `communication-contact-conversation-history`: A navegação contato↔conversa passa a suportar abertura ancorada na mensagem de origem.
- `ui-archetypes-admin-chrome`: O catálogo administrativo passa a aceitar a variante de cards centralizados quando a referência de domínio exigir expansão inline.
- `ui-archetypes-master-detail`: Rails contextuais de contato e conversa passam a acomodar uma subvisão navegável de conteúdo compartilhado no desktop e mobile.

## Impact

- `apps/api`: novas requests/actions/queries/resources e rotas públicas, extensão da timeline, configuração de rollout, OpenAPI e testes. Sem migration prevista.
- `apps/web`: catálogo, detalhes, workspace, contexto de conversa, modal outbound, galeria/viewer, composables, tipos e testes responsivos/visuais.
- Segurança: leitura continua em `communication.view`; iniciação exige `communication.reply` e inbox visível. Nenhum JID, LID, caminho de storage, ciphertext ou payload bruto será exposto.
- Egress: iniciação fica desabilitada por padrão, com kill switch ativo e allowlist vazia; o dispatcher revalida o gate antes do transporte.
- Compatibilidade: mudanças em `/api/v1` são aditivas; preview/download e `MESSAGE_SEND` permanecem compatíveis. API deve ser publicada antes da SPA.
