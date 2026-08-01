## ADDED Requirements

### Requirement: URLs canônicas internas não usam query string

A SPA SHALL emitir e manter URLs internas canônicas sem query string. Identidade de recursos e contextos compartilháveis SHALL usar paths; filtros transitórios, busca, paginação e ordenação SHALL permanecer em estado de sessão. Query parameters de requests HTTP e URLs externas SHALL permanecer fora desta regra.

#### Scenario: Usuário altera filtros
- **WHEN** o operador altera busca, filtros, ordenação, página ou tab transitória
- **THEN** a lista usa o novo estado para consultar a API e `window.location.search` permanece vazio

#### Scenario: Request filtrado é enviado
- **WHEN** uma superfície sem query solicita dados paginados ou filtrados
- **THEN** o cliente HTTP continua enviando os parâmetros suportados ao endpoint `/api/v1` sem projetá-los na barra de endereço

### Requirement: Estado de superfície é isolado e efêmero

Filtros transitórios SHALL sobreviver à navegação interna entre lista e detalhe na mesma sessão, SHALL ser isolados por usuário e tenant e SHALL voltar aos defaults após hard reload, logout ou troca de contexto. Presets salvos SHALL continuar sendo a única persistência explícita entre sessões.

#### Scenario: Detalhe retorna à lista
- **WHEN** o usuário abre um recurso a partir de uma lista filtrada e retorna pelo controle da interface
- **THEN** busca, filtros, ordenação e página anteriores são restaurados sem query string

#### Scenario: Tenant muda
- **WHEN** a sessão troca de tenant ou identidade
- **THEN** nenhum filtro ou intenção do contexto anterior permanece acessível no novo contexto

#### Scenario: Página sofre hard reload
- **WHEN** uma URL canônica sem contexto no path é recarregada
- **THEN** filtros transitórios retornam aos defaults e nenhum valor é recuperado de localStorage

### Requirement: Contextos estáveis usam paths tipados

Conversa, mensagem, contato, seção de processo, calendário, contexto documental, tipo de Saúde e comandos de criação compartilháveis SHALL possuir paths allowlisted. Valores inválidos SHALL ser rejeitados ou canonicalizados para o recurso base sem revelar dados.

#### Scenario: Mensagem é compartilhada
- **WHEN** uma mensagem visível é aberta por deep-link
- **THEN** conversa e mensagem são identificadas no path, carregadas de forma autorizada e a URL não contém query

#### Scenario: Contexto de path é inválido
- **WHEN** um segmento não satisfaz o allowlist ou não pertence ao tenant
- **THEN** a SPA usa o fallback canônico fail-closed sem inventar conteúdo nem manter segmento inválido

### Requirement: Intenções filtradas são consumidas uma vez

Atalhos internos que aplicam filtros SHALL publicar uma intenção tipada antes de navegar. A superfície alvo SHALL validar, aplicar e remover essa intenção uma única vez; reload ou acesso direto ao path base SHALL usar defaults.

#### Scenario: KPI abre uma lista filtrada
- **WHEN** o usuário aciona um KPI com recorte de tab, cliente ou departamento
- **THEN** a lista alvo aplica a intenção na sessão, consulta a API e mantém o path base sem query

#### Scenario: KPI sem responsável abre o recorte correspondente
- **WHEN** o usuário aciona o KPI `Sem responsável`
- **THEN** a SPA publica a intenção de lista com `tab=sem_responsavel`, a API retorna somente tarefas abertas não atribuídas do tenant corrente e a URL permanece `/work/tasks`

### Requirement: Somente URLs canônicas são aceitas após a migração

Após o ciclo de compatibilidade, a SPA SHALL NOT converter queries de navegador anteriores em estado, intenção ou path. A navegação interna SHALL emitir somente paths canônicos e fragmentos one-shot previstos.

#### Scenario: Bookmark anterior é aberto
- **WHEN** uma URL antiga contém chaves válidas da superfície
- **THEN** a SPA não aplica estado, intenção ou path equivalente a partir da query

#### Scenario: Query desconhecida é recebida
- **WHEN** uma URL interna contém chave não allowlisted
- **THEN** a chave é removida sem ser refletida, persistida ou registrada

### Requirement: Novos produtores de query são bloqueados

O gate Web SHALL reprovar leitura/escrita de query de navegador e URLs internas literais com `?`. Clientes HTTP, tipos gerados, fragmentos one-shot e URLs externas SHALL permanecer permitidos.

#### Scenario: Gate de navegação é executado
- **WHEN** testes estáticos analisam páginas, componentes, middleware, composables e helpers de navegação
- **THEN** nenhum middleware, página, componente ou helper de navegação consome query do navegador
