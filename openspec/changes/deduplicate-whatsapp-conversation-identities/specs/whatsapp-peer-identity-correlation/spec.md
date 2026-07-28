## ADDED Requirements

### Requirement: O gateway projeta o peer remoto pela conversa
O Wazync SHALL usar o `MessageSource.Chat` normalizado como identidade primária de todo evento 1:1 e SHALL NOT substituir o peer pela identidade da própria sessão.

#### Scenario: Mensagem inbound com chat LID
- **WHEN** uma mensagem inbound possui `Chat=LID` e `SenderAlt=PN` remota
- **THEN** o evento contém o LID como `source_identity.primary` e a PN remota como `source_identity.alternate`

#### Scenario: Mensagem outbound com chat LID
- **WHEN** uma mensagem outbound possui `Chat=LID`, `SenderAlt=PN` da sessão e `RecipientAlt=PN` remota
- **THEN** o evento contém o LID como primary, a PN remota como alternate e não contém a PN da sessão como peer ou alias

#### Scenario: Histórico preserva a mesma projeção
- **WHEN** uma mensagem equivalente chega por history sync
- **THEN** sua projeção de primary e alternate é igual à projeção de uma mensagem live

### Requirement: A API resolve somente aliases remotos comprovados
A API SHALL aceitar `from` legado, SHALL preferir `source_identity` válido quando presente e SHALL remover o endereço da inbox de todo conjunto de aliases antes de persistir.

#### Scenario: `from` LID com PN remota estruturada
- **WHEN** um evento contém `from=LID`, primary LID e alternate PN diferente da sessão
- **THEN** a API usa a PN remota como identidade canônica e mantém o LID como alias do mesmo contato

#### Scenario: Alternate coincide com a sessão
- **WHEN** um evento contém primary LID e alternate igual ao endereço da inbox
- **THEN** a API mantém o LID como peer e não cria nem religa a identity da sessão ao contato remoto

#### Scenario: Evento legado sem identidade estruturada
- **WHEN** um evento válido contém apenas `from` remoto
- **THEN** a API continua resolvendo o peer pelo endereço legado sem alterar o contrato público

### Requirement: Aliases convergem para uma conversa ativa
Ao receber uma associação LID↔PN comprovada, a API SHALL reconciliar as identities no mesmo tenant e SHALL manter no máximo uma conversation ativa para esse peer em cada inbox.

#### Scenario: PN chega antes do LID
- **WHEN** uma mensagem por PN cria a conversation e um evento posterior associa essa PN a um LID
- **THEN** a mensagem posterior reutiliza a conversation existente e as duas identities pertencem ao mesmo contato

#### Scenario: LID chega antes da PN
- **WHEN** uma mensagem por LID cria a conversation e um evento posterior associa o LID à PN remota
- **THEN** a API promove a PN canônica sem criar uma segunda conversation ativa

#### Scenario: Registros já fragmentados
- **WHEN** LID e PN comprovadamente equivalentes já pertencem a contatos e conversations ativas diferentes
- **THEN** a API reúne as identities, preserva as mensagens na conversation sobrevivente e resolve as conversations ativas doadoras

### Requirement: Correlação é transacional, concorrente e tenant-safe
A correlação SHALL executar dentro da transação do evento, SHALL serializar eventos que compartilham aliases e SHALL NOT consultar ou alterar identities de outro tenant ou inbox fora do fio correspondente.

#### Scenario: Eventos concorrentes com aliases sobrepostos
- **WHEN** dois eventos concorrentes compartilham LID ou PN para a mesma inbox
- **THEN** ambos convergem para um contato e uma conversation ativa sem violar constraints

#### Scenario: Mesmo endereço em tenants distintos
- **WHEN** tenants diferentes recebem o mesmo endereço normalizado
- **THEN** cada tenant mantém sua própria identity, contato e conversation sem correlação cruzada

### Requirement: Self-chat falha de forma fechada
A API SHALL NOT criar ou reabrir uma conversation cujo único peer seja o endereço da própria inbox.

#### Scenario: Evento contém apenas a PN da sessão
- **WHEN** o único endereço remoto resolvível coincide com a sessão da inbox
- **THEN** a ingestão rejeita o peer como self-chat e não cria contato, identity ou conversation

#### Scenario: Self-chat legado é encontrado
- **WHEN** uma correlação confiável encontra uma conversation ativa ligada à identity da própria sessão
- **THEN** essa conversation não é usada como peer remoto nem recebe novas mensagens
