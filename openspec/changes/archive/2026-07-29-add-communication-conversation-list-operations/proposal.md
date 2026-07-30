## Why

O change `refactor-communication-conversation-workspace` estabelece a lista compacta, a timeline incremental e o estado compartilhado de leitura, mas a fila continua limitada à abertura de uma conversa por vez. A operação diária precisa de seleção múltipla, filtros completos e ações individuais/em lote inspiradas no Chatwoot, sem perder autorização tenant-safe, concorrência otimista, feedback real de falha ou a composição master–detail já adotada.

## What Changes

- Separar seleção de detalhe e seleção operacional, com checkboxes por linha, “selecionar carregadas”, limpeza explícita e reset quando busca, filtros ou ordenação mudarem.
- Adicionar ações contextuais por conversa e uma barra de lote para status/reabertura/snooze, leitura local, responsável, departamento e adição/remoção de rótulos.
- Completar os filtros operacionais de inbox, status, não lidas, responsável, sem responsável, departamento e rótulos, além de ordenações allowlisted e preferências de status/ordenação por usuário e tenant.
- Enfileirar lotes por snapshot dos IDs carregados, com idempotência, operação rastreável, resultado explícito por item, reautorização no processamento e atualização realtime sanitizada.
- Tornar a lista incremental e virtualizada, preservando loading, erro, vazio, retry, fim da lista, teclado, foco, touch, deep-link e detalhe desktop/mobile.
- Manter fora do escopo filtros compostos AND/OR, views salvas, exclusão, prioridade em massa, seleção do resultado filtrado inteiro, alterações no composer/timeline e qualquer mudança no Wazync ou em receipts remotos.

## Capabilities

### New Capabilities

- `communication-conversation-list-operations`: Contratos de filtros e preferências da fila, seleção de conversas carregadas, ações individuais/em lote e execução assíncrona tenant-safe.

### Modified Capabilities

- `ui-archetypes-master-detail`: O painel mestre de Communication passa a acomodar seleção múltipla, filtros e barra contextual sem alterar resize, detalhe adjacente, slideovers, deep-link ou restauração de foco.

## Impact

- Dependência: a implementação começa somente após a base relevante de `refactor-communication-conversation-workspace` estar concluída, sem duplicar seus contratos de lista, read-state ou timeline.
- `apps/api`: evolução aditiva de `/api/v1/communication`, contrato OpenAPI público, preferências tenant-scoped, persistência de operações/itens, jobs na fila `communication`, eventos, autorização e retenção.
- `apps/web`: tipos e cliente HTTP aditivos, estado separado de consulta/seleção/operação, lista e filtros Nuxt UI, virtualização e testes desktop/mobile.
- PostgreSQL, Redis/Horizon e Reverb são afetados; `apps/wazync` e o contrato privado Laravel–Wazync não mudam, e nenhuma ação nova produz egress WhatsApp.
- O rollout publica migrations/API/jobs antes do frontend. Logs, métricas e eventos permanecem restritos a IDs internos, contagens e códigos sanitizados.
