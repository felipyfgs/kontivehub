## Context

O WhatsMeow distingue `MessageSource.Chat`, `SenderAlt` e `RecipientAlt`. Para chats 1:1 com LID, o bridge anterior substituía `Chat` por `SenderAlt`; em outbound esse valor costuma representar a própria sessão. O contrato local em andamento já introduz `source_identity`, mas a API ainda dá precedência absoluta ao `from` legado, aceita o endereço da sessão entre os aliases e não reconcilia identidades que já pertencem a contatos diferentes.

A API já executa cada evento dentro de uma transação PostgreSQL. `communication_identities` garante unicidade do endereço físico por tenant/canal e `communication_conversations_one_active` garante uma conversa ativa por inbox/identity, mas nenhuma constraint expressa a equivalência semântica LID↔PN.

## Goals / Non-Goals

**Goals:**

- Impedir que PN da sessão seja projetada ou persistida como peer remoto.
- Correlacionar LID e PN somente quando o gateway fornecer a associação no mesmo `source_identity`.
- Fazer a associação convergir para um contato e uma conversa ativa por inbox, inclusive quando os aliases já existirem separados.
- Preservar mensagens e vínculos funcionais ao consolidar conversas ativas comprovadamente equivalentes.
- Manter ingestão idempotente, tenant-scoped e segura sob eventos concorrentes.

**Non-Goals:**

- Inferir que dois telefones ou um LID e uma PN são equivalentes sem evidência do gateway.
- Agrupar conversas heurísticamente na SPA.
- Suportar grupos, newsletters ou JIDs crus no contrato.
- Atribuir automaticamente mensagens de self-chats legados a um peer quando os dados persistidos não contêm evidência suficiente.
- Executar limpeza direta em ambiente de produção durante esta mudança.

## Decisions

### `MessageSource.Chat` é a chave técnica do peer no Wazync

Eventos live, history e action usarão `Chat` como `from`/`source_identity.primary`. Quando o chat for LID, `SenderAlt` será aceito como PN remota apenas em inbound e `RecipientAlt` apenas em outbound. `SenderAlt` outbound nunca será anunciado como alias.

Alternativa considerada: continuar substituindo `Chat` pelo primeiro PN disponível. Foi rejeitada porque o significado dos campos alternativos depende da direção e já produziu self-chat.

### A API prefere a identidade estruturada e mantém compatibilidade com `from`

Quando `source_identity` estiver presente e válido, `primary` representa `Chat`; uma `alternate` PN diferente do endereço da inbox é o endereço canônico preferido, independentemente de `from` também conter o LID. O campo `from` continua aceito sozinho para gateways compatíveis com o contrato anterior.

`aliases()` devolverá somente endereços remotos normalizados e removerá qualquer valor igual à sessão antes de persistir. Kinds incoerentes ou ausência completa do peer falham de forma fechada.

Alternativa considerada: dar precedência permanente a `from`. Foi rejeitada porque o bridge envia `from=Chat`; assim a PN remota estruturada nunca seria promovida.

### A correlação será um serviço transacional da API

A criação/reconciliação de contato, identities e conversation sairá do `GatewayEventIngestor` para um serviço do domínio Communication. O serviço adquirirá advisory locks transacionais em ordem determinística para cada alias no escopo tenant/inbox; eventos que compartilham qualquer alias serão serializados sem bloquear peers não relacionados.

Ao observar uma associação LID↔PN:

1. carrega e bloqueia todas as identities correspondentes;
2. escolhe como contato sobrevivente o contato da identity canônica já existente, preferindo assim a PN remota, ou o primeiro contato determinístico;
3. move as identities comprovadamente equivalentes para esse contato;
4. seleciona uma conversation sobrevivente na inbox, preferindo uma ativa e depois a mais recente;
5. move mensagens e vínculos de cliente/label das conversas ativas equivalentes para a sobrevivente, preserva o maior `last_message_at` e resolve as doadoras;
6. cria uma única conversation quando nenhuma existir e reabre a sobrevivente para evento live.

Conversas resolved históricas não serão combinadas por mera coincidência de contact; somente conversas ativas encontradas durante uma correlação comprovada serão consolidadas. Self-conversations identificadas pelo endereço da própria inbox serão encerradas, mas seu conteúdo legado não será atribuído a outro peer sem evidência.

Alternativa considerada: adicionar agrupamento por contact apenas na query pública. Foi rejeitada porque deixaria timeline, automações e atualizações escrevendo em conversations diferentes.

### Não haverá mudança heurística nem nova exposição pública

O contrato privado evolui de forma aditiva com `source_identity`; `from` permanece aceito. A API pública e a SPA não recebem JID cru nem nova chave de agrupamento. Logs e exceções não incluem endereços completos, apenas códigos estáveis e IDs internos quando necessário.

## Risks / Trade-offs

- [Aliases incorretos vindos de gateway antigo] → a API exclui a PN da inbox e só correlaciona `primary/alternate` normalizados; ausência de evidência mantém as identities separadas.
- [Corrida na criação quando uma linha ainda não existe] → advisory locks por alias são adquiridos antes da consulta e criação, sempre na mesma ordem.
- [Conversa doadora possui automação ativa] → a consolidação preserva o registro doador resolvido e não apaga execuções; testes verificam que nenhum FK funcional é perdido.
- [Self-chat legado sem peer recuperável] → encerrar o fio impede novas mensagens nele, mas a recuperação histórica exige reconciliação auditada fora deste change.
- [Worktree já contém alterações correlatas] → a implementação será incremental e manterá os diffs existentes do contrato/bridge, adicionando somente lacunas confirmadas e testes.

## Migration Plan

1. Publicar Wazync e API com o contrato aditivo `source_identity` e compatibilidade com `from`.
2. Novos eventos deixam de criar aliases da sessão imediatamente.
3. O primeiro evento que trouxer uma associação LID↔PN confiável reconcilia lazy os registros ativos daquele peer dentro da transação de ingestão.
4. Não haverá comando de produção nem mutação massiva nesta mudança. Casos legados sem evidência serão diagnosticados e tratados por procedimento auditado separado.
5. Rollback de código restaura o comportamento anterior sem exigir rollback de schema; identities já reunidas continuam válidas como aliases do mesmo contato.

## Open Questions

Nenhuma para a implementação preventiva e a reconciliação lazy. Uma limpeza global de self-chats históricos dependerá de amostra auditada dos payloads reais e será proposta separadamente se necessária.
