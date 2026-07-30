## ADDED Requirements

### Requirement: Filtros da lista possuem hierarquia compacta e aplicação previsível

A SPA SHALL manter busca diretamente acessível, expor status, ordenação e filtros avançados por gatilhos iconográficos independentes e editar filtros de escopo em um popover responsivo com regras de campo, operador e valor e rascunho explícito. Filtros aplicados SHALL permanecer identificáveis por resumo truncável e a composição SHALL NOT criar rolagem horizontal no painel mestre suportado.

#### Scenario: Painel mestre estreito
- **WHEN** o painel de conversas é exibido ou redimensionado em uma largura suportada
- **THEN** busca, três gatilhos quadrados e resumo cabem ou truncam sem select largo, padding lateral geral, scrollbar horizontal, controles cortados ou conteúdo inacessível

#### Scenario: Operador abre um controle simples
- **WHEN** o usuário aciona status ou ordenação
- **THEN** o respectivo dropdown abre a partir de um botão somente com ícone, identifica a opção aplicada e não altera os demais filtros

#### Scenario: Menus de filtro mantêm vínculo espacial com o gatilho
- **WHEN** status, ordenação ou filtros avançados são abertos
- **THEN** status ancora por `start`, ordenação e o popover avançado ancoram por `end`, e a colisão pode deslocá-los somente o necessário para permanecer dentro do viewport

#### Scenario: Usuário constrói filtros avançados
- **WHEN** o usuário abre filtros avançados, adiciona ou remove uma regra e escolhe seu valor
- **THEN** o popover apresenta campo, operador compatível e valor, combina as regras por `E` e não oferece operadores que a API não consegue representar

#### Scenario: Usuário confirma vários filtros
- **WHEN** o usuário altera inbox, responsável, fila, marcadores, não lidas, sem responsável ou remove/mantém o contato ativo e aciona Aplicar
- **THEN** todos os valores e a presença do contato no rascunho substituem juntos o escopo aplicado, atualizam a URL e a lista executa uma recarga consolidada

#### Scenario: Regra que exige valor permanece incompleta
- **WHEN** inbox, responsável, fila ou marcadores possui regra sem valor selecionado
- **THEN** Aplicar permanece desabilitado, o rascunho é preservado e o editor informa que cada filtro precisa de valor

#### Scenario: Escopo aplicado muda com editor aberto
- **WHEN** rota ou estado autoritativo altera os filtros de origem enquanto o popover está aberto
- **THEN** o rascunho é ressincronizado e não pode reaplicar valores obsoletos sobre o novo escopo

#### Scenario: Usuário cancela o editor
- **WHEN** o usuário altera o rascunho e fecha o popover por Escape, clique externo ou pelo gatilho
- **THEN** filtros aplicados, URL e seleção permanecem inalterados

#### Scenario: Deep-link possui filtros ativos
- **WHEN** o workspace restaura filtros válidos da URL
- **THEN** o editor permanece fechado e até dois resumos mais `+N` comunicam o escopo ativo sem duplicar busca, status ou ordenação

### Requirement: Seleção de conversas é contextual e centralizada

A SPA SHALL manter o checkbox de cada conversa centralizado sobre o avatar como controle irmão da abertura e do menu. Sem seleção, nenhuma faixa permanente de selecionar carregadas SHALL ocupar a lista; após a primeira seleção, uma faixa contextual no topo SHALL expor estado parcial/total, contagem, ações autorizadas por ícones e limpeza sem cobrir conteúdo.

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
- **THEN** leitura, status, responsável e organização aparecem como gatilhos iconográficos separados com nomes acessíveis, sem `ShellBulkActionBar` ou menu genérico “Ações”

#### Scenario: Menus bulk respeitam a posição no grupo
- **WHEN** o operador abre uma ação da faixa contextual
- **THEN** leitura e status ancoram por `start`, responsável e organização ancoram por `end`, e o menu permanece associado ao ícone sem ultrapassar o viewport

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
- **THEN** a seleção é removida, a barra desaparece e o foco retorna a uma conversa carregada anterior ou ao contêiner da lista

#### Scenario: Faixa contextual e fim da lista coexistem
- **WHEN** a faixa contextual está visível e o usuário alcança a última conversa ou o carregador incremental
- **THEN** a faixa permanece acima da lista no fluxo, nenhum conteúdo fica coberto e não surge overflow horizontal na página, no painel ou na própria faixa
