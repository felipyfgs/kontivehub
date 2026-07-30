## 1. Dependência e contratos

- [x] 1.1 Confirmar que a base necessária de `refactor-communication-conversation-workspace` está concluída e reconciliar tipos/rotas finais sem duplicar lista compacta, read-state ou timeline.
- [x] 1.2 Definir enums, DTOs e Resources versionados para filtros, preferências, ações, operação e item, preservando envelopes e defaults publicados de `/api/v1`.
- [x] 1.3 Evoluir o gerador OpenAPI público com os parâmetros e endpoints aditivos e regenerar os tipos consumidos pelo Nuxt.

## 2. Persistência, filtros e preferências

- [x] 2.1 Criar migrations PostgreSQL reversíveis, models e factories tenant-scoped para preferências, operações e itens, com FKs/uniques/índices iniciados por `tenant_id`.
- [x] 2.2 Estender o Form Request, DTO e query de conversas com `label_ids` OR e `sort_by` allowlisted, mantendo a ordenação anterior quando ausentes.
- [x] 2.3 Implementar GET/PUT da preferência de status/ordenação por usuário e tenant, com defaults `OPEN`/`last_activity_desc` e sem aceitar `tenant_id`.
- [x] 2.4 Cobrir filtros, ordenação estável, paginação, defaults, preferência e isolamento cross-tenant com testes Feature PostgreSQL.

## 3. Criação e execução das operações bulk

- [x] 3.1 Implementar Form Request, autorização, controller fino e Resources para criar, consultar e paginar itens de uma operação bulk.
- [x] 3.2 Implementar criação transacional com `Idempotency-Key`, digest, validação integral dos IDs e materialização chunked do snapshot antes do dispatch after-commit.
- [x] 3.3 Implementar o processor por item para status/reabertura/snooze, responsável e departamento, reutilizando lock/versionamento e elegibilidade das ações unitárias.
- [x] 3.4 Implementar ADD/REMOVE LABELS idempotente e publicar evento sanitizado também nas mutações unitárias de rótulo.
- [x] 3.5 Implementar MARK_READ por `through_message_id` e MARK_UNREAD por versão, sem alterar receipts ou enfileirar egress WhatsApp.
- [x] 3.6 Resolver IDs canonicalizados, deduplicar survivors dentro da operação e registrar explicitamente no-op, conflito, purge e falhas permanentes.
- [x] 3.7 Reautorizar ator, tenant, membership, inbox e permissão por item, incluindo contexto privilegiado tipado e auditoria sem membership fabricada.

## 4. Filas, realtime e retenção

- [x] 4.1 Criar job idempotente na fila `communication` que processe até 100 itens, persista o resultado na mesma transação e redispatche a continuação após commit.
- [x] 4.2 Configurar tries, backoff, timeout, tags Horizon e `failed()` sanitizado, finalizando a operação como sucesso, parcial ou falha sem reaplicar itens terminais.
- [x] 4.3 Integrar eventos after-commit e sync/Reverb para reconciliar linhas afetadas sem nome, telefone, JID ou conteúdo de mensagem no payload.
- [x] 4.4 Criar comando de retenção de operações terminais com 30 dias e agendá-lo diariamente usando `withoutOverlapping` e `onOneServer`.
- [x] 4.5 Cobrir dispatch after-commit, chunks maiores que cem, retries, falha parcial, permissão revogada, merge, retenção e ausência de payload sensível.

## 5. Lista e operações no Nuxt

- [x] 5.1 Evoluir tipos e cliente HTTP de Communication para filtros, preferências e operações bulk usando somente Sanctum e a API Laravel.
- [x] 5.2 Separar no composable estado de consulta, detalhe aberto, seleção por IDs e operação pendente, limpando seleção em toda mudança de consulta/tenant.
- [x] 5.3 Preservar filtros de escopo não sensíveis na rota, manter busca apenas em memória e carregar/salvar status/ordenação no servidor com falha explícita.
- [x] 5.4 Refatorar cada linha para checkbox, botão de abertura e menu contextual irmãos, com estados selecionado/não lido/loading e ações unitárias autorizadas.
- [x] 5.5 Implementar “Selecionar carregadas”, estado indeterminado e barra contextual para status/snooze, leitura, responsável, departamento e rótulos.
- [x] 5.6 Virtualizar linhas de altura estável e integrar o sentinel incremental com loading, erro, retry, vazio e fim da lista reais.
- [x] 5.7 Submeter operação com chave idempotente, manter seleção em erro, limpar após `202`, acompanhar resultado e distinguir sucesso total, parcial e falha.
- [x] 5.8 Preservar deep-link, setas, foco, resize, timeline desktop, contexto e slideovers mobile, sem alterar composer ou allowlists de fidelidade.

## 6. Testes, compatibilidade e rollout

- [x] 6.1 Cobrir seleção individual/carregadas, nova página não selecionada, reset por filtros, menus, barra, preferências, virtualização e falhas em testes unitários/Nuxt.
- [x] 6.2 Cobrir teclado, foco, touch, detalhe desktop/mobile e resultado bulk total/parcial em Playwright local sem versionar artefatos.
- [x] 6.3 Executar testes de contrato e compatibilidade do OpenAPI público e confirmar que nenhum contrato Wazync foi alterado.
- [x] 6.4 Executar os gates completos da API: Composer validate, Pint e PHPUnit na stack deste checkout.
- [x] 6.5 Executar os gates completos do Web: lint, typecheck, generate, test, test:fidelity e test:artifacts.
- [x] 6.6 Validar o change em modo strict e registrar no handoff a ordem de rollout migrations/API/Horizon antes do frontend e o rollback por roll-forward das operações aceitas.

## Evidências finais — 2026-07-29

- Playwright local: 4 cenários de lista/operações passaram, cobrindo teclado, foco, checkbox sem troca de detalhe, touch/slideover mobile, bulk total/parcial/falha e tema escuro.
- O runner removeu a stack E2E e encerrou com código 0; nenhum relatório, trace, screenshot ou vídeo foi versionado.
- Gates compartilhados: API com 1.272 testes e 17.777 asserções; Web com 121 arquivos e 614 testes, além de lint, typecheck, generate, fidelity 74/74 e artifacts em 439 arquivos; Wazync com `go test ./...` e `go vet ./...`.
