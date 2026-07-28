## Context

O `apps/web` possui 74 páginas e uma biblioteca de componentes `Shell*`, mas a
auditoria em `audit.md` encontrou adoção desigual dos arquétipos locais. A
referência canônica é `.local/references/dashboard`, validada pela skill
`ui-archetypes`; o Laravel continua sendo a fonte de dados, autorização e
tenancy. Esta change é um guarda-chuva documental: o código da SPA muda somente
em changes filhos independentes.

## Goals / Non-Goals

**Goals:**

- Manter um inventário reproduzível das superfícies e de sua conformidade.
- Executar a padronização em seis lotes filhos ordenados, com menor delta e
  gates completos por lote.
- Preservar rotas, contratos da API, testids, responsividade, acessibilidade e
  estados reais de loading, erro e vazio.

**Non-Goals:**

- Redesenhar o produto, criar dados sintéticos ou alterar negócio/autorização.
- Criar componentes Shell antes de comprovar hierarquia e slots idênticos.
- Ampliar allowlists do gate de fidelidade ou alterar a matriz sem mudança de
  página.
- Alterar SSR, Pinia, dependências, `/api/v1` ou integrações internas.

## Decisions

1. **Um change filho por lote.** Cada lote possui proposal, design, delta spec,
   tasks, testes focados e gates web próprios. Isso mantém revisão, rollback e
   sincronização de specs independentes.
2. **Ordem fixa.** Admin, listas monitoring, dashboards, master-detail, docs e
   quick wins. O lote seguinte parte do inventário atualizado pelo anterior.
3. **Composição antes de abstração.** Analytics e master-detail reutilizam
   `ShellPagePanel`, `ShellPageNavbar`, `ShellNavbarRefresh` e os wrappers de
   domínio existentes; não serão criados `ShellAnalyticsPage` ou
   `ShellMasterDetailWorkspace`.
4. **Compatibilidade de superfície.** Rotas, testids, slots, matrizes e
   allowlists permanecem estáveis. `ShellLoadError` substitui apenas falha de
   carga inicial; estados stale, parciais ou contextuais permanecem locais.
5. **Validação por lote.** A referência deve passar no script da skill antes da
   edição. Testes focados precedem lint, typecheck, generate, Vitest, fidelity e
   artifacts, seguidos de inspeção desktop/mobile, teclado, foco e labels.

## Risks / Trade-offs

- [Testes baseados em texto quebrarem com tags equivalentes] → ajustar somente
  a expectativa estrutural coberta pelo lote, preservando testids e sem ampliar
  allowlists.
- [Shell genérico apagar particularidades de domínio] → limitar a migração ao
  chrome equivalente e manter panels, rails, slideovers e estados no domínio.
- [Auditoria ficar stale entre lotes] → atualizar a conclusão do lote pai logo
  após os gates do filho e inventariar quick wins somente depois dos lotes 1–5.
- [Regressão visual não detectada por Vitest] → comparação local desktop/mobile
  e verificação de teclado, foco e nomes acessíveis antes do archive.

## Migration Plan

1. Completar os artefatos deste guarda-chuva e validar em modo strict.
2. Concluir e arquivar cada change filho na ordem definida, sincronizando sua
   delta spec e marcando o lote correspondente neste roadmap.
3. Reexecutar a auditoria final, validar que não houve mudança de rotas,
   matriz ou allowlists e sincronizar as capabilities deste guarda-chuva.
4. Arquivar o guarda-chuva somente quando todos os filhos estiverem concluídos.

Rollback é feito por change filho, restaurando apenas seu chrome anterior. Não
há migration, mudança de dados, flag ou contrato público.

## Open Questions

Nenhuma.
