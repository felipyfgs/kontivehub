## Context

O worktree já contém uma implementação ampla de fotos de perfil, porém a maior parte ainda não está integrada e a execução é bloqueada por quatro configurações exclusivas. `CommunicationProfilePictureRollout` impede Resources, stream, job e dispatcher quando `enabled=false`, o kill switch está ativo ou o tenant não está allowlisted; a allowlist de hosts vazia funciona como um quarto desligamento. A validação visual real mostrou sete conversas com fallback e nenhum request de imagem.

Evolution busca a foto durante upsert/update de contatos e processamento de mensagens. Evolution Go também documenta que `GetProfilePictureInfo` precisa de aproximadamente 80 segundos para atravessar o ciclo de IQ do WhatsMeow; o Wazync atual usa deadline global de 15 segundos. O KontiveHub não pode expor a URL upstream como Evolution, então mantém o cache privado no Laravel e a entrega same-origin.

## Goals / Non-Goals

**Goals:**

- Tornar foto comportamento nativo quando Communication, tenant, inbox e sessão estiverem operacionais.
- Remover feature flag, kill switch, rollout por tenant e host configurável sem enfraquecer SSRF, tenancy ou autorização.
- Atualizar fotos por lifecycle real de contato/conversa/mensagem e por `events.Picture`, com backfill de contatos sem conversa.
- Entregar contratos públicos estáveis e atualização realtime sanitizada.
- Provar o fluxo sem interceptar APIs da Communication no browser.

**Non-Goals:**

- Remover switches de outbound, flows ou media recovery, que governam mutação/automação/egress de maior risco.
- Expor URL de CDN, JID, `picture_id`, objeto de storage ou payload do gateway.
- Buscar foto síncrona dentro de requests de lista/detalhe ou suportar grupos.

## Decisions

### Disponibilidade natural substitui rollout de foto

`CommunicationProfilePictureRollout` e as chaves `ENABLED`, `FETCH_KILL_SWITCH` e `ALLOWED_TENANT_IDS` serão removidos. O job consultará o gateway somente depois de `CommunicationAvailability::assertEnabled($inbox, true)`, que confirma os gates globais, tenant, inbox e status conectado. O dispatcher aplicará os mesmos predicados na seleção.

Assets `READY` não dependem do gateway conectado para leitura. O endpoint reautoriza ator, tenant e inbox, de modo que indisponibilidade do provider não precisa apagar um cache privado válido.

Alternativa rejeitada: manter flags ligadas por default. Isso preservaria caminhos de configuração conflitantes e permitiria que ambientes voltassem silenciosamente ao fallback.

### Política de URL é código de segurança, não configuração de rollout

`ALLOWED_HOSTS` será removida. O adapter aceitará somente hostname `whatsapp.net` ou sufixo `.whatsapp.net`, HTTPS/443, DNS integralmente público e conexão cURL pinada ao IP validado, sem redirects, cookies ou credenciais. MIME, magic bytes, dimensões e 2 MiB continuam fail-closed.

Novo host requer mudança revisada de código. Não haverá wildcard configurável nem fallback que aceite a URL porque ela veio do gateway.

### Triggers imitam Evolution sem criar tempestade

Criação/atualização de profile, conversa e mensagem chamará um serviço idempotente que agenda refresh apenas em `UNKNOWN`, `PENDING` ou quando TTL/retry venceu. `picture_id` novo invalida a versão; clear remove a URL. O dispatcher unirá profiles derivados de conversas e profiles de contatos conhecidos pela inbox, priorizando atividade e aplicando cota por inbox antes do limite global.

O Wazync terá deadline de 80 segundos somente em `PROFILE_PICTURE`; Laravel usará 90 segundos. Outros tipos continuam com os limites atuais.

### Contrato público é estável e realtime é after-commit

Resources sempre incluirão `profile_picture_url` nullable e `profile_picture_state`. A promoção, invalidação ou indisponibilidade gravará um evento sanitizado after-commit com IDs internos, estado e versão. Workspace e catálogo recarregam as projeções afetadas; a SPA nunca consulta Wazync/CDN.

## Risks / Trade-offs

- [WhatsApp muda o domínio de CDN] → fetch falha fechado e exige alteração explícita da política, sem liberar host arbitrário.
- [Backfill pressiona WhatsMeow] → unicidade, TTL, backoff e limites por inbox/global permanecem.
- [Inbox desconecta durante o job] → revalidação imediatamente antes do egress encerra sem consulta.
- [Provider nega a foto] → estado `UNAVAILABLE` e cache negativo mantêm fallback sem retry agressivo.
- [Worktree contém implementação não rastreada] → integrar somente arquivos da superfície de fotos e preservar todas as mudanças não relacionadas.

## Migration Plan

1. Integrar/aplicar a migration de assets e publicar API que sempre aceita os campos nullable.
2. Publicar Wazync com deadline corrigido.
3. Publicar job/dispatcher sem rollout e a SPA compatível.
4. Reconciliar profiles por inbox e deixar o dispatcher limitado preencher o backlog.
5. Validar Playwright e o smoke autorizado no contato terminado em `2709`.

Rollback interrompe novos workers/scheduler no nível operacional da fila ou desabilita a inbox/Communication existente; não reintroduz switches de foto e não apaga assets em massa.

## Open Questions

Nenhuma bloqueante.
