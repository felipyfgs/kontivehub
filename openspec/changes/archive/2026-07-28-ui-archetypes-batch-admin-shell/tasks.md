# Tasks — ui-archetypes-batch-admin-shell

Implementa o Lote 1 do roadmap `ui-archetypes-standardization`. Referências:
`proposal.md`, `design.md` e `specs/ui-archetypes-admin-chrome/spec.md`
deste change; `audit.md` do guarda-chuva.

## 1. Preparação

- [x] 1.1 Rodar `node .agents/skills/ui-archetypes/scripts/check-dashboard-reference.mjs` e interromper se não passar
- [x] 1.2 Ler o arquétipo settings em `.local/references/dashboard/app/pages/settings.vue` e a skill `ui-archetypes`
- [x] 1.3 Subir a stack de desenvolvimento com `make dev` para os gates no container `frontend-dev`

## 2. Console SERPRO → ShellSettingsShell

- [x] 2.1 Migrar `apps/web/app/pages/admin/serpro.vue` para `ShellSettingsShell` (`id="admin-serpro"`, `test-id="admin-serpro-panel"`, `title="Integração SERPRO"`), mantendo `UDashboardSidebarCollapse` via navbar do shell
- [x] 2.2 Mover `SectionNavigation` + `SERPRO_NAV_ITEMS` para `<template v-if="canAccessPlatformSerpro" #toolbar>`, preservando `admin-serpro-section-navigation` e o `aria-label`
- [x] 2.3 Manter o gate de body: `UAlert` warning `admin-serpro-denied` quando negado e `NuxtPage` quando permitido
- [x] 2.4 Atualizar o comentário de fonte do arquivo para `.local/references/dashboard`
- [x] 2.5 Rodar testes focados: `serpro-nav.nuxt.test.ts`, `serpro-navigation.test.ts`, `admin-serpro-inventory-gate.test.ts`, `navigation.test.ts`

## 3. Detalhe de escritório → Shell page chrome

- [x] 3.1 Migrar `apps/web/app/pages/admin/tenants/[id].vue` para `ShellPagePanel` (`id="admin-tenant-detail"`, `test-id="admin-tenant-detail"`, `body-class="lg:py-12"`) + `ShellPageNavbar` com `:title="pageTitle"`
- [x] 3.2 Trocar o botão inline «Lista» por `ShellNavbarBack to="/admin/tenants" label="Lista" test-id="admin-tenant-back"` no slot `#leading`; manter o badge de lifecycle (`admin-tenant-lifecycle-badge`) no slot `#right`
- [x] 3.3 Trocar o `UAlert` de erro por `ShellLoadError` com `test-id="admin-tenant-error"`, `:description="loadError"` e `@retry="load"` (inclui acesso negado e ID inválido, ambos fail-closed e idempotentes)
- [x] 3.4 Manter inline: skeletons (`admin-tenant-loading`), botão «Atualizar» do cabeçalho Resumo, hints de lifecycle, `ShellFormModal` de correção e `ActivationOneTimeSecret`
- [x] 3.5 Atualizar o comentário de fonte do arquivo para `.local/references/dashboard`

## 4. Criação de escritório → Shell page chrome

- [x] 4.1 Migrar `apps/web/app/pages/admin/tenants/new.vue` para `ShellPagePanel` (`id="admin-tenants-new"`, `test-id="admin-tenants-new"`, `body-class="lg:py-12"`) + `ShellPageNavbar title="Novo escritório"`
- [x] 4.2 Trocar o botão inline «Escritórios» por `ShellNavbarBack to="/admin/tenants" label="Escritórios"` no slot `#leading`
- [x] 4.3 Manter inline o wizard completo: stepper/progress mobile, `formError` contextual, validações, submit com `confirmPassword` + `idempotency_key` e cartão de segredo único

## 5. Validação

- [x] 5.1 Rodar testes focados das superfícies tocadas (serpro, navigation, shell migration gates)
- [x] 5.2 Rodar gates completos no `frontend-dev`: `lint`, `typecheck`, `generate`, `test`, `test:fidelity`, `test:artifacts`
- [x] 5.3 Comparar as três páginas com o arquétipo em desktop e mobile (navbar, voltar responsivo, erro com retry, toolbar condicional) e verificar teclado/foco/labels
- [x] 5.4 Confirmar que `template-parity-matrix.md` e allowlists do gate permanecem inalteradas (nenhuma página criada/removida)

## 6. Encerramento

- [x] 6.1 Marcar o Lote 1 como concluído no `tasks.md` do guarda-chuva `ui-archetypes-standardization`
- [x] 6.2 Arquivar este change após validação, sincronizando a delta spec `ui-archetypes-admin-chrome`
