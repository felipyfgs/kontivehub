## Context

O Wazync já normaliza uma identity conhecida, chama `WhatsMeow.GetProfilePictureInfo` e devolve `{user,id,url}` na query privada `PROFILE_PICTURE`; eventos `Picture` projetam somente `picture_id`. O Laravel persiste esse ID em `communication_inbox_identity_profiles`, mas `CommunicationContactResource` e `CommunicationConversationResource` não expõem imagem, e a SPA renderiza `UAvatar` sem `src`.

A URL do WhatsApp é efêmera, pode deixar de funcionar por privacidade/conectividade e não deve virar contrato público. As listas são paginadas/virtualizadas e não podem consultar o gateway por linha. A implementação também chega sobre três changes ativos que já possuem mudanças locais nos mesmos Resources, queries e componentes; por isso ela deve ser aditiva e integrar o estado atual sem revertê-lo.

As referências locais levam a dois padrões complementares: Chatwoot baixa e anexa avatar no servidor antes de publicar `thumbnail`, e seu `CardAvatar` sobrepõe o checkbox no centro do avatar; Evolution expõe a URL remota diretamente, alternativa incompatível com os limites de privacidade e disponibilidade deste monorepo.

## Goals / Non-Goals

**Goals:**

- Materializar uma foto preview privada por `(tenant_id,inbox_id,identity_id)` sem bloquear requests de lista/detalhe.
- Manter aquisição, autorização, escolha entre inboxes, retenção e contrato público no Laravel.
- Reutilizar `PROFILE_PICTURE` sem ampliar o contrato privado Wazync.
- Entregar somente URL same-origin autenticada, com fallback determinístico e sem N+1.
- Exibir a foto nas superfícies de conversa/contato e sobrepor o checkbox como no Chatwoot sem alterar a casca master–detail.
- Operar e fazer rollout de forma fail-closed, idempotente e observável sem dados sensíveis.

**Non-Goals:**

- Upload/edição manual, foto de grupos, imagem full-size ou avatar de vCard recebido em mensagem.
- Expor URL do CDN, JID, `picture_id`, object ID, path de storage ou payload do gateway.
- Tornar a imagem requisito para abrir lista/conversa ou realizar fetch síncrono pelo browser.
- Alterar o endpoint administrativo existente de consulta da foto ou habilitar egress real em exemplos/ambiente.

## Decisions

### Laravel mantém uma projeção de asset junto ao perfil por inbox

Uma migration nova adicionará a `communication_inbox_identity_profiles` os campos `profile_picture_state`, `profile_picture_object_id`, `profile_picture_mime_type`, `profile_picture_size_bytes`, `profile_picture_sha256`, `profile_picture_storage_context`, `profile_picture_version`, `profile_picture_fetched_at`, `profile_picture_retry_at` e `profile_picture_error_code`. O estado será `UNKNOWN`, `PENDING`, `READY`, `UNAVAILABLE` ou `FAILED`.

Os bytes serão gravados no `CommunicationMediaStore`, cifrados por envelope e associados por AAD ao tenant, inbox, profile e versão local. `picture_id` continuará interno e representará apenas a versão observada no provider; ele nunca será usado como URL pública.

Alternativas consideradas: tabela global por contato, que perderia diferenças de privacidade entre inboxes; e URL remota persistida, que expiraria e vazaria o provider. Ambas foram rejeitadas.

### Aquisição é assíncrona, versionada e fail-closed

`RefreshCommunicationProfilePictureJob` receberá apenas IDs internos e um snapshot da versão de campo. Ele resolverá inbox/session/identity tenant-safe, executará `PROFILE_PICTURE` com `preview=true`, baixará a URL imediatamente e promoverá o objeto somente se o perfil ainda corresponder ao snapshot. Resultado obsoleto será apagado e uma observação mais nova permanecerá autoritativa.

O job será disparado após commit na primeira conversa de uma identity, em mudança/clear novo de `picture_id` e por backfill. Repetições serão coalescidas por chave tenant+inbox+identity; retries terão timeout/backoff finitos e cada tentativa consultará uma URL nova. `nil`, 403/404 ou privacidade serão `UNAVAILABLE` com cache negativo; falhas transitórias esgotadas serão `FAILED` com `retry_at`. Refresh periódico com erro preservará um asset ainda coerente, enquanto mudança explícita de `picture_id` esconderá a foto antiga até a nova estar pronta.

Configuração: `COMMUNICATION_PROFILE_PICTURES_ENABLED=false`, `COMMUNICATION_PROFILE_PICTURES_FETCH_KILL_SWITCH=true`, allowlists de tenants/hosts vazias, refresh e cache negativo de 24 horas, máximo de 2 MiB e batch global/inbox de 100/25. O comando despachante rodará a cada quinze minutos com `withoutOverlapping` e `onOneServer`, priorizando conversations por atividade recente.

O backfill deduplicará aliases e conversations pela identity canônica antes de ranquear candidatos. A cota de 25 será aplicada por inbox antes do limite global de 100, usando ranking SQL determinístico, para que uma inbox muito ativa não cause starvation das demais. Dispatcher e job exigirão novamente tenant com Communication habilitado e inbox habilitada em estado `CONNECTED`; a revalidação no job fecha a corrida entre despacho e execução.

Alternativa considerada: fetch sob demanda no GET da imagem ou na listagem. Foi rejeitada por acoplar UX ao gateway, criar N+1 e tornar retries ambíguos.

### O download remoto usa um port e um adapter cURL restrito

O port receberá a URL apenas em memória e devolverá stream temporário + MIME/tamanho/dimensões. O adapter exigirá HTTPS, porta 443, host allowlisted, DNS somente com endereços públicos e conexão fixada ao IP validado; desabilitará redirects, credenciais e cookies, verificará TLS/hostname, aplicará connect/total timeout, limitará o stream a 2 MiB e aceitará somente JPEG, PNG ou WebP cujo header, magic bytes e dimensões (até 4096×4096) sejam coerentes.

Erros terão códigos allowlisted e logs somente com IDs internos/código/status; URL, host completo, body e bytes não serão registrados. Não haverá retry interno amplo: o job refaz consulta+download apenas para timeout, 408/425/429 e 5xx.

O transporte Laravel aceitará somente códigos remotos estáveis e allowlisted; qualquer conteúdo arbitrário será normalizado para `GATEWAY_HTTP_<status>`. O Wazync mapeará os sentinels tipados de privacidade e foto ausente para `PROFILE_PICTURE_PRIVACY`/403 e `PROFILE_PICTURE_NOT_FOUND`/404, sem `DirectPath`, hash, URL ou JID, permitindo cache negativo sem transformar indisponibilidade esperada em retry operacional.

Alternativa considerada: fazer Laravel expor diretamente a URL confiada do Wazync. Foi rejeitada porque o navegador contornaria autorização, retenção e disponibilidade same-origin.

### A resolução pública respeita contexto e visibilidade

Para conversa, o resolver escolherá exclusivamente o perfil `READY` da inbox e da identity/canonical identity comprovada da conversation. Para contato, a query receberá o ator e escolherá, entre inboxes retornadas por `CommunicationAccess::visibleInboxIds`, o asset `READY` ligado à conversation canônica não expurgada de maior `last_message_at`, com desempate por conversation/profile ID. O cálculo será eager/subquery, nunca uma consulta por Resource.

`CommunicationContactResource` terá `profile_picture_url: string|null`; o resumo `contact` de `CommunicationConversationResource` terá o mesmo campo. A URL relativa apontará para `GET /api/v1/communication/profile-pictures/{profile}/{version}`. O request exigirá `CommunicationView`, tenant corrente e acesso à inbox do profile; recurso ausente, versão divergente, purge ou acesso negado retornarão 404.

O stream verificará estado/versão/objeto, responderá com MIME e tamanho persistidos, `X-Content-Type-Options: nosniff`, `ETag` pelo SHA-256 e `Cache-Control: private, no-cache, must-revalidate`; uma revalidação autorizada poderá retornar 304 sem descriptografar bytes.

A rota terá limiter próprio aplicado antes do controller e do acesso ao storage: por padrão, 600 requests/minuto por usuário+tenant e 1.200/minuto por IP. Os limites serão configuráveis, e uma resposta 429 não abrirá, descriptografará nem recalculará o hash do objeto.

### Lifecycle remove conteúdo e não perde a ordenação existente

O merger de perfis continuará ordenando por `(observed_at,event_id)`. Somente uma mudança aceita de `picture_id` invalidará/agenda asset; evento repetido ou antigo não fará egress. Clear explícito removerá a referência pública e enfileirará deleção do objeto após commit.

Merge PN↔LID manterá, por inbox, o asset compatível com o `picture_id` vencedor ou marcará `PENDING`; objetos doadores abandonados serão apagados após commit. Purge coletará object IDs antes de deletar profiles e tentará apagá-los com retry. Export continuará JSON e incluirá somente estado, MIME, tamanho, SHA-256 e timestamps, nunca os bytes/path/URL.

Ao excluir uma inbox, a action coletará e bloqueará os object IDs antes do cascade, registrará intents duráveis na mesma transação e só executará a remoção física após commit. Rollback preservará inbox, profiles, referências e bytes.

### A SPA aplica o menor delta sobre os arquétipos existentes

`profile_picture_url` será opcional nos tipos. Os `UAvatar` da lista, navbar/timeline e contexto da conversa serão circulares; catálogo/detalhe manterão 42 px e `rounded-lg`. Todos recebem `src` e iniciais/`?` continuam como fallback real, inclusive em erro 404.

Na lista virtualizada, o avatar sairá do botão de abertura e ficará em wrapper relativo irmão. O `UCheckbox` ocupará `absolute inset-0`, com overlay/blur circular visível em hover, `focus-within` ou seleção; em ponteiro coarse será visível sem hover. O clique será interrompido e alterará apenas o Set operacional. A linha mantém 92 px, seleção carregada, menu, teclado e deep-link atuais.

## Risks / Trade-offs

- [Host do CDN muda] → allowlist explícita faz o fetch falhar fechado; código seguro e métrica indicam atualização operacional sem liberar wildcard.
- [Foto muda durante o download] → snapshot + lock descartam o objeto obsoleto e a próxima observação agenda novo job.
- [Backfill pressiona WhatsApp/Horizon] → flags, batches globais/per-inbox, prioridade por atividade, unicidade e cache negativo limitam egress.
- [Revogação de acesso após carregar a lista] → GET reautoriza e usa revalidação privada; URL adivinhada não concede acesso.
- [Objeto cifrado fica órfão após falha entre filesystem e DB] → swaps registram o novo objeto somente em transação e enfileiram deleção compensatória para objetos não promovidos/substituídos.
- [Mesmo contato tem fotos diferentes] → conversa usa contexto exato; catálogo escolhe somente a conversation visível mais recente e aceita que outro ator veja outra representação autorizada.
- [Overlay reduz visibilidade da foto no touch] → o controle permanece descobrível e com alvo suficiente; a foto integral continua disponível nas demais superfícies.

## Migration Plan

1. Estabilizar os três changes dependentes e aplicar a migration aditiva com todos os flags desligados.
2. Publicar API/rota/campos opcionais; consumidores antigos ignoram os campos e nenhum fetch ocorre.
3. Publicar a SPA com fallback compatível quando o campo estiver ausente ou nulo.
4. Em rollout separado e autorizado, configurar hosts/tenants, habilitar exposição e só então liberar o fetch kill switch; acompanhar fila, falhas e storage por códigos/contagens.
5. O scheduler preenche gradualmente dados existentes e eventos atualizam mudanças novas.

Rollback desliga `COMMUNICATION_PROFILE_PICTURES_ENABLED` e religa o fetch kill switch. A SPA volta a iniciais; schema e assets permanecem para roll-forward e são removidos apenas pelos lifecycles normais, sem rollback destrutivo.

## Open Questions

Nenhuma bloqueante.
