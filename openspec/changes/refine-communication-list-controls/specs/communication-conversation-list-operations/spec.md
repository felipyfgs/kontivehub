## ADDED Requirements

### Requirement: Filtros da lista possuem hierarquia compacta e aplicação previsível

A SPA SHALL manter busca diretamente acessível em uma faixa própria, oferecer três presets fixos em tabs e expor somente dois controles iconográficos após elas: um popover de status/ordenação e um popover de filtros avançados. Filtros avançados SHALL usar regras de campo, operador e valor com rascunho explícito. Filtros aplicados SHALL permanecer identificáveis por badge e resumo truncável, e a composição SHALL NOT criar rolagem horizontal no painel mestre suportado.

#### Scenario: Painel mestre estreito
- **WHEN** o painel de conversas é exibido ou redimensionado em uma largura suportada
- **THEN** busca ocupa a primeira faixa abaixo do navbar e tabs, dois ícones e resumo cabem ou compactam sem select largo, padding lateral geral, scrollbar horizontal, quebra de linha, controles cortados ou conteúdo inacessível

#### Scenario: Operador escolhe uma visão rápida
- **WHEN** o usuário aciona `Em aberto`, `Não lidas` ou `Não atribuídas`
- **THEN** a tab aplica atomicamente o preset correspondente sobre status/unread/unassigned, limpa a seleção operacional e não altera filtros avançados fora dessas dimensões

#### Scenario: Tabs mantêm memória espacial
- **WHEN** o painel muda de largura ou uma visão fora dos três presets fica ativa
- **THEN** a ordem das tabs não muda, `Não atribuídas` compacta visualmente para `Não atrib.` somente na largura mínima e seu nome acessível permanece completo

#### Scenario: Estado aplicado não corresponde a um preset
- **WHEN** status, unread ou unassigned formam uma combinação diferente das três visões rápidas
- **THEN** nenhuma tab é marcada falsamente e o controle de status comunica a preferência aplicada

#### Scenario: Operador ajusta status ou ordenação
- **WHEN** o usuário aciona `Status e ordenação`
- **THEN** um popover apresenta dois selects compactos, identifica os valores aplicados e altera somente a preferência escolhida

#### Scenario: Operador abre filtros avançados
- **WHEN** o usuário aciona `Filtros avançados`
- **THEN** um popover separado abre o editor de regras com rascunho e comunica a quantidade de filtros aplicados no gatilho

#### Scenario: Popovers mantêm vínculo espacial com os gatilhos
- **WHEN** status/ordenação ou filtros avançados são abertos
- **THEN** status/ordenação ancora por `end`, filtros avançados ancora por `start`, ambos usam portal e `collisionPadding` de 8 px e podem deslocar-se somente o necessário para permanecer dentro do viewport

#### Scenario: Usuário constrói filtros avançados
- **WHEN** o usuário abre filtros avançados, adiciona ou remove uma regra e escolhe seu valor
- **THEN** o popover apresenta campo, operador compatível e valor, combina as regras por `E` e não oferece operadores que a API não consegue representar

#### Scenario: Usuário confirma vários filtros
- **WHEN** o usuário altera inbox, responsável, fila, marcadores, não lidas, sem responsável ou remove/mantém o contato ativo e aciona Aplicar
- **THEN** todos os valores e a presença do contato no rascunho substituem juntos o estado da superfície, a URL permanece sem query string e a lista executa uma recarga consolidada

#### Scenario: Regra que exige valor permanece incompleta
- **WHEN** inbox, responsável, fila ou marcadores possui regra sem valor selecionado
- **THEN** Aplicar permanece desabilitado, o rascunho é preservado e o editor informa que cada filtro precisa de valor

#### Scenario: Escopo aplicado muda com editor aberto
- **WHEN** uma intenção ou o estado autoritativo altera os filtros de origem enquanto o popover está aberto
- **THEN** o rascunho é ressincronizado e não pode reaplicar valores obsoletos sobre o novo escopo

#### Scenario: Usuário cancela o editor
- **WHEN** o usuário altera o rascunho e fecha o popover por Escape, clique externo ou pelo gatilho
- **THEN** filtros aplicados, estado da superfície e seleção permanecem inalterados

#### Scenario: Estado da superfície possui filtros ativos
- **WHEN** o workspace restaura filtros válidos da sessão ou consome uma intenção one-shot
- **THEN** o editor permanece fechado e até dois resumos mais `+N` comunicam o escopo ativo sem duplicar busca, status, ordenação ou a condição já expressa pela tab ativa

### Requirement: Seleção de conversas é contextual e centralizada

A SPA SHALL manter o checkbox de cada conversa centralizado sobre o avatar como controle irmão da abertura e do menu. Sem seleção, nenhuma faixa permanente de selecionar carregadas SHALL ocupar a lista; após a primeira seleção, uma faixa contextual SHALL substituir tabs e resumo no mesmo slot e expor estado parcial/total, contagem, ações autorizadas por ícones e limpeza sem cobrir conteúdo.

#### Scenario: Mouse ou teclado descobre a seleção
- **WHEN** o avatar recebe hover, a linha recebe foco ou a conversa está selecionada
- **THEN** o checkbox aparece no centro geométrico do avatar e alterná-lo não abre o detalhe nem aciona o menu

#### Scenario: Touch seleciona sem hover
- **WHEN** a lista é usada com ponteiro coarse
- **THEN** o controle central permanece visível e operável sem deslocar avatar, texto ou ações

#### Scenario: Estado do checkbox acompanha as conversas carregadas
- **WHEN** a faixa aparece com `S` conversas selecionadas entre `N` conversas carregadas
- **THEN** o checkbox fica indeterminado somente quando `0 < S < N` e marcado quando `S = N`, inclusive quando existe uma única conversa carregada; com `S = 0`, a faixa desaparece

#### Scenario: Operador reconhece as ações selecionadas
- **WHEN** a faixa contextual está visível
- **THEN** leitura e status aparecem como gatilhos diretos, responsável/fila/marcadores ficam nas reticências e todos possuem tooltip e nomes acessíveis, sem `ShellBulkActionBar` ou texto genérico “Ações”

#### Scenario: Ações sem efeito são omitidas
- **WHEN** uma ação produziria no-op para todas as conversas selecionadas
- **THEN** essa opção não é apresentada na faixa ou em seus menus

#### Scenario: Menus bulk respeitam a posição no grupo
- **WHEN** o operador abre uma ação da faixa contextual
- **THEN** leitura e status ancoram por `start`, reticências ancoram por `end`, e o menu permanece associado ao ícone sem ultrapassar o viewport

#### Scenario: Seleção parcial vira seleção carregada completa
- **WHEN** o usuário marca o checkbox indeterminado da barra
- **THEN** exatamente as conversas atualmente carregadas ficam selecionadas e o checkbox passa ao estado marcado

#### Scenario: Carregamento incremental amplia a coleção
- **WHEN** novas conversas são anexadas depois que todas as linhas anteriores estavam selecionadas
- **THEN** as novas linhas iniciam desmarcadas e o checkbox é recalculado como indeterminado sobre a coleção atualmente carregada

#### Scenario: Mudança de consulta substitui a coleção
- **WHEN** busca, visão ou filtro altera o contexto da listagem
- **THEN** a seleção operacional é limpa antes de nova ação bulk e nenhum ID do escopo anterior permanece acionável

#### Scenario: Usuário limpa a seleção
- **WHEN** o usuário desmarca o checkbox total ou aciona limpar
- **THEN** a seleção é removida, tabs e resumo retornam e o foco volta a uma conversa carregada anterior ou ao contêiner da lista

#### Scenario: Faixa contextual e fim da lista coexistem
- **WHEN** a faixa contextual está visível e o usuário alcança a última conversa ou o carregador incremental
- **THEN** a faixa permanece acima da lista no fluxo, nenhum conteúdo fica coberto e não surge overflow horizontal na página, no painel ou na própria faixa

### Requirement: Ações unitárias usam descoberta progressiva consistente

A SPA SHALL expor as mesmas categorias autorizadas de ação na linha e no cabeçalho da timeline por um menu hierárquico compartilhado. Leitura SHALL mostrar somente a mudança aplicável; status, responsável, fila e marcadores SHALL usar submenus de no máximo um nível. Status atual SHALL ser omitido, responsável e fila atuais SHALL ser identificados e marcadores SHALL usar checkboxes. O menu SHALL NOT mostrar operações sem contrato de domínio e SHALL manter abertura, seleção e foco separados.

#### Scenario: Operador abre ações de uma linha
- **WHEN** o menu de reticências de uma conversa é acionado
- **THEN** o dropdown ancora por `end`, não contém “Abrir conversa”, oferece somente ações autorizadas e permite navegar por teclado entre leitura e submenus sem abrir a conversa

#### Scenario: Operador abre ações na timeline
- **WHEN** uma conversa está aberta e o menu do cabeçalho é acionado
- **THEN** o mesmo builder apresenta categorias e nomes equivalentes aos da linha, adaptados ao contexto da conversa aberta

#### Scenario: Operador altera um atributo unitário
- **WHEN** status, responsável, fila ou marcador é escolhido no menu de uma conversa
- **THEN** a mutação target-aware usa o ID e a versão daquela conversa, reconcilia a resposta autoritativa e não altera a seleção bulk nem abre outra conversa

#### Scenario: Ação da referência não existe no domínio
- **WHEN** o menu é construído
- **THEN** arquivar, silenciar, favoritar, bloquear, limpar e apagar não são exibidos nem simulados localmente
