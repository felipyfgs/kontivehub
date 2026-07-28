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
- [ ] 4.2 Extrair os demais boundaries de plataforma e autenticação; schema e bootstrap Sanctum pertencem à change `repair-legacy-auth-schema`.
  - [x] Extrair seleção privilegiada de tenant, proprietário, onboarding inicial, administração comercial e controles fiscais.
  - [x] Extrair consolidação de consumo, contratos, kill switch, breaker, configuração global, versões de credencial, gates externos e limites SERPRO.
  - [ ] Extrair administração de tenants, operações, canário DTE e revisar onboarding produtivo SERPRO.
- [ ] 4.3 Extrair Form Requests, Resources e Actions de `ClientController` e controllers auxiliares, preservando Policies e contratos.
- [ ] 4.4 Extrair Form Requests, Resources e Actions dos controllers de tenant, settings, credenciais e autorizações SERPRO.
- [ ] 4.5 Cobrir os lotes de plataforma/clientes/tenant com sucesso, validação, autorização negada e isolamento cross-tenant.

## 5. Controllers de Work

- [ ] 5.1 Extrair Form Requests e DTOs dos filtros e mutações de Work.
- [ ] 5.2 Mover workflows e transações remanescentes de controllers Work para Actions/Services.
- [ ] 5.3 Introduzir Resources para tarefas, processos, templates, departamentos, grupos e paginações sem mudar envelopes.
- [ ] 5.4 Padronizar Policies/autorizações Work e cobrir denied/cross-tenant em feature tests.

## 6. Controllers de Communication

- [ ] 6.1 Extrair Form Requests, DTOs e Actions dos endpoints de inbox, conversation, contacts, catalog, flows e automations.
- [ ] 6.2 Introduzir Resources/transformers versionados para projeções e paginações de Communication.
- [ ] 6.3 Manter HMAC-before-parse nos endpoints internos e extrair validators dedicados com testes de assinatura, schema e replay.
- [ ] 6.4 Cobrir Policies, membership real, isolamento tenant e compatibilidade dos contratos Laravel↔Wazync.

## 7. Controllers fiscais, outbound e integrações

- [ ] 7.1 Extrair Form Requests/DTOs/Actions dos endpoints fiscais de leitura e monitoramento.
- [ ] 7.2 Extrair Form Requests/DTOs/Actions das mutações fiscais e manter todas as capabilities e kill switches fail-closed.
- [ ] 7.3 Extrair Form Requests/DTOs/Actions de outbound, imports, exports, SEFAZ, SERPRO, FGTS e MEI sem egress real.
- [ ] 7.4 Introduzir Resources/transformers de projeções fiscais e paginações com testes OpenAPI e backward compatibility.
- [ ] 7.5 Cobrir autorização, tenant, idempotência, rollback e ausência de side-effects em falha.

## 8. Eloquent, volume, cache e factories

- [ ] 8.1 Habilitar strict lazy loading em desenvolvimento/testes e corrigir N+1 com eager loading e aggregates explícitos.
- [ ] 8.2 Substituir scans e comandos unbounded: varreduras completas usam `chunkById`, `lazy` ou `cursor` com evidência de completude; claims parciais usam limite, cursor/checkpoint persistido e reentrada. Cobrir datasets vazios, múltiplos chunks, interrupção e retomada.
- [ ] 8.3 Classificar models concretos/read-only/pivots e adicionar factories e states aos grafos persistidos sem alterar migrations históricas.
- [ ] 8.4 Auditar caches por tenant/environment/locale, documentar TTL e invalidação e corrigir chaves ou locks incompletos.
- [ ] 8.5 Auditar todos os `withoutGlobalScope(s)` e exigir constraint confiável ou contexto privilegiado tipado com teste de isolamento.

## 9. Transações e consistência

- [ ] 9.1 Auditar workflows multi-write, rechecando invariantes mutáveis dentro da transação sob lock/claim ou constraint, e adicionar transações, locks e unique constraints onde a atomicidade não estiver provada.
- [ ] 9.2 Mover jobs, broadcasts e eventos observáveis para after-commit e provar ausência de dispatch em rollback.
- [ ] 9.3 Tornar jobs e outboxes idempotentes, selecionar e transicionar tentativas por claim atômico, limitar retries, excluir estados terminais do dispatch normal e manter recovery auditável.

## 10. Ports, HTTP, queues e Horizon

- [ ] 10.1 Auditar cada egress e mover dependências diretas remanescentes para ports/adapters e DTOs próprios.
- [ ] 10.2 Definir, por adapter/método/status/exception, timeouts de conexão/total, retry somente transitório e idempotente, `Retry-After` limitado, backoff com jitter e classificação tipada/sanitizada. Mutações só repetem com idempotency key ou reconciliação; cobrir timeout sem escrita duplicada.
- [ ] 10.3 Centralizar filas nomeadas e completar tries, backoff, timeout, idempotência, `failed()` e tags seguras por job, validando `timeout < retry_after` para cada conexão.
- [ ] 10.4 Adicionar métricas e thresholds de throughput, runtime, retry, failure e backlog ao Horizon/readiness, auditando sinks e labels contra a allowlist de baixa cardinalidade.

## 11. Scheduler

- [ ] 11.1 Classificar os 26 schedules por overlap, singleton, manutenção e risco externo.
- [ ] 11.2 Aplicar `withoutOverlapping(releaseOnTerminationSignals: true)`, `onOneServer` e gates de config conforme a classificação, com lock store atômico compartilhado, TTL explícito e falha fail-closed quando indisponível.
- [ ] 11.3 Adicionar testes de schedule list e dupla réplica sobre o mesmo lock store, cobrindo expiração, término por sinal e ausência de execução duplicada.

## 12. Fechamento e gates

- [ ] 12.1 Regenerar inventário, ledger, findings e summary de code quality e provar que não há finding stale ou gap sem owner.
- [ ] 12.2 Executar os testes de arquitetura e os testes focados de cada lote.
- [ ] 12.3 Executar `composer validate`, Pint e a suite PHPUnit completa na stack deste checkout.
- [ ] 12.4 Executar CodeRabbit no diff de `apps/api`, corrigir Critical/Warning e repetir até não restarem findings acionáveis.
- [ ] 12.5 Validar a change OpenSpec e documentar a evidência final requisito por requisito.
