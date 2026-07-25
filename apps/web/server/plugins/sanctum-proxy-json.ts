import type { FetchContext } from 'ofetch'

interface SanctumProxyHooks {
  hook: (
    name: 'sanctum:proxy:request',
    callback: (ctx: FetchContext) => void
  ) => () => void
}

// Force Accept: application/json on every Nuxt → Laravel Sanctum proxy hop.
// nuxt-auth-sanctum sets accept: application/json first, then spreads the
// browser headers — so a client Accept of "*/*" overwrites it. Fortify then
// returns 302, the proxy follows to GET /, and nginx can answer 403.
export default defineNitroPlugin((nitroApp) => {
  const hooks = nitroApp.hooks as unknown as SanctumProxyHooks

  hooks.hook('sanctum:proxy:request', (ctx) => {
    // ofetch normaliza ResolvedFetchOptions.headers para Headers antes do hook.
    ctx.options.headers.set('Accept', 'application/json')
  })
})
