## Context

A SPA possui dois padrões concorrentes: algumas listas mantêm estado local e removem queries, enquanto Communication, Work, documentos e catálogos espelham filtros e paginação em `route.query`. O master–detail já identifica conversa e tarefa no path, mas preserva filtros na query; reset de senha ainda transporta token/e-mail nela. O Chatwoot local separa identidade/contextos em paths, filtros transitórios em store e visões salvas no backend. O KontiveHub deve adotar essa hierarquia sob as restrições de SPA estática, sem Pinia e com isolamento por usuário/tenant.

## Goals / Non-Goals

**Goals:**

- Garantir que toda URL interna canônica exibida no navegador seja livre de query string.
- Preservar deep-links de recursos e contextos estáveis, master–detail, foco, teclado e filtros salvos.
- Manter filtros, paginação e ordenação transitórios durante a navegação da sessão sem persistência implícita.
- Canonicalizar URLs antigas de forma segura e preservar compatibilidade do contrato HTTP `/api/v1`.
- Remover token/e-mail de reset da query antes do primeiro request da página.

**Non-Goals:**

- Remover query parameters de requests HTTP, OpenAPI, paginação ou filtros Laravel.
- Tornar todo filtro compartilhável, salvar automaticamente o último estado ou adicionar Pinia/localStorage.
- Alterar banco, migrations, tenancy, autorização, Wazync ou o layout visual dos painéis.
- Manter para sempre o adaptador de URLs legadas.

## Decisions

### Estado transitório usa uma fundação tipada por superfície

`useSurfaceNavigationState<T>` usará `useState` para manter um mapa normalizado por superfície e contexto de sessão. A chave lógica inclui user, tenant e `sessionEpoch`; troca de identidade/tenant limpa estados e intenções. Cada domínio fornece defaults e normalizador, enquanto `patch`, `replace`, `reset` e consumo one-shot permanecem genéricos. Nenhum valor vai para localStorage ou para o servidor implicitamente.

Alternativas descartadas: refs por página perdem estado ao alternar index/detail; Pinia é proibido; persistência automática no backend surpreende o operador; sessionStorage para filtros amplia retenção de dados. SessionStorage será usado somente para o retorno autenticado one-shot, que precisa sobreviver ao redirect para login.

### Paths representam recursos e contextos estáveis

Permanecem canônicos `/communication/conversations/:id` e `/work/tasks/:id`. Serão adicionados:

- `/communication/conversations/:conversationId/messages/:messageId`;
- `/communication/contacts/:contactId/conversations` e `/:conversationId`;
- `/work/processes/:id/tasks|comments|history`, mantendo resumo em `/work/processes/:id`;
- `/work/calendar/:view/:date`;
- `/docs/catalog/type/:kind`, `/docs/catalog/client/:clientId` e `/docs/imports/new`;
- `/health/type/:type` e `/exports/new`.

Filtros combináveis, tabs operacionais, paginação e ordenação permanecem fora do path. Atalhos que hoje fabricam query aplicam uma `SurfaceNavigationIntent` validada e navegam ao path base; a intenção é consumida uma vez. Abrir recurso em nova aba preserva somente o contexto expresso no path, não filtros efêmeros.

### Compatibilidade fica concentrada em um adaptador allowlisted

Um middleware global anterior ao auth reconhecerá apenas chaves legadas conhecidas. Ele normaliza dados, aplica estado/intenção ou converte para o novo path e retorna `replace` sem query. Valores desconhecidos são descartados. `message_id`, `contact_id`, seção, calendário e comandos one-shot viram paths; filtros viram estado de superfície; `redirect` vira retorno de autenticação; reset antigo entra no mesmo fluxo one-shot em memória. Nenhum valor é logado.

O gate permitirá leitura de `to.query` somente nesse adaptador e proibirá novos produtores de query interna. `{ query }` dos clientes HTTP e URLs externas permanecem fora do gate.

### Reset reutiliza o padrão seguro de ativação

Laravel gerará `/reset-password#token=…&email=…`. O middleware anterior ao auth consumirá o fragmento ou a query legada antes do mount, manterá as credenciais somente em memória e navegará com `replace` para o path limpo. A página consumirá esse estado uma vez e manterá o POST Fortify atual. Ativação continuará em `/activate#token=…`.

### Migração ocorre por domínio com evolução aditiva mínima da fila

Communication, Work, documentos/clientes/fechamento e catálogos trocarão parsers/serializadores de rota por estado tipado e continuarão produzindo os parâmetros existentes para seus clientes HTTP. Filtros salvos existentes continuam explícitos e autoritativos. A fila de Work recebe o valor opcional `tab=sem_responsavel` para que o KPI homônimo não degrade para a aba aberta genérica; o recorte mantém o tenant corrente e exclui tarefas atribuídas ou encerradas. As demais mudanças da API limitam-se aos links de navegador emitidos por reset e Saúde.

## Risks / Trade-offs

- **[Reload perde filtro transitório]** → comportamento assumido; deep-links estáveis usam path e filtros duráveis exigem preset explícito.
- **[Estado vaza entre tenants]** → chave por identidade/tenant, limpeza por `sessionEpoch` e testes cross-tenant.
- **[Middleware aplica query inválida]** → allowlist e normalizadores de domínio; desconhecidos são descartados.
- **[Links antigos quebram]** → adaptador por um ciclo, testes table-driven e canonicalização imediata.
- **[Fragmento de reset permanece no histórico]** → consumo e `replaceState` no primeiro mount; nenhum log/telemetria inclui segredo.
- **[Rotas novas desmontam master–detail]** → wrappers mínimos reutilizam os componentes existentes e mantêm a matriz/paridade.
- **[Gate confunde query HTTP com browser]** → escopo explícito em navegação/middleware/pages e exclusão de `composables/api`/tipos gerados.

## Migration Plan

1. Adicionar fundação, rotas/helpers e testes de compatibilidade sem remover produtores antigos.
2. Migrar Communication e Work, depois listas/documentos e autenticação/API.
3. Remover produtores/consumidores de query nas superfícies e ativar o gate estático.
4. Publicar com middleware legado por um ciclo; rollback reverte os novos paths/estado e a forma dos links, sem migração de dados.
5. Remover o adaptador em change separado após o primeiro release estável desta mudança.

## Open Questions

Nenhuma.
