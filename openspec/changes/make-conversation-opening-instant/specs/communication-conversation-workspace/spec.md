## ADDED Requirements

### Requirement: Abertura de conversa reutiliza workspace e timeline preparada

A SPA SHALL manter a mesma instância do workspace ao navegar entre a lista, o deep-link de conversa e o deep-link de mensagem. A primeira página real da timeline das conversas visíveis SHALL ser preparada com concorrência limitada e isolada pelo usuário, tenant e epoch da sessão; prefetch e seleção concorrentes MUST compartilhar a mesma requisição.

Selecionar uma conversa SHALL trocar detalhe e timeline de forma atômica, reutilizar cache inicializado e atualizar dados silenciosamente, sem skeleton de mensagens, texto transitório de abertura, remount do workspace ou transição de entrada/saída no detalhe mobile. A SPA MUST preservar falhas reais, vazio, paginação, deep-link, histórico, leitura após renderização visível e restauração de foco; ela SHALL NOT inventar mensagens quando a API falhar.

#### Scenario: Conversa visível foi preparada

- **WHEN** o operador seleciona uma conversa cuja primeira página real já está no cache da sessão
- **THEN** o painel ou slideover exibe imediatamente essa conversa, atualiza a URL e busca novidades em segundo plano sem limpar as mensagens existentes

#### Scenario: Prefetch ainda está em andamento

- **WHEN** o operador seleciona uma conversa enquanto detalhe ou timeline estão sendo preparados
- **THEN** a seleção reutiliza as promises ativas e realiza uma única troca atômica após a resposta, sem skeleton ou requests iniciais duplicados

#### Scenario: Conversa fria falha ao carregar

- **WHEN** a API falha ao preparar detalhe ou timeline de uma conversa ainda sem cache válido
- **THEN** a SPA mantém conteúdo real anterior quando aplicável, apresenta a falha real e não mostra mensagens de outra conversa nem fallback sintético

#### Scenario: Navegação interna preserva o workspace

- **WHEN** a rota muda entre `/communication`, `/communication/conversations/{id}` e seu deep-link de mensagem
- **THEN** lista, cache, scroll, filtros e subscriptions permanecem na mesma instância sem reinicializar catálogo ou sincronização

#### Scenario: Operador abre e fecha no mobile

- **WHEN** uma conversa preparada é aberta abaixo de `lg` e depois fechada por voltar ou `Esc`
- **THEN** a timeline aparece e desaparece sem transição espacial e o foco retorna à linha correspondente

#### Scenario: Tenant ou sessão muda

- **WHEN** o epoch da sessão muda durante ou após um prefetch
- **THEN** fila, requests e cache do contexto anterior são invalidados e nenhuma resposta antiga é exibida no novo tenant

#### Scenario: Lista inicial e histórico paginado carregam

- **WHEN** a primeira lista ainda não possui dados ou o operador solicita mensagens anteriores/novas
- **THEN** os estados honestos de loading, erro e paginação continuam disponíveis sem serem confundidos com a troca pré-carregada entre conversas
