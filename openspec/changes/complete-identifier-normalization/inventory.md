# Inventário de normalização

## API Laravel

| Família antiga | Regra nova |
|---|---|
| `Http\\Requests\\Communication\\Communication*` | remover `Communication`; bases recebem nomes semânticos como `TenantScopedRequest`, `FlowRequest`, `ConversationGatewayRequest`, `InboxGatewayRequest` e `AutomationRecipientsRequest` |
| `Http\\Requests\\Tenant\\*Tenant*` | remover `Tenant` quando for apenas o contexto do namespace; preservar conceitos `Settings`, `Member`, `AutXml` e `SerproAuthorization` |
| `Http\\Requests\\Work\\*Work*` | remover `Work`; `WorkRequest` vira `TenantScopedRequest` |
| `Actions\\Tenant\\*Tenant*Action` | remover `Tenant` |
| `Actions\\Work\\*Work*Action` | remover `Work` |
| `Controllers\\Api\\V1\\Work\\Work*Controller` | remover `Work` |
| `Http\\Resources\\Work*` | mover para `Http\\Resources\\Work` e remover `Work` |
| `Enums\\Communication\\Communication*Failure` | remover `Communication`, preservando cases e backing values |
| `Jobs\\Communication\\*Communication*Job` | remover `Communication`, sem alias do FQCN antigo |
| `Models\\Pagtoweb*` | usar `Models\\PagtoWeb*` com `$table = 'pagtoweb_*'` explícita |
| variáveis e métodos privados `pagtoweb*` | usar `pagtoWeb*`; remover frases redundantes quando a responsabilidade permanecer clara |

Exceções: Models, Policies e Commands em namespaces globais mantêm o prefixo de domínio; migrations, tabelas, constraints, códigos de erro e testes BDD descritivos não são encurtados.

## Web Nuxt

| Antigo | Novo |
|---|---|
| `flows/FlowBindingsSection.vue` | `flows/BindingsSection.vue` |
| `flows/FlowCatalogModals.vue` | `flows/CatalogModals.vue` |
| `flows/FlowCatalogTable.vue` | `flows/CatalogTable.vue` |
| `flows/FlowDraftSection.vue` | `flows/DraftSection.vue` |
| `flows/FlowEditorCanvas.client.vue` | `flows/EditorCanvas.client.vue` |
| `flows/FlowEditorInspector.vue` | `flows/EditorInspector.vue` |
| `flows/FlowEditorListMode.vue` | `flows/EditorListMode.vue` |
| `flows/FlowEditorPalette.vue` | `flows/EditorPalette.vue` |
| `flows/FlowMetadataSection.vue` | `flows/MetadataSection.vue` |
| `flows/FlowRunsUnavailable.vue` | `flows/RunsUnavailable.vue` |
| `flows/FlowVersionsSection.vue` | `flows/VersionsSection.vue` |
| `contacts/ContactActions.vue` | `contacts/Actions.vue` |
| `contacts/ContactContext.vue` | `contacts/Context.vue` |
| `quick-responses/QuickResponseEditorModal.vue` | `quick-responses/EditorModal.vue` |
| `quick-responses/QuickResponseDuplicateModal.vue` | `quick-responses/DuplicateModal.vue` |
| `quick-responses/QuickResponseDeactivateModal.vue` | `quick-responses/DeactivateModal.vue` |
| `pgdasd/PgdasdHistoryPeriodGrid.vue` | `pgdasd/HistoryPeriodGrid.vue` |
| `CommunicationWorkspacePage.vue` | `WorkspacePage.vue` |
| privados `CommunicationWorkspace*` | remover `Communication`; usar o contexto `Workspace*` |
| tipos `Communication*` | separar por subdomínio e remover o prefixo somente sem colisão |

Exceções: helpers exportados em `utils/communication-*` conservam o qualificador para não colidir no namespace global de auto-import.

## Wazync

| Antigo | Novo |
|---|---|
| `WAConnectTimeout` | `WhatsAppConnectTimeout` |
| `WAReadyTimeout` | `WhatsAppReadyTimeout` |
| `WAHTTPTimeout` | `WhatsAppHTTPTimeout` |
| `WAProxyURL` | `WhatsAppProxyURL` |
| `WARetryHandlers` | `WhatsAppRetryHandlers` |
| `WAZYNC_WA_*` | `WAZYNC_WHATSAPP_*` |
| `MediaFetcher` / `media` / `WithMediaFetcher` | `MediaSource` / `mediaSource` / `WithMediaSource` |
| `baseURL` no fetcher de mídia | `sourceURL` |
| `endpoint` no dispatcher | `eventIngestURL` |
| `testMediaStore` | `testSpoolStore` |

Exceções: JSON, comandos, queries, eventos, rotas, HMAC, métricas, SQL, `MediaRetry`, `WhatsMeow`, `JID`, `URL` e `MIME` permanecem inalterados.

## Remoções de compatibilidade anterior

| Superfície removida | Forma canônica exclusiva |
|---|---|
| middleware Web de conversão de query de browser | paths, estado de sessão e fragmentos emitidos diretamente pela SPA |
| `WAZYNC_EVENTS_URL` | `WAZYNC_EVENT_INGEST_URL` |
| `WAZYNC_MEDIA_URL` | `WAZYNC_MEDIA_SOURCE_URL` |
| foto com URL e estado ausente | URL utilizável somente com `profile_picture_state = READY` |
| filenames e textos `legacy`/`legado` | nomes canônicos sem terminologia de transição |
