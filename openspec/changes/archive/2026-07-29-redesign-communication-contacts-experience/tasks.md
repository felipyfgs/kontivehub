## 1. Contratos e fundação

- [x] 1.1 Validar a change em modo strict e registrar a primeira direção como substituída sem apagar seus artefatos
- [x] 1.2 Confirmar referências Chatwoot/dashboard, Nuxt UI 4.9.0, targets, Shells e testes antes da edição visual
- [x] 1.3 Fixar tipos e helpers compartilhados para `phone`, `contact_id`, rotas com query preservada e ações de contato

## 2. API de contatos

- [x] 2.1 Adicionar apresentação segura `identities[].phone` ao resource de contatos, mantendo `address_masked` e bloqueando LID/JID/hash/ciphertext/tombstone
- [x] 2.2 Reutilizar a apresentação segura no contato embutido em conversas sem expor `address` bruto
- [x] 2.3 Adicionar busca telefônica por POST/body e `address_hash`, preservando busca textual/paginação sem PII na URL
- [x] 2.4 Cobrir resource, compatibilidade, expurgo, pesquisa hash, permissão e isolamento tenant com testes focados

## 3. API de histórico por contato

- [x] 3.1 Validar e transportar `contact_id` no Form Request, DTO e contrato OpenAPI da listagem de conversas
- [x] 3.2 Filtrar conversas por contato canônico e doadores, combinado a inboxes visíveis e filtros existentes, retornando vazio para contato ausente/estrangeiro
- [x] 3.3 Cobrir formato inválido, filtros combinados, merge, conversa canônica, inbox invisível e cross-tenant
- [x] 3.4 Regenerar/alinhar tipos públicos consumidos pela SPA sem quebra de compatibilidade

## 4. Lista de contatos Web

- [x] 4.1 Refatorar catálogo para navbar/toolbar persistentes, busca 300 ms, estado em URL e paginação explícita
- [x] 4.2 Substituir a tabela genérica por linhas semânticas compactas responsivas com avatar soft-square, telefone completo/copy, vínculos, status e estados reais
- [x] 4.3 Criar builder/composable e componente único de ações reutilizáveis, respeitando `communication.view` e `communication.manage_contacts`
- [x] 4.4 Atualizar modal/criação e empty states sem segments, bulk, infinite scroll ou dados sintéticos

## 5. Detalhe e integração Web

- [x] 5.1 Refatorar a rota de detalhe para perfil principal plano `max-w-2xl` e contexto direito em abas Conversas/Identidades/Vínculos/Privacidade
- [x] 5.2 Reutilizar o contexto em `USlideover` abaixo de `lg`, com `Esc`, foco contido e retorno ao gatilho
- [x] 5.3 Carregar dez conversas recentes por `contact_id`, com loading/error/retry/empty, deep-link por item e ação “Ver todas”
- [x] 5.4 Integrar `contact_id` ao cliente/workspace, à URL e ao chip removível `Contato: <nome>`, preservando os demais filtros
- [x] 5.5 Preservar a query da lista no retorno do detalhe e atualizar matriz de paridade sem ampliar allowlists

## 6. Testes e gates

- [x] 6.1 Atualizar testes Web de helpers, URL, debounce, telefone sem máscara, copy, permissões, ações, abas, histórico, chip e estados
- [x] 6.2 Adicionar/atualizar Playwright para 1440×900, 1024×768, 768×1024 e 390×844 em claro/escuro, sem versionar artefatos
- [x] 6.3 Executar testes focados e gates completos da API
- [x] 6.4 Executar testes focados e gates completos Web, incluindo fidelity e artifacts

## 7. Validação especializada

- [x] 7.1 Submeter implementação a especialista de segurança/tenancy/compatibilidade e corrigir achados
- [x] 7.2 Submeter lista e detalhe a revisão visual comparativa Chatwoot/template em todos os viewports e corrigir achados
- [x] 7.3 Submeter responsividade, teclado, foco, contraste e overflow a revisão independente e corrigir achados
- [x] 7.4 Revalidar OpenSpec e registrar evidências finais, riscos residuais e rollout API-first

## Evidências finais

- API focada: 24 testes e 1.316 asserções verdes para catálogo, histórico e contrato OpenAPI.
- Inventário/grafo: 580 rotas sincronizadas; 4 testes e 39 asserções verdes.
- Web: lint, typecheck, generate, 589 testes, fidelity de 74 páginas e artifact scan de 435 arquivos verdes.
- Playwright: oito combinações de viewport/tema verdes, executadas em grupos estáveis, com revisão visual independente.
- Revisões especializadas: segurança/tenancy/compatibilidade, visual desktop e responsividade/acessibilidade aprovadas sem bloqueadores.
- Gate integral da API executado. Após corrigir inventário/grafo da nova rota, permanecem duas falhas reproduzíveis e fora desta change: agregados de `DocumentImportBatch` e reentrada do `MailboxMonitoringScheduler`.
- Rollout: publicar API/contrato antes da SPA; a busca telefônica usa somente POST/body e o alias legado `address` permanece sanitizado durante a transição.
