## Why

`DocsWorkspace` ainda monta manualmente a navbar, o refresh e o erro de carga
inicial, apesar de esses elementos já possuírem Shells canônicos. O Lote 5
remove essa duplicação sem redesenhar o posto documental ou alterar seus
contratos de navegação e dados.

## What Changes

- Reusar `ShellPageNavbar` no chrome interno de `DocsWorkspace`.
- Reusar `ShellNavbarRefresh` na ação de recarga existente.
- Reusar `ShellLoadError` somente quando a primeira carga falhar sem dados
  válidos para exibir.
- Preservar a tabela densa, filtros, insights, importação, exportação,
  paginação, seleção, detalhe em modal, `min-w-0`, rotas e testids.
- Manter a matriz de paridade e a allowlist atuais sem ampliação.
- Não criar componente Shell público, não alterar `/api/v1`, flags,
  dependências, egress ou persistência.

## Capabilities

### New Capabilities

- `ui-archetypes-docs-chrome`: chrome canônico interno do posto documental,
  preservando a composição densa, a navegação por rota e o detalhe modal.

### Modified Capabilities

Nenhuma.

## Impact

- Código: `apps/web/app/components/docs/Workspace.vue` e testes focados do
  frontend.
- Consumidores: `/docs`, `/docs/catalog` e `/docs/[accessKey]`, sem mudança de
  contrato, URL, query, testid ou cliente HTTP.
- Operação: nenhum efeito em API, banco, filas, integrações, flags ou tráfego
  externo.
