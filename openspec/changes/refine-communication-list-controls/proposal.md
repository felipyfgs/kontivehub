## Why

O painel estreito de Atendimento distribui status, ordenação e escopos em controles concorrentes, cria rolagem horizontal e mantém uma faixa de seleção sempre visível. Além de desperdiçar espaço no master-detail, o checkbox deslocado para o canto do avatar contradiz o contrato já estabelecido de seleção central e prejudica a leitura da lista.

Depois da primeira compactação, busca, visões rápidas e ações da conversa ainda carecem de uma hierarquia única de “chat”: a busca disputa largura com ícones, presets frequentes exigem abrir menus e ações equivalentes aparecem de formas diferentes na linha e na timeline. A referência visual fornecida mostra uma organização mais direta que pode ser adaptada sem copiar branding, dados ou operações inexistentes.

## What Changes

- Colocar a busca em uma faixa própria imediatamente abaixo do navbar e alternar a faixa seguinte entre visões rápidas e ações de seleção.
- Usar três tabs fixas no padrão visual observado no Chatwoot — `Em aberto`, `Não lidas` e `Não atribuídas` — com sublinhado ativo, ordem estável e rótulo compacto somente na largura mínima.
- Expor somente dois controles iconográficos após as tabs: `Status e ordenação` em um popover com selects e `Filtros avançados` em um popover próprio com badge.
- Editar filtros de escopo no popover avançado com regras explícitas de campo, operador e valor, rascunho descartável, aplicação conjunta e resumo compacto do estado aplicado.
- Remover a faixa permanente “Selecionar carregadas” e centralizar o checkbox de cada conversa sobre o avatar, preservando mouse, teclado e touch.
- Quando houver seleção, substituir tabs e resumo por uma faixa contextual com tri-state, contagem, leitura, status, mais ações e limpeza.
- Ancorar `Status e ordenação` por `end` e `Filtros avançados` por `start`; menus de ações seguem sua posição e todos preservam ajuste de colisão no viewport estreito.
- Extrair um menu hierárquico reutilizável para linha e cabeçalho da timeline, com leitura, status, responsável, fila e marcadores organizados em submenus de um nível.
- Simplificar o navbar e o cabeçalho da timeline: somente “Nova conversa” permanece direta no navbar; sincronização/administração e ações secundárias migram para menus de reticências.
- Preservar a densidade e os sinais atuais das linhas enquanto o menu unitário perde ações redundantes e passa a cobrir responsável, fila e marcadores.
- Preservar API pública e contratos HTTP, preferências por usuário/tenant, estado de sessão isolado, seleção de itens carregados, virtualização, master-detail e autorização existentes, mantendo a URL canônica sem query string.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `communication-conversation-list-operations`: tornar filtros e seleção bulk compactos, responsivos, explícitos e acessíveis dentro do painel mestre.
- `communication-conversation-workspace`: alinhar navbar, lista e cabeçalho da timeline em uma hierarquia de chat consistente e progressivamente revelada.
- `ui-archetypes-master-detail`: preservar a composição master-detail enquanto a toolbar e a barra contextual se adaptam sem overflow ou cobertura de conteúdo.

## Impact

- Afeta somente a SPA em `apps/web`, principalmente filtros, lista, workspace, navbar da timeline e um menu compartilhado de ações de Communication, além de testes Vitest/Playwright relacionados.
- Não altera API pública, OpenAPI, banco, migrations, filas, flags, Laravel, Wazync ou egress.
- Reutiliza Nuxt UI e Shells existentes; não modifica os contratos compartilhados de `ShellScrollableTabs` ou `ShellBulkActionBar`.
- Mantém o total autoritativo no navbar e não inventa contagens por tab que a API atual não fornece.
- Não adiciona ações da referência sem suporte de domínio, como arquivar, silenciar, favoritar, bloquear, limpar ou apagar conversa.
- Coordena o estado transitório com `standardize-query-free-browser-navigation`; filtros não voltam a ser serializados na URL.
