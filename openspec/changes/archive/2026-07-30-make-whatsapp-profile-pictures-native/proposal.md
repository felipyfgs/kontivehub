## Why

As fotos reais de contatos e conversas WhatsApp foram modeladas, mas continuam invisíveis porque a aquisição e a exposição dependem de feature flag, kill switch, allowlist de tenants e allowlist de hosts vazia. Foto de perfil é parte nativa de uma inbox WhatsApp operacional e precisa funcionar automaticamente, preservando privacidade, tenancy e os limites de egress do gateway.

## What Changes

- Remover os switches exclusivos de fotos (`ENABLED`, `FETCH_KILL_SWITCH`, allowlist de tenants) e a allowlist de hosts configurável que hoje funciona como bloqueio implícito.
- Derivar aquisição somente da disponibilidade real de Communication, tenant, inbox, sessão e identidade WhatsApp; assets `READY` continuam legíveis durante desconexões temporárias.
- Substituir a allowlist configurável por política de URL fixa e fail-closed para HTTPS/443 em `whatsapp.net` e subdomínios, com DNS público, conexão pinada, TLS, MIME, tamanho e dimensões validados.
- Alinhar o deadline de `PROFILE_PICTURE` ao WhatsMeow, disparar refresh idempotente em criação/atualização de contato, conversa, mensagem e evento `Picture`, e incluir contatos sem conversa no backfill limitado.
- Expor `profile_picture_url` e `profile_picture_state` de forma estável nos Resources e atualizar lista, timeline, contexto e catálogo por evento sanitizado após commit.
- Substituir fixtures/interceptações de browser no aceite por validação real Laravel-Wazync-WhatsApp e Playwright, incluindo smoke autorizado no contato terminado em `2709`.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `communication-whatsapp-profile-pictures`: fotos passam a ser comportamento nativo, sem switches ou rollout por tenant, com aquisição automática e política fixa de URL.
- `communication-conversation-workspace`: conversas passam a receber e atualizar foto real com estado explícito, mantendo iniciais apenas como fallback verdadeiro.
- `communication-contacts-experience`: catálogo e detalhe passam a compartilhar a mesma projeção real e atualização de avatar.

## Impact

- **API Laravel:** remoção de config/serviço de rollout, dispatcher/job/resolver/Resources, evento after-commit, testes e integração da migration/asset privado existente.
- **Wazync:** deadline específico para `GetProfilePictureInfo`; contrato privado permanece compatível.
- **Contrato `/api/v1`:** `profile_picture_url` torna-se sempre presente e nullable; `profile_picture_state` é aditivo.
- **SPA Nuxt:** tipos e merges de conversas/contatos passam a reagir ao estado/versão real sem dados sintéticos.
- **Operação:** desaparecem quatro chaves de ativação; permanecem apenas limites de segurança/disponibilidade e os gates naturais `COMMUNICATION_ENABLED`, `WAZYNC_ENABLED`, tenant e inbox.
- **Egress:** leitura remota ocorre somente para inbox WhatsApp ativa e conectada; automações, outbound e recuperação de mídia mantêm seus switches próprios.
