# AGENTS.md — apps/web

Deltas locais. Raiz: `/AGENTS.md`.

## Escopo

Nuxt 4 SPA (`ssr: false`, `nuxt generate`). Nuxt UI 4. Sanctum cookie. Sem Pinia.

## Agente preferido

`nuxt_panel` / `nuxt-panel`. UI: skill `ui-archetype` obrigatória.

## Gates

```bash
pnpm run lint
pnpm run typecheck
pnpm run generate
pnpm run test
pnpm run test:fidelity
pnpm run test:artifacts
```

Playwright E2E é local, não gate de CI.

## Playwright: CLI vs MCP vs Test

Instrução real da documentação Playwright (2026):

| Ferramenta | Quando usar |
|---|---|
| **`playwright-cli` (`@playwright/cli`)** | Coding agents (este monorepo). Menos tokens, skill/shell, ideal para QA visual e exploração. |
| **Playwright MCP (`@playwright/mcp`)** | Loops agentic longos com estado persistente da página; clientes só-MCP. |
| **`@playwright/test`** | Suite E2E versionada e regressão (`tests/e2e`). |

Para agentes de código no KontiveHub, preferir **CLI** (oficial: “best for coding agents”). MCP pode coexistir, mas não é o default para UI do monorepo.

```bash
# a partir de apps/web (ou container frontend-dev)
corepack pnpm exec playwright-cli --help
corepack pnpm run pw:open            # abre http://127.0.0.1:3000 headed
corepack pnpm run pw:cli -- snapshot
corepack pnpm run pw:cli -- screenshot --filename=list.png

# suite E2E (isolada, não é o CLI de agente)
corepack pnpm run test:e2e
```

Config do CLI: `apps/web/.playwright/cli.config.json` (locale pt-BR, viewport 1440×900, origins locais).  
Artefatos do CLI: `.playwright-cli/` (não versionar).  
Auth: login real no app (`operador@example.com` / seed local) ou `state-save` / `state-load` após sessão válida.

## Never

- Chamar sidecar MEI direto do frontend
- Redesenhar shell fora do arquétipo (`.local/reference/nuxt-dashboard-template`)
- Inventar fallbacks sintéticos de monitoramento em erro de API
