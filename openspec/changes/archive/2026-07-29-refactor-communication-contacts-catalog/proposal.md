## Why

> **Status:** substituída por `redesign-communication-contacts-experience`. Este
> artefato permanece como histórico da primeira direção, rejeitada na validação
> de produto; não deve orientar novas alterações visuais.

O catálogo de contatos de comunicação já é um CRUD Shell funcional, mas a experiência operacional fica aquém da referência Chatwoot e do workspace de conversas: a lista é densa e genérica, a ficha é monólito, e o fluxo conversa↔contato é desconectado. O escritório precisa de um catálogo legível em 1 segundo, ficha seccionada e deep-links com o inbox, sem copiar branding, infinite scroll ou features sem contrato na API Laravel.

## What Changes

- Refatorar a **lista de contatos** com anatomia de linha inspirada no Chatwoot (identidade com avatar/iniciais, WhatsApp mascarado, vínculos, status) e chrome visual do arquétipo **customers** via Shell (`ShellPagePanel`, `ShellDataTable`, toolbar de filtros).
- Refatorar a **ficha de contato** extraindo seções (perfil, identidades, vínculos, privacidade/LGPD) e melhorando hierarquia: header com status, ações contextuais e zona destrutiva separada.
- Adicionar **deep-link bidirecional**: da ficha e da lista para o workspace de conversas (quando possível) e do `ContextPanel` da conversa para a ficha do contato.
- Completar o **create modal** com campos opcionais já suportados pela API (`client_contact_id`, flags de vínculo) e empty states diferenciados (zero data vs filtros).
- Alinhar **permissões** de export/purge no workspace a `communication.manage_contacts`.
- Manter **fora do escopo**: segments/saved views, bulk actions sem API, merge manual, import/export CSV de catálogo, notes/media/presence Chatwoot, infinite scroll, mudanças no Wazync, e alteração breaking de contratos `/api/v1` (só aditivo se necessário para campos de apresentação).

## Capabilities

### New Capabilities

- `communication-contacts-catalog`: Catálogo de contatos de comunicação na SPA — lista, filtros, criação, ficha seccionada, deep-links e gates de permissão/privacidade.

### Modified Capabilities

- (nenhuma — o chrome Shell de lista admin permanece o contrato vigente; a capability nova fixa o comportamento de domínio do catálogo de contatos sobre esse chrome)

## Impact

- `apps/web`: páginas e componentes em `communication/contacts/*`, composables, utils, `ContextPanel`/workspace, testes unitários e fixtures de parity/fidelity.
- `apps/api`: sem mudança obrigatória de schema; apenas consumo aditivo de campos já existentes no resource. Se a apresentação exigir `created_at` ou contagem de identidades, a evolução do resource será aditiva e coberta por teste de catálogo.
- Não afeta `apps/wazync`, contratos privados, egress WhatsApp nem o domínio fiscal `ClientContact` além do vínculo já existente.
- Rollout: frontend-only preferencial; se houver adição de campo no resource, publicar API antes da SPA consumir.
- Segurança: PII continua mascarada; export/purge gated; sem JID cru na UI; logs sem telefone plaintext.
