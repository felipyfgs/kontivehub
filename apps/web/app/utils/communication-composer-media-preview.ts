import type { ComposerMediaItem, ComposerMediaKind } from '~/types/communication/composer-draft'

export function composerMediaPreviewKind(kind: ComposerMediaKind): 'image' | 'video' | 'document' {
  if (kind === 'IMAGE') return 'image'
  if (kind === 'VIDEO') return 'video'
  return 'document'
}

export function syncComposerMediaPreviewUrls(
  items: readonly ComposerMediaItem[],
  previous: ReadonlyMap<string, string>,
  createObjectURL: (value: Blob) => string = URL.createObjectURL.bind(URL),
  revokeObjectURL: (url: string) => void = URL.revokeObjectURL.bind(URL)
): Map<string, string> {
  const next = new Map<string, string>()
  for (const item of items) {
    if (item.kind === 'DOCUMENT') continue
    const existing = previous.get(item.clientItemId)
    if (existing) {
      next.set(item.clientItemId, existing)
      continue
    }
    next.set(item.clientItemId, createObjectURL(item.file))
  }
  for (const [clientItemId, url] of previous) {
    if (!next.has(clientItemId)) revokeObjectURL(url)
  }
  return next
}

export function revokeComposerMediaPreviewUrls(
  urls: ReadonlyMap<string, string>,
  revokeObjectURL: (url: string) => void = URL.revokeObjectURL.bind(URL)
): void {
  for (const url of urls.values()) revokeObjectURL(url)
}
