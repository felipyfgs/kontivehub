## Context

O Lote 1 do roadmap `ui-archetypes-standardization` migra as três superfícies
admin divergentes para os componentes Shell já existentes. Estado atual
(código lido nesta sessão):

- `apps/web/app/pages/admin/serpro.vue` reproduz manualmente a casca settings
  (`UDashboardPanel` + `UDashboardNavbar` + `UDashboardToolbar` +
  `DashboardContent width="comfortable"`), idêntica à que
  `apps/web/app/components/shell/SettingsShell.vue` encapsula e que
  `apps/web/app/pages/conta.vue` consome de forma canônica. Particularidade:
  gate `canAccessPlatformSerpro` — toolbar só para autorizado; body com
  `UAlert` warning (`admin-serpro-denied`) e `NuxtPage` caso contrário.
- `apps/web/app/pages/admin/tenants/[id].vue` usa `UDashboardPanel` +
  `UDashboardNavbar` com badge de lifecycle e botão inline
  (`UButton arrow-left label="Lista"`), erro de carga via `UAlert` com ação
  «Tentar novamente» e skeletons inline. O padrão já migrado equivalente é
  `apps/web/app/pages/clients/[id].vue`: `ShellPagePanel` +
  `ShellPageNavbar` com `ShellNavbarBack` no slot `#leading`.
- `apps/web/app/pages/admin/tenants/new.vue` usa o mesmo chrome manual com
  botão inline `label="Escritórios"`; o wizard (stepper, validações, submit
  com `idempotency_key`) não é objeto desta mudança.
- `ShellLoadError` já é o padrão adotado em `exports.vue`,
  `work/templates/index.vue`, `communication/contacts/[id].vue` e
  `communication/flows/[id]/*`.

Restrições: skill `ui-archetypes` (menor delta possível em tela existente,
regra 7); não ampliar allowlists do gate `test:fidelity`; preservar
`data-testid` (contratos com testes unitários/gate existentes); Vue/TS com
2 espaços, sem ponto e vírgula e sem vírgula final.

## Goals / Non-Goals

**Goals:**

- Casca SERPRO via `ShellSettingsShell`, eliminando a duplicação de
  panel/navbar/toolbar/content.
- Detalhe e criação de escritório via `ShellPagePanel` + `ShellPageNavbar` +
  `ShellNavbarBack` (padrão `clients/[id].vue`).
- Erro de carga do detalhe via `ShellLoadError` com retry idempotente.
- Zero mudança de comportamento de autorização, rotas, fluxos de ativação e
  `data-testid`.

**Non-Goals:**

- Nenhum componente Shell novo (skeleton de página, seção naked etc.).
- Não trocar `UPageCard` por `ShellSectionCard` nas filhas settings (Lote 6).
- Não alterar `formError` inline do wizard (erro de formulário contextual,
  não erro de carga de página).
- Não tocar dashboards, monitoring, master-detail, docs, matriz de paridade
  ou allowlists do gate.
- Não mudar regras de autorização: `canAccessPlatformSerpro` e
  `canAccessPlatformAdmin` continuam fail-closed.

## Decisions

1. **`serpro.vue` → `ShellSettingsShell` com gate no consumidor.**
   A toolbar condicional usa `v-if="canAccessPlatformSerpro"` no
   `<template #toolbar>` (o shell só renderiza `UDashboardToolbar` quando o
   slot existe — vide `SettingsShell.vue` linhas 50-56). O gate de body
   (`UAlert` negado vs `NuxtPage`) permanece no slot default.
   Alternativa considerada: estender `SettingsShell` com prop `denied` —
   rejeitada por ampliar API de um Shell para um único consumidor.
2. **Voltar via `ShellNavbarBack` no slot `#leading` da navbar.**
   Segue `clients/[id].vue`: `ShellNavbarBack to="/admin/tenants"` com
   `label`/`test-id` preservados ("Lista" / `admin-tenant-back`;
   "Escritórios" em `new.vue`). O componente já entrega o comportamento
   responsivo (label no desktop, square no mobile).
   Alternativa: manter `UButton` inline no slot `#right` — rejeitada por
   perpetuar a duplicação que o lote elimina. Efeito colateral aceito: o
   botão sai do slot `#right` para o `#leading`, alinhando-se ao arquétipo.
3. **Erro de carga via `ShellLoadError` para todos os estados de erro do
   detalhe**, incluindo acesso negado e ID inválido: `load()` é fail-closed
   e idempotente, então o retry re-executa e devolve a mesma mensagem —
   comportamento atual já oferece retry nesses casos. Preserva-se
   `data-testid="admin-tenant-error"` via prop `test-id`.
   Alternativa: `UAlert` warning sem retry para negação — rejeitada por
   mudar comportamento sem ganho e criar exceção ao padrão.
4. **Badge de lifecycle permanece no slot `#right` da navbar**, e o botão
   «Atualizar» do cabeçalho «Resumo» permanece inline: `ShellNavbarRefresh`
   é ghost de navbar/toolbar e não cabe no `UPageCard` naked; a troca de
   seções naked por `ShellSectionHeader` pertence ao Lote 6.
5. **Preservar todos os `data-testid`** e ids de painel
   (`admin-serpro-panel`, `admin-tenant-detail`, `admin-tenants-new`,
   `page-navbar`), repassando-os às props dos Shell (`test-id`, `id`).
6. **Atualizar comentários de fonte nos arquivos tocados** de
   `.local/reference/nuxt-dashboard-template` para o caminho canônico da
   skill `.local/references/dashboard` (achado secundário do guarda-chuva;
   somente onde já há edição).
7. **Nenhuma mudança em matriz/allowlist/gates**: páginas continuam `SHELL` e
   as cascas usadas já são permitidas (`ShellPagePanel`, `ShellSettingsShell`).

## Risks / Trade-offs

- [Regressão visual sutil no chrome (posição do voltar, ícone
  `i-lucide-wifi-off` e margem `mb-4` do `ShellLoadError`, tamanho `sm` do
  `ShellNavbarBack`)] → É o padrão canônico já adotado em `clients/[id].vue`,
  `exports.vue` e `communication/*`; cobertura pelos gates `test:fidelity` e
  testes de superfície; comparação visual desktop/mobile na validação.
- [Quebra de testes que buscam o botão voltar no slot `#right`] → Testes
  focados rodados antes dos gates; `data-testid` preservado no
  `ShellNavbarBack` (`test-id` + sufixo `-mobile`).
- [Toolbar SERPRO sumir para não-admin por renderização do slot] → `v-if`
  permanece no consumidor e há testid dedicado
  (`admin-serpro-section-navigation`) para verificação.
- [Rollback] → Mudança isolada em 3 arquivos; reversão = restaurar os arquivos
  anteriores, sem migration, flag ou contrato envolvido.

## Open Questions

Nenhuma bloqueante. Refinamentos adiados por escopo: skeleton de página como
Shell futuro (se outros lote o exigirem) e `ShellSectionHeader`/`SectionCard`
nas seções naked/subtle destas páginas (Lote 6).
