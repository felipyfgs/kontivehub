# communication-contact-profile-resolution Specification

## Purpose

Definir a preservação e resolução segura de perfis de identidade por inbox para Communication.

## Requirements

### Requirement: Perfis preservam fontes por inbox e identidade
O sistema SHALL persistir `address_book_first_name`, `address_book_full_name`, `verified_name`, `business_name`, `push_name`, `picture_id` e `about` separadamente em `communication_inbox_identity_profiles`, com unicidade por `(tenant_id, inbox_id, identity_id)`.

Cada fonte SHALL manter sua ordenação por `(observed_at, event_id)`. Campos ausentes SHALL preservar o valor anterior; somente `cleared_fields` SHALL remover um campo, e uma observação anterior SHALL ser ignorada.

#### Scenario: Duas inboxes têm nomes de agenda diferentes
- **WHEN** a mesma identity é observada como “Maria Fiscal” em uma inbox e “Maria Silva” em outra
- **THEN** cada conversa resolve o nome da agenda da própria inbox, sem vazamento entre inboxes

#### Scenario: Evento parcial preserva outras fontes
- **WHEN** chega um evento PUSH contendo apenas `push_name` após uma observação BUSINESS
- **THEN** somente `push_name` é atualizado e `business_name` permanece intacto

#### Scenario: Clear explícito
- **WHEN** um evento mais novo inclui `cleared_fields: ["push_name"]`
- **THEN** `push_name` é removido, mas campos ausentes e outras fontes são preservados

#### Scenario: Evento fora de ordem
- **WHEN** chega uma observação cujo `(observed_at,event_id)` é anterior ao salvo para a mesma fonte
- **THEN** ela é ignorada sem regressão do perfil

#### Scenario: Contato curado não é sobrescrito
- **WHEN** chega qualquer observação para `CommunicationContact` com nome manual
- **THEN** o nome manual permanece inalterado e as fontes são gravadas somente no perfil da inbox

### Requirement: Laravel resolve o nome com precedência única
A API SHALL resolver `display_name` exclusivamente no Laravel, sem replicar a precedência no Wazync ou no Nuxt, nesta ordem:

1. nome manual de `CommunicationContact`;
2. único nome distinto de `ClientContact` vinculado;
3. `address_book_full_name` ou `address_book_first_name` da inbox;
4. `verified_name` já observado por `USER_INFO`;
5. `business_name`;
6. `push_name`;
7. `CommunicationContact.name` quando `is_provisional = true`;
8. telefone/endereço mascarado;
9. identificador interno opaco.

`display_name_source` SHALL indicar a fonte vencedora. Empresas fiscais SHALL permanecer contexto secundário e SHALL NOT substituir a pessoa no título.

Para o item 7, `display_name_source` SHALL preservar o backing value observável `LEGACY_PROVISIONAL`; esse literal contratual não autoriza o termo em outros identificadores, comentários ou arquivos.

#### Scenario: Nome manual vence todos os observados
- **WHEN** uma conversa possui nome manual, ClientContact, agenda, verified, business e push
- **THEN** `display_name` usa o nome manual

#### Scenario: Um único ClientContact distinto
- **WHEN** existem um ou mais vínculos que resultam em exatamente um nome distinto de `ClientContact`
- **THEN** esse nome vence as fontes WhatsApp

#### Scenario: ClientContacts ambíguos
- **WHEN** existem dois nomes distintos de `ClientContact`
- **THEN** nenhum deles é escolhido automaticamente e a resolução continua na agenda da inbox

#### Scenario: Agenda vence verified
- **WHEN** há nome de agenda e verified name, mas não há manual ou ClientContact único
- **THEN** o nome de agenda é usado

#### Scenario: Nome do contato provisório é o fallback disponível
- **WHEN** `CommunicationContact.name` está preenchido, `is_provisional = true` e nenhuma fonte de maior precedência existe
- **THEN** a API usa esse nome e retorna `display_name_source = LEGACY_PROVISIONAL`

#### Scenario: Fallback sem PII crua
- **WHEN** nenhuma fonte de nome existe
- **THEN** a API usa endereço mascarado ou ID opaco e não expõe JID cru

### Requirement: Query privada consulta somente store local e identities conhecidas
O contrato Wazync SHALL oferecer `CONTACT_PROFILES` para 1 a 100 endereços 1:1 solicitados. A implementação SHALL usar somente `ContactStore.GetContact`, SHALL NOT usar `GetAllContacts` e SHALL NOT iniciar egress remoto para completar perfis.

A API SHALL construir batches exclusivamente a partir de identities existentes da inbox/sessão. A reconciliação SHALL ser idempotente, retomável e tratar falha ou `found=false` como desconhecido, nunca como clear.

#### Scenario: Campos de agenda permanecem separados
- **WHEN** `GetContact` retorna `FirstName` e `FullName` diferentes
- **THEN** a resposta preserva `address_book_first_name` e `address_book_full_name` separadamente

#### Scenario: Contato não encontrado
- **WHEN** `GetContact` retorna `Found=false`
- **THEN** a resposta identifica o resultado como desconhecido e a API não limpa dados persistidos

#### Scenario: Batch excede o limite
- **WHEN** são enviados mais de 100 endereços
- **THEN** a query é rejeitada antes de consultar o store

#### Scenario: LID sem PN
- **WHEN** uma identity LID conhecida não possui evidência de PN alternativa
- **THEN** ela é consultada como LID e nenhuma PN é inferida

### Requirement: Perfil participa de merge, export e purge
Merge PN↔LID SHALL escolher, por fonte e inbox, a observação mais recente. O asset de foto SHALL acompanhar o `picture_id` vencedor somente quando sua versão for coerente; caso contrário o perfil SHALL ficar sem URL e agendar refresh. Export SHALL incluir os dados e metadados autorizados, e purge SHALL remover perfil e objeto cifrado.

#### Scenario: Donor tem fonte mais nova
- **WHEN** o donor possui push name mais recente e o survivor possui agenda mais recente
- **THEN** o perfil consolidado mantém o push do donor e a agenda do survivor

#### Scenario: Donor possui a foto vencedora
- **WHEN** o donor possui `picture_id` e asset coerentes mais recentes que os do survivor na mesma inbox
- **THEN** o perfil consolidado mantém esse asset uma única vez e agenda a deleção de qualquer objeto abandonado

#### Scenario: Versão e asset divergem
- **WHEN** o `picture_id` vencedor não corresponde ao asset disponível
- **THEN** nenhuma foto antiga é exposta e um refresh idempotente é agendado após commit

#### Scenario: Perfil é expurgado
- **WHEN** o contato canônico é purgado
- **THEN** metadados são removidos na transação e os bytes cifrados são apagados com retry seguro
