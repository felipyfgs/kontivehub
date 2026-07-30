## MODIFIED Requirements

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
