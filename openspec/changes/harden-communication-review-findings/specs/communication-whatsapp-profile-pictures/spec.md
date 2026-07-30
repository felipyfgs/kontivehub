## MODIFIED Requirements

### Requirement: Foto é adquirida de forma assíncrona e privada

O Laravel SHALL consultar `PROFILE_PICTURE` somente para identities WhatsApp já materializadas em uma inbox do tenant, SHALL solicitar `preview=true` e SHALL realizar aquisição em job após commit. Requests de contatos, conversations e imagens MUST NOT iniciar consulta síncrona ao Wazync. O endpoint manual que agenda a consulta SHALL aplicar o limiter de profile picture por ator+tenant e por IP antes de enfileirar trabalho.

A URL retornada pelo gateway SHALL existir somente durante a execução do job. O sistema SHALL armazenar os bytes em storage cifrado e MUST NOT persistir, registrar ou expor a URL remota, JID, `picture_id`, path ou payload bruto. O Wazync SHALL expor somente a contagem agregada de queries `PROFILE_PICTURE` em voo, sem labels ou logs que identifiquem tenant, inbox, sessão, query, endereço ou URL.

#### Scenario: Primeira conversation agenda foto
- **WHEN** a primeira conversation de uma identity WhatsApp é commitada e a capability está autorizada para o tenant
- **THEN** um job único é agendado após commit e a resposta da conversation não espera sua conclusão

#### Scenario: Lista com cache vazio
- **WHEN** contatos ou conversations são listados antes de existir asset `READY`
- **THEN** `profile_picture_url` é `null`, nenhum fetch síncrono ocorre e a UI usa iniciais

#### Scenario: URL do provider é processada
- **WHEN** o Wazync devolve uma URL válida e a imagem passa por todas as validações
- **THEN** somente os bytes cifrados e metadados allowlisted são persistidos

#### Scenario: Scheduling excede o limite
- **WHEN** o POST manual ultrapassa qualquer budget configurado
- **THEN** a API responde 429 com headers padrão e nenhum job ou egress é iniciado

#### Scenario: Duas consultas longas estão em voo
- **WHEN** duas queries autorizadas aguardam o provider simultaneamente
- **THEN** `/metrics` expõe gauge agregado igual a dois e retorna a zero após ambas terminarem, sem labels sensíveis
