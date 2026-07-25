import { apiErrorMessage } from '~/utils/api-error'
import {
  looksLikeJsonErrorBlobText,
  toSanctumApiPath
} from '~/utils/authenticated-download'

/**
 * Download de artefatos/API via cliente Sanctum (cookie + proxy),
 * evitando navegação top-level unauthenticated em `/api/sanctum/...`.
 */
export function useAuthenticatedDownload() {
  const sanctum = useSanctumClient()
  const toast = useToast()
  const apiBase = String(useRuntimeConfig().public.apiBase || '').replace(/\/$/, '')
  const downloading = ref(false)

  async function download(
    urlOrPath: string,
    filename: string,
    options?: { silentToast?: boolean }
  ): Promise<boolean> {
    const path = toSanctumApiPath(urlOrPath, apiBase)
    if (!path || downloading.value) return false

    downloading.value = true
    try {
      const blob = await sanctum<Blob>(path, {
        method: 'GET',
        // Sanctum client tipa responseType como json; blob é suportado em runtime.
        responseType: 'blob' as 'json',
        headers: {
          Accept: 'application/pdf, application/octet-stream, */*'
        }
      })

      if (!(blob instanceof Blob)) {
        if (!options?.silentToast) {
          toast.add({ title: 'Resposta inválida ao baixar o arquivo', color: 'error' })
        }
        return false
      }

      const head = await blob.slice(0, 64).text()
      if (looksLikeJsonErrorBlobText(head)) {
        let msg = 'Arquivo indisponível.'
        try {
          const err = JSON.parse(await blob.text()) as { message?: string }
          if (err?.message) msg = err.message
        } catch {
          // ignore
        }
        if (!options?.silentToast) {
          toast.add({ title: msg, color: 'error' })
        }
        return false
      }

      const objectUrl = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = filename || 'download.bin'
      anchor.rel = 'noopener'
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      URL.revokeObjectURL(objectUrl)
      return true
    } catch (caught: unknown) {
      if (!options?.silentToast) {
        toast.add({
          title: apiErrorMessage(caught, 'Falha ao baixar. Verifique a sessão e tente de novo.'),
          color: 'error'
        })
      }
      return false
    } finally {
      downloading.value = false
    }
  }

  return {
    downloading: readonly(downloading),
    download,
    toSanctumApiPath: (urlOrPath: string) => toSanctumApiPath(urlOrPath, apiBase)
  }
}
