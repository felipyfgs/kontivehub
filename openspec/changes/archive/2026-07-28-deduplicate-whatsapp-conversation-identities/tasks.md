## 1. Contrato e projeção Wazync

- [x] 1.1 Completar a projeção de `MessageSource.Chat` e aliases remotos em eventos live, history e action sem expor `SenderAlt` da sessão.
- [x] 1.2 Adicionar testes Go inbound/outbound/history que distinguem PN remota da PN da sessão.
- [x] 1.3 Validar o `SourceIdentity` aditivo e sua combinação semântica LID→PN no OpenAPI privado e nos DTOs/allowlists dos dois consumidores.

## 2. Resolução e correlação na API

- [x] 2.1 Corrigir `WhatsappPeerResolver` para preferir a PN remota estruturada mesmo com `from=LID`, excluir a sessão de `aliases()` e rejeitar pares semanticamente incoerentes.
- [x] 2.2 Extrair um correlator transacional que bloqueia aliases/membros e reexpande classes de contacts em ordem determinística, reúne identities comprovadamente equivalentes, move identities adicionais sem equipará-las e preserva o contato curado.
- [x] 2.3 Consolidar conversations ativas equivalentes na inbox, preservar mensagens/clientes/labels na sobrevivente e impedir reabertura de self-chat.
- [x] 2.4 Integrar o correlator ao `GatewayEventIngestor` mantendo idempotência, isolamento tenant, timestamps monotônicos e compatibilidade com eventos legados.
- [x] 2.5 Adicionar migration/modelos para redirect de contact, identity canônica e redirect de conversation, integrando writers outbound e show/update/add identity/export/purge canonical-aware.
- [x] 2.6 Preservar a conversation com flow run não terminal e falhar fechado quando fragmentos distintos tiverem runs ativos.

## 3. Regressões

- [x] 3.1 Cobrir PN→LID e LID→PN, inbound/outbound e alternate igual à sessão em testes unitários/feature da API.
- [x] 3.2 Cobrir identities/contatos/conversations já fragmentados, IDs stale de donor, purge/export da classe, identity extra não equivalente, contato curado, redirects e timeline monotônica.
- [x] 3.3 Cobrir concorrência PostgreSQL real entre processos, inclusive classes disjuntas com contact compartilhado, retry com provider ID duplicado e isolamento cross-tenant dos aliases.
- [x] 3.4 Cobrir seleção/falha de flow runs e impedir recriação por automação ou escrita em donor.

## 4. Validação

- [x] 4.1 Executar testes focados de Communication e do bridge Wazync.
- [x] 4.2 Executar Pint/contrato/API proporcionais ao diff e `make wazync-test`.
- [x] 4.3 Validar o change OpenSpec e registrar limitações de reconciliação de self-chats legados.

## Limitações registradas (4.3)

- Self-chats legados sem evidência LID↔PN no payload **não** são reatribuídos a um peer remoto; apenas são fechados (RESOLVED) quando a correlação detecta identity da sessão e não recebem novas mensagens.
- Recuperação massiva de histórico self-chat exige procedimento auditado separado (fora deste change).
- Concorrência produtiva é coberta em PostgreSQL por dois processos/conexões independentes (`test_disjoint_identity_classes_sharing_a_contact_converge_concurrently`); execuções simultâneas de suítes que usam `RefreshDatabase` no mesmo database continuam proibidas por interferirem no schema de teste.
- Binário Wazync foi rebuildado com projeção Chat-only; eventos em fila antigos com `alternate=session` continuam sendo filtrados na API.
- Conflitos com múltiplos flow runs ativos falham fechado e geram log sanitizado, mas exigem intervenção operacional; nenhum survivor é escolhido automaticamente.
- Depois do primeiro merge persistido, rollback seguro é roll-forward para API canonical-aware; não executar código antigo nem remover as colunas de redirect.
