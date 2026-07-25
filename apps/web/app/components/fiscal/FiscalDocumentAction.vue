<script setup lang="ts">
/**
 * Ação de evidência oficial: botão só com available=true e href do servidor.
 * Download via cliente Sanctum (sem navegar o path da API como rota SPA).
 */
import type { FiscalDocumentDescriptor } from '~/types/fiscal-modules'
import {
  documentActionVisible,
  documentUnavailableLabel
} from '~/types/fiscal-modules'
import { useAuthenticatedDownload } from '~/composables/useAuthenticatedDownload'
import { fiscalDocumentDownloadFilename } from '~/utils/authenticated-download'

const props = withDefaults(defineProps<{
  document?: FiscalDocumentDescriptor | null
  /**
   * Quando true e o documento está indisponível com motivo público,
   * exibe texto muted (nunca botão). Superfícies NEVER devem deixar false.
   */
  showUnavailable?: boolean
  /** Exibe tipo, fonte e observação fornecidos pelo descriptor. */
  showMetadata?: boolean
  size?: 'xs' | 'sm' | 'md'
  /** Força ocultar (ex.: submódulo MIT, surface allows_document=false). */
  disabled?: boolean
}>(), {
  document: null,
  showUnavailable: false,
  showMetadata: false,
  size: 'xs',
  disabled: false
})

const { download: downloadAuthenticated, downloading } = useAuthenticatedDownload()

const visible = computed(() =>
  !props.disabled && documentActionVisible(props.document)
)

const label = computed(() => {
  const raw = props.document?.label
  if (typeof raw === 'string' && raw.trim()) return raw.trim()
  return 'Ver/Baixar documento oficial'
})

const unavailableText = computed(() => {
  if (!props.showUnavailable || props.disabled || visible.value) return null
  return documentUnavailableLabel(props.document?.unavailable_reason)
})

const href = computed(() => {
  if (!visible.value) return null
  const h = props.document?.href
  return typeof h === 'string' && h.trim() ? h.trim() : null
})

const filename = computed(() =>
  fiscalDocumentDownloadFilename({
    label: props.document?.label,
    kind: props.document?.kind
  })
)

const metadata = computed(() => {
  if (!visible.value || !props.showMetadata) return null
  const parts = [props.document?.kind, props.document?.source_label]
  if (props.document?.observed_at) {
    parts.push(`observado em ${formatDateTime(props.document.observed_at)}`)
  }
  return parts.filter((part): part is string => typeof part === 'string' && part.trim() !== '')
    .join(' · ')
})

async function onDownload() {
  if (!href.value || downloading.value) return
  await downloadAuthenticated(href.value, filename.value)
}
</script>

<template>
  <UButton
    v-if="visible && href"
    :size="size"
    color="primary"
    variant="ghost"
    icon="i-lucide-file-down"
    :label="label"
    :loading="downloading"
    :disabled="downloading"
    data-testid="fiscal-document-action"
    @click="onDownload"
  />
  <span
    v-if="visible && metadata"
    class="text-xs text-muted"
    data-testid="fiscal-document-metadata"
  >
    {{ metadata }}
  </span>
  <span
    v-else-if="unavailableText"
    class="text-xs text-muted"
    data-testid="fiscal-document-unavailable"
  >
    {{ unavailableText }}
  </span>
</template>
