## 1. Filtros compactos

- [x] 1.1 Adicionar testes de componente/gate para gatilhos iconográficos, regras avançadas, rascunho, resumo ativo e ausência de overflow.
- [x] 1.2 Refatorar `ConversationListFilters` para busca direta, status/ordenação em dropdowns e filtros avançados em popover responsivo.
- [x] 1.3 Integrar contato, total autoritativo e toolbar sem padding lateral ou rolagem horizontal no workspace.

## 2. Seleção contextual

- [x] 2.1 Atualizar testes unitários e E2E para centralização geométrica, teclado/touch, faixa contextual superior e remoção da faixa permanente.
- [x] 2.2 Centralizar o checkbox no avatar e simplificar o contrato de seleção da lista.
- [x] 2.3 Implementar faixa bulk superior com tri-state, ações iconográficas e restauração de foco.

## 3. Validação

- [x] 3.1 Executar testes focados de filtros, seleção, foco e gates de Communication.
- [x] 3.2 Inspecionar a UI em desktop/mobile, corrigir uma rodada de defeitos e executar o detector Impeccable.
- [x] 3.3 Executar lint, typecheck, generate, test, test:fidelity e test:artifacts no container Web.

## 4. Ancoragem contextual dos overlays

- [x] 4.1 Cobrir em gate e E2E o alinhamento `start`/`end` de filtros e ações bulk, incluindo colisão sem overflow em viewport estreito.
- [x] 4.2 Refatorar os dropdowns para declarar a ancoragem conforme a posição de cada gatilho no grupo.
- [x] 4.3 Reexecutar testes focados, inspeção desktop/mobile e gates Web após a refatoração.

## 5. Atendimento limpo e contextual

- [x] 5.1 Adicionar gates e testes para busca exclusiva, três tabs fixas no padrão Chatwoot, dois popovers iconográficos, badge/resumo, troca tabs↔seleção e ausência de overflow.
- [x] 5.2 Extrair o modelo dos presets `Em aberto`, `Não lidas` e `Não atribuídas` com aplicação atômica, ordem estável, estado sem tab falsa e preservação dos demais filtros no estado de sessão.
- [x] 5.3 Refatorar `ConversationListFilters` para busca primeiro, tabs fixas e popovers separados de status/ordenação e filtros avançados com rascunho e limpeza.
- [x] 5.4 Simplificar navbar e faixa bulk: realtime semântico, nova conversa direta, reticências secundárias e somente leitura/status como ações bulk diretas.
- [x] 5.5 Extrair um builder/componente compartilhado de ações unitárias com leitura aplicável e submenus de status, responsável, fila e marcadores, respeitando autorização e estado atual.
- [x] 5.6 Tornar mutações unitárias target-aware e integrar o menu compartilhado nas linhas sem alterar seleção bulk, conversa aberta ou contratos HTTP.
- [x] 5.7 Reorganizar o cabeçalho da timeline para preservar identidade/status/contexto, remover select largo/adiar isolado e consumir o menu compartilhado.
- [x] 5.8 Cobrir teclado, Escape/foco, ações no-op, submenus, rótulo compacto de `Não atribuídas`, 320/390/1024/1440 px, master-detail/slideover e fazer uma rodada visual desktop/mobile.
- [x] 5.9 Executar testes focados, lint, typecheck, generate, test, test:fidelity e test:artifacts no container Web e validar o change OpenSpec.

## 6. Tabs em cápsula do arquétipo

- [x] 6.1 Atualizar proposta, design e delta spec para o `UTabs` pill do inbox do dashboard.
- [x] 6.2 Aplicar a variante `pill` nativa com altura compacta e distribuição em largura total, mover os dois gatilhos para a faixa da busca e adicionar 8 px de respiro lateral, preservando ordem, estado sem preset falso e compactação em 320 px.
- [x] 6.3 Não contabilizar no badge ou no resumo avançado a condição já representada pela tab ativa.
- [x] 6.4 Reexecutar testes, gates Web, detector e inspeção visual desktop/mobile.
