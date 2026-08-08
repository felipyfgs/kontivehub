export interface ComposerEmoji {
  value: string
  label: string
  keywords: readonly string[]
}

export type ComposerExpressionTab = 'EMOJI' | 'GIF' | 'STICKER'

export interface ComposerExpressionCapabilities {
  emoji?: boolean
  gif?: boolean
  gifProviderSearch?: boolean
  sticker?: boolean
}

/** Curated Unicode expressions keep the initial picker small and do not load remote assets. */
export const COMPOSER_EMOJIS: readonly ComposerEmoji[] = [
  { value: '😀', label: 'Sorrindo', keywords: ['sorriso', 'feliz', 'rosto'] },
  { value: '😂', label: 'Rindo', keywords: ['risada', 'feliz', 'rosto'] },
  { value: '😍', label: 'Apaixonado', keywords: ['amor', 'coração', 'rosto'] },
  { value: '👍', label: 'Gostei', keywords: ['ok', 'aprovar', 'positivo'] },
  { value: '🙏', label: 'Agradecimento', keywords: ['obrigado', 'prece'] },
  { value: '🎉', label: 'Comemoração', keywords: ['festa', 'sucesso'] },
  { value: '✅', label: 'Concluído', keywords: ['confirmar', 'feito', 'ok'] },
  { value: '👀', label: 'Observando', keywords: ['olhos', 'verificar'] },
  { value: '🚀', label: 'Lançamento', keywords: ['rápido', 'progresso'] },
  { value: '💡', label: 'Ideia', keywords: ['sugestão', 'luz'] },
  { value: '📌', label: 'Destaque', keywords: ['fixar', 'importante'] },
  { value: '🤝', label: 'Acordo', keywords: ['parceria', 'combinar'] }
]

function normalized(value: string): string {
  return value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLocaleLowerCase('pt-BR')
}

export function searchComposerEmojis(query: string, emojis: readonly ComposerEmoji[] = COMPOSER_EMOJIS): ComposerEmoji[] {
  const term = normalized(query.trim())
  if (!term) return [...emojis]
  return emojis.filter(emoji => normalized([emoji.value, emoji.label, ...emoji.keywords].join(' ')).includes(term))
}

export function insertComposerExpression(value: string, expression: string, selectionStart: number, selectionEnd: number) {
  const start = Math.max(0, Math.min(selectionStart, value.length))
  const end = Math.max(start, Math.min(selectionEnd, value.length))
  const nextValue = `${value.slice(0, start)}${expression}${value.slice(end)}`
  const caret = start + expression.length
  return { value: nextValue, selectionStart: caret, selectionEnd: caret }
}

/** Session-only recents: intentionally no localStorage/sessionStorage persistence. */
export function createRecentComposerExpressions(limit = 16) {
  let values: string[] = []
  return {
    all: () => [...values],
    add: (expression: string) => {
      values = [expression, ...values.filter(value => value !== expression)].slice(0, limit)
      return [...values]
    },
    clear: () => { values = [] }
  }
}

export function isPrivateGifPreviewPath(path: string): boolean {
  return /^\/api\/v1\/communication\/gifs\/[A-Za-z0-9]{40}\/preview$/.test(path)
}

export function isPrivateGifAssetPath(path: string): boolean {
  return /^\/api\/v1\/communication\/gifs\/[A-Za-z0-9]{40}\/asset$/.test(path)
}

export function isPrivateGifResult(result: { preview_path: string, asset_path: string, asset_token: string }): boolean {
  const preview = /^\/api\/v1\/communication\/gifs\/([A-Za-z0-9]{40})\/preview$/.exec(result.preview_path)
  const asset = /^\/api\/v1\/communication\/gifs\/([A-Za-z0-9]{40})\/asset$/.exec(result.asset_path)
  return preview !== null
    && asset !== null
    && preview[1] === asset[1]
    && result.asset_token === preview[1]
}

export function availableComposerExpressionTabs(capabilities: ComposerExpressionCapabilities): ComposerExpressionTab[] {
  return [
    ...(capabilities.emoji !== false ? ['EMOJI' as const] : []),
    ...(capabilities.gif ? ['GIF' as const] : []),
    ...(capabilities.sticker ? ['STICKER' as const] : [])
  ]
}

export function resolveComposerExpressionTab(
  current: ComposerExpressionTab,
  capabilities: ComposerExpressionCapabilities
): ComposerExpressionTab | null {
  const tabs = availableComposerExpressionTabs(capabilities)
  return tabs.includes(current) ? current : tabs[0] ?? null
}
