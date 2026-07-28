## Context

O WhatsMeow distingue `MessageSource.Chat`, `SenderAlt` e `RecipientAlt`. Para chats 1:1 com LID, o bridge anterior substituía `Chat` por `SenderAlt`; em outbound esse valor costuma representar a própria sessão. O contrato local em andamento já introduz `source_identity`, mas a API ainda dá precedência absoluta ao `from` legado, aceita o endereço da sessão entre os aliases e não reconcilia identidades que já pertencem a contatos diferentes.

A API já executa cada evento dentro de uma transação PostgreSQL. `communication_identities` garante unicidade do endereço físico por tenant/canal e `communication_conversations_one_active` garante uma conversa ativa por inbox/identity, mas nenhuma constraint expressa a equivalência semântica LID↔PN ou impede que IDs antigos de contact, identity ou conversation sejam usados depois de uma consolidação.

## Goals / Non-Goals

**Goals:**

- Impedir que PN da sessão seja projetada ou persistida como peer remoto.
- Correlacionar LID e PN somente quando o gateway fornecer a associação no mesmo `source_identity`.
- Fazer a associação convergir para um contato e uma conversa ativa por inbox, inclusive quando os aliases já existirem separados.
- Preservar mensagens e vínculos funcionais ao consolidar conversas ativas comprovadamente equivalentes.
- Manter ingestão idempotente, tenant-scoped e segura sob eventos concorrentes.
- Fazer retries com o mesmo `provider_message_id` ainda aplicarem evidência nova de correlação.
- Impedir que automações e mutações concorrentes escrevam ou reabram um registro doador.
- Preservar leitura, mutação, exportação e purge quando o cliente ainda usa o ID de um contact doador.

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

`aliases()` devolverá somente endereços remotos normalizados e removerá qualquer valor igual à sessão antes de persistir. Uma associação só será aceita quando `primary_kind=LID`, `alternate_kind=PN` e `evidence=MESSAGE_SOURCE_ALT`; pares PN↔PN, LID↔LID, PN→LID, endereços iguais ou ausência completa do peer falham de forma fechada.

Alternativa considerada: dar precedência permanente a `from`. Foi rejeitada porque o bridge envia `from=Chat`; assim a PN remota estruturada nunca seria promovida.

### A correlação será um serviço transacional da API

A criação/reconciliação de contato, identities e conversation sairá do `GatewayEventIngestor` para um serviço do domínio Communication. O serviço adquirirá advisory locks transacionais em ordem determinística para cada alias no escopo tenant/canal, igual à constraint de `communication_identities`. Para uma classe já canonicalizada, também bloqueará todos os IDs membros em ordem, reexpandirá a classe até ponto fixo e então bloqueará/revalidará as classes de contacts em ordem. Assim, eventos conectados por alias, canonical pointer ou contact serão serializados mesmo entre inboxes do mesmo tenant, sem um lock tenant-wide que bloqueie peers não relacionados.

Ao observar uma associação LID↔PN:

1. carrega, expande e bloqueia todas as identities da classe correspondente;
2. bloqueia/reexpande as classes de contacts e escolhe o sobrevivente independentemente da identity canônica, priorizando contact não purgado, curado/nomeado e ativo, com ID como desempate determinístico;
3. mescla nome/metadados sem sobrescrever os valores do sobrevivente curado, move **todas** as identities dos contacts doadores para ele e persiste redirects de contact; somente as identities da classe LID↔PN comprovada recebem `canonical_identity_id`, de modo que telefones adicionais legítimos continuam independentes;
4. seleciona uma conversation sobrevivente na inbox, preferindo uma ativa e depois a mais recente;
5. se exatamente uma conversation tiver um flow run não terminal, ela obrigatoriamente sobrevive; se mais de uma tiver run ativo, a correlação falha fechada;
6. move mensagens e vínculos de cliente/label das conversations ativas equivalentes para a sobrevivente, preserva o maior `last_message_at`, resolve as doadoras e persiste o redirecionamento para a sobrevivente;
7. persiste a identity canônica para todos os aliases comprovados, permitindo que writers que recebem uma identity antiga escolham o mesmo fio;
8. cria uma única conversation quando nenhuma existir e reabre a sobrevivente para evento live.

Conversas resolved históricas não serão combinadas por mera coincidência de contact; somente conversas ativas encontradas durante uma correlação comprovada serão consolidadas. Self-conversations identificadas pelo endereço da própria inbox serão encerradas, mas seu conteúdo legado não será atribuído a outro peer sem evidência.

### Canonicalização persistente protege todos os writers

Uma migration aditiva introduzirá `communication_contacts.merged_into_contact_id`, `communication_identities.canonical_identity_id` e `communication_conversations.merged_into_conversation_id`. As referências serão diretas, tenant/channel/inbox-safe por FKs compostas e protegidas contra self-reference; contacts e conversations doadores também ficam inativos/resolved por checks. O correlator achata cadeias ao observar nova evidência, remove nome/metadata do contact doador depois de copiar os dados e não deixa PII duplicada nele.

Seleção outbound por identity sempre resolve primeiro `canonical_identity_id`. Mutações que recebem uma conversation antiga seguem o redirecionamento quando a operação é naturalmente transferível (mensagem, label e ação técnica) ou retornam conflito para atualizações otimistas de estado. Conversations doadoras recebem bump de `lock_version`, permanecem `RESOLVED` e não aparecem nas listagens.

Show, update, add identity, export e purge que recebem um contact antigo resolvem o redirect para o sobrevivente. Listagens omitem donors mesmo quando incluem inativos. Export mantém row locks de toda a classe durante o stream para não produzir um arquivo vazio ou parcial no meio de um merge; purge bloqueia e limpa PII, mensagens, anexos e flows de toda a classe de contacts, seja chamado pelo ID sobrevivente ou doador.

Alternativa considerada: resolver apenas por `contact_id`. Foi rejeitada porque um contato pode possuir múltiplos telefones legítimos sem equivalência entre eles.

### Idempotência não antecede correlação

Eventos e `provider_message_id` são serializados com advisory locks transacionais antes do recheck autoritativo. Em `MESSAGE_RECEIVED`, o peer é validado e correlacionado antes de retornar uma mensagem já existente. Assim, history/retry com o mesmo provider ID e evidência LID↔PN nova converge o estado e devolve o `conversation_id` atualizado, sem criar outra mensagem.

O timestamp da mensagem (`payload.occurred_at`) é calculado antes da correlação. Toda atualização de `last_message_at` usa o maior valor já persistido, inclusive em live atrasado e history sync.

Message, action e history executam com até três tentativas transacionais. A ordem de um batch de history pode expor ciclos A→B/B→A entre transações; o retry do PostgreSQL é seguro porque o evento, provider ID, redirects e movimentos são rechecados autoritativamente dentro de cada nova tentativa.

Alternativa considerada: adicionar agrupamento por contact apenas na query pública. Foi rejeitada porque deixaria timeline, automações e atualizações escrevendo em conversations diferentes.

### Evidência temporal impede oscilação entre telefones

Quando uma classe já relaciona um LID a mais de uma PN observada ao longo do tempo, a PN canônica é escolhida pela evidência mais recente. History atrasado não substitui uma PN vista posteriormente em live; empate preserva a raiz estável. `last_seen_at` só avança para aliases realmente presentes no evento, sem promover artificialmente identities antigas que apenas pertencem à mesma classe.

Alternativa considerada: sempre promover a PN do evento atual. Foi rejeitada porque um history sync antigo faria a conversa oscilar de volta para um telefone obsoleto.

### Não haverá mudança heurística nem nova exposição pública

O contrato privado evolui de forma aditiva com `source_identity`; `from` permanece aceito. A API pública e a SPA não recebem JID cru nem nova chave de agrupamento. Logs e exceções não incluem endereços completos, apenas códigos estáveis e IDs internos quando necessário. Em especial, conflito entre múltiplos flow runs ativos gera `PEER_CORRELATION_CONFLICT` com tenant, inbox e conversation IDs, sem LID, JID ou telefone.

## Risks / Trade-offs

- [Aliases incorretos vindos de gateway antigo] → a API exclui a PN da inbox e só correlaciona `primary/alternate` normalizados; ausência de evidência mantém as identities separadas.
- [Corrida na criação quando uma linha ainda não existe] → advisory locks por alias são adquiridos antes da consulta e criação, sempre na mesma ordem.
- [Conversa doadora possui automação ativa] → a conversation com o único run não terminal sobrevive; múltiplos runs ativos em fragmentos distintos abortam a correlação com código sanitizado.
- [Writer usa ID/identity anterior ao merge] → redirecionamento persistente, identity canônica e bump de `lock_version` impedem reabertura e recriação.
- [Contato curado está no alias não canônico] → seleção do contato é independente da identity canônica; nome/metadados são preservados e contatos doadores ficam inativos.
- [Deadlock ao descobrir membros/contacts durante locks incrementais] → classes são reexpandidas e revalidadas até ponto fixo; ingestão de message/action/history repete a transação até três vezes.
- [Export concorrente com merge/update/purge] → o stream mantém locks da classe para consistência, aceitando bloquear essas mutações durante uma exportação longa.
- [Múltiplos flow runs ativos] → falha fechada com log sanitizado exige intervenção operacional; nenhuma execução é escolhida arbitrariamente.
- [Migration em tabelas grandes] → criação de índices, FKs e checks pode adquirir locks bloqueantes; duração e janela de rollout precisam ser avaliadas com cardinalidade real antes de produção.
- [Self-chat legado sem peer recuperável] → encerrar o fio impede novas mensagens nele, mas a recuperação histórica exige reconciliação auditada fora deste change.
- [Worktree já contém alterações correlatas] → a implementação será incremental e manterá os diffs existentes do contrato/bridge, adicionando somente lacunas confirmadas e testes.

## Migration Plan

1. Aplicar a migration aditiva e publicar a API canonical-aware, ainda compatível com eventos legados que contêm apenas `from`.
2. Só depois publicar o Wazync que envia `source_identity`; a allowlist estrita da API antiga rejeita esse campo novo, portanto a ordem inversa interromperia a ingestão.
3. Novos eventos deixam de criar aliases da sessão imediatamente. O primeiro evento que trouxer uma associação LID↔PN confiável reconcilia lazy os registros ativos daquele peer dentro da transação de ingestão.
4. Não haverá comando de produção nem mutação massiva nesta mudança. Casos legados sem evidência serão diagnosticados e tratados por procedimento auditado separado.
5. Antes do primeiro merge, o artefato novo pode ser retirado preservando as colunas aditivas. Depois que qualquer redirect for persistido, voltar à API antiga ou remover as colunas não é behavior-safe: código antigo ignora donors, pode listá-los/reabri-los e perde a navegação canônica. A recuperação operacional obrigatória é roll-forward para uma API canonical-aware.
6. O método `down()` existe para validação/desenvolvimento antes de dados canonicalizados; não é estratégia de rollback em produção após o rollout.

## Open Questions

Nenhuma para a implementação preventiva e a reconciliação lazy. Uma limpeza global de self-chats históricos dependerá de amostra auditada dos payloads reais e será proposta separadamente se necessária.
