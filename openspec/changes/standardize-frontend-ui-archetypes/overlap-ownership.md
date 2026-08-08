# Ownership de arquivos sobrepostos

Data da delimitação: 2026-08-04.

O change `complete-whatsapp-message-composer` mantém ownership funcional do
composer, dos rascunhos tipados e das famílias outbound. Este change de
padronização pode somente reaplicar contratos transversais de autorização,
tokens, semântica, foco, movimento, responsividade e runtime sobre o diff mais
recente, sem remover ou reestruturar comportamento do composer.

## Arquivos protegidos pelo composer

- `apps/web/app/components/communication/Composer.vue` e futuros componentes de
  launcher, picker, preview, recorder e formulários estruturados;
- `apps/web/app/composables/useCommunicationComposerDrafts.ts`;
- `apps/web/app/types/communication/composer-draft.ts`;
- `apps/web/app/utils/communication-composer-draft.ts`;
- `apps/web/app/utils/communication-composer-draft-api.ts`;
- `apps/web/tests/unit/communication-composer-drafts.test.ts`;
- contratos outbound e alterações correspondentes em `apps/api` e
  `apps/wazync`.

Esses arquivos não são alterados por `standardize-frontend-ui-archetypes`.

## Arquivos compartilhados estabilizados

Os arquivos abaixo já continham alterações de comunicação quando este lote foi
aplicado. Mudanças deste change ficam limitadas ao requisito transversal
indicado e preservam a lógica existente:

- `components/communication/ConversationList.vue`: semântica `list/listitem`,
  estado atual/busy e alvo móvel;
- `components/communication/ContextPanel.vue`, `TimelinePanel.vue` e
  `MessageContent.vue`: tokens semânticos, tipografia legível, attrs/alt e
  feedback acessível;
- `components/communication/contacts/CatalogTable.vue` e
  `contacts/ProfileSection.vue`: tokens semânticos, lista/ação e avatar;
- `components/communication/ProfileAvatar.vue`: fallback visual de foto;
- `plugins/communication-realtime.client.ts`: inicialização condicional,
  import sob demanda e teardown por sessão/permissão/tenant;
- `pages/communication/flows/**` e
  `pages/communication/quick-responses/index.vue`: guarda de rota antes de
  loaders, sem mudar contratos de domínio;
- `utils/communication.ts`, `utils/communication-contacts.ts` e testes de
  comunicação relacionados: apenas contratos visuais/runtime já descritos.

## Regra de integração

O diff atual é o baseline estabilizado. Integração e correções futuras devem ser
aditivas sobre ele, sem checkout/revert de arquivos compartilhados. Qualquer
mudança funcional no composer, nos drafts ou nos contratos outbound permanece
fora deste change e deve ser concluída no change do composer.
