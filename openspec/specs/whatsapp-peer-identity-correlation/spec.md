# whatsapp-peer-identity-correlation Specification

## Purpose

Definir a correlação de identidades de peer WhatsApp entre Wazync e a API
Laravel: projeção do peer remoto pelo gateway, resolução apenas de aliases
remotos comprovados, convergência para uma conversation ativa, correlação
transacional/concorrente/tenant-safe e rejeição fail-closed de self-chat.

## Requirements

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
A API SHALL aceitar o `from` anterior, SHALL preferir `source_identity` válido quando presente e SHALL remover o endereço da inbox de todo conjunto de aliases antes de persistir.

#### Scenario: `from` LID com PN remota estruturada
- **WHEN** um evento contém `from=LID`, primary LID e alternate PN diferente da sessão
- **THEN** a API usa a PN remota como identidade canônica e mantém o LID como alias do mesmo contato

#### Scenario: Alternate coincide com a sessão
- **WHEN** um evento contém primary LID e alternate igual ao endereço da inbox
- **THEN** a API mantém o LID como peer e não cria nem religa a identity da sessão ao contato remoto

#### Scenario: Evento anterior sem identidade estruturada
- **WHEN** um evento válido contém apenas `from` remoto
- **THEN** a API continua resolvendo o peer pelo endereço anterior sem alterar o contrato público

#### Scenario: Evidência estrutural incoerente
- **WHEN** `source_identity` tenta associar PN↔PN, LID↔LID, PN→LID, endereços iguais ou evidence diferente de `MESSAGE_SOURCE_ALT`
- **THEN** o contrato e o resolver rejeitam a correlação sem alterar contacts, identities ou conversations

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

#### Scenario: Contato curado está no alias LID
- **WHEN** o LID pertence a contato nomeado/não provisório e a PN canônica pertence a contato provisório
- **THEN** os aliases convergem para o contato curado, seus metadados são preservados e nenhum contato doador vazio permanece ativo

#### Scenario: ID de contact doador é usado após o merge
- **WHEN** show, update, add identity, export ou purge recebe o ID de um contact já redirecionado
- **THEN** a operação usa o contact sobrevivente; listagens omitem o donor e purge remove os dados recuperáveis de toda a classe redirecionada

#### Scenario: Contact doador possui outra identity legítima
- **WHEN** um contact doador do par LID↔PN também possui uma identity que não participa da evidência
- **THEN** a identity adicional passa a pertencer ao contact sobrevivente, mas não recebe `canonical_identity_id` nem tem sua conversation fundida sem evidência própria

#### Scenario: Writer usa alias após a consolidação
- **WHEN** uma automação ou mutação recebe uma identity ou conversation doadora já canonicalizada
- **THEN** a operação reutiliza a identity/conversation sobrevivente ou retorna conflito de versão, sem criar nem reabrir outro fio

#### Scenario: Um fragmento possui flow run ativo
- **WHEN** exatamente uma das conversations equivalentes possui flow run não terminal
- **THEN** essa conversation sobrevive e o run continua apontando para o fio canônico

#### Scenario: Mais de um fragmento possui flow run ativo
- **WHEN** conversations equivalentes distintas possuem flow runs não terminais
- **THEN** a correlação falha fechada e nenhuma conversation, message ou execução é movida

### Requirement: Correlação é transacional, concorrente e tenant-safe
A correlação SHALL executar dentro da transação do evento, SHALL serializar eventos que compartilham aliases, SHALL NOT consultar ou alterar identities de outro tenant e SHALL restringir mutations de conversations à inbox correspondente.

#### Scenario: Eventos concorrentes com aliases sobrepostos
- **WHEN** dois eventos concorrentes compartilham LID ou PN para a mesma inbox
- **THEN** ambos convergem para um contato e uma conversation ativa sem violar constraints

#### Scenario: Classes concorrentes compartilham contact
- **WHEN** dois processos PostgreSQL correlacionam classes de identity inicialmente disjuntas que apontam para contacts da mesma classe redirecionada
- **THEN** member/contact locks, reexpansão e retry fazem ambos convergirem para o contact curado sem donor ativo ou merge parcial

#### Scenario: Retry idempotente acrescenta alias
- **WHEN** uma mensagem já existe por LID e um retry/history com o mesmo `provider_message_id` acrescenta a PN comprovada
- **THEN** nenhuma nova mensagem é criada, mas identities e conversations fragmentadas são reconciliadas e o evento referencia a conversation sobrevivente

#### Scenario: Evento atrasado não regride atividade
- **WHEN** live ou history chega com `occurred_at` anterior ao `last_message_at` persistido
- **THEN** a correlação preserva o maior timestamp da timeline

#### Scenario: History antigo contém uma PN anterior
- **WHEN** um LID foi relacionado a uma PN recente em live e history atrasado apresenta outra PN com evidência mais antiga
- **THEN** a PN recente permanece canônica e `last_seen_at` só avança nos aliases presentes no evento

#### Scenario: Mesmo endereço em tenants distintos
- **WHEN** tenants diferentes recebem o mesmo endereço normalizado
- **THEN** cada tenant mantém sua própria identity, contato e conversation sem correlação cruzada

### Requirement: Self-chat falha de forma fechada
A API SHALL NOT criar ou reabrir uma conversation cujo único peer seja o endereço da própria inbox.

#### Scenario: Evento contém apenas a PN da sessão
- **WHEN** o único endereço remoto resolvível coincide com a sessão da inbox
- **THEN** a ingestão rejeita o peer como self-chat e não cria contato, identity ou conversation

#### Scenario: Self-chat anterior é encontrado
- **WHEN** uma correlação confiável encontra uma conversation ativa ligada à identity da própria sessão
- **THEN** essa conversation não é usada como peer remoto nem recebe novas mensagens
### Requirement: Merge PN↔LID consolida perfil e ledger exatamente
Ao consolidar peers LID↔PN com evidência válida, a API SHALL transferir perfis por inbox e a união exata do ledger para a identity/conversation sobreviventes.

A escolha SHALL usar uma ordenação total única em retries e merges concorrentes. O contato sobrevivente prioriza, nesta ordem, raiz não expurgada, não provisória, com nome, ativa e menor `id`. A identity prioriza PN sobre LID e, entre PNs, maior `last_seen_at`, raiz canônica e menor `id`; sem PN, permanece a identity do endereço canônico comprovado pelo evento. A conversation prioriza a única execução de flow não terminal; sem flow, prioriza estado não resolvido, maior `last_message_at` e maior `id`. Mais de uma conversation com flow não terminal é conflito fail-closed. Donors, profiles, ledger, read-state e redirects MUST convergir para esses mesmos survivors.

#### Scenario: Fragmentos provisórios empatam
- **WHEN** retries ou workers concorrentes encontram fragmentos com a mesma prioridade funcional
- **THEN** locks de aliases ordenados e os desempates por `id` escolhem o mesmo survivor, e todos os donors apontam para ele sem cadeia divergente

#### Scenario: Perfis escolhem a fonte mais recente
- **WHEN** survivor e donor possuem observações diferentes da mesma fonte
- **THEN** vence o maior `(observed_at,event_id)` daquela fonte, preservando fontes independentes

#### Scenario: Ledger contém lacunas
- **WHEN** os fragmentos possuem pendências não contíguas
- **THEN** cada `message_id` pendente é movido uma única vez para a sobrevivente

#### Scenario: Read-state é consolidado
- **WHEN** fragmentos possuem read-states diferentes
- **THEN** a sobrevivente recebe cursor compatível, autoria preservada quando aplicável e uma nova versão

#### Scenario: Writer recebe ID donor
- **WHEN** READ ou UNREAD recebe uma conversa redirecionada
- **THEN** a operação aplica-se à sobrevivente e mantém a regra de conflito de versão

### Requirement: Correlação não inventa PN
O Wazync SHALL converter `JIDAlt` válido do provider no objeto normalizado `source_identity`; a API SHALL aceitar somente esse objeto contratual e SHALL NOT consumir campos protobuf crus. Para inbound, `source_identity.primary` é o peer remoto LID e `alternate` MAY vir de `SenderAlt` remoto PN. Para outbound, o alternate remoto MAY vir somente de `RecipientAlt`; `SenderAlt` representa a sessão e MUST NOT promover o peer. Evidência válida exige `primary_kind=LID`, `alternate_kind=PN`, `evidence=MESSAGE_SOURCE_ALT`, endereços 1:1 normalizados e alternate diferente da PN da sessão. Grupo/broadcast, kinds trocados, alternate ausente/inválido ou a PN da própria sessão são rejeitados como prova de associação. O sistema SHALL NOT inferir PN para LID desconhecido.

#### Scenario: Evento outbound contém PNs de sessão e destinatário
- **WHEN** `SenderAlt` é a PN da sessão e `RecipientAlt` é a PN remota válida
- **THEN** somente `RecipientAlt` é publicado como alternate em `source_identity`, e a PN da sessão não participa do merge

#### Scenario: Alternate não comprova peer 1:1
- **WHEN** `JIDAlt` é grupo/broadcast, usa kind incompatível, falha normalização ou coincide com a PN da sessão
- **THEN** `source_identity` não contém associação LID↔PN e o LID permanece sem PN canônica

#### Scenario: LID sem alternate
- **WHEN** um perfil/evento contém somente LID
- **THEN** ele permanece LID até existir evidência válida de PN
