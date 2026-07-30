## Context

O catálogo de contatos de comunicação vive em:

- Lista: `apps/web/app/pages/communication/contacts/index.vue` → `CommunicationContactsCatalog` + `useCommunicationContactsCatalog`
- Ficha: `apps/web/app/pages/communication/contacts/[id].vue` (monólito ~620 linhas)
- API dona: `CommunicationContactController` + resource com identities/links mascarados
- Workspace: `CommunicationWorkspacePage` + `ContextPanel` (export/purge, sem deep-link para a ficha)

Referências:

- **Chatwoot** (`.local/references/chatwoot/.../components-next/Contacts`): IA list→detail, card de identidade, header com search/sort/more, empty states, sidebar de detalhe (notes/history/merge) — fonte de **comportamento de domínio**, não de tokens/stack.
- **Dashboard Nuxt UI** (`.local/references/dashboard` customers): chrome visual lista admin — fonte de **fidelidade** via Shell (`ShellPagePanel`, `ShellDataTable`, `ShellListFilterToolbar`).

Constraints: SPA Nuxt sem Pinia/SSR; permissões `communication.view` / `communication.manage_contacts`; PII mascarada; paginação offset 10/20/50; sem Wazync na SPA.

## Goals / Non-Goals

**Goals:**

1. Lista legível em 1s: célula de identidade (iniciais + nome + sinal provisório), WhatsApp mascarado, clientes, status, ações de linha.
2. Ficha seccionada e testável, com hierarquia clara e zona destrutiva (export/purge) no final ou no header.
3. Deep-link workspace → ficha e ficha/lista → workspace de conversas (rota canônica do inbox, sem inventar filtro se a API não suportar `contact_id`).
4. Create modal alinhado ao `StoreContactRequest` (vínculo opcional completo quando o usuário escolher cliente).
5. Permissões de export/purge no workspace alinhadas a `manage_contacts`.
6. Manter URL-sync, sessionEpoch, stale-on-refresh e testes de contrato existentes.

**Non-Goals:**

- Segments, labels avançados, notes, media gallery, presence, voice call.
- Bulk select/delete/labels sem endpoints de lote.
- Merge manual de contatos (backend LID↔PN já correlaciona).
- Import/export CSV de catálogo.
- Infinite scroll / load-more.
- Mudanças no Wazync ou em receipts.
- Virar a ficha em master-detail dual-panel (inbox archetype).

## Decisions

### D1 — Chrome visual = customers/Shell; domínio = Chatwoot

**Decisão:** Preservar `ShellPagePanel` + `ShellDataTable` (`ui-preset="monitoring-compact"` já usado em contatos/clients). Não copiar `ContactsCard` empilhado do Chatwoot.

**Alternativa:** Lista de cards gap-4 como Chatwoot.

**Por quê não:** Quebra gates de lista admin e densidade do escritório; clients já usa table.

### D2 — Ficha = settings sections, não inbox dual-panel

**Decisão:** Extrair componentes sob `components/communication/contacts/`:

- `DetailPage.vue` (orquestra load/estado) ou manter page fina + `ContactDetail.vue`
- `ContactProfileSection.vue`
- `ContactIdentitiesSection.vue`
- `ContactLinksSection.vue`
- `ContactPrivacySection.vue` (export/purge + confirmação)
- Composable `useCommunicationContactDetail.ts` espelhando o padrão DI do catálogo

**Alternativa:** Sidebar de tabs Chatwoot.

**Por quê não no v1:** Sem notes/history/media na API; tabs vazias pioram UX. Seções empilhadas `max-w-3xl` batem com settings e com o audit parcial atual.

### D3 — Célula de identidade rica sem avatar remoto

**Decisão:** `UAvatar` com iniciais derivadas do nome (ou `?` / `#id` se provisório). Sem foto de perfil WhatsApp no catálogo (gateway é per-inbox e não faz parte do list resource).

### D4 — Deep-link conversas

**Decisão:**

- Workspace → ficha: botão “Abrir ficha” no `ContextPanel` quando `conversation.contact.id` existe → `communicationContactPath(id)`.
- Ficha → conversas: botão “Conversas” → `COMMUNICATION_PATH` (inbox). Se no futuro a lista de conversas aceitar `contact_id` na query, evoluir aditivamente; **não** inventar filtro client-side.

**Alternativa:** Listar conversas do contato na ficha.

**Por quê não agora:** Requer endpoint ou filtro de list conversations por contact; fora do escopo se não existir.

### D5 — Create modal

**Decisão:** Manter `ShellFormModal`. Quando `client_id` selecionado, carregar `ClientContact` do cliente e expor select opcional + checkboxes `is_primary` / `receives_automatic`, já aceitos pelo POST.

### D6 — Permissões workspace

**Decisão:** Export/purge no `ContextPanel` / workspace usam `canManageCommunicationContacts`, não `canManageCommunication` (inboxes). View da ficha permanece `canViewCommunication`.

### D7 — API

**Decisão preferencial:** zero mudança de API. Só enriquecer UI com campos já no resource.

Se `created_at` for necessário na tabela e ausente no JSON, adicionar campo aditivo no `CommunicationContactResource` + teste feature, sem BREAKING.

## Risks / Trade-offs

| Risco | Mitigação |
|-------|-----------|
| Gates de fidelity/source-string quebram com rename de componentes | Atualizar `communication-contacts.test.ts` e parity matrix no mesmo change |
| Deep-link “Conversas” sem filtro de contact frusta usuário | Label honesta (“Ir para conversas”); não prometer filtro inexistente |
| Permissão workspace mais restrita some botões para quem só tem manage_inboxes | Correto: API já exige manage_contacts; UI deixa de mentir |
| Ficha extraída demais aumenta boilerplate | Composable DI testável + seções finas; page orquestra |
| Avatar com iniciais colide com branding | Usar tokens Nuxt UI padrão; sem cor/canal inventados |

## Migration Plan

1. Extrair utils de apresentação (iniciais, actions) e testes unitários.
2. Refatorar lista (células + actions menu) sem mudar contrato de query.
3. Extrair ficha em composable + seções; page vira orquestrador fino.
4. Wire deep-links e permissões no workspace.
5. Atualizar create modal.
6. Rodar testes unitários focados → fidelity → typecheck/lint.
7. Validação visual desktop/mobile no browser (agentes Playwright/Chrome).

Rollback: reverter commits da SPA; sem migration de schema se API não mudar.

## Open Questions

- Nenhuma bloqueante. Histórico de conversas na ficha fica para change futura quando houver listagem por `contact_id`.
