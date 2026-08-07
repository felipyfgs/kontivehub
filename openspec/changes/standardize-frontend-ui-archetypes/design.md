## Context

`apps/web` é uma SPA Nuxt 4/Nuxt UI 4 com 83 páginas registradas na matriz de paridade. O gate estrutural e a referência local do dashboard passam, e a aplicação já possui componentes `Shell*`, tokens green/zinc, locale pt-BR e estados de dados honestos. A auditoria encontrou, porém, deriva em superfícies específicas: personalização arbitrária de paleta, autenticação com gradiente/blur, semântica incompleta de listas master-detail, movimento sem alternativa reduzida, tabelas densas que apenas rolam no mobile e trabalho de rede/runtime executado sem necessidade.

O dashboard em `.local/references/dashboard` continua sendo a autoridade estrutural; `DESIGN.md` e `PRODUCT.md` definem identidade e verdade de produto. Laravel permanece dono do domínio, tenant e autorização. Há mudanças em andamento na área de comunicação, portanto qualquer lote que toque esses arquivos precisa partir do diff mais recente e preservar o trabalho existente.

## Goals / Non-Goals

**Goals:**

- Tornar arquétipos, tokens, acessibilidade, responsividade e estados reais contratos verificáveis em todas as superfícies.
- Corrigir primeiro falhas P1 de autorização, interação e deriva visual, depois P2 de responsividade, movimento, copy e eficiência.
- Preservar a Home operacional como variação documentada do arquétipo analítico.
- Reduzir requests, conexões e código pesado sem mudar contratos públicos da API.
- Entregar a refatoração em lotes pequenos, testáveis e reversíveis dentro de um único change.

**Non-Goals:**

- Criar métricas, gráficos, períodos ou dados analíticos sem contrato Laravel real.
- Alterar regras de domínio, permissões, isolamento por tenant ou contratos públicos da API.
- Introduzir SSR, Pinia, novas bibliotecas visuais ou atualizar Nuxt/Nuxt UI.
- Copiar mocks, branding, usuários, links ou regras de negócio do dashboard de referência.
- Redesenhar fluxos de comunicação que pertencem aos changes em andamento.

## Decisions

### 1. Um change guarda-chuva, aplicado em lotes por risco e arquétipo

O change será executado em fases: baseline e contratos; segurança/runtime P1; identidade visual; acessibilidade; transformação responsiva; otimização medida; validação final. Cada fase terá testes focados antes do gate web completo. Isso mantém uma única fonte de requisitos sem produzir um diff monolítico.

Alternativa considerada: changes separados por disciplina. Foi rejeitada porque tokens, Shells, acessibilidade e responsividade se cruzam nas mesmas superfícies e exigiriam contratos duplicados.

### 2. Arquétipos e componentes Shell são o limite estrutural

Cada superfície será classificada como casca global, analítica, lista administrativa, master-detail, configurações/formulários ou autenticação. Mudanças preservarão a hierarquia e os slots do arquivo exato da referência, reutilizando Nuxt UI e um `Shell*` somente quando ele encapsular o mesmo contrato de estados, foco e responsividade. A matriz de paridade continuará cobrindo todas as páginas.

A Home manterá seus blocos operacionais e estados reais dentro da hierarquia analítica. Filtros de período, gráficos ou tabelas só serão adicionados se já houver dados reais e contrato estável; ausência desses dados não será mascarada por conteúdo sintético.

### 3. A identidade visual será canônica, não configurável pelo usuário

`green`/`zinc`, Public Sans, ícones Lucide e tokens semânticos serão os únicos defaults globais. O seletor de paleta do menu de usuário será removido e nenhum fluxo poderá mutar `appConfig.ui.colors` em runtime. O `theme-color` escuro será alinhado a `#09090b`. A autenticação usará superfícies tonais, bordas e a escala de raio do design system, sem gradiente decorativo, blur ou a copy não confirmada “uso interno”.

Cores cruas continuarão permitidas apenas quando forem parte intrínseca de mídia, QR code ou conteúdo fornecido pelo usuário; estados do produto usarão cores semânticas.

### 4. Listas master-detail terão um padrão de teclado explícito

Listas com `role="listbox"` usarão foco móvel entre opções: um único item no tab order, `ArrowUp`/`ArrowDown`, `Home`/`End`, `aria-selected` e restauração de foco ao fechar o detalhe. Listas comuns continuarão como `role="list"` com um botão por linha e estado atual no controle acionável. A virtualização será preservada, com metadados de posição e foco estável.

### 5. Responsividade transforma a composição abaixo de `md`

Tabelas densas usarão `ShellDataTable`/`ShellMobileCards` ou uma composição equivalente de resumo, expansão e ações. Modais tabulares usarão lista/card responsivo ou detalhe progressivo. Nenhuma superfície dependerá de uma tabela estruturalmente larga para ser operável no mobile, inclusive `platform_admin`. Scroll horizontal permanecerá apenas para conteúdo intrinsecamente bidimensional e não acionável, com região nomeada e alternativa legível.

No mobile, controles acionáveis terão alvo mínimo de 44×44 px. Tamanhos tipográficos visíveis não ficarão abaixo do token de label de 12 px, salvo metadado não interativo cuja legibilidade e contraste sejam comprovados.

### 6. Movimento e feedback respeitarão preferências e estados reais

Loaders e transições manterão feedback sem animação contínua quando `prefers-reduced-motion: reduce` estiver ativo. Loading usará `aria-busy`/`role="status"` quando aplicável; erro, vazio, indisponibilidade e permissão virão do estado real da API. Toda copy visível ficará em pt-BR e não declarará disponibilidade comercial não comprovada.

### 7. Autorização e bootstrap serão fail-closed antes de carregar dados

Páginas protegidas encerrarão o setup ao redirecionar e não instanciarão composables de domínio nem chamarão APIs após falha de permissão. O middleware global será a fonte do refresh de identidade durante navegação; o refresh redundante de `useDashboard` será removido ou substituído por uma operação single-flight compartilhada.

O status de onboarding guest será deduplicado por sessão SPA e invalidado após uma transição que possa alterar o estado de instalação. Realtime de comunicação só será iniciado quando a feature estiver habilitada, houver sessão válida, permissão efetiva e tenant resolvido; perder qualquer condição encerrará canais e conexão.

### 8. Otimização será orientada por medição e preservará interfaces internas

Antes de alterar imports, serão registrados chunks gerados e requests de bootstrap em rotas representativas. Clientes API e dependências pesadas serão carregados sob demanda quando a medição demonstrar ganho; a fachada tipada existente poderá usar inicialização preguiçosa para evitar uma migração ampla dos consumidores. Requests longos deverão aceitar cancelamento e ignorar respostas de epoch/tenant antigos.

Não será adicionada dependência de analyzer ou acessibilidade sem necessidade: primeiro serão usados os artefatos do `nuxt generate`, Playwright e os testes existentes.

## Risks / Trade-offs

- **[Risco]** A padronização toca muitas superfícies e pode gerar regressão transversal. → **Mitigação:** lotes por arquétipo, ownership exclusivo por arquivo, testes focados e gate completo a cada marco.
- **[Risco]** Arquivos de comunicação já possuem trabalho em andamento. → **Mitigação:** executar esse lote depois do change de comunicação aplicável ou reaplicar os achados sobre o diff estabilizado, sem reverter mudanças alheias.
- **[Risco]** Remover a paleta configurável muda uma preferência visível. → **Mitigação:** tratar green/zinc como contrato de marca aprovado nesta proposta e cobrir ausência de mutação de tema por teste.
- **[Risco]** Cards mobile podem ocultar contexto comparativo de matrizes densas. → **Mitigação:** preservar identidade, estado, resumo e ações no card e oferecer detalhe expansível para campos secundários.
- **[Risco]** Lazy loading e deduplicação podem introduzir corridas de sessão/tenant. → **Mitigação:** testes de epoch, troca de tenant, permissão e teardown realtime antes do gate final.
- **[Risco]** Score visual ou bundle pode variar por ambiente. → **Mitigação:** comparar sempre no mesmo build, viewport, seed e revisão da referência, registrando baseline e delta.

## Migration Plan

1. Registrar baseline de fidelidade, testes, rede e chunks sem alterar comportamento.
2. Corrigir guardas de permissão e bootstrap/realtime redundantes com testes de regressão.
3. Fixar tokens e autenticação, mantendo shell e rotas intactos.
4. Aplicar semântica de teclado, movimento reduzido, copy e alvos de toque por arquétipo.
5. Transformar tabelas e modais densos no mobile, começando por componentes Shell compartilhados e depois exceções de domínio.
6. Aplicar lazy loading/cancelamento somente onde a medição comprovar benefício.
7. Rodar detector Impeccable uma vez sobre os alvos alterados, testes focados, `test-gate`, `test:fidelity` e QA Playwright desktop/mobile autenticado.

Não há migração de dados. Um lote pode ser revertido isoladamente restaurando sua composição anterior, desde que os requisitos P1 de autorização e acessibilidade não sejam reintroduzidos.

## Open Questions

Nenhuma. Home, empacotamento do change e transformação mobile foram decididos pelo usuário durante a auditoria.
