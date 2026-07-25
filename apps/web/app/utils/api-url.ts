/**
 * Prefixa paths relativos da API com `runtimeConfig.public.apiBase`.
 *
 * Em `make dev` (NUXT_SANCTUM_PROXY) o base é `/api/sanctum`; em same-origin
 * (nginx :8080 / generate) fica vazio. Sem o prefixo, `<a href="/api/v1/...">`
 * cai no Nuxt HMR (:3000) e o Vue Router trata como página — "Page not found".
 */
export function resolveApiUrl(path: string, apiBase = ''): string {
  const base = String(apiBase || '').replace(/\/$/, '')
  const trimmed = String(path || '').trim()
  if (!trimmed) {
    return ''
  }
  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed
  }
  if (base && (trimmed === base || trimmed.startsWith(`${base}/`))) {
    return trimmed
  }
  if (trimmed.startsWith('/')) {
    return `${base}${trimmed}`
  }
  return trimmed
}
