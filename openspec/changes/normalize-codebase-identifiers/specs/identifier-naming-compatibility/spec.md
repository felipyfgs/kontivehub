## ADDED Requirements

### Requirement: Identificadores internos usam o contexto do módulo
O código SHALL evitar repetir no basename de um símbolo o contexto já fornecido de forma inequívoca pelo namespace, diretório ou módulo, preservando termos oficiais e qualificadores necessários para evitar colisões.

#### Scenario: Símbolo está em namespace de domínio
- **WHEN** uma classe interna é renomeada dentro de um namespace que já identifica `Tenant`, `Work` ou `Communication`
- **THEN** o novo basename omite o prefixo redundante e todos os consumidores passam a usar o novo FQCN

#### Scenario: Encurtamento criaria ambiguidade
- **WHEN** a retirada de um prefixo produziria colisão ou esconderia um termo oficial relevante
- **THEN** o identificador mantém o qualificador semântico necessário em vez de adotar uma abreviação

### Requirement: Refactor de nomes preserva contratos observáveis
O sistema MUST preservar rotas, formatos de payload, valores serializados, persistência, autorização, isolamento tenant, métricas e protocolos de autenticação durante a normalização de identificadores internos.

#### Scenario: Classe de borda é renomeada
- **WHEN** um Controller, Resource, DTO ou consumidor interno recebe novo nome
- **THEN** as mesmas entradas produzem os mesmos paths, status HTTP, campos e valores de resposta anteriores

#### Scenario: Identificador atravessa deploys
- **WHEN** um nome pode estar persistido em fila, configuração ou infraestrutura
- **THEN** a mudança mantém alias compatível ou permanece fora do lote até existir um plano de rollout seguro

### Requirement: Auto-imports Nuxt não repetem diretórios
Os componentes e composables Nuxt SHALL usar basenames que produzam auto-imports legíveis e não dupliquem o nome do diretório que os contém.

#### Scenario: Componente de clientes é auto-importado
- **WHEN** um componente sob `components/clients` possui basename iniciado por `Client`
- **THEN** o arquivo e seus consumidores são renomeados para produzir um identificador iniciado uma única vez por `Clients`

### Requirement: A normalização é validada por app
Cada lote SHALL atualizar testes e executar os gates do app afetado antes do handoff.

#### Scenario: Renomeações dos três apps são concluídas
- **WHEN** API, Web e Wazync tiverem identificadores modificados
- **THEN** buscas residuais, testes focados e gates documentados dos três apps são executados e qualquer falha residual é reportada
