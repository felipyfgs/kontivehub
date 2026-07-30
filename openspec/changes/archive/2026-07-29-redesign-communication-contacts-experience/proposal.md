## Why

A primeira refatoração do catálogo de contatos entregou funcionalidade, mas não atingiu a qualidade visual e operacional esperada: a lista continua genérica, a ficha dispersa a leitura em cards e o telefone útil fica mascarado. Precisamos substituir essa direção por uma experiência fiel ao Chatwoot atual, adaptada ao Shell/Nuxt UI do KontiveHub e conectada ao histórico real de conversas, sem ampliar a superfície de dados sensíveis para identificadores técnicos.

## What Changes

- Substituir a experiência entregue por `refactor-communication-contacts-catalog`, preservando seus artefatos como histórico, por uma lista compacta de largura total e uma rota de detalhe separada inspiradas no Chatwoot mais recente em `.local/references/chatwoot`.
- Exibir o telefone completo para usuários com `communication.view`, somente quando a identidade puder ser apresentada como E.164 seguro; manter `address_masked` no contrato por compatibilidade, mas não o usar na nova interface.
- Evoluir de forma aditiva `GET /api/v1/communication/conversations` com `contact_id`, combinado aos filtros existentes e aos limites de inbox, tenant e conversa canônica.
- Permitir pesquisa de contatos por telefone completo normalizado usando `address_hash`, enviando o valor sensível em POST/body e sem varrer ou descriptografar identidades.
- Reestruturar `/communication/contacts` com navbar e toolbar persistentes, busca com debounce, estado não sensível em URL, linhas semânticas responsivas, paginação e ações reutilizáveis.
- Reestruturar `/communication/contacts/:id` com perfil principal de leitura confortável e contexto lateral em abas para conversas, identidades, vínculos e privacidade; abaixo de `lg`, apresentar o contexto em `USlideover` com foco e fechamento acessíveis.
- Integrar o histórico recente do contato ao detalhe e permitir abrir o workspace com um filtro removível de contato.
- Preservar gates: `communication.view` para leitura e telefone; `communication.manage_contacts` para criação, edição, exportação e expurgo.
- Manter fora do escopo: segments/saved views, bulk actions de contato, merge manual, importação, notes/media, labels manuais, chamadas, infinite scroll, mudanças no Wazync e qualquer egress real.

## Capabilities

### New Capabilities

- `communication-contacts-experience`: Lista e detalhe responsivos de contatos, apresentação segura de telefone completo, estados, ações e permissões.
- `communication-contact-conversation-history`: Filtro tenant-safe de conversas por contato e integração navegável entre ficha e workspace.

### Modified Capabilities

- `ui-archetypes-admin-chrome`: A lista de contatos passa a aplicar a anatomia administrativa do template com densidade e comportamento responsivo específicos do catálogo.
- `ui-archetypes-master-detail`: O contexto secundário do detalhe passa a usar painel lateral no desktop e `USlideover` abaixo de `lg`.

## Impact

- `apps/api`: Resource e query de contatos, validação/DTO/query de conversas, contrato OpenAPI público e testes de contrato, autorização e isolamento. Não há migration.
- `apps/web`: páginas de contatos, componentes e composables reutilizáveis, tipos gerados/manuais, filtro do workspace, estados responsivos e testes Vitest/Playwright.
- Consumidores: a SPA recebe campos e filtro aditivos; `address_masked` permanece disponível para clientes existentes. API deve ser publicada antes da SPA.
- Segurança: nenhum JID, LID, hash, ciphertext ou identificador do gateway será exposto; telefone só sai quando normalizado como E.164 seguro. Contato inexistente ou de outro tenant não revela existência pelo filtro.
- Dependências e runtime: sem atualização de Nuxt UI, Nuxt, Laravel ou Wazync; sem flags, filas, egress ou migrations novas.
