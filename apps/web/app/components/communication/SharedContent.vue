<script setup lang="ts">
import type { SharedContentCategory, SharedContentItem } from '~/types/communication/shared-content'
import type { MediaViewerItem } from '~/types/communication/media'
import { apiErrorMessage } from '~/utils/api-error'
import { resolveApiUrl } from '~/utils/api-url'

const props = defineProps<{
  conversationId?: number | null
  contactId?: number | null
  inboxId?: number | null
  compact?: boolean
}>()

const emit = defineEmits<{
  jump: [input: { conversationId: number, messageId: number }]
  expand: []
}>()

const api = useApi()
const download = useAuthenticatedDownload()
const apiBase = String(useRuntimeConfig().public.apiBase || '')
const category = ref<SharedContentCategory>('media')
const items = ref<SharedContentItem[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
const loaded = ref(false)
const error = ref<string | null>(null)
const expanded = ref(false)
const viewerOpen = ref(false)
const viewerIndex = ref(0)
let requestEpoch = 0

const tabs = [
  { label: 'Mídias', value: 'media', icon: 'i-lucide-images' },
  { label: 'Links', value: 'links', icon: 'i-lucide-link-2' },
  { label: 'Documentos', value: 'documents', icon: 'i-lucide-file-text' }
] satisfies Array<{ label: string, value: SharedContentCategory, icon: string }>

const megabyteFormatter = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 })

const mediaItems = computed(() => items.value.filter(item => item.attachment))
const viewerItems = computed<MediaViewerItem[]>(() => mediaItems.value.flatMap((item) => {
  if (!item.attachment) return []
  return [{
    id: item.id,
    conversationId: item.conversation_id,
    messageId: item.message_id,
    attachment: item.attachment
  }]
}))
const effectiveCompact = computed(() => Boolean(props.compact) && !expanded.value)

function mediaUrl(url: string | null | undefined): string | undefined {
  return url ? resolveApiUrl(url, apiBase) : undefined
}

function reset() {
  requestEpoch++
  items.value = []
  cursor.value = null
  loaded.value = false
  error.value = null
}

async function load(more = false) {
  if (!props.conversationId && !props.contactId) return
  const epoch = ++requestEpoch
  const conversationId = props.conversationId
  const contactId = props.contactId
  const inboxId = props.inboxId
  const requestedCategory = category.value
  const requestedCursor = more ? cursor.value : null
  loading.value = true
  error.value = null
  try {
    const params = {
      category: requestedCategory,
      limit: effectiveCompact.value ? 6 : 30,
      ...(more && requestedCursor ? { cursor: requestedCursor } : {}),
      ...(contactId && inboxId ? { inbox_id: inboxId } : {})
    }
    const response = conversationId
      ? await api.communication.conversations.sharedContent(conversationId, params)
      : await api.communication.contacts.sharedContent(contactId!, params)
    if (epoch !== requestEpoch) return
    items.value = more ? [...items.value, ...response.data] : response.data
    cursor.value = response.meta.next_cursor
    loaded.value = true
  } catch (caught) {
    if (epoch !== requestEpoch) return
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o conteúdo compartilhado.')
  } finally {
    if (epoch === requestEpoch) loading.value = false
  }
}

watch(category, () => {
  reset()
  void load()
})
watch(() => [props.conversationId, props.contactId, props.inboxId], () => {
  reset()
  void load()
}, { immediate: true })

function open(item: SharedContentItem) {
  if (!item.attachment) return
  viewerIndex.value = Math.max(0, viewerItems.value.findIndex(viewerItem => viewerItem.id === item.id))
  viewerOpen.value = true
}

function jump(item: SharedContentItem) {
  viewerOpen.value = false
  emit('jump', { conversationId: item.conversation_id, messageId: item.message_id })
}

function jumpFromViewer(item: MediaViewerItem) {
  viewerOpen.value = false
  emit('jump', { conversationId: item.conversationId, messageId: item.messageId })
}

function showAll() {
  expanded.value = true
  reset()
  void load()
  emit('expand')
}

function collapse() {
  expanded.value = false
  reset()
  void load()
}

function downloadAttachment(item: SharedContentItem) {
  if (!item.attachment?.download_url) return
  void download.download(item.attachment.download_url, item.attachment.filename)
}

function downloadViewerItem(item: MediaViewerItem) {
  if (!item.attachment.download_url) return
  void download.download(item.attachment.download_url, item.attachment.filename)
}

function displaySize(bytes?: number) {
  if (!bytes) return ''
  return bytes < 1024 * 1024
    ? `${Math.ceil(bytes / 1024)} KB`
    : `${megabyteFormatter.format(bytes / 1024 / 1024)} MB`
}
</script>

<template>
  <section class="space-y-3" data-testid="communication-shared-content">
    <div class="flex items-center justify-between gap-2">
      <div class="flex min-w-0 items-center gap-1">
        <UButton
          v-if="compact && expanded"
          size="xs"
          color="neutral"
          variant="ghost"
          icon="i-lucide-arrow-left"
          aria-label="Voltar à prévia"
          @click="collapse"
        />
        <h3 class="truncate text-sm font-semibold text-highlighted">
          Mídias, links e documentos
        </h3>
      </div>
      <UButton
        v-if="effectiveCompact"
        size="xs"
        color="neutral"
        variant="link"
        label="Ver tudo"
        @click="showAll"
      />
    </div>
    <UTabs
      v-model="category"
      :items="tabs"
      :content="false"
      size="xs"
    />
    <div v-if="loading && !loaded" class="grid grid-cols-3 gap-2" aria-label="Carregando conteúdo compartilhado">
      <USkeleton v-for="item in 3" :key="item" class="aspect-square rounded-lg" />
    </div>
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :title="error"
    >
      <template #actions>
        <UButton
          size="xs"
          color="neutral"
          variant="outline"
          label="Tentar novamente"
          @click="load()"
        />
      </template>
    </UAlert>
    <p v-if="loaded && !items.length && !error" class="text-sm text-muted">
      Nenhum item visível nesta categoria.
    </p>
    <template v-if="items.length">
      <div v-if="category === 'media'" class="grid grid-cols-3 gap-2">
        <button
          v-for="item in items"
          :key="item.id"
          type="button"
          class="group relative aspect-square overflow-hidden rounded-lg bg-elevated ring-primary focus:outline-none focus-visible:ring-2"
          :aria-label="`Abrir ${item.attachment?.filename || 'mídia'}`"
          @click="open(item)"
        >
          <img
            v-if="item.attachment?.mime_type.startsWith('image/')"
            :src="mediaUrl(item.attachment.preview_url || item.attachment.download_url)"
            :alt="item.attachment.filename"
            class="size-full object-cover"
          >
          <div v-else class="flex size-full flex-col items-center justify-center gap-1 text-muted">
            <UIcon :name="item.attachment?.mime_type.startsWith('video/') ? 'i-lucide-video' : 'i-lucide-audio-lines'" class="size-6" /><span class="max-w-full truncate px-1 text-xs">{{ item.attachment?.filename }}</span>
          </div>
        </button>
      </div>
      <ul v-else class="divide-y divide-default rounded-lg border border-default">
        <li v-for="item in items" :key="item.id" class="flex min-w-0 items-center gap-2 p-3">
          <UIcon :name="category === 'links' ? 'i-lucide-link-2' : 'i-lucide-file-text'" class="size-4 shrink-0 text-muted" />
          <a
            v-if="category === 'links' && item.link"
            :href="item.link.url"
            target="_blank"
            rel="noopener noreferrer"
            class="min-w-0 flex-1 truncate text-sm text-primary underline-offset-2 hover:underline"
          >{{ item.link.url }}</a>
          <span v-else class="min-w-0 flex-1 truncate text-sm">{{ item.attachment?.filename }} <span class="text-muted">{{ displaySize(item.attachment?.size_bytes) }}</span></span>
          <UButton
            v-if="item.attachment?.download_url"
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-download"
            :aria-label="`Baixar ${item.attachment.filename}`"
            @click="downloadAttachment(item)"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-message-square"
            aria-label="Ir para mensagem"
            @click="jump(item)"
          />
        </li>
      </ul>
      <UButton
        v-if="cursor && !effectiveCompact"
        block
        color="neutral"
        variant="outline"
        :loading="loading"
        label="Carregar mais"
        @click="load(true)"
      />
    </template>
  </section>

  <CommunicationMediaViewer
    v-model:open="viewerOpen"
    v-model:index="viewerIndex"
    :items="viewerItems"
    image-test-id="communication-shared-content-viewer-image"
    @download="downloadViewerItem"
    @jump="jumpFromViewer"
  />
</template>
