## 1. Contratos e fundação

- [x] 1.1 Validar a change OpenSpec em modo strict e confirmar integração com changes ativas sem reverter o worktree
- [x] 1.2 Fixar tipos públicos, DTOs e envelopes para iniciação outbound, conteúdo compartilhado e âncora por mensagem
- [x] 1.3 Confirmar referências Chatwoot/dashboard, Nuxt UI instalado, breakpoints e testes de fidelidade antes da edição visual

## 2. API de conteúdo compartilhado

- [x] 2.1 Implementar request, autorização e query cursorizada/snapshot por conversa para Mídias, Links e Documentos
- [x] 2.2 Implementar agregação por contato canônico/doadores limitada a inboxes visíveis e filtro opcional de inbox
- [x] 2.3 Criar resources/controllers/rotas com resposta allowlisted, `private, no-store` e links de preview/download autenticados
- [x] 2.4 Cobrir categorias, paginação, tenant, inbox membership, merge, purge, revoke, view-once e ausência de campos sensíveis

## 3. API de âncora da timeline

- [x] 3.1 Estender request/query de mensagens com `anchor=message` e `message_id` validado na conversa visível
- [x] 3.2 Cobrir mensagem disponível, estrangeira, expurgada e paginação anterior/posterior sem regressão das âncoras existentes

## 4. API de iniciação outbound

- [x] 4.1 Implementar request multipart e autorização por `communication.reply`, contato/identidade/inbox tenant-safe e self-chat
- [x] 4.2 Implementar gate/configuração fail-closed e projeção aditiva em outbound capabilities
- [x] 4.3 Extrair writer transacional compartilhado com staging/cleanup de blob, evento/outbox e efeitos after-commit
- [x] 4.4 Implementar correlação reutilizar/reabrir/criar sob lock e idempotência que inclui destino e digest
- [x] 4.5 Revalidar o gate no dispatcher sem alterar o contrato `MESSAGE_SEND` do Wazync
- [x] 4.6 Cobrir texto, tipos de anexo, replay/conflito, concorrência, rollback, permissões, flags/allowlist/kill switch e self-chat

## 5. Contratos públicos e cliente Web

- [x] 5.1 Atualizar gerador/OpenAPI público para as três superfícies novas e campos de capabilities
- [x] 5.2 Regenerar ou alinhar tipos públicos e adicionar tipos/composables Nuxt para shared content e iniciação
- [x] 5.3 Implementar URL canônica com `conversation_id`/`message_id` e consumo da âncora no workspace

## 6. Catálogo e detalhes Chatwoot-like

- [x] 6.1 Refatorar catálogo para header único e coleção full-width/full-height com cards `rounded-xl`, gap 4 e avatar de 42 px
- [x] 6.2 Implementar expansão exclusiva, formulário resumido, confirmação de alterações não salvas e ações reutilizáveis
- [x] 6.3 Ajustar Detalhes para perfil e contexto flexíveis full-width/full-height, reutilizando o contexto em slideover abaixo de `lg`
- [x] 6.4 Integrar o modal compartilhado “Nova conversa” no workspace, cards e detalhes com estados/gates reais

## 7. Área Mídias, Links e Documentos

- [x] 7.1 Criar módulo reutilizável de teaser e vista completa com carregamento cursorizado independente por categoria
- [x] 7.2 Implementar grid de mídia, listas de links/documentos, empty/error/retry e download autenticado
- [x] 7.3 Implementar viewer acessível com imagem/vídeo/áudio, navegação, contador, zoom/rotação, download e retorno de foco
- [x] 7.4 Integrar teaser/vista completa nos contextos de conversa e contato em desktop e mobile
- [x] 7.5 Implementar “Ir para mensagem” com seleção, carga, scroll e destaque da origem

## 8. Testes e validação

- [x] 8.1 Atualizar testes Web de cards, expansão, formulário, permissões, modal outbound, galeria, viewer, deep-link e estados
- [x] 8.2 Atualizar matriz de paridade, inventários e fixtures contratuais sem ampliar allowlists indevidas
- [x] 8.3 Executar testes focados e gates completos da API
- [x] 8.4 Executar lint, typecheck, generate, testes, fidelity e artifacts da Web
- [x] 8.5 Validar visualmente contra Chatwoot em claro/escuro e 1440×900, 1024×768, 768×1024 e 390×844
- [x] 8.6 Executar detector e revisão final Impeccable, corrigir achados materiais e registrar evidências
- [x] 8.7 Validar OpenSpec strict e marcar todas as tarefas concluídas somente com evidência correspondente

## Evidências finais — 2026-07-29

- API focada: `CommunicationSharedContentAndInitiationTest` com 12 testes e 131 asserções, mais `CommunicationOutboundInitiationConcurrencyTest` com 1 teste e 14 asserções. A cobertura inclui categorias, paginação, tenancy/membership, merge, purge/revoke/view-once, âncora por mensagem, texto, AUDIO/VIDEO/DOCUMENT/STICKER, replay/conflito, self-chat, gates, rollback integral e concorrência PostgreSQL multiprocesso.
- Writer compartilhado: `CommunicationOutboundMessageWriter` é implementado por `CreateCommunicationMessageAction` e consumido também por `StartCommunicationConversationAction`, com staging/cleanup e persistência transacional de mensagem, anexo, evento e outbox.
- API completa: Composer strict e Pint em 3.300 arquivos; PHPUnit com 1.272 testes e 17.777 asserções.
- Web: lint, typecheck e generate concluídos; 121 arquivos/614 testes; fidelity 74/74; artifacts inspecionou 439 arquivos sem material sensível.
- Runtime Web focado: viewer de mídia, zoom/rotação/teclado/paginação/retry/download/deep-link e modal outbound com áudio/PTT, retry, idempotência e gates reais.
- Playwright local: 12/12 cenários passaram. A matriz de contatos cobriu 1440×900, 1024×768, 768×1024 e 390×844 em claro/escuro; operações cobriram teclado, foco, touch, detalhe e resultados bulk. Nenhum artefato E2E foi versionado.
- Matriz de paridade, inventários e fixtures foram exercitados por fidelity/artifacts sem ampliar allowlists.
- Impeccable: detector único executado nas seis superfícies de contatos/conteúdo compartilhado, sem achados.
- OpenSpec strict: change válida após o fechamento do checklist.
