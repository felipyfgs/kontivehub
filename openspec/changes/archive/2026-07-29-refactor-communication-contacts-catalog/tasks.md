## 1. Fundação e inventário

- [x] 1.1 Confirmar referência dashboard (ui-archetypes check) e mapear gaps lista/ficha vs Chatwoot portável
- [x] 1.2 Estender helpers de apresentação em `utils/communication-contacts.ts` (iniciais, contagem de identidades, labels de ação) com testes unitários
- [x] 1.3 Garantir paths/testids estáveis e permissões `canView` / `manage_contacts` documentadas nos testes de contrato

## 2. Lista de contatos

- [x] 2.1 Enriquecer `CatalogTable` com célula de identidade (UAvatar + nome + provisório), badges de multi-identidade se >1, clientes e status
- [x] 2.2 Adicionar menu de ações de linha (abrir ficha; ir para conversas)
- [x] 2.3 Refinar empty states (zero data com CTA vs filtros com limpar) e aria-labels
- [x] 2.4 Completar `CreateModal` com `client_contact_id` e flags quando `client_id` estiver selecionado; alinhar body do composable
- [x] 2.5 Manter URL-sync, sessionEpoch, stale e `ui-preset` Shell; não introduzir infinite scroll

## 3. Ficha de contato

- [x] 3.1 Extrair `useCommunicationContactDetail` (DI testável) a partir da lógica de `[id].vue`
- [x] 3.2 Extrair seções: Profile, Identities, Links, Privacy (export/purge + confirm)
- [x] 3.3 Header da ficha: status badge, ação “Conversas”, export/purge só com manage_contacts e não purged
- [x] 3.4 Page `[id].vue` fina: gate + orquestração de seções/modais

## 4. Integração workspace

- [x] 4.1 No `ContextPanel`, ação “Abrir ficha” → `communicationContactPath`
- [x] 4.2 Gatear export/purge do painel com `canManageCommunicationContacts`
- [x] 4.3 Atualizar `CommunicationWorkspacePage` se passar props de permissão

## 5. Testes e paridade

- [x] 5.1 Atualizar `communication-contacts.test.ts` (source gates, create body, helpers, detail extract)
- [x] 5.2 Cobrir deep-link e perms no workspace (unit/source gate mínimo)
- [x] 5.3 Atualizar `template-parity-matrix` se necessário; sem ampliar allowlists de fidelity
- [x] 5.4 Rodar testes unitários focados e gates web do app alterado

## 6. Validação visual

- [x] 6.1 Validar desktop lista + ficha (hierarquia, densidade, empty, loading)
- [x] 6.2 Validar mobile (cards Shell, navbar, seções, modais)
- [x] 6.3 Validar teclado/foco e contraste de badges/ações
- [x] 6.4 Corrigir regressões visuais encontradas
