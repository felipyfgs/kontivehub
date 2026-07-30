# communication-whatsapp-profile-pictures Specification

## Purpose

Definir a aquisição privada, armazenamento e entrega autorizada de fotos de perfil do WhatsApp.

## Requirements

### Requirement: Foto de perfil é comportamento nativo

O sistema SHALL adquirir e expor fotos de perfil como parte nativa de uma inbox WhatsApp operacional e SHALL NOT depender de feature flag, kill switch, allowlist de tenants ou allowlist de hosts configurável exclusiva dessa função.

#### Scenario: Inbox operacional
- **WHEN** Communication e Wazync estão disponíveis, o tenant está ativo e a inbox WhatsApp está habilitada e conectada
- **THEN** perfis elegíveis entram automaticamente no fluxo assíncrono de aquisição sem configuração adicional de foto

#### Scenario: Cache durante desconexão
- **WHEN** uma inbox fica temporariamente desconectada depois de produzir um asset `READY`
- **THEN** usuários ainda autorizados continuam recebendo a URL Laravel do asset, enquanto novos fetches aguardam disponibilidade real

#### Scenario: Controles de maior risco permanecem independentes
- **WHEN** outbound, flows ou recuperação administrativa de mídia estão desligados
- **THEN** a foto continua operacional e esses controles não são contornados nem removidos

### Requirement: Foto é adquirida de forma assíncrona e privada

O Laravel SHALL consultar `PROFILE_PICTURE` somente para identities WhatsApp já materializadas em uma inbox operacional do tenant, SHALL solicitar `preview=true` e SHALL realizar aquisição em job após commit. Requests de contatos, conversations e imagens MUST NOT iniciar consulta síncrona ao Wazync. O endpoint manual que agenda a consulta SHALL aplicar o limiter de profile picture por ator+tenant e por IP antes de enfileirar trabalho.

A URL retornada pelo gateway SHALL existir somente durante a execução do job. O sistema SHALL armazenar os bytes em storage cifrado e MUST NOT persistir, registrar ou expor a URL remota, JID, `picture_id`, path ou payload bruto. O Wazync SHALL expor somente a contagem agregada de queries `PROFILE_PICTURE` em voo, sem labels ou logs que identifiquem tenant, inbox, sessão, query, endereço ou URL.

Agendamentos concorrentes SHALL ser coalescidos pela identidade estável `(tenant, inbox, identity WhatsApp canônica, profile_picture_version)`. Um perfil `PENDING` SHALL permanecer elegível para recuperação, mas repetições enquanto a chave única do job estiver vigente MUST NOT iniciar um segundo egress; após falha ou expiração do lock, uma execução posterior MAY recuperar o mesmo snapshot ainda atual.

#### Scenario: Primeira conversation agenda foto
- **WHEN** a primeira conversation de uma identity WhatsApp é commitada em inbox operacional
- **THEN** um job único é agendado após commit e a resposta da conversation não espera sua conclusão

#### Scenario: Contato ou mensagem torna perfil elegível
- **WHEN** um contato de inbox é criado/atualizado ou chega mensagem e a foto está ausente, pendente ou expirada
- **THEN** o sistema agenda no máximo um refresh aplicável sem esperar o dispatcher periódico

#### Scenario: Lista com cache vazio
- **WHEN** contatos ou conversations são listados antes de existir asset `READY`
- **THEN** `profile_picture_url` é `null`, o estado real é informado, nenhum fetch síncrono ocorre e a UI usa iniciais

#### Scenario: URL do provider é processada
- **WHEN** o Wazync devolve uma URL válida e a imagem passa por todas as validações
- **THEN** somente os bytes cifrados e metadados allowlisted são persistidos

#### Scenario: Scheduling excede o limite
- **WHEN** o POST manual ultrapassa qualquer budget configurado
- **THEN** a API responde 429 com headers padrão e nenhum job ou egress é iniciado

#### Scenario: Duas consultas longas estão em voo
- **WHEN** duas queries autorizadas aguardam o provider simultaneamente
- **THEN** `/metrics` expõe gauge agregado igual a dois e retorna a zero após ambas terminarem, sem labels sensíveis

### Requirement: Download remoto falha fechado

O downloader SHALL aceitar somente HTTPS na porta 443 cujo hostname seja `whatsapp.net` ou termine em `.whatsapp.net`, e DNS composto apenas por IPs públicos; SHALL fixar a conexão ao IP validado, verificar TLS/hostname, rejeitar redirects e limitar connect/total timeout e tamanho de stream. A política MUST NOT ser ampliada por variável de ambiente.

Somente JPEG, PNG ou WebP com header, assinatura e dimensões coerentes de até 4096×4096 e no máximo 2 MiB SHALL ser promovidos. Falha SHALL produzir somente código seguro e MUST NOT registrar URL, host completo, body ou bytes.

#### Scenario: URL aponta para rede privada
- **WHEN** o hostname resolve para loopback, link-local, rede privada, reservada ou endereço misto público/privado
- **THEN** o download é rejeitado antes da conexão e nenhum objeto é persistido

#### Scenario: Host fora do provider
- **WHEN** a URL usa hostname diferente de `whatsapp.net` e seus subdomínios
- **THEN** o adapter rejeita a URL sem consultar configuração ou executar fallback permissivo

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

Uma conversation SHALL usar somente o asset `READY` do perfil pertencente à sua inbox e identity canônica comprovada. Um contato SHALL priorizar, entre as inboxes visíveis ao ator, o perfil canônico `READY` com activity da conversation canônica não expurgada mais recente; um perfil `READY` sem conversation SHALL continuar diretamente elegível depois dos perfis com activity, com desempate por atualização e ID. A resolução MUST NOT consultar ou revelar perfil de inbox não visível.

#### Scenario: Mesmo contato em duas inboxes
- **WHEN** duas inboxes possuem fotos diferentes para o mesmo contato e o ator acessa ambas
- **THEN** a conversation mostra sua própria foto e o contato mostra a foto da conversation visível mais recente

#### Scenario: Inbox mais recente não é visível
- **WHEN** a activity mais recente pertence a inbox sem membership para o ator
- **THEN** o contato escolhe a próxima foto visível ou usa fallback, sem revelar a existência da inbox oculta

#### Scenario: Perfil pronto ainda não possui conversation
- **WHEN** uma inbox visível possui profile canônico `READY` para o contato sem conversation materializada
- **THEN** catálogo e detalhe podem usar diretamente esse asset autorizado, sem fabricar activity nem consultar outra inbox

#### Scenario: Alias PN e LID são correlacionados
- **WHEN** uma conversation usa alias comprovado cuja identity canônica possui perfil na mesma inbox
- **THEN** ela reutiliza o asset canônico sem inferir correlação ou cruzar outra inbox

### Requirement: API pública entrega somente imagem autorizada

Os Resources públicos SHALL incluir sempre `profile_picture_url: string|null` e `profile_picture_state` no contato e no resumo `contact` da conversation sem remover ou alterar campos existentes. URL não nula SHALL apontar para `GET /api/v1/communication/profile-pictures/{profile}/{version}` no Laravel.

O GET SHALL reautorizar `CommunicationView`, tenant e acesso à inbox; SHALL responder 404 para acesso negado, purge, estado não `READY`, versão divergente ou objeto ausente. Resposta válida SHALL ter MIME/tamanho persistidos, `nosniff`, `ETag` e cache privado com revalidação; `If-None-Match` válido MAY retornar 304 após autorização.

#### Scenario: Consumidor recebe shape estável
- **WHEN** a foto ainda não está pronta ou está indisponível
- **THEN** o Resource mantém `profile_picture_url=null`, informa o estado real e preserva envelopes, paginação e campos existentes

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

O despachante agendado SHALL selecionar automaticamente tenants com Communication ativa, inboxes habilitadas/conectadas e identities WhatsApp ativas; SHALL rodar a cada quinze minutos com singleton/overlap lock, priorizar activity recente e respeitar, por execução, os limites de 100 jobs globais e 25 jobs por inbox. Candidatos excedentes MUST NOT ser descartados e SHALL permanecer elegíveis para execuções posteriores sob a mesma ordenação e cotas. Contatos conhecidos pela inbox SHALL ser elegíveis mesmo sem conversation.

Merge SHALL manter somente asset coerente com o perfil vencedor; purge SHALL apagar todos os objetos da classe de contato e export SHALL incluir apenas estado e metadados allowlisted, nunca bytes, URL ou path.

#### Scenario: Ambiente funcional sem chave de foto
- **WHEN** Communication/Wazync e a inbox estão operacionais usando os valores padrão da superfície de foto
- **THEN** fetch, backfill e URL pública funcionam sem `*_PROFILE_PICTURES_ENABLED`, kill switch ou allowlist de tenant

#### Scenario: Contato sem conversa
- **WHEN** a reconciliação materializa profile WhatsApp de contato conhecido pela inbox sem conversation
- **THEN** o dispatcher o processa depois das candidatas mais recentes, respeitando os mesmos limites

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
