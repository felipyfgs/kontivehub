## Context

`apps/web/app/components/docs/Workspace.vue` serve três rotas e concentra a
lista documental por cliente ou catálogo, filtros, insights, importação,
exportação e detalhe em modal. A composição de domínio já está consolidada;
somente a navbar, a ação de refresh e o erro sem dados repetem chrome que os
Shells existentes encapsulam.

## Goals / Non-Goals

**Goals:**

- Reusar `ShellPageNavbar`, `ShellNavbarRefresh` e `ShellLoadError` apenas nos
  pontos estruturalmente equivalentes.
- Preservar toda a composição documental, inclusive tabela densa, modal,
  filtros, paginação, seleção, queries e testids.
- Manter `min-w-0`, responsividade, foco, labels, matriz e allowlist atuais.

**Non-Goals:**

- Não substituir `DocsWorkspace`, `DocsCatalog` ou `DocsDetailModal` por um
  wrapper novo.
- Não mover filtros para outro slot, trocar paginação ou alterar os estados
  parciais de insights.
- Não alterar API, rotas, tipos, dependências, dados, flags ou integrações.

## Decisions

### Trocar apenas o chrome equivalente

A navbar interna passará a `ShellPageNavbar`, e sua ação existente de recarga
passará a `ShellNavbarRefresh`. Título, contagem, ações de importar/exportar e
condições de permissão permanecem nos mesmos slots.

Alternativa: envolver o workspace inteiro com `ShellPagePanel`. Rejeitada
porque o componente é consumido dentro da casca de roteamento atual e a troca
alteraria ownership, scroll e composição das três rotas.

### Limitar `ShellLoadError` à falha inicial

O erro canônico será exibido apenas quando `loadError` existir e a visão ativa
não possuir linhas válidas. Erros com conteúdo preservado continuam no alerta
contextual existente, evitando apagar a última carga utilizável.

Alternativa: substituir todos os alertas do componente. Rejeitada porque
insights e operações parciais possuem semânticas distintas do erro inicial.

### Preservar tabela e detalhe sem reparenting

`DocsCatalog`, tabela por cliente, filtros e `DocsDetailModal` não serão
movidos. O gate focado verificará que `min-w-0`, rotas, testids, modal e
allowlist permanecem estáveis.

## Risks / Trade-offs

- [Erro contextual virar estado de página inteira] → condicionar o Shell ao
  conjunto ativo vazio e manter o alerta parcial para dados preservados.
- [Slots da navbar mudarem a ordem das ações] → copiar os slots atuais sem
  mover condições, handlers ou labels.
- [Regressão em tabela/modal] → impedir alterações nesses blocos e executar
  testes focados, seis gates Web e inspeção desktop/mobile.

## Migration Plan

1. Adicionar o gate focado do chrome documental.
2. Aplicar as três substituições mínimas em `DocsWorkspace`.
3. Rodar testes focados, gates completos e inspeção local.
4. Sincronizar a capability, marcar o Lote 5 no guarda-chuva e arquivar.

Rollback restaura apenas as tags do chrome e o alerta inicial; não há estado
persistido, migração de dados, flag ou rollout.

## Open Questions

Nenhuma bloqueante.
