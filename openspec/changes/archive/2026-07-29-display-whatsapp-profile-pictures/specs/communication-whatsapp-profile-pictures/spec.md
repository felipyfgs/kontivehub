## ADDED Requirements

### Requirement: Foto é adquirida de forma assíncrona e privada

O Laravel SHALL consultar `PROFILE_PICTURE` somente para identities WhatsApp já materializadas em uma inbox do tenant, SHALL solicitar `preview=true` e SHALL realizar aquisição em job após commit. Requests de contatos, conversations e imagens MUST NOT iniciar consulta síncrona ao Wazync.

A URL retornada pelo gateway SHALL existir somente durante a execução do job. O sistema SHALL armazenar os bytes em storage cifrado e MUST NOT persistir, registrar ou expor a URL remota, JID, `picture_id`, path ou payload bruto.

#### Scenario: Primeira conversation agenda foto
- **WHEN** a primeira conversation de uma identity WhatsApp é commitada e a capability está autorizada para o tenant
- **THEN** um job único é agendado após commit e a resposta da conversation não espera sua conclusão

#### Scenario: Lista com cache vazio
- **WHEN** contatos ou conversations são listados antes de existir asset `READY`
- **THEN** `profile_picture_url` é `null`, nenhum fetch síncrono ocorre e a UI usa iniciais

#### Scenario: URL do provider é processada
- **WHEN** o Wazync devolve uma URL válida e a imagem passa por todas as validações
- **THEN** somente os bytes cifrados e metadados allowlisted são persistidos

### Requirement: Download remoto falha fechado

O downloader SHALL aceitar somente HTTPS na porta 443, host explicitamente allowlisted e DNS composto apenas por IPs públicos; SHALL fixar a conexão ao IP validado, verificar TLS/hostname, rejeitar redirects e limitar connect/total timeout e tamanho de stream.

Somente JPEG, PNG ou WebP com header, assinatura e dimensões coerentes de até 4096×4096 e no máximo 2 MiB SHALL ser promovidos. Falha SHALL produzir somente código seguro e MUST NOT registrar URL, host completo, body ou bytes.

#### Scenario: URL aponta para rede privada
- **WHEN** o hostname resolve para loopback, link-local, rede privada, reservada ou endereço misto público/privado
- **THEN** o download é rejeitado antes da conexão e nenhum objeto é persistido

#### Scenario: Redirect recebido
- **WHEN** o servidor responde com qualquer redirect
- **THEN** o adapter não segue o destino e o job registra somente falha allowlisted

#### Scenario: Conteúdo não é imagem permitida
- **WHEN** MIME, assinatura, dimensões ou tamanho violam os limites
- **THEN** o stream temporário é descartado e o asset anterior coerente não é substituído

### Requirement: Asset é versionado, idempotente e invalidável

Cada perfil por inbox+identity SHALL manter estado `UNKNOWN`, `PENDING`, `READY`, `UNAVAILABLE` ou `FAILED` e versão pública local. Uma mudança aceita de `picture_id` SHALL invalidar a imagem anterior e agendar refresh; evento repetido ou fora de ordem SHALL NOT causar novo egress. Clear explícito SHALL remover imediatamente a URL pública e liberar o objeto antigo após commit.

O job SHALL promover o objeto somente se o snapshot ainda for atual. Resultado `nil`, privacidade, 403 ou 404 SHALL produzir `UNAVAILABLE` com cache negativo; erro transitório SHALL usar retry/backoff finitos e, ao esgotar, `FAILED` com próxima tentativa futura.

#### Scenario: Evento fora de ordem
- **WHEN** um evento de foto mais antigo chega após uma versão já persistida
- **THEN** o perfil, o asset, a versão pública e a fila permanecem inalterados

#### Scenario: Foto muda durante o fetch
- **WHEN** o job termina depois que `picture_id` recebeu uma observação mais nova
- **THEN** o objeto baixado é descartado e não substitui nem reexpõe a versão anterior

#### Scenario: Foto é removida no WhatsApp
- **WHEN** um clear novo de `picture_id` é commitado
- **THEN** a Resource passa a devolver `null` e o objeto anterior é apagado de forma idempotente após commit

#### Scenario: Provider restringe ou remove a foto
- **WHEN** o WhatsApp devolve seu erro tipado de privacidade ou foto ausente
- **THEN** o Wazync responde com código estável e sanitizado, o Laravel grava cache negativo sem retry operacional e nenhum dado do provider é persistido ou registrado

### Requirement: Resolução respeita inbox, identity e ator

Uma conversation SHALL usar somente o asset `READY` do perfil pertencente à sua inbox e identity canônica comprovada. Um contato SHALL usar, entre as inboxes visíveis ao ator, o asset `READY` ligado à conversation canônica não expurgada com atividade mais recente e desempate determinístico. A resolução MUST NOT consultar ou revelar perfil de inbox não visível.

#### Scenario: Mesmo contato em duas inboxes
- **WHEN** duas inboxes possuem fotos diferentes para o mesmo contato e o ator acessa ambas
- **THEN** a conversation mostra sua própria foto e o contato mostra a foto da conversation visível mais recente

#### Scenario: Inbox mais recente não é visível
- **WHEN** a activity mais recente pertence a inbox sem membership para o ator
- **THEN** o contato escolhe a próxima foto visível ou usa fallback, sem revelar a existência da inbox oculta

#### Scenario: Alias PN e LID são correlacionados
- **WHEN** uma conversation usa alias comprovado cuja identity canônica possui perfil na mesma inbox
- **THEN** ela reutiliza o asset canônico sem inferir correlação ou cruzar outra inbox

### Requirement: API pública entrega somente imagem autorizada

Os Resources públicos SHALL adicionar `profile_picture_url: string|null` ao contato e ao resumo `contact` da conversation sem remover ou alterar campos existentes. URL não nula SHALL apontar para `GET /api/v1/communication/profile-pictures/{profile}/{version}` no Laravel.

O GET SHALL reautorizar `CommunicationView`, tenant e acesso à inbox; SHALL responder 404 para acesso negado, purge, estado não `READY`, versão divergente ou objeto ausente. Resposta válida SHALL ter MIME/tamanho persistidos, `nosniff`, `ETag` e cache privado com revalidação; `If-None-Match` válido MAY retornar 304 após autorização.

#### Scenario: Consumidor legado ignora o campo
- **WHEN** um cliente anterior consulta contatos ou conversations
- **THEN** envelopes, paginação e campos existentes permanecem compatíveis

#### Scenario: Usuário perde acesso
- **WHEN** uma URL previamente recebida é requisitada após remoção da membership da inbox
- **THEN** o endpoint responde 404 e não abre nem descriptografa o objeto

#### Scenario: ETag coincide
- **WHEN** um ator ainda autorizado revalida a mesma versão com ETag correspondente
- **THEN** a API responde 304 sem transmitir novamente os bytes

#### Scenario: Limite da rota é excedido
- **WHEN** o limite configurado por usuário+tenant ou por IP é excedido
- **THEN** a API responde 429 antes de consultar, abrir ou descriptografar o objeto

### Requirement: Backfill e lifecycle são limitados e completos

O despachante agendado SHALL permanecer no-op enquanto a capability ou allowlist não estiver habilitada ou o fetch kill switch estiver ativo. Quando autorizado, SHALL rodar a cada quinze minutos com singleton/overlap lock, priorizar activity recente e respeitar limites globais e por inbox de 100 e 25 jobs.

Merge SHALL manter somente asset coerente com o perfil vencedor; purge SHALL apagar todos os objetos da classe de contato e export SHALL incluir apenas estado e metadados allowlisted, nunca bytes, URL ou path.

#### Scenario: Defaults fail-closed
- **WHEN** a aplicação usa somente valores de exemplo/default
- **THEN** nenhum fetch, backfill ou URL pública de foto é habilitado

#### Scenario: Inbox muito ativa não causa starvation
- **WHEN** uma inbox possui mais candidatas recentes que a janela global e outras inboxes possuem candidates elegíveis
- **THEN** aliases são deduplicados, a cota por inbox é aplicada antes do limite global e as demais inboxes continuam concorrendo de forma determinística

#### Scenario: Estado muda após o despacho
- **WHEN** tenant ou inbox é desabilitado ou a inbox deixa de estar conectada antes da execução do job
- **THEN** o job revalida a disponibilidade e encerra sem consultar o gateway

#### Scenario: Purge do contato
- **WHEN** um contato canônico e seus donors são expurgados
- **THEN** todos os objetos de foto relacionados deixam de ser servidos e são apagados ou enviados para retry de deleção

#### Scenario: Exclusão da inbox sofre rollback
- **WHEN** a exclusão da inbox falha após coletar os assets
- **THEN** inbox, profiles e referências permanecem válidos e nenhum objeto é removido após a transação revertida

#### Scenario: Export autorizado
- **WHEN** um gestor exporta o contato
- **THEN** o JSON contém estado, MIME, tamanho, SHA-256 e timestamps da foto, mas não contém bytes, URL remota, object ID nem path
