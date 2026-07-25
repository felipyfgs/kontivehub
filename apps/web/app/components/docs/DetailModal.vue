<script setup lang="ts">
/**
 * Modal de detalhe do documento fiscal.
 *
 * Anatomia (skill modal + ClientDetailModal):
 *  title / description / #actions
 *  #body → layout tipo nota (DocsDetail)
 *  #footer → copiar · baixar XML · fechar
 */
import type { NfseNote } from '~/types/api'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  accessKey: string | null
  preview?: NfseNote | null
}>()

const sanctum = useSanctumClient()
const toast = useToast()
const downloading = ref(false)
/** Preenchido pelo GET de detalhe quando a lista filtrada não tem preview. */
const resolvedNote = ref<NfseNote | null>(null)

watch(
  () => props.accessKey,
  () => {
    resolvedNote.value = null
  }
)

watch(
  () => props.preview,
  (preview) => {
    if (preview?.access_key === props.accessKey) {
      resolvedNote.value = preview
    }
  },
  { immediate: true }
)

const displayNote = computed(() => {
  if (props.preview?.access_key === props.accessKey) return props.preview
  if (resolvedNote.value?.access_key === props.accessKey) return resolvedNote.value
  return null
})

const title = computed(() => {
  const n = displayNote.value
  if (n?.number) return `${n.kind_label || documentKindLabel(n.kind)} nº ${n.number}`
  const key = props.accessKey
  if (!key) return 'Detalhe do documento'
  if (key.length <= 22) return key
  return `${key.slice(0, 10)}…${key.slice(-8)}`
})

const description = computed(() => {
  const n = displayNote.value
  if (!n) return 'Documento fiscal eletrônico'
  const parts = [
    n.kind_label || documentKindLabel(n.kind),
    n.competence ? `Competência ${n.competence}` : null,
    n.fiscal_role ? statusLabel(n.fiscal_role) : null,
    n.xml_completeness === 'SUMMARY_ONLY' || n.is_summary ? 'somente resumo' : null
  ].filter(Boolean)
  return parts.length ? parts.join(' · ') : 'Documento fiscal eletrônico'
})

function onDetailLoaded(note: NfseNote) {
  if (note.access_key === props.accessKey) {
    resolvedNote.value = note
  }
}

async function copyAccessKey() {
  const key = (props.accessKey || '').trim()
  if (!key) return
  try {
    await navigator.clipboard.writeText(key)
    toast.add({ title: 'Chave copiada', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível copiar a chave', color: 'error' })
  }
}

/**
 * Download via client Sanctum (mesmo baseUrl/proxy das demais APIs) + blob .xml.
 * Não usa fetch(apiUrl) cru — evita path errado e salva sempre como .xml.
 */
async function downloadXml() {
  const key = (props.accessKey || '').trim()
  if (!key || downloading.value) return

  downloading.value = true
  try {
    const blob = await sanctum<Blob>(
      `/api/v1/documents/${encodeURIComponent(key)}/xml`,
      {
        method: 'GET',
        // Sanctum client tipa responseType como json por padrão; blob é suportado em runtime.
        responseType: 'blob' as 'json',
        headers: {
          Accept: 'application/xml, text/xml, application/octet-stream, */*'
        }
      }
    )

    if (!(blob instanceof Blob)) {
      toast.add({ title: 'Resposta inválida ao baixar XML', color: 'error' })
      return
    }

    // Rejeita corpo JSON de erro (ex.: 422 serializado como blob)
    const head = await blob.slice(0, 64).text()
    const trimmed = head.trimStart()
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
      let msg = 'XML indisponível no cofre.'
      try {
        const err = JSON.parse(await blob.text()) as { message?: string }
        if (err?.message) msg = err.message
      } catch {
        // ignore
      }
      toast.add({ title: msg, color: 'error' })
      return
    }

    const filename = `${key}.xml`
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.rel = 'noopener'
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
    toast.add({ title: 'XML baixado', color: 'success' })
  } catch (caught: unknown) {
    const msg = apiErrorMessage(caught, 'Falha ao baixar o XML. Verifique a sessão e tente de novo.')
    toast.add({ title: msg, color: 'error' })
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <ShellScrollableModal
    v-model:open="open"
    test-id="note-detail-modal"
    :title="title"
    :description="description"
    content-class="w-[calc(100vw-1.5rem)] sm:w-[min(42rem,calc(100vw-2rem))] lg:w-[min(48rem,calc(100vw-2rem))] sm:max-w-none h-[min(92dvh,52rem)] max-h-[min(92dvh,52rem)] overflow-hidden"
    :show-default-footer="false"
    @cancel="() => { open = false }"
  >
    <template #actions>
      <div
        v-if="displayNote"
        class="me-6 flex flex-wrap items-center gap-1.5"
      >
        <ShellStatusBadge
          v-if="displayNote.status"
          :status="displayNote.status"
          :label="displayNote.status_label"
        />
        <UBadge
          v-if="displayNote.service_amount != null"
          color="neutral"
          variant="subtle"
          class="tabular-nums"
          size="sm"
        >
          {{ formatCurrency(displayNote.service_amount) }}
        </UBadge>
      </div>
    </template>

    <template #body>
      <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
        <DocsDetail
          v-if="accessKey"
          :access-key="accessKey"
          :preview="preview"
          embedded
          @loaded="onDetailLoaded"
        />
      </div>
    </template>

    <template #footer>
      <div class="flex w-full flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-muted">
          Download do XML é auditado
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <UButton
            v-if="accessKey"
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-copy"
            label="Copiar chave"
            @click="copyAccessKey"
          />
          <UButton
            v-if="accessKey"
            color="primary"
            variant="soft"
            size="sm"
            icon="i-lucide-download"
            label="Baixar XML"
            :loading="downloading"
            :disabled="downloading"
            @click="downloadXml"
          />
          <UButton
            color="neutral"
            variant="subtle"
            size="sm"
            label="Fechar"
            @click="() => { open = false }"
          />
        </div>
      </div>
    </template>
  </ShellScrollableModal>
</template>
