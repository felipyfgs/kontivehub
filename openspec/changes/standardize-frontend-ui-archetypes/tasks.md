## 1. Baseline e contratos de regressão

- [x] 1.1 Validar a revisão do dashboard, registrar o resultado atual de `test:fidelity` e confirmar a matriz de 83 rotas antes de editar UI.
- [x] 1.2 Classificar cada rota da matriz como casca global, analítica, lista administrativa, master-detail, configurações/formulários ou autenticação e adicionar o arquétipo explícito ao fixture.
- [x] 1.3 Registrar baseline reproduzível dos chunks de `nuxt generate` e das requests de bootstrap em login, Home, clientes, conta e editor de fluxos.
- [x] 1.4 Adicionar testes de contrato para paleta canônica, Home operacional, guardas sem request, teclado master-detail, movimento reduzido e transformação mobile antes das correções.
- [x] 1.5 Delimitar os arquivos de comunicação sobrepostos ao change `complete-whatsapp-message-composer` e só iniciar esse lote sobre o diff estabilizado, sem reverter alterações alheias.

## 2. Autorização e bootstrap fail-closed

- [x] 2.1 Corrigir as rotas de fluxos e respostas rápidas para encerrar o setup após redirecionamento e instanciar loaders somente depois da permissão efetiva.
- [x] 2.2 Cobrir deep links não autorizados de lista, detalhe e editor, comprovando ausência de requests de catálogo, detalhe e editor.
- [x] 2.3 Tornar o middleware a fonte autoritativa da identidade de navegação e eliminar o refresh redundante disparado pelo mount de `useDashboard`.
- [x] 2.4 Implementar single-flight para refreshes concorrentes de identidade e testar falha, recuperação, logout e troca de tenant.
- [x] 2.5 Deduplicar o status de onboarding guest durante a sessão SPA, invalidando-o após onboarding/autenticação que possa mudar o estado de instalação.
- [x] 2.6 Condicionar a criação de Echo/Pusher a feature, sessão, permissão e tenant válidos e encerrar transporte/canais quando qualquer pré-condição se perder.

## 3. Sistema visual e arquétipos

- [x] 3.1 Remover do menu de usuário a mutação runtime de primary/neutral e manter green/zinc como identidade global coberta por teste.
- [x] 3.2 Alinhar o `theme-color` dark do app ao canvas/PWA `#09090b` e verificar metadados nos dois modos de cor.
- [x] 3.3 Refatorar o layout de autenticação com os tokens, superfícies tonais, bordas, raios e espaçamento canônicos, removendo gradiente, blur e a copy “uso interno”.
- [x] 3.4 Substituir cores cruas de estados em utilities e componentes por tokens semânticos, preservando apenas exceções comprovadas de mídia, QR e conteúdo do usuário.
- [x] 3.5 Formalizar a Home operacional no arquétipo analítico, preservando blocos e dados reais sem introduzir filtros, gráficos ou tabelas sintéticos.
- [x] 3.6 Normalizar strings visíveis remanescentes para pt-BR e adicionar regressão para os rótulos ingleses encontrados em guias, saúde e sincronizações.

## 4. Acessibilidade e interação

- [x] 4.1 Implementar foco móvel, ArrowUp/ArrowDown, Home/End, seleção anunciada e restauração de foco na lista master-detail da Caixa Postal.
- [x] 4.2 Ajustar listas comuns e virtualizadas de comunicação para manter `list/listitem`, ação nativa por linha e estado atual/busy no controle acionável.
- [x] 4.3 Criar uma alternativa canônica para `prefers-reduced-motion` e aplicá-la a loaders spin/pulse e transições visíveis sem remover feedback de estado.
- [x] 4.4 Padronizar regiões assíncronas com `aria-busy`, `role="status"`, erro e retry coerentes nos Shells e nas exceções de domínio encontradas pela auditoria.
- [x] 4.5 Elevar tipografia operacional abaixo de 12 px para o token de label ou documentar/testar somente exceções não interativas comprovadamente legíveis.
- [x] 4.6 Garantir alvos de toque de 44×44 px em calendário, linhas master-detail, toolbars e controles compactos abaixo de `md`.
- [x] 4.7 Auditar formulários, mídia e overlays por arquétipo e corrigir labels, associação de erro, alt text, foco inicial, fechamento por teclado e retorno de foco faltantes.

## 5. Transformação responsiva

- [x] 5.1 Endurecer `ShellDataTable` e `ShellMobileCards` para preservar identidade, estado, resumo, seleção, ações e detalhe expansível no mobile.
- [x] 5.2 Converter as matrizes globais e por escritório de módulos fiscais em cards/detalhes responsivos abaixo de `md`, removendo a dependência de `min-w` e scroll horizontal para operar ações.
- [x] 5.3 Converter o histórico DAS e demais modais tabulares largos encontrados no inventário em resumo/detail responsivo com os mesmos campos factuais.
- [x] 5.4 Revisar todas as exceções `mobileCards=false`, larguras fixas e regiões horizontais, mantendo scroll somente para conteúdo bidimensional read-only com região nomeada e resumo estreito.
- [x] 5.5 Validar a transformação de Home, clientes, mailbox, conta, autenticação e administração em 390, 768 e 1440 px sem truncar ações ou criar scroll de página.

## 6. Otimização medida de runtime e bundle

- [x] 6.1 Comparar o baseline e confirmar quais fábricas de `useApi`, dependências de gráfico/editor e imports realtime entram em chunks ou bootstrap de rotas não relacionadas.
- [x] 6.2 Tornar clientes API preguiçosos e memoizados sem mudar a interface tipada apenas onde a medição demonstrar redução de código/trabalho inicial.
- [x] 6.3 Isolar editor visual, gráficos, drag-and-drop e realtime em carregamento client/route sob demanda e revisar `optimizeDeps` sem atualizar dependências.
- [x] 6.4 Adicionar cancelamento ou proteção por epoch/tenant aos requests longos ainda descobertos e garantir cleanup de timers, listeners e subscriptions no dispose.
- [x] 6.5 Repetir as medições com o mesmo build, seed e rotas e rejeitar otimizações que não reduzam chunks/requests ou que prejudiquem estados de erro e autorização.

## 7. Validação e acabamento

- [x] 7.1 Rodar os testes focados de shell, Home, master-detail, comunicação, autorização, responsividade e performance após cada lote e corrigir regressões antes de avançar.
- [x] 7.2 Executar uma única passagem do detector Impeccable sobre todos os alvos UI alterados, verificar falsos positivos e corrigir achados reais em um lote.
- [x] 7.3 Executar `corepack pnpm --dir apps/web run test:gate` e `corepack pnpm --dir apps/web run test:fidelity` com a referência ainda validada.
- [x] 7.4 Executar QA Playwright autenticado em desktop e mobile para os arquétipos representativos, cobrindo teclado, foco, reduced-motion, loading, erro e vazio, e salvar screenshots comparativas.
- [x] 7.5 Revisar o diff final contra tenant, autorização, contratos API, ownership dos arquivos de comunicação, ausência de dependências novas e ausência de dados sintéticos.

> Validação final de 7.3 em 2026-08-06: `test:gate` verde, incluindo tipos
> públicos sincronizados, lint, typecheck, generate, 167 arquivos/796 testes,
> `test:fidelity` com 83/83 rotas e varredura de 520 arquivos sem material
> sensível.
