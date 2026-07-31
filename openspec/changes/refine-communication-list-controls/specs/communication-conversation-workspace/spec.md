## ADDED Requirements

### Requirement: Cabeçalhos do chat priorizam identidade e ações frequentes

O workspace SHALL apresentar navbar da lista, toolbar e cabeçalho da timeline como uma hierarquia única de chat. “Nova conversa” SHALL permanecer diretamente acessível; sincronização e administração SHALL ficar nas reticências do navbar; realtime SHALL ser um indicador semântico junto ao título. Busca SHALL ser o primeiro controle abaixo do navbar; identidade da conversa, status e contexto SHALL permanecer visíveis na timeline. Ações secundárias SHALL usar menus iconográficos com tooltip, nomes acessíveis e sem selects largos permanentes.

#### Scenario: Operador entra no Atendimento
- **WHEN** a lista é exibida sem conversa selecionada
- **THEN** título, total autoritativo, indicador realtime, nova conversa e menu secundário aparecem no navbar, seguidos por busca em largura integral, três tabs fixas e dois controles iconográficos da lista

#### Scenario: Conversa é aberta no desktop
- **WHEN** a timeline adjacente é exibida
- **THEN** avatar, nome resolvido e badge de status permanecem no cabeçalho, contexto usa `panel-right-open/close`, o select largo e o botão isolado de adiar não aparecem e atributos secundários migram para o menu compartilhado

#### Scenario: Conversa é aberta no mobile
- **WHEN** a timeline usa o slideover abaixo de `lg`
- **THEN** voltar, identidade, status e menu continuam operáveis sem overflow, sem select largo e sem perder restauração de foco para a lista

### Requirement: Linhas de conversa mantêm densidade informativa estável

Cada linha SHALL priorizar identidade, contexto/telefone, preview, horário e não lidas, exibindo no máximo o necessário para reconhecer e triar a conversa. Seleção central no avatar, abertura e menu de ações SHALL permanecer controles irmãos. A virtualização SHALL derivar cálculo e CSS da mesma altura responsiva à fonte após qualquer ajuste de densidade.

#### Scenario: Linha possui vários sinais operacionais
- **WHEN** conversa contém não lidas, prioridade, estado não aberto, responsável ausente e marcadores
- **THEN** nome, horário e preview continuam legíveis, sinais secundários truncam ou são progressivamente revelados sem aumentar arbitrariamente a altura da linha

#### Scenario: Fonte ou zoom é ampliado
- **WHEN** a preferência de texto aumenta
- **THEN** a linha cresce a partir da medida real, offsets virtuais permanecem alinhados e menu, checkbox e abertura não se sobrepõem

#### Scenario: Linha está selecionada
- **WHEN** a conversa está aberta ou marcada para ação bulk
- **THEN** o estado tonal diferencia a linha sem substituir a foto, esconder o preview ou confundir seleção operacional com conversa aberta
