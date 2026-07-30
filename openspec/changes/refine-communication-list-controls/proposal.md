## Why

O painel estreito de Atendimento distribui status, ordenação e escopos em controles concorrentes, cria rolagem horizontal e mantém uma faixa de seleção sempre visível. Além de desperdiçar espaço no master-detail, o checkbox deslocado para o canto do avatar contradiz o contrato já estabelecido de seleção central e prejudica a leitura da lista.

## What Changes

- Reorganizar a busca e três gatilhos iconográficos para status, ordenação e filtros avançados em uma hierarquia compacta, responsiva e sem overflow horizontal.
- Editar filtros de escopo em um popover ancorado com regras explícitas de campo, operador e valor, rascunho descartável, aplicação conjunta e resumo truncável dos filtros ativos.
- Remover a faixa permanente “Selecionar carregadas” e centralizar o checkbox de cada conversa sobre o avatar, preservando mouse, teclado e touch.
- Exibir seleção total/parcial, contagem, ações iconográficas e limpeza somente em uma faixa contextual no topo da lista, logo abaixo dos filtros.
- Ancorar cada dropdown conforme a posição do gatilho no grupo, abrindo os primeiros por `start` e os últimos por `end`, com ajuste de colisão no viewport estreito.
- Preservar API pública e contratos HTTP, preferências por usuário/tenant, query string, seleção de itens carregados, virtualização, master-detail e autorização existentes.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `communication-conversation-list-operations`: tornar filtros e seleção bulk compactos, responsivos, explícitos e acessíveis dentro do painel mestre.
- `ui-archetypes-master-detail`: preservar a composição master-detail enquanto a toolbar e a barra contextual se adaptam sem overflow ou cobertura de conteúdo.

## Impact

- Afeta somente a SPA em `apps/web`, principalmente os componentes de filtros, lista e workspace de Communication, além de testes Vitest/Playwright relacionados.
- Não altera API pública, OpenAPI, banco, migrations, filas, flags, Laravel, Wazync ou egress.
- Reutiliza Nuxt UI e Shells existentes; não modifica os contratos compartilhados de `ShellScrollableTabs` ou `ShellBulkActionBar`.
