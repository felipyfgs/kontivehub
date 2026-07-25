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

## Never

- Chamar sidecar MEI direto do frontend
- Redesenhar shell fora do arquétipo (`.local/reference/nuxt-dashboard-template`)
- Inventar fallbacks sintéticos de monitoramento em erro de API
