## Context

A change `refactor-communication-contacts-catalog` deixou um catálogo funcional no worktree, porém sua validação visual não foi aceita: a tabela Shell ficou genérica, a ficha empilhou seções em cards e a identidade primária continuou mascarada. Esta change substitui essa direção sem apagar o trabalho anterior e parte das referências locais nas revisões atuais:

- Chatwoot `ed5a099425a55af1ab0ea9c5737e8521fabda306`: `ContactsListLayout.vue`, `ContactsCard.vue`, `ContactManageView.vue` e `ContactsDetailsLayout.vue`;
- template Nuxt UI `31970177d818eae501c142f4d6c17489cfad5b5a`: `pages/customers.vue`, `pages/inbox.vue` e `components/inbox/*`;
- Nuxt UI instalado no app: 4.9.0, sem atualização de dependência.

Hoje `CommunicationContactResource` expõe somente `address_masked`; a busca do catálogo consulta nome e máscara. A listagem de conversas já aplica inboxes visíveis e omite conversas doadoras, mas não aceita `contact_id`. A SPA possui rotas separadas de lista e detalhe, mutations de perfil/identidade/vínculo/privacidade e um workspace de conversas com filtros em URL.

A API Laravel continua dona de autorização, tenancy, correlação e apresentação segura de identidade. O Nuxt apenas renderiza o contrato público e nunca acessa Wazync ou ciphertext.

## Goals / Non-Goals

**Goals:**

- Entregar lista e detalhe de contatos com hierarquia, densidade, responsividade e acessibilidade comparáveis ao Chatwoot atual.
- Mostrar telefone completo útil a todo usuário autorizado por `communication.view`, sem expor identificadores técnicos.
- Oferecer histórico real de conversas do contato e navegação bidirecional com o workspace.
- Preservar compatibilidade de `/api/v1`, contratos de autorização e dados do worktree que não pertencem a esta change.
- Consolidar ações de contato em uma única fonte reutilizável para linha, navbar e painel contextual.

**Non-Goals:**

- Implementar segments, saved views, bulk actions, infinite scroll, merge manual, importação, notes, media, labels manuais ou chamadas.
- Alterar Wazync, o contrato privado, schemas de banco, flags, filas ou egress.
- Copiar branding, dependências, mocks, dados ou componentes proprietários do Chatwoot.
- Transformar a rota de lista em master-detail; o detalhe permanece uma rota própria.

## Decisions

### 1. Evolução aditiva e apresentação segura do telefone

`CommunicationContactResource.identities[]` ganhará `phone: string|null`. O campo será preenchido somente quando:

1. contato e identidade não estiverem expurgados;
2. o valor descriptografado já representar uma identidade telefônica;
3. o valor final satisfizer `^\+[1-9]\d{7,14}$`.

LID (`lid:*` ou `*@lid`), JID, group JID, hash, ciphertext, valor inválido e tombstone produzirão `null`. `address_masked` será mantido sem mudança para consumidores existentes. A mesma apresentação segura será usada onde a conversa inclui contato. Como esse envelope já possuía `address`, ele permanece temporariamente como alias legado/deprecated de `phone`, sujeito à mesma autorização e validação; nunca volta a transportar o endereço técnico bruto.

Alternativas consideradas:

- remover `address_masked`: rejeitada por ser breaking;
- devolver `address_encrypted`/JID e normalizar na SPA: rejeitada por violar a fronteira de segurança;
- manter somente máscara: rejeitada pelo requisito operacional explícito.

### 2. Pesquisa telefônica por hash, sem varredura de ciphertext

Quando a busca potencialmente telefônica chegar pelo endpoint dedicado `POST /communication/contacts/search`, `CommunicationContactQuery` tentará normalizá-la por `WhatsappAddressNormalizer` para E.164, calculará o mesmo SHA-256 usado na escrita e acrescentará uma correspondência exata em `identities.address_hash`. Nome e busca legada por máscara continuam disponíveis para compatibilidade. Entradas não telefônicas seguem o `GET` textual; não haverá decrypt scan nem telefone pesquisado em URL, Referer ou access/error log do proxy.

A SPA classifica conservadoramente como sensível qualquer busca com oito ou mais dígitos, envia os filtros pelo body e não persiste esse `q` na URL. Busca textual, filtros estruturados, ordenação e paginação continuam restauráveis. A busca telefônica é deliberadamente efêmera: ao sair ou recarregar a rota, precisa ser redigitada. Essa exceção de segurança prevalece sobre restauração de URL.

Alternativa considerada: descriptografar identidades e filtrar em memória. Rejeitada por custo, risco de PII e perda de paginação correta.

### 3. `contact_id` no endpoint canônico de conversas

`ListCommunicationConversationsRequest` aceitará `contact_id` inteiro positivo e o DTO levará `?int`. O filtro será aplicado no mesmo `CommunicationConversationQuery`, junto a inbox, status, assignee, labels, unread, busca, ordenação e paginação.

Antes de filtrar, a query resolve o contato dentro do `CurrentTenant`. Se o ID for um doador, segue `merged_into_contact_id` até o contato canônico com limite defensivo contra ciclos. Em seguida inclui o ID canônico e todos os doadores tenant-scoped que apontam para ele, para não perder histórico pré-merge. Contato inexistente, estrangeiro ou cadeia inválida resulta em conjunto vazio; formato inválido continua sendo `422`. A própria query ainda exige inbox visível e `merged_into_conversation_id IS NULL`.

Essa semântica evita um endpoint paralelo de histórico e permite que lista, detalhe e workspace usem o mesmo resource/paginação. Não haverá `404` específico, pois isso distinguiria contato estrangeiro de inexistente.

### 4. Lista como superfície administrativa de largura total

`/communication/contacts` continuará em `ShellPagePanel`/`ShellPageNavbar`, com `UDashboardToolbar` ou Shell equivalente no header. A busca terá debounce de 300 ms e busca textual, filtros, ordenação, página e tamanho permanecerão na URL; telefone completo nunca será serializado nela.

O corpo usará uma lista de linhas semânticas compactas, inspirada no `ContactsCard` atual do Chatwoot:

- avatar soft-square de 40–42 px, iniciais locais e sem imagem remota;
- nome como ação primária, indicador provisório e quantidade de identidades;
- telefone E.164 completo com ação de copiar;
- vínculos fiscais, situação e menu contextual;
- divisores e superfícies sem cartões elevados por linha.

No desktop, os metadados mantêm colunas alinhadas. Em telas estreitas, a mesma linha reordena identidade, telefone/vínculo e ações, sem tabela horizontal e sem ocultar o telefone. Paginação continua explícita; bulk selection e infinite scroll ficam fora.

### 5. Detalhe em duas zonas, com contexto adaptativo

`/communication/contacts/:id` manterá uma navbar fina com retorno que preserva a query da lista, status e ações. O corpo terá:

- zona principal plana, centralizada em `max-w-2xl`, com cabeçalho de identidade, telefone, edição de perfil e ações de mutação;
- zona contextual direita de largura limitada com `UTabs`: Conversas, Identidades, Vínculos e Privacidade.

Em `lg+`, o contexto permanece visível como painel lateral com borda semântica. Abaixo de `lg`, um botão “Ver contexto” abre o mesmo componente em `USlideover`; `Esc` fecha, o foco fica contido pelo overlay e retorna ao gatilho. Nenhuma informação essencial existe apenas por hover.

As seções atuais serão reaproveitadas quando preservarem domínio e mutations, porém a composição visual será plana; cards decorativos e grandes vazios serão removidos.

### 6. Histórico e filtro navegável do workspace

A aba Conversas solicitará `per_page=10`, `contact_id` e ordenação por atividade recente. Cada item mostrará status, inbox/contexto, preview sem conteúdo sintético e horário, e navegará para a conversa canônica.

“Ver todas” abrirá `/communication` com `contact_id` na query. O workspace:

- incluirá `contact_id` no cliente HTTP e na sincronização URL↔estado;
- exibirá um chip removível `Contato: <nome>` quando o resource do contato estiver disponível;
- recarregará a lista ao remover o chip;
- preservará os demais filtros e o deep-link de conversa.

Falha ao carregar histórico será um estado local com retry; lista vazia informará que ainda não há conversas.

### 7. Ações reutilizáveis e gates

Um composable/builder de ações receberá contato, capacidades e handlers e devolverá grupos tipados para `UDropdownMenu`. `CommunicationContactsContactActions` será o único renderer de menu/ações compactas e será usado na linha, navbar e contexto quando aplicável.

Leitura, telefone, copiar e navegação exigem `communication.view`. Criação, edição, identidade, vínculo, exportação e expurgo exigem `communication.manage_contacts`; expurgo continua em confirmação destrutiva. Estados purged são somente leitura.

### 8. Contratos, testes e validação visual

OpenAPI e `apps/web/app/types/generated/public-api.ts` serão atualizados pelo fluxo existente; tipos manuais refletirão `phone` e `contact_id`. Testes API cobrirão apresentação segura, compatibilidade de máscara/alias, pesquisa hash por POST, validação, merge, inbox visibility e cross-tenant. Testes Web cobrirão transporte sensível fora da URL, debounce, telefone sem asteriscos, copy, gates, abas, histórico, filtro-chip, loading/error/empty e ações compartilhadas.

Playwright comparará luz/escuro em 1440×900, 1024×768, 768×1024 e 390×844. A revisão verificará overflow horizontal, densidade, foco, `Esc`, retorno de foco, labels, contraste e hierarquia em relação às referências locais.

## Risks / Trade-offs

- [Telefone plaintext amplia a superfície de PII autorizada] → devolver apenas E.164 validado, nunca identificadores técnicos; manter escopo em `communication.view`, contrato testado e nenhum log.
- [Descriptografia no resource pode aumentar custo] → limitar-se às identidades já eager-loaded na página e evitar qualquer decrypt scan em busca.
- [Histórico pré-merge pode ficar incompleto ou duplicado] → resolver IDs de contatos doadores, manter apenas conversas canônicas e testar cadeias/isolamento.
- [Worktree contém changes de conversa concorrentes] → tocar somente símbolos necessários, integrar `contact_id` de forma aditiva e não reverter campos/filtros existentes.
- [Lista sem `UTable` pode divergir do gate Shell] → preservar painel/navbar/toolbar/paginação e tokens do arquétipo; atualizar apenas os testes de contrato da rota, sem ampliar allowlists.
- [Painel lateral reduz largura do perfil em tablet] → breakpoint `lg`; abaixo dele usar slideover de largura responsiva.

## Migration Plan

1. Publicar a API aditiva com `phone`, pesquisa hash e `contact_id`.
2. Publicar a SPA; enquanto `phone` estiver ausente ou `null`, mostrar “Número indisponível”, nunca reconstruir máscara.
3. Validar telemetria técnica apenas por status/código e taxa de erro, sem telefone ou query.
4. Rollback da SPA restaura a interface anterior, ainda compatível com `address_masked`.
5. Rollback da API pode remover os campos/filtro novos somente depois da SPA; não há migration ou backfill.

## Open Questions

Nenhuma decisão bloqueante permanece. Funcionalidades Chatwoot fora do contrato atual continuarão explicitamente fora do escopo.
