## MODIFIED Requirements

### Requirement: Seleção operacional usa somente conversas carregadas

A SPA SHALL manter a conversa aberta no detalhe separada do conjunto de IDs selecionados para operações. “Selecionar carregadas” SHALL selecionar exatamente as conversas presentes na coleção carregada naquele instante e SHALL NOT representar o resultado filtrado inteiro.

Alterar busca, filtro, ordenação, inbox ou tenant SHALL limpar a seleção. Carregar outra página SHALL preservar os IDs anteriores, mas SHALL NOT selecionar automaticamente as novas linhas. O checkbox da linha SHALL ficar centralizado sobre o avatar e SHALL permanecer um controle separado do botão de abertura e do menu.

#### Scenario: Selecionar todas as carregadas
- **WHEN** existem 50 conversas carregadas e o usuário aciona “Selecionar carregadas”
- **THEN** exatamente esses 50 IDs entram na seleção, independentemente do total informado pela paginação

#### Scenario: Nova página é carregada
- **WHEN** uma página adicional chega depois de todas as linhas anteriores terem sido selecionadas
- **THEN** as novas linhas permanecem desmarcadas e o controle de seleção passa ao estado indeterminado

#### Scenario: Checkbox não abre o detalhe
- **WHEN** o usuário alterna o checkbox sobre o avatar por clique ou teclado
- **THEN** somente a seleção operacional muda e a rota/conversa aberta permanecem inalteradas

#### Scenario: Checkbox permanece descobrível
- **WHEN** a linha recebe hover/foco, está selecionada ou usa dispositivo sem hover
- **THEN** o overlay central fica visível com label acessível e alvo operável

### Requirement: Lista incremental permanece acessível e observável

A lista SHALL virtualizar linhas carregadas e carregar páginas adicionais por sentinel, preservando fallback/retry, fim da lista, item ativo e scroll. Avatar/checkbox, abertura e menu SHALL ser controles semanticamente separados, operáveis por teclado e touch; nenhuma seleção SHALL depender exclusivamente de hover. O overlay SHALL manter a altura estável da linha e não aninhar controles interativos.

Jobs SHALL executar na fila `communication`, com retry/backoff/timeout finitos e tags Horizon. Logs e métricas SHALL NOT conter nome, telefone, endereço, JID, busca ou conteúdo de mensagem. Operações terminais SHALL ser removidas após 30 dias por rotina singleton sem apagar `CommunicationEvent` de auditoria.

#### Scenario: Navegação por teclado em lista virtualizada
- **WHEN** o usuário navega, seleciona, abre e fecha conversas por teclado
- **THEN** o item correto entra na viewport, o foco retorna ao controle previsto e atalhos não atuam dentro do composer

#### Scenario: Touch não depende de hover
- **WHEN** a lista é usada com ponteiro coarse
- **THEN** o controle central de seleção permanece visível e não desloca avatar, texto ou ações

#### Scenario: Retenção é executada
- **WHEN** a rotina diária encontra operações terminais expiradas
- **THEN** remove operações e itens elegíveis uma única vez sem tocar operações ativas ou eventos de auditoria
