## Why

A SPA expõe filtros, paginação, comandos one-shot e até credenciais de redefinição na query string, embora os recursos canônicos já usem paths e o produto adote URLs limpas em outras superfícies. Isso cria URLs ruidosas, amplia exposição de dados, espalha sincronização `route.query` por domínios e faz a navegação depender de contratos inconsistentes.

## What Changes

- Tornar toda URL canônica interna da SPA livre de query string, sem alterar query parameters das requisições HTTP à API.
- Manter IDs e contextos compartilháveis em paths tipados; mover filtros combináveis e paginação para estado de sessão isolado por usuário, tenant e superfície.
- Preservar filtros salvos explícitos e transformar atalhos filtrados em intenções de navegação one-shot.
- Usar compatibilidade centralizada e allowlisted somente durante a migração; o follow-up `complete-identifier-normalization` encerra a janela e remove o adaptador.
- Migrar âncoras de mensagem, contexto de contato, seções de processo, calendário, documentos, Saúde e ações de criação para paths canônicos.
- Migrar o link público de redefinição de senha para fragmento consumido e removido imediatamente, mantendo endpoints e body Fortify.
- Proibir novos produtores de query no navegador por gate estático, preservando clientes HTTP, OpenAPI e links externos.

## Capabilities

### New Capabilities

- `query-free-browser-navigation`: política canônica de paths, estado de sessão e intenções de navegação; a compatibilidade temporária de migração já foi encerrada.

### Modified Capabilities

- `communication-conversation-workspace`: substituir URL↔filtros por path do recurso e estado de sessão sem perder seleção, foco ou master–detail.
- `communication-contact-conversation-history`: mover contexto de contato e âncora de mensagem da query para paths canônicos.
- `communication-contacts-catalog`: preservar o estado do catálogo no retorno do detalhe sem query string.
- `communication-contacts-experience`: tornar estado não sensível também session-scoped e manter busca telefônica fora da URL.
- `spa-session-authentication`: substituir redirect de login por retorno one-shot e reset por fragmento seguro.
- `ui-archetypes-master-detail`: preservar deep-links, seleção e foco com URLs canônicas sem query.

## Impact

- Afeta `apps/web` transversalmente: middleware, helpers de rota, estado de listas e superfícies de Communication, Work, documentos, clientes, fechamento, Saúde, templates, exportações e autenticação.
- Afeta `apps/api` na forma do link de reset, dos links de navegação para Saúde e pela adição compatível do recorte `tab=sem_responsavel` à fila de Work; endpoints, bodies e paginação existentes permanecem compatíveis.
- Não altera banco, migrations, filas, Wazync, SSR, Pinia, permissões ou tenancy; o contrato HTTP recebe apenas um valor aditivo e opcional.
- URLs anteriores conhecidas foram compatíveis por um ciclo através de um único adaptador. Após `complete-identifier-normalization`, somente paths e fragmentos one-shot canônicos são aceitos.
