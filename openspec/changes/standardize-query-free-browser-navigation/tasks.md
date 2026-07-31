## 1. Fundação e compatibilidade Web

- [x] 1.1 Adicionar testes para estado de superfície isolado, intenções one-shot, canonicalização legada e gate que separa query de navegador de query HTTP.
- [x] 1.2 Implementar `useSurfaceNavigationState`, intenções tipadas e limpeza por sessão/tenant sem Pinia ou storage persistente.
- [x] 1.3 Implementar middleware legado allowlisted e helpers de paths canônicos, descartando queries desconhecidas sem logs.

## 2. Communication sem query

- [x] 2.1 Migrar filtros e seleção do workspace para estado de sessão, mantendo conversa no path e removendo sincronização `route.query`.
- [x] 2.2 Adicionar paths de mensagem e contexto de contato, incluindo fallback de mensagem indisponível e remoção do chip de contato.
- [x] 2.3 Migrar catálogos de contatos, fluxos e respostas rápidas para estado de sessão e retorno do detalhe sem query.
- [x] 2.4 Atualizar testes unitários/E2E de filtros, deep-links, foco, teclado, touch e master–detail de Communication.

## 3. Work sem query

- [x] 3.1 Migrar fila de tarefas e agrupamento de processos para estado de sessão e intenções de KPIs/atalhos.
- [x] 3.2 Migrar seções do processo para child paths e remover `from` da URL mantendo retorno e foco.
- [x] 3.3 Migrar calendário para `/work/calendar/:view/:date` e templates para estado de sessão.
- [x] 3.4 Atualizar testes de fila, agrupamento, navegação, calendário, KPIs e detalhes.

## 4. Demais superfícies Web

- [x] 4.1 Migrar documentos e clientes para estado de sessão, preservando filtros salvos e adicionando paths de contexto/importação.
- [x] 4.2 Migrar fechamento, Saúde, exportações e aliases para estado/paths sem query.
- [x] 4.3 Substituir URLs literais e atalhos filtrados por helpers/intents e atualizar matriz de paridade para as novas páginas.
- [x] 4.4 Cobrir catálogos, presets, paginação, criação, retorno e ausência de `location.search` em testes focados.

## 5. Autenticação e links Laravel

- [x] 5.1 Migrar retorno pós-login para sessionStorage one-shot seguro e reset Web para fragmento consumido imediatamente.
- [x] 5.2 Alterar a notificação Laravel de reset e links operacionais de Saúde para paths sem query, preservando endpoints públicos.
- [x] 5.3 Cobrir reset novo/legado, ativação, redirect autorizado, links de Saúde e compatibilidade Fortify em Web/API.

## 6. Validação final

- [x] 6.1 Executar testes focados Web/API e corrigir regressões de tipagem, autorização, tenant, foco e navegação.
- [x] 6.2 Executar lint, typecheck, generate, test, test:fidelity e test:artifacts Web; composer validate, Pint e PHPUnit API.
- [x] 6.3 Executar E2E/inspeção local de URLs canônicas, `git diff --check` e `openspec validate standardize-query-free-browser-navigation --strict`.
