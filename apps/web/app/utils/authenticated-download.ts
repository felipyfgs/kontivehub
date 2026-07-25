/**
 * Normaliza URL/path de download para o path canônico do cliente Sanctum.
 * Em `make dev`, `apiBase` é `/api/sanctum` — o client já usa esse baseUrl.
 */
export function toSanctumApiPath(urlOrPath: string, apiBase = ''): string {
  const trimmed = String(urlOrPath || '').trim()
  if (!trimmed) return ''

  let path = trimmed
  try {
    if (/^https?:\/\//i.test(trimmed)) {
      path = new URL(trimmed).pathname
    }
  } catch {
    // mantém trimmed
  }

  const base = String(apiBase || '').replace(/\/$/, '')
  if (base && (path === base || path.startsWith(`${base}/`))) {
    path = path.slice(base.length) || '/'
  }

  if (!path.startsWith('/')) {
    path = `/${path}`
  }

  return path
}

export function looksLikeJsonErrorBlobText(head: string): boolean {
  const trimmed = head.trimStart()
  return trimmed.startsWith('{') || trimmed.startsWith('[')
}

/**
 * Filename local para save do descriptor fiscal (label/kind → slug.pdf).
 * Content-Disposition da API prevalece se o browser o respeitar no blob.
 */
export function fiscalDocumentDownloadFilename(options?: {
  label?: string | null
  kind?: string | null
}): string {
  const label = typeof options?.label === 'string' ? options.label.trim() : ''
  if (label) {
    const slug = label
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 80)
    if (slug) return `${slug}.pdf`
  }

  const kind = String(options?.kind || 'documento')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/gi, '-')
    .replace(/^-+|-+$/g, '')

  return `${kind || 'documento'}.pdf`
}
