<script setup lang="ts">
import { usePreferredReducedMotion } from '@vueuse/core'
import type { MediaViewerItem } from '~/types/communication/media'
import { resolveApiUrl } from '~/utils/api-url'

const props = withDefaults(defineProps<{
  items: MediaViewerItem[]
  imageTestId?: string
  showJump?: boolean
}>(), {
  showJump: true
})

const emit = defineEmits<{
  download: [item: MediaViewerItem]
  jump: [item: MediaViewerItem]
  played: [item: MediaViewerItem]
}>()

const open = defineModel<boolean>('open', { default: false })
const index = defineModel<number>('index', { default: 0 })
const apiBase = String(useRuntimeConfig().public.apiBase || '')
const reducedMotion = usePreferredReducedMotion()
const zoom = ref(1)
const rotation = ref(0)

const current = computed(() => props.items[index.value] ?? null)
const isImage = computed(() => current.value?.attachment.mime_type.startsWith('image/') ?? false)
const isVideo = computed(() => current.value?.attachment.mime_type.startsWith('video/') ?? false)

function mediaUrl(url?: string | null): string | undefined {
  return url ? resolveApiUrl(url, apiBase) : undefined
}

function resetTransform(): void {
  zoom.value = 1
  rotation.value = 0
}

function zoomOut(): void {
  zoom.value = Math.max(0.5, zoom.value - 0.25)
}

function zoomIn(): void {
  zoom.value = Math.min(3, zoom.value + 0.25)
}

function rotateImage(): void {
  rotation.value = (rotation.value + 90) % 360
}

function toggleZoom(): void {
  zoom.value = zoom.value === 1 ? 1.5 : 1
}

function previous(): void {
  if (props.items.length < 2) return
  index.value = (index.value - 1 + props.items.length) % props.items.length
}

function next(): void {
  if (props.items.length < 2) return
  index.value = (index.value + 1) % props.items.length
}

function close(): void {
  open.value = false
}

function onKeydown(event: KeyboardEvent): void {
  if (!open.value || event.altKey || event.ctrlKey || event.metaKey) return
  const target = event.target
  if (target instanceof HTMLElement && (
    target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT', 'AUDIO', 'VIDEO'].includes(target.tagName)
  )) return
  if (event.key === 'ArrowLeft') {
    event.preventDefault()
    previous()
  } else if (event.key === 'ArrowRight') {
    event.preventDefault()
    next()
  } else if (event.key === 'Escape') {
    close()
  } else if (isImage.value && (event.key === '+' || event.key === '=')) {
    event.preventDefault()
    zoom.value = Math.min(3, zoom.value + 0.25)
  } else if (isImage.value && event.key === '-') {
    event.preventDefault()
    zoom.value = Math.max(0.5, zoom.value - 0.25)
  } else if (isImage.value && event.key.toLowerCase() === 'r') {
    event.preventDefault()
    rotation.value = (rotation.value + 90) % 360
  }
}

watch([index, () => props.items.length], ([nextIndex, length]) => {
  resetTransform()
  if (!length) {
    index.value = 0
    close()
    return
  }
  index.value = Math.min(Math.max(nextIndex, 0), length - 1)
}, { immediate: true })
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <UModal
    v-model:open="open"
    fullscreen
    :title="current?.attachment.filename || 'Visualizador de mídia'"
    :description="current ? `${index + 1} de ${items.length}` : undefined"
    data-testid="communication-media-viewer"
    :ui="{ body: 'flex min-h-0 flex-1 flex-col p-0 sm:p-0' }"
  >
    <template #body>
      <div v-if="current" class="flex min-h-0 flex-1 flex-col bg-default">
        <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-auto bg-elevated/50 p-3 sm:p-6">
          <img
            v-if="isImage"
            :src="mediaUrl(current.attachment.preview_url || current.attachment.download_url)"
            :alt="current.attachment.filename"
            :data-testid="imageTestId || 'communication-media-viewer-image'"
            class="max-h-full max-w-full object-contain will-change-transform"
            :class="reducedMotion === 'reduce' ? '' : 'transition-transform duration-200 ease-out'"
            :style="{ transform: `scale(${zoom}) rotate(${rotation}deg)` }"
            @dblclick="toggleZoom"
          >
          <video
            v-else-if="isVideo"
            :src="mediaUrl(current.attachment.preview_url || current.attachment.download_url)"
            controls
            playsinline
            preload="metadata"
            data-testid="communication-media-viewer-video"
            class="max-h-full max-w-full"
          />
          <audio
            v-else
            :src="mediaUrl(current.attachment.preview_url || current.attachment.download_url)"
            controls
            preload="metadata"
            data-testid="communication-media-viewer-audio"
            class="w-full max-w-2xl"
            @play="emit('played', current)"
          />

          <UButton
            v-if="items.length > 1"
            icon="i-lucide-chevron-left"
            color="neutral"
            variant="solid"
            aria-label="Mídia anterior"
            class="absolute left-3 top-1/2 -translate-y-1/2 sm:left-6"
            @click="previous"
          />
          <UButton
            v-if="items.length > 1"
            icon="i-lucide-chevron-right"
            color="neutral"
            variant="solid"
            aria-label="Próxima mídia"
            class="absolute right-3 top-1/2 -translate-y-1/2 sm:right-6"
            @click="next"
          />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-default px-3 py-2 sm:px-6 sm:py-3">
          <div class="flex items-center gap-2 text-sm text-muted">
            <span class="tabular-nums">{{ index + 1 }} de {{ items.length }}</span>
            <span aria-hidden="true">·</span>
            <span class="max-w-48 truncate sm:max-w-md">{{ current.attachment.filename }}</span>
          </div>
          <div class="flex flex-wrap items-center justify-end gap-1">
            <template v-if="isImage">
              <UButton
                icon="i-lucide-zoom-out"
                color="neutral"
                variant="ghost"
                aria-label="Reduzir imagem"
                :disabled="zoom <= 0.5"
                @click="zoomOut"
              />
              <UButton
                icon="i-lucide-rotate-cw"
                color="neutral"
                variant="ghost"
                aria-label="Girar imagem"
                @click="rotateImage"
              />
              <UButton
                icon="i-lucide-zoom-in"
                color="neutral"
                variant="ghost"
                aria-label="Ampliar imagem"
                :disabled="zoom >= 3"
                @click="zoomIn"
              />
            </template>
            <UButton
              v-if="current.attachment.download_url"
              icon="i-lucide-download"
              label="Baixar"
              color="neutral"
              variant="outline"
              @click="emit('download', current)"
            />
            <UButton
              v-if="showJump !== false"
              icon="i-lucide-message-square"
              label="Ir para mensagem"
              color="neutral"
              variant="outline"
              @click="emit('jump', current)"
            />
            <UButton
              icon="i-lucide-x"
              color="neutral"
              variant="ghost"
              aria-label="Fechar visualizador"
              @click="close"
            />
          </div>
        </div>
      </div>
    </template>
  </UModal>
</template>
