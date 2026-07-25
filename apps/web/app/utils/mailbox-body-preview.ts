import { looksLikeJsonErrorBlobText } from '~/utils/authenticated-download'

export type MailboxBodyPreviewResult
  = | { ok: true, text: string, contentType: string }
    | { ok: false, error: string }

/**
 * Interpreta blob do endpoint de corpo para preview textual no painel.
 * HTML é tratado como texto (sem innerHTML); JSON de erro vira falha.
 */
export async function parseMailboxBodyPreviewBlob(
  blob: Blob,
  fallbackError = 'Corpo indisponível.'
): Promise<MailboxBodyPreviewResult> {
  const contentType = String(blob.type || 'text/plain')
  const text = await blob.text()
  if (looksLikeJsonErrorBlobText(text)) {
    try {
      const err = JSON.parse(text) as { message?: string }
      return { ok: false, error: err?.message || fallbackError }
    } catch {
      return { ok: false, error: fallbackError }
    }
  }
  if (!text.trim()) {
    return { ok: false, error: 'Corpo vazio.' }
  }
  return { ok: true, text, contentType }
}
