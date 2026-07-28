## 1. Contrato e projeção Wazync

- [ ] 1.1 Completar a projeção de `MessageSource.Chat` e aliases remotos em eventos live, history e action sem expor `SenderAlt` da sessão.
- [ ] 1.2 Adicionar testes Go inbound/outbound/history que distinguem PN remota da PN da sessão.
- [ ] 1.3 Validar o `SourceIdentity` aditivo no OpenAPI privado e nos DTOs/allowlists dos dois consumidores.

## 2. Resolução e correlação na API

- [ ] 2.1 Corrigir `WhatsappPeerResolver` para preferir a PN remota estruturada mesmo com `from=LID` e excluir a sessão de `aliases()`.
- [ ] 2.2 Extrair um correlator transacional que bloqueia aliases em ordem determinística e reúne identities comprovadamente equivalentes no mesmo contato.
- [ ] 2.3 Consolidar conversations ativas equivalentes na inbox, preservar mensagens/clientes/labels na sobrevivente e impedir reabertura de self-chat.
- [ ] 2.4 Integrar o correlator ao `GatewayEventIngestor` mantendo idempotência, isolamento tenant e compatibilidade com eventos legados.

## 3. Regressões

- [ ] 3.1 Cobrir PN→LID e LID→PN, inbound/outbound e alternate igual à sessão em testes unitários/feature da API.
- [ ] 3.2 Cobrir identities/contatos/conversations já fragmentados e verificar uma única conversation ativa com timeline preservada.
- [ ] 3.3 Cobrir concorrência PostgreSQL e isolamento cross-tenant dos aliases.

## 4. Validação

- [ ] 4.1 Executar testes focados de Communication e do bridge Wazync.
- [ ] 4.2 Executar Pint/contrato/API proporcionais ao diff e `make wazync-test`.
- [ ] 4.3 Validar o change OpenSpec e registrar limitações de reconciliação de self-chats legados.
