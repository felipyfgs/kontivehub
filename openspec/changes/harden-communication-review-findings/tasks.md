## 1. Finalização e observabilidade no Wazync

- [x] 1.1 Parametrizar finalizações PostgreSQL com `domain.CommandRetryMedia`, preservando status e fencing por tentativa.
- [x] 1.2 Cobrir tipo canônico, transições `PROCESSED`/`RETRY` e tentativa obsoleta no boundary PostgreSQL.
- [x] 1.3 Nomear parâmetros da interface Store e normalizar comando ausente de `FinalizeCommandFailureWithEvent` para `ErrStateConflict` em Memory/PostgreSQL.
- [x] 1.4 Terminalizar imediatamente descriptor ausente e request inválida de media retry, com eventos/códigos allowlisted.
- [x] 1.5 Expor gauge sanitizado de queries `PROFILE_PICTURE` in-flight e cobrir concorrência/retorno a zero.

## 2. Hardening da API Laravel

- [x] 2.1 Aplicar `ApiRateLimit::CommunicationProfilePicture` ao POST de scheduling e alinhar budgets esperados à normalização `max(1, ...)`.
- [x] 2.2 Fazer `CommunicationMediaStore::oldObjectIds` pular diretórios lexicograficamente anteriores ao cursor e cobrir a paginação.
- [x] 2.3 Aceitar `media_state=REQUESTED` sem metadata completa no invariant OpenAPI e cobrir DTO/contrato.
- [x] 2.4 Aplicar o constraint central de visibilidade no ledger/mark-unread para ignorar mensagens quarentenadas.
- [x] 2.5 Substituir o literal `READY` por enum com binding e explicitar tenant em todos os lookups do filtro de contato.
- [x] 2.6 Reutilizar a coleção de inboxes visíveis nas checagens do catálogo sem alterar a precedência dos motivos de indisponibilidade.

## 3. Reconciliação e acessibilidade na SPA

- [x] 3.1 Recarregar o catálogo autoritativo após update e cobrir concorrência sem remover outros contatos.
- [x] 3.2 Corrigir guarda de permissão e montagem do modal de nova conversa no detalhe; carregar inboxes do catálogo com epoch/sequence antes de abrir.
- [x] 3.3 Reconciliar timeout/erro bulk, evento parcial de read-state e falha de acknowledgement por snapshot.
- [x] 3.4 Centralizar ação unitária de status no workspace e consumir restauração de foco mesmo sem alvo.
- [x] 3.5 Tornar a altura virtual responsiva a fonte ampliada e emitir `aria-controls` somente para painel montado.

## 4. Revisão, gates e entrega

- [x] 4.1 Concluir a primeira revisão automatizada e registrar veredito dos findings inválidos com evidência.
- [x] 4.2 Validar este change em modo strict e executar testes focados após cada correção.
- [x] 4.3 Reexecutar CodeRabbit contra `origin/main` com untracked e corrigir findings válidos residuais, em no máximo três ciclos.
- [x] 4.4 Executar gates integrais isolados de API, Web e Wazync e auditar segredos/artefatos.
- [x] 4.5 Criar commits Conventional Commits atômicos em pt-BR, sem push.
