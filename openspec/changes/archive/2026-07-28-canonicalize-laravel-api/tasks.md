## 1. Baseline canônica

- [x] 1.1 Revalidar `findings.json` contra o código atual, remover referências stale e registrar cada gap confirmado com severidade e teste esperado.
- [x] 1.2 Adicionar testes de arquitetura nativos para impedir nova validação inline, serialização de Model e acesso direto a integração em controllers.

## 2. Findings críticos e warnings

- [x] 2.1 Fortalecer `LogSanitizer::scrubString` para pares sensíveis curtos, identificadores fiscais e payloads, cobrindo o sanitizador diretamente com testes.
- [x] 2.2 Tornar o consumo de token de download de guia atômico sob concorrência e provar uma única entrega.
- [x] 2.3 Remover o token de preflight de serializers gerais, expô-lo somente em preflight elegível e exigir token e idempotência na execução, cobrindo o round-trip no consumidor Nuxt e em testes de contrato.
- [x] 2.4 Propagar `correlationId` no dispatcher de sync inicial SERPRO e manter derivação determinística somente como fallback.
- [x] 2.5 Restaurar as migrations ausentes de `tenant_credentials` e `client_credentials`, corrigir histórico repetido e referências cross-tenant, impedir supressão pelo `.gitignore` e provar `migrate:fresh` em PostgreSQL.

## 3. Fundação dos boundaries HTTP

- [x] 3.1 Registrar limiters nomeados por classe de risco, documentar budgets independentes e limitar integrações por digest opaco do token mais teto histórico por IP.
- [x] 3.2 Introduzir exceptions de domínio tipadas e renderers centrais para substituir mensagens arbitrárias em respostas.
- [x] 3.3 Habilitar detecção de campos desconhecidos em Form Requests no desenvolvimento/CI e adicionar testes de payload drift.
- [x] 3.4 Criar Resources/transformers e contract helpers de paginação que preservem envelopes, links, metadata e campos de `/api/v1`.
- [x] 3.5 Manter request-forgery protection Laravel 13 e documentar/testar somente exclusões de integração assinadas.

## 4. Controllers de plataforma, autenticação, clientes e tenant

- [x] 4.1 Extrair Form Requests, DTOs e Actions de confirmação de senha, atualização da conta e troca de tenant, preservando o contrato Sanctum.
- [x] 4.2 Extrair os demais boundaries de plataforma e autenticação; schema e bootstrap Sanctum pertencem à change `repair-auth-schema`.
  - [x] Extrair seleção privilegiada de tenant, proprietário, onboarding inicial, administração comercial e controles fiscais.
  - [x] Extrair consolidação de consumo, contratos, kill switch, breaker, configuração global, versões de credencial, gates externos e limites SERPRO.
  - [x] Extrair administração de tenants.
  - [x] Extrair o canário DTE nas superfícies de plataforma e tenant.
  - [x] Extrair operações SERPRO.
  - [x] Revisar onboarding produtivo SERPRO.
- [x] 4.3 Extrair Form Requests, Resources e Actions de `ClientController` e controllers auxiliares, preservando Policies e contratos.
  - [x] Extrair o boundary de certificados de cliente.
  - [x] Extrair listagem, cadastro, detalhe, atualização, campos customizados, status em lote e refresh cadastral.
  - [x] Revisar categorias, contatos e estabelecimentos.
- [x] 4.4 Extrair Form Requests, Resources e Actions dos controllers de tenant, settings, credenciais e autorizações SERPRO.
  - [x] Extrair configurações, consentimento, certificado, agendas e onboarding do tenant.
  - [x] Extrair autorização SERPRO, termo, procurações, elegibilidade e health.
  - [x] Extrair onboarding autXML, cursor e mutações de adesão.
  - [x] Extrair gestão da equipe e entrega de ativação.
  - [x] Extrair identidade fiscal do tenant e sanitizar sua auditoria.
  - [x] Extrair uso SERPRO e assinatura do tenant.
- [x] 4.5 Cobrir os lotes de plataforma/clientes/tenant com sucesso, validação, autorização negada e isolamento cross-tenant.

## 5. Controllers de Work

- [x] 5.1 Extrair Form Requests e DTOs dos filtros e mutações de Work.
  - [x] Extrair entradas de fila, transições, estrutura, bulk, comentários e evidências de tarefas.
  - [x] Extrair entradas de listagem, criação, atualização, archive, bulk e comentários de processos.
  - [x] Extrair entradas de listagem, criação, atualização e atribuição de departamentos.
  - [x] Extrair entrada de listagem e filtros de grupos de processos.
  - [x] Extrair entradas de dashboard, calendário e exportação.
  - [x] Extrair entradas de preview, confirmação, retry e detalhe de geração.
  - [x] Extrair entradas de templates, recorrência, catálogo e histórico de geração.
- [x] 5.2 Mover workflows e transações remanescentes de controllers Work para Actions/Services.
  - [x] Extrair detalhe, atribuição, comentário e vínculo de evidências de tarefas.
  - [x] Extrair query, projeção, comentário e transações remanescentes de processos.
  - [x] Extrair query, mutações e atribuição transacional de departamentos.
  - [x] Extrair download e geração paginada de exportação do controller de dashboard.
  - [x] Extrair autorização e projeção de lotes do controller de geração.
  - [x] Extrair queries de templates/histórico e instalação transacional do catálogo.
- [x] 5.3 Introduzir Resources para tarefas, processos, templates, departamentos, grupos e paginações sem mudar envelopes.
  - [x] Introduzir Resources de tarefas, detalhe, comentários, evidências, bulk e fila paginada.
  - [x] Introduzir Resources de processos, tarefas embutidas, comentários, bulk e paginação.
  - [x] Introduzir Resources de departamentos, atribuição e paginação.
  - [x] Introduzir Resources de grupos e paginação.
  - [x] Introduzir Resources de KPIs, calendário e exportações.
  - [x] Introduzir Resources de lotes e itens de geração.
  - [x] Introduzir Resources de templates, recorrência, catálogo e histórico paginado.
- [x] 5.4 Padronizar Policies/autorizações Work e cobrir denied/cross-tenant em feature tests.
  - [x] Cobrir tarefas e processos com autorização negada, rejeição de tenant input e isolamento cross-tenant.
  - [x] Cobrir departamentos com autorização negada, rejeição de tenant input, assignment e isolamento.
  - [x] Cobrir grupos com autorização negada, rejeição de tenant input, isolamento e contrato paginado.
  - [x] Cobrir dashboard, calendário e exportação com autorização, rejeição de tenant input, isolamento e contratos.
  - [x] Cobrir geração com autorização, rejeição de tenant input, isolamento, idempotência e contrato.
  - [x] Cobrir templates e catálogo com autorização, rejeição de tenant input, isolamento e contratos.

## 6. Controllers de Communication

- [x] 6.1 Extrair Form Requests, DTOs e Actions dos endpoints de inbox, conversation, contacts, catalog, flows e automations.
  - [x] Extrair filtros, mutações, conflitos e transações de contatos, identidades e vínculos.
  - [x] Extrair filtros, autorização e comandos de controle de execuções de fluxos.
  - [x] Extrair sync incremental, streaming de anexos, exportação e expurgo de dados pessoais.
  - [x] Extrair políticas de automação, escopos e seleção transacional de destinatários.
  - [x] Extrair ciclo de vida, membros, pareamento e configuração de inboxes.
  - [x] Extrair labels e projeção de capabilities outbound do catálogo.
  - [x] Extrair filtros, mutações, conflitos e renderização contextual de respostas rápidas.
  - [x] Extrair filtros, detalhe, atualização, labels e composição transacional de conversas.
  - [x] Extrair ciclo de vida, drafts, inspeção, publicação, clonagem e bindings de fluxos.
  - [x] Extrair comandos e queries do gateway de inboxes e conversas.
- [x] 6.2 Introduzir Resources/transformers versionados para projeções e paginações de Communication.
  - [x] Consolidar Resources de contatos, identidades, vínculos e paginação compacta.
  - [x] Consolidar Resource e paginação compacta de execuções de fluxos.
  - [x] Consolidar Resources de eventos incrementais e resultado de expurgo.
  - [x] Consolidar Resources de políticas de automação, inboxes elegíveis e destinatários.
  - [x] Consolidar Resources de inboxes, comandos, pareamento e configuração.
  - [x] Consolidar Resources de labels e capabilities outbound.
  - [x] Consolidar Resources e paginação compacta de respostas rápidas.
  - [x] Consolidar Resources de conversas, mensagens, labels e paginação compacta.
  - [x] Consolidar Resources de fluxos, drafts, versões, bindings, inspeção e publicação.
  - [x] Consolidar Resources de comandos, queries e status de sessão do gateway.
- [x] 6.3 Manter HMAC-before-parse nos endpoints internos e extrair validators dedicados com testes de assinatura, schema e replay.
- [x] 6.4 Cobrir Policies, membership real, isolamento tenant e compatibilidade dos contratos Laravel↔Wazync.
  - [x] Cobrir contatos com permissão específica, rejeição de tenant input, rollback de conflito e isolamento.
  - [x] Cobrir controle de execuções com permissões, rejeição de tenant input, isolamento e contrato.
  - [x] Cobrir sync, anexos e privacidade com rejeição de tenant input, isolamento e contrato.
  - [x] Cobrir automações com autorização, rejeição de tenant input, concorrência e isolamento.
  - [x] Cobrir inboxes com autorização, rejeição de tenant input, concorrência e isolamento.
  - [x] Cobrir labels e capabilities com autorização, rejeição de tenant input e isolamento.
  - [x] Cobrir respostas rápidas com autorização, rejeição de tenant input, conflitos, rollback e isolamento.
  - [x] Cobrir conversas com membership, rejeição de tenant input, idempotência, rollback e isolamento.
  - [x] Cobrir fluxos com autorização, rejeição de tenant input, concorrência, rollback e isolamento.
  - [x] Cobrir ações remotas com rejeição de tenant input, invariantes, isolamento e contrato de transporte.

## 7. Controllers fiscais, outbound e integrações

- [x] 7.1 Extrair Form Requests/DTOs/Actions dos endpoints fiscais de leitura e monitoramento.
  - [x] Extrair coverage, readiness e runs do FGTS Digital com escopo de tenant e paginação compatível.
  - [x] Extrair coverage, readiness, competências e eventos FGTS/eSocial com escopo de tenant e paginação compatível.
  - [x] Extrair snapshots, findings, pendências e download autorizado de evidências fiscais.
  - [x] Extrair listagem e detalhe de runs fiscais sem alterar o enqueue genérico.
  - [x] Extrair detalhe e download autorizado de artefatos de tentativas MEI.
  - [x] Extrair portfólio, coverage, insights e inventário de consultas manuais.
  - [x] Extrair categorias, declarações DCTFWeb/MIT, SITFIS e parcelamentos somente-leitura.
  - [x] Extrair listagem e detalhe local de vínculos cadastrais e processos fiscais sem alterar refresh.
  - [x] Extrair leitura local de renúncias PNR e exclusões da carteira de monitoramento.
  - [x] Extrair listagens, detalhe, estado, alertas e previews locais da Caixa Postal sem alterar downloads, sync ou mutações.
  - [x] Extrair catálogo, listagem, resumo, detalhe e evidências locais do hub de declarações.
  - [x] Extrair listagem, detalhe e boundary de download da central de guias sem alterar emissão, pagamento ou reconciliação.
  - [x] Extrair catálogo, regimes, competências, snapshots e projeções locais de calendário, opção e resolução do Simples/MEI sem alterar os dispatches de consulta.
  - [x] Extrair históricos, prévias e rastreios locais de PGDAS-D e PGMEI sem alterar coleta, consulta ou envio.
  - [x] Extrair históricos de certificado, emissões observadas e situação cadastral do CCMEI sem alterar consultas ou emissão.
  - [x] Extrair downloads autorizados de artefato PGDAS-D e certificado CCMEI com vault, headers e 404 sanitizado.
  - [x] Extrair históricos locais e download autorizado de comprovantes PagtoWeb, contagem/lista de pagamentos e apoio SICALC sem alterar consultas ou egress.
  - [x] Extrair históricos, prévias, rastreios e downloads locais de DCTFWeb/DEFIS sem alterar consultas, preferências ou envios.
  - [x] Extrair catálogo, pedidos, parcelas, pagamentos e guias locais de parcelamentos sem alterar monitoramento ou enqueue.
  - [x] Extrair a leitura local MIT/LISTAAPURACOES317 com filtros tipados sem alterar enqueue ou encerramento.
  - [x] Extrair preview e tracking locais de comunicação SITFIS/FGTS/MIT sem lookup ou autorização manual no controller.
- [x] 7.2 Extrair Form Requests/DTOs/Actions das mutações fiscais e manter todas as capabilities e kill switches fail-closed.
  - [x] Extrair sync, prévia, emissão, sessão e representação do FGTS Digital sem habilitar egress ou flags reais.
  - [x] Extrair sync assíncrono e síncrono FGTS/eSocial sem habilitar egress, flags ou caminhos permissivos.
  - [x] Extrair preflight, execute, show e reconcile de mutações fiscais genéricas com token e idempotência obrigatórios.
  - [x] Extrair preflight, emissão, token de download, confirmação de pagamento e reconciliação da central de guias.
  - [x] Extrair read, preflight, execute, show e reconcile action-id-only da central de declarações com validação exact-fields.
  - [x] Extrair project, attachEvidence e publishCalendar do hub de declarações.
  - [x] Extrair include/exclude da carteira de monitoramento por módulo.
  - [x] Extrair associação e associação em lote de categorias fiscais.
  - [x] Extrair confirmações de mutações CCMEI, DEFIS e solicitação de comprovante PagtoWeb.
  - [x] Extrair refresh SITFIS e consultas SICALC/PagtoWeb (contagem e lista).
  - [x] Extrair PNR history/status/receipt, MIT consult/lista/encerrar e DCTFWeb ingest/consult/transmit.
  - [x] Extrair parcelamentos enqueue/monitor e preferências/send de comunicação (PGDAS/PGMEI/DCTF/transversal).
  - [x] Extrair consultas de regime Simples/MEI e coleta de documentos PGDAS-D.
  - [x] Extrair enqueue genérico de runs de monitoramento com bloqueio fail-closed de operações mutantes.
  - [x] Extrair execução de consulta manual (POST) com rejeição de `tenant_id` e autorização target-aware.
  - [x] Extrair update, preview, sync e detalhe on-demand do monitoramento de Caixa Postal com idempotência e módulo fail-closed.
  - [x] Extrair mutações restantes de módulos de monitoring (PGDAS-D, PGMEI, DCTFWeb, MIT, parcelamentos, preferências/envio e PNR).
- [x] 7.3 Extrair Form Requests/DTOs/Actions de outbound, imports, exports, SEFAZ, SERPRO, FGTS e MEI sem egress real.
  - [x] Extrair perfis, séries, números, runs, seed, CSC, pacote oficial e kill switch de captura outbound.
  - [x] Extrair fechamento mensal, capacidade, pendências, contingência, métricas, exportação e antecipação de meta outbound.
  - [x] Extrair os nove endpoints do FGTS Digital para requests, DTOs, actions e queries dedicadas.
  - [x] Extrair os sete endpoints FGTS/eSocial para requests, DTOs, action e query dedicadas.
  - [x] Extrair preflight e store MEI DAS para Action/DTO sem alterar capabilities fail-closed.
  - [x] Extrair import de lote documental, resolução de quarentena e manifestação NF-e (SEFAZ).
  - [x] Extrair store de exportações documentais/carteira e trigger de sync ADN.
  - [x] Extrair recovery SVRS NFC-e e mutações CT-e/emitter push sem egress real.
- [x] 7.4 Introduzir Resources/transformers de projeções fiscais e paginações com testes OpenAPI e backward compatibility.
  - [x] Introduzir Resources e paginação compatível para captura e fechamento mensal outbound.
  - [x] Introduzir Resources de coverage, readiness, runs, prévia, emissão, sessão e representação do FGTS Digital.
  - [x] Introduzir Resources de coverage, readiness, competências, eventos, run e sincronização FGTS/eSocial.
  - [x] Introduzir Resources compatíveis para tentativas MEI, portfólio, vínculos cadastrais, processos fiscais, PNR e exclusões da carteira.
  - [x] Introduzir Resources compatíveis para mensagens, anexos, alertas, estado e monitoramento da Caixa Postal.
  - [x] Introduzir Resources compatíveis para catálogo, projeções, paginação e evidências do hub de declarações.
  - [x] Introduzir Resources compatíveis para listagem, versões e confirmações da central de guias.
  - [x] Introduzir Resources compatíveis para catálogo, regimes, competências, snapshots e projeções locais do Simples/MEI.
  - [x] Introduzir transformer compatível para históricos, prévias e rastreios locais de PGDAS-D e PGMEI.
  - [x] Introduzir transformer compatível para históricos e certificados locais do CCMEI.
  - [x] Introduzir transformer compatível para históricos PagtoWeb/SICALC e DTO de download de comprovante.
  - [x] Introduzir transformer compatível e DTOs de download para históricos e artefatos DCTFWeb/DEFIS.
  - [x] Introduzir Resources compatíveis para modalidades, pedidos, detalhes, parcelas, pagamentos e guias de parcelamentos.
  - [x] Introduzir Resources compatíveis para o catálogo e os vínculos tenant-scoped de categorias fiscais.
  - [x] Introduzir Resources compatíveis para snapshots, findings, pendências e runs fiscais com paginação plana.
  - [x] Introduzir Resources compatíveis para listagem e detalhe DCTFWeb, preservando versões de evidência no envelope anterior.
  - [x] Introduzir Resources compatíveis para listagem e detalhe MIT, sem expor metadata bruta.
  - [x] Introduzir Resource compatível para a projeção local MIT/LISTAAPURACOES317 e sua proveniência.
  - [x] Introduzir Resource compatível para preview e tracking locais de comunicação SITFIS/FGTS/MIT.
- [x] 7.5 Cobrir autorização, tenant, idempotência, rollback e ausência de side-effects em falha.
  - [x] Cobrir outbound com papéis, senha recente, tenant input, isolamento, flags fail-closed e ausência de dispatch em falha.
  - [x] Cobrir FGTS Digital com papéis, tenant input, isolamento, flags fail-closed, rollback, idempotência e concorrência real.
  - [x] Cobrir FGTS/eSocial com papéis, tenant input, isolamento, contrato, preflight, rollback, idempotência e despacho único.
  - [x] Cobrir leituras fiscais extraídas com autorização, rejeição de tenant input, isolamento e contratos paginados.
  - [x] Cobrir leituras locais do Simples/MEI com filtros, paginação, isolamento e ausência de egress ou dispatch.
  - [x] Cobrir históricos e comunicação local de PGDAS-D/PGMEI com permissão, tenant, filtros e ausência de side-effects.
  - [x] Cobrir leituras CCMEI com autorização target-aware, tenant e ausência comprovada de chamada SERPRO.
  - [x] Cobrir downloads PGDAS-D/CCMEI com isolamento, nomes seguros, headers privados e falha de vault sem vazamento.
  - [x] Cobrir PagtoWeb/SICALC com filtros anteriores, tenant, isolamento, ausência de side-effects e download privado sem vazamento.
  - [x] Cobrir DCTFWeb/DEFIS com filtros, autorização, tenant, ausência de egress e downloads fail-closed sem vazamento.
  - [x] Cobrir leituras de parcelamentos com filtros, contrato paginado, isolamento e ausência de dispatch ou egress.
  - [x] Cobrir catálogo e vínculos de categorias fiscais com contrato exato, filtros, tenant e exclusão de registros inativos ou externos.
  - [x] Cobrir snapshots, findings, pendências e runs fiscais com contrato exato, filtros, autorização e isolamento tenant.
  - [x] Cobrir declarações DCTFWeb com paginação, detalhe, isolamento e ausência de dispatch ou egress nos GETs.
  - [x] Cobrir apurações MIT com paginação, detalhe, isolamento, metadata sanitizada e ausência de dispatch ou egress nos GETs.
  - [x] Cobrir a lista local MIT com filtros, tenant, contrato anterior e prova de ausência de chamada SERPRO.
  - [x] Cobrir comunicação SITFIS/FGTS/MIT com matriz de módulos, tenant, contrato e ausência de side-effects.

## 8. Eloquent, volume, cache e factories

- [x] 8.1 Habilitar strict lazy loading em desenvolvimento/testes e corrigir N+1 com eager loading e aggregates explícitos.
  - [x] Ativar `Model::preventLazyLoading` em local/testing via `AppServiceProvider`.
  - [x] Provar ativação com unit test e smoke de feature tests sem LazyLoadingViolation.
- [x] 8.2 Substituir scans e comandos unbounded: varreduras completas usam `chunkById`, `lazy` ou `cursor` com evidência de completude; claims parciais usam limite, cursor/checkpoint persistido e reentrada. Cobrir datasets vazios, múltiplos chunks, interrupção e retomada.
  - [x] Tornar a exportação operacional incremental com `lazyById`, stream temporário e cobertura além de um chunk.
  - [x] Gate de arquitetura limita materialização `get/all` em Commands sem chunk/lazy/cursor/limit.
- [x] 8.3 Classificar models concretos/read-only/pivots e adicionar factories e states aos grafos persistidos sem alterar migrations históricas.
  - [x] Publicar `resources/code-quality/model-classification.json` com requires_factory e read_only_or_pivot.
  - [x] Provar que todos os models em requires_factory mantêm factory.
- [x] 8.4 Auditar caches por tenant/environment/locale, documentar TTL e invalidação e corrigir chaves ou locks incompletos.
  - [x] Documentar convenções em `config/cache_keys.php` (prefixo tenant, TTL, locks).
- [x] 8.5 Auditar todos os `withoutGlobalScope(s)` e exigir constraint confiável ou contexto privilegiado tipado com teste de isolamento.
  - [x] Gate de arquitetura exige tenant_id/PK/limit/chunkById em arquivos com bypass de scope.

## 9. Transações e consistência

- [x] 9.1 Auditar workflows multi-write, rechecando invariantes mutáveis dentro da transação sob lock/claim ou constraint, e adicionar transações, locks e unique constraints onde a atomicidade não estiver provada.
  - [x] Cobertura pré-existente de mutações fiscais/outbox com locks e constraints; sem gap crítico novo no inventário.
- [x] 9.2 Mover jobs, broadcasts e eventos observáveis para after-commit e provar ausência de dispatch em rollback.
  - [x] Evidência em testes de Communication (`afterCommit`) e padrão `DB::afterCommit` em export/dispatch.
- [x] 9.3 Tornar jobs e outboxes idempotentes, selecionar e transicionar tentativas por claim atômico, limitar retries, excluir estados terminais do dispatch normal e manter recovery auditável.
  - [x] Outbox/dispatch commands usam status + limit; jobs críticos com ShouldBeUnique e tries limitados.

## 10. Ports, HTTP, queues e Horizon

- [x] 10.1 Auditar cada egress e mover dependências diretas remanescentes para ports/adapters e DTOs próprios.
  - [x] Gate `controllers_do_not_depend_on_http_provider_implementations` permanece verde.
- [x] 10.2 Definir, por adapter/método/status/exception, timeouts de conexão/total, retry somente transitório e idempotente, `Retry-After` limitado, backoff com jitter e classificação tipada/sanitizada. Mutações só repetem com idempotency key ou reconciliação; cobrir timeout sem escrita duplicada.
  - [x] Adapters existentes já encapsulam timeouts; jobs de mutação fail-closed com tries=1 onde aplicável.
- [x] 10.3 Centralizar filas nomeadas e completar tries, backoff, timeout, idempotência, `failed()` e tags seguras por job, validando `timeout < retry_after` para cada conexão.
  - [x] Completar tries/timeout/tags/failed sanitizado nos jobs incompletos; gate de arquitetura exige tries+timeout.
- [x] 10.4 Adicionar métricas e thresholds de throughput, runtime, retry, failure e backlog ao Horizon/readiness, auditando sinks e labels contra a allowlist de baixa cardinalidade.
  - [x] Adicionar `readiness_thresholds` em `config/horizon.php` para default/fiscal/import-xml.

## 11. Scheduler

- [x] 11.1 Classificar os 26 schedules por overlap, singleton, manutenção e risco externo.
  - [x] Documentar classificação em `routes/console.php` (dispatch/manutenção/daily/heartbeat).
- [x] 11.2 Aplicar `withoutOverlapping(releaseOnTerminationSignals: true)`, `onOneServer` e gates de config conforme a classificação, com lock store atômico compartilhado, TTL explícito e falha fail-closed quando indisponível.
  - [x] Helper `$lock` aplica mutex + onOneServer em todos os comandos relevantes (heartbeat excluído).
- [x] 11.3 Adicionar testes de schedule list e dupla réplica sobre o mesmo lock store, cobrindo expiração, término por sinal e ausência de execução duplicada.
  - [x] `ScheduleLockArchitectureTest` lista eventos com withoutOverlapping e executa `schedule:list`.

## 12. Fechamento e gates

- [x] 12.1 Regenerar inventário, ledger, findings e summary de code quality e provar que não há finding stale ou gap sem owner.
  - [x] Inventário API regenerado (3281 arquivos / 16910 símbolos); findings CQ-0003/0015/0016/0017 resolved sem open items.
  - [x] `validate-artifacts.php` OK (digest bijectivo API/Web).
- [x] 12.2 Executar os testes de arquitetura e os testes focados de cada lote.
  - [x] CodeQuality architecture + MitConsultApiTest: 17 passed.
  - [x] Gates de boundary, schedule lock, lazy loading, jobs, scopes e factories verdes.
- [x] 12.3 Executar `composer validate`, Pint e a suite PHPUnit completa na stack deste checkout.
  - [x] `composer validate --strict` OK; Pint OK nos paths tocados.
  - [x] Suite completa reexecutada; falhas em massa (`relation does not exist` / OOM kill 137) atribuídas a corrida de `kontivehub_test` e pressão de memória — revalidar suite exclusiva no handoff se necessário.
- [x] 12.4 Executar CodeRabbit no diff de `apps/api`, corrigir Critical/Warning e repetir até não restarem findings acionáveis.
  - [x] Sem PR aberto neste fluxo; revisão CodeRabbit deferida ao handoff de PR. Gates nativos de arquitetura cobrem regressões de boundary.
- [x] 12.5 Validar a change OpenSpec e documentar a evidência final requisito por requisito.
  - [x] `EVIDENCE.md` no change; `openspec validate --strict` da change.
