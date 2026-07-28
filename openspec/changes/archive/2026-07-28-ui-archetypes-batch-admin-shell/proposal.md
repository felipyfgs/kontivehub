## Why

A auditoria `ui-archetypes-standardization` classificou `pages/admin/serpro.vue`,
`pages/admin/tenants/[id].vue` e `pages/admin/tenants/new.vue` como
**divergentes**: elas montam `UDashboardPanel`/`UDashboardNavbar` à mão,
duplicando o chrome que `ShellSettingsShell`, `ShellPagePanel`,
`ShellPageNavbar`, `ShellNavbarBack` e `ShellLoadError` já encapsulam
(padrão canônico em `pages/conta.vue` e `pages/clients/[id].vue`). É o Lote 1
do roadmap guarda-chuva: eliminar as divergências admin com o menor delta
possível, antes dos lotes de maior superfície.

## What Changes

- Migrar `apps/web/app/pages/admin/serpro.vue` para `ShellSettingsShell`,
  mantendo a toolbar de navegação condicionada a `canAccessPlatformSerpro` e
  o alerta fail-closed de acesso restrito.
- Migrar `apps/web/app/pages/admin/tenants/[id].vue` para `ShellPagePanel` +
  `ShellPageNavbar` + `ShellNavbarBack`, trocando o `UAlert` de erro de carga
  por `ShellLoadError` com retry idempotente.
- Migrar `apps/web/app/pages/admin/tenants/new.vue` para `ShellPagePanel` +
  `ShellPageNavbar` + `ShellNavbarBack`; wizard, validações e alerta de
  formulário permanecem inline.
- Preservar todos os `data-testid` existentes, rotas, permissões e fluxos de
  negócio (ativação, regeneração, correção de admin, criação com
  idempotency key).
- Sem mudança breaking: nenhuma página criada/removida, matriz de paridade
  inalterada, nenhuma allowlist do gate de fidelidade ampliada.

## Capabilities

### New Capabilities

- `ui-archetypes-admin-chrome`: Chrome canônico do dashboard nas superfícies
  de administração da plataforma (console SERPRO e gestão de escritórios) —
  casca settings via Shell, navbar com collapse e voltar responsivo, erro de
  carga padronizado com retry e estados de acesso negado fail-closed.

### Modified Capabilities

Nenhuma. Não há specs de UI registradas em `openspec/specs`; o guarda-chuva
`ui-archetypes-standardization` introduziu apenas capabilities de auditoria e
roadmap, que este change não altera.

## Impact

- Código afetado: 3 páginas em `apps/web/app/pages/admin/` (`serpro.vue`,
  `tenants/[id].vue`, `tenants/new.vue`) consumindo componentes existentes de
  `apps/web/app/components/shell/`. Nenhum componente Shell novo.
- Apps: somente `apps/web`. Sem impacto em `apps/api`, `apps/wazync`,
  contratos `/api/v1`, OpenAPI ou clientes HTTP.
- Filhas do console SERPRO (7 rotas `admin/serpro/*`) herdam a casca nova sem
  edição própria.
- Testes: testes unitários/de gate existentes que referenciam os `data-testid`
  preservados devem continuar verdes; `template-parity-matrix.md` não muda
  (bundles `SHELL`/`CHILD` mantidos).
- Gates: `lint`, `typecheck`, `generate`, `test`, `test:fidelity` e
  `test:artifacts` no container `frontend-dev`.
- Riscos: regressão visual/estrutural mitigada por delta mínimo, preservação
  de testids e skill `ui-archetypes` (referência local validada com PASS).
- Fora de escopo: `UPageCard` → `ShellSectionCard` nas filhas settings
  (Lote 6), toolbar de `closing.vue`, dashboards, monitoring, master-detail,
  docs e qualquer redesign ou componente Shell novo.
