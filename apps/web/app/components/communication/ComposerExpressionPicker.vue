<script setup lang="ts">
import {
  availableComposerExpressionTabs,
  isPrivateGifResult,
  resolveComposerExpressionTab,
  type ComposerExpressionTab
} from '~/utils/communication-expression-picker'
import type {
  StickerLibraryFilter,
  StickerLibraryItem,
  StickerLibraryView
} from '~/types/communication/sticker-library'

type ReadonlyStickerLibraryView = Omit<Readonly<StickerLibraryView>, 'items'> & {
  readonly items: readonly StickerLibraryItem[]
}

export interface ComposerGifResult {
  id: string
  title: string
  /** Relative Laravel preview route only; provider URLs are deliberately rejected. */
  preview_path: string
  /** Relative Laravel asset route only; fetched through the authenticated API client before upload. */
  asset_path: string
  /** Binds preview and private asset routes to the same Laravel search result. */
  asset_token: string
}

export type { ComposerExpressionCapabilities } from '~/utils/communication-expression-picker'

const props = withDefaults(defineProps<{
  open?: boolean
  capabilities?: import('~/utils/communication-expression-picker').ComposerExpressionCapabilities
  stickerViews?: Readonly<Record<StickerLibraryFilter, ReadonlyStickerLibraryView>>
  stickerPreviewUrls?: ReadonlyMap<string, string>
  stickerMutatingIds?: ReadonlySet<string>
  stickerImporting?: boolean
  stickerImportError?: string | null
  selectionError?: string | null
  selectionLoadingId?: string | null
  /** Laravel-backed callback supplied by the composer integration; never a provider URL. */
  searchGifs?: (query: string) => Promise<readonly ComposerGifResult[]>
}>(), {
  open: true,
  capabilities: () => ({ emoji: true }),
  stickerPreviewUrls: () => new Map(),
  stickerMutatingIds: () => new Set(),
  stickerImporting: false,
  stickerImportError: null,
  selectionError: null,
  selectionLoadingId: null
})

const emit = defineEmits<{
  close: []
  selectEmoji: [emoji: string]
  selectGif: [gif: ComposerGifResult]
  selectSticker: [sticker: StickerLibraryItem]
  selectLocalGif: [file: File]
  selectLocalSticker: [file: File]
  importSticker: [file: File]
  loadStickers: [filter: StickerLibraryFilter, append?: boolean]
  toggleStickerFavorite: [sticker: StickerLibraryItem]
}>()

const colorMode = useColorMode()
const emojiTheme = computed(() => colorMode.value === 'dark' ? 'dark' : 'light')
const emojiPickerContainer = ref<HTMLElement | null>(null)
const localGifInput = ref<HTMLInputElement | null>(null)
const localStickerInput = ref<HTMLInputElement | null>(null)
const libraryImportInput = ref<HTMLInputElement | null>(null)
const searchInput = ref<{ inputRef?: HTMLInputElement | null } | null>(null)
const pickerRoot = ref<HTMLElement | null>(null)
const query = ref('')
const tab = ref<ComposerExpressionTab>('EMOJI')
const stickerViewFilter = ref<StickerLibraryFilter>('recent')
const gifResults = ref<readonly ComposerGifResult[]>([])
const gifLoading = ref(false)
const gifError = ref<string | null>(null)

const hasMultipleTabs = computed(() => availableComposerExpressionTabs(props.capabilities).length > 1)
const tabs = computed(() => availableComposerExpressionTabs(props.capabilities).map(id => ({
  id,
  label: id === 'EMOJI' ? 'Emoji' : id === 'GIF' ? 'GIF' : 'Figurinhas'
})))
const privateGifResults = computed(() => gifResults.value.filter(isPrivateGifResult))
const stickerView = computed(() => props.stickerViews?.[stickerViewFilter.value] ?? null)
const filteredStickers = computed(() => {
  const term = query.value.trim().toLocaleLowerCase('pt-BR')
  const stickers = stickerView.value?.items ?? []
  return term
    ? stickers.filter(sticker => stickerLabel(sticker).toLocaleLowerCase('pt-BR').includes(term))
    : stickers
})
const stickerAnnouncement = computed(() => {
  const view = stickerView.value
  if (!view) return ''
  if (view.status === 'loading') return 'Carregando biblioteca de figurinhas.'
  if (view.status === 'error' || view.status === 'unavailable') return view.reason ?? 'Biblioteca indisponível.'
  if (view.status === 'empty') return stickerViewFilter.value === 'recent'
    ? 'Nenhuma figurinha recente observada.'
    : 'Nenhuma figurinha favorita observada.'
  return `${view.items.length} figurinha${view.items.length === 1 ? '' : 's'} ${view.items.length === 1 ? 'disponível' : 'disponíveis'}. Sincronização parcial.`
})

let pickerInstance: HTMLElement | null = null

async function mountEmojiMart() {
  if (!emojiPickerContainer.value || pickerInstance) return

  const [{ Picker }, data, i18n] = await Promise.all([
    import('emoji-mart'),
    import('@emoji-mart/data').then(m => m.default ?? m),
    import('@emoji-mart/data/i18n/pt.json').then(m => m.default ?? m)
  ])

  if (!emojiPickerContainer.value) return

  pickerInstance = new Picker({
    data,
    i18n,
    onEmojiSelect: (emoji: { native: string }) => {
      emit('selectEmoji', emoji.native)
    },
    onClickOutside: () => {
      emit('close')
    },
    autoFocus: true,
    theme: emojiTheme.value,
    set: 'native',
    perLine: 8,
    emojiButtonSize: 36,
    emojiSize: 22,
    maxFrequentRows: 3,
    previewPosition: 'none',
    skinTonePosition: 'search',
    navPosition: 'bottom',
    searchPosition: 'sticky'
  }) as unknown as HTMLElement

  emojiPickerContainer.value.appendChild(pickerInstance)
}

function destroyEmojiMart() {
  if (pickerInstance && emojiPickerContainer.value?.contains(pickerInstance)) {
    emojiPickerContainer.value.removeChild(pickerInstance)
  }
  pickerInstance = null
}

function setTab(next: ComposerExpressionTab) {
  tab.value = next
  query.value = ''
  gifError.value = null
  if (next === 'EMOJI') {
    void nextTick(mountEmojiMart)
  } else {
    destroyEmojiMart()
    if (next === 'STICKER') ensureStickerViewLoaded()
    void nextTick(() => searchInput.value?.inputRef?.focus({ preventScroll: true }))
  }
}

function setStickerView(filter: StickerLibraryFilter) {
  stickerViewFilter.value = filter
  query.value = ''
  ensureStickerViewLoaded()
  void nextTick(focusFirstSticker)
}

function ensureStickerViewLoaded() {
  const view = props.stickerViews?.[stickerViewFilter.value]
  if (!view || view.status === 'idle' || view.status === 'error') {
    emit('loadStickers', stickerViewFilter.value)
  }
}

function stickerLabel(sticker: StickerLibraryItem): string {
  return sticker.label?.trim() || 'Figurinha'
}

function stickerSourceLabel(sticker: StickerLibraryItem): string {
  if (sticker.source === 'local_import' || sticker.source === 'LOCAL_IMPORT') return 'Importada no KontiveHub'
  if (sticker.device_favorite || sticker.source === 'device_favorite' || sticker.source === 'DEVICE_FAVORITE') {
    return 'Favorita observada no dispositivo'
  }
  if (sticker.source === 'message' || sticker.source === 'DEVICE_MESSAGE') return 'Observada em uma conversa'
  return 'Recente observada no dispositivo'
}

function focusFirstSticker() {
  pickerRoot.value?.querySelector<HTMLElement>('[data-expression-item]')?.focus({ preventScroll: true })
}

async function runGifSearch() {
  if (!props.capabilities.gifProviderSearch || !props.searchGifs || !query.value.trim()) return
  gifLoading.value = true
  gifError.value = null
  try {
    gifResults.value = await props.searchGifs(query.value.trim())
  } catch {
    gifResults.value = []
    gifError.value = 'Não foi possível buscar GIFs agora.'
  } finally {
    gifLoading.value = false
  }
}

function chooseLocalGif() {
  localGifInput.value?.click()
}

function chooseLocalSticker() {
  localStickerInput.value?.click()
}

function chooseLibraryImport() {
  libraryImportInput.value?.click()
}

function focusSearchInput() {
  searchInput.value?.inputRef?.focus({ preventScroll: true })
}

function selectFile(event: Event, kind: 'GIF' | 'STICKER' | 'IMPORT_STICKER') {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (kind === 'GIF') emit('selectLocalGif', file)
  else if (kind === 'STICKER') emit('selectLocalSticker', file)
  else emit('importSticker', file)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  event.preventDefault()
  emit('close')
}

function onGridKeydown(event: KeyboardEvent) {
  const columns = 3
  const size = tab.value === 'GIF' ? privateGifResults.value.length : filteredStickers.value.length
  if (!size) return
  const moves: Record<string, number> = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: columns, ArrowUp: -columns }
  const move = moves[event.key]
  if (move === undefined) return
  event.preventDefault()
  const current = Number((event.target as HTMLElement)?.dataset?.expressionIndex ?? 0)
  const next = (current + move + size) % size
  const grid = (event.target as HTMLElement).closest<HTMLElement>('[role="grid"]')
  void nextTick(() => grid?.querySelector<HTMLElement>(`[data-expression-index="${next}"]`)?.focus())
}

function onStickerViewTabKeydown(event: KeyboardEvent) {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
  event.preventDefault()
  setStickerView(stickerViewFilter.value === 'recent' ? 'favorites' : 'recent')
}

watch(() => props.open, (open) => {
  if (open && tab.value === 'EMOJI') {
    void nextTick(mountEmojiMart)
  } else if (!open) {
    destroyEmojiMart()
  }
  if (open && tab.value === 'STICKER') ensureStickerViewLoaded()
})

watch(emojiTheme, (theme) => {
  pickerInstance?.setAttribute('theme', theme)
})

watch(tabs, () => {
  const next = resolveComposerExpressionTab(tab.value, props.capabilities)
  if (next) tab.value = next
}, { immediate: true })

onMounted(() => {
  if (props.open && tab.value === 'EMOJI') {
    void nextTick(mountEmojiMart)
  }
  if (props.open && tab.value === 'STICKER') ensureStickerViewLoaded()
})

onBeforeUnmount(() => {
  destroyEmojiMart()
})

defineExpose({
  focusSearch: () => {
    if (tab.value === 'EMOJI') {
      // emoji-mart manages its own search focus
    } else {
      focusSearchInput()
    }
  }
})
</script>

<template>
  <section
    v-if="open"
    ref="pickerRoot"
    class="w-full rounded-lg border border-default bg-elevated shadow-lg motion-reduce:transition-none"
    :class="tab === 'EMOJI' ? 'max-w-sm' : 'max-w-md p-3 max-sm:max-w-none'"
    role="dialog"
    aria-label="Seletor de expressões"
    @keydown="onKeydown"
  >
    <!-- Tab bar (only when GIF or Sticker tabs are available) -->
    <div
      v-if="hasMultipleTabs"
      class="flex gap-1 border-b border-muted px-3 pt-3 pb-2"
      :class="tab !== 'EMOJI' ? '!px-0 !pt-0' : ''"
      role="tablist"
      aria-label="Tipos de expressao"
    >
      <UButton
        v-for="item in tabs"
        :key="item.id"
        :label="item.label"
        color="neutral"
        :variant="tab === item.id ? 'soft' : 'ghost'"
        size="sm"
        class="min-h-11"
        role="tab"
        :aria-selected="tab === item.id"
        @click="setTab(item.id)"
      />
    </div>

    <!-- Emoji tab: emoji-mart Web Component -->
    <div
      v-show="tab === 'EMOJI'"
      ref="emojiPickerContainer"
      class="emoji-mart-host"
    />

    <!-- GIF tab -->
    <div v-if="tab === 'GIF'" class="mt-3">
      <div class="flex gap-2">
        <UInput
          ref="searchInput"
          v-model="query"
          class="min-w-0 flex-1"
          placeholder="Buscar GIF"
          aria-label="Buscar GIF"
          icon="i-lucide-search"
          @keydown.enter.prevent="runGifSearch()"
        />
        <UButton
          v-if="capabilities.gifProviderSearch"
          label="Buscar"
          color="primary"
          :loading="gifLoading"
          :disabled="!query.trim()"
          class="min-h-11"
          @click="runGifSearch"
        />
      </div>

      <p v-if="!capabilities.gifProviderSearch" class="mt-2 text-sm text-muted">
        A busca remota de GIFs não está disponível para esta caixa de entrada.
      </p>
      <p v-if="gifError" class="mt-2 text-sm text-error" role="status">
        {{ gifError }}
      </p>
      <p v-if="selectionError" class="mt-2 text-sm text-error" role="status">
        {{ selectionError }}
      </p>

      <UButton
        label="Enviar GIF do dispositivo"
        icon="i-lucide-upload"
        color="neutral"
        variant="outline"
        class="mt-3 min-h-11"
        @click="chooseLocalGif"
      />
      <input
        ref="localGifInput"
        class="sr-only"
        type="file"
        accept="image/gif,video/*"
        @change="selectFile($event, 'GIF')"
      >
      <div
        v-if="privateGifResults.length"
        class="mt-3 grid grid-cols-3 gap-2"
        role="grid"
        aria-label="Resultados de GIF"
      >
        <UButton
          v-for="(gif, index) in privateGifResults"
          :key="gif.id"
          :data-expression-index="index"
          color="neutral"
          variant="outline"
          class="min-h-11 overflow-hidden p-0"
          :loading="selectionLoadingId === gif.id"
          :aria-label="`Selecionar GIF ${gif.title}`"
          @keydown="onGridKeydown"
          @click="emit('selectGif', gif)"
        >
          <img :src="gif.preview_path" :alt="gif.title" class="aspect-square h-full w-full object-cover">
        </UButton>
      </div>
    </div>

    <!-- Sticker tab -->
    <div v-if="tab === 'STICKER'" class="mt-3">
      <div
        class="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1"
        role="tablist"
        aria-label="Biblioteca de figurinhas"
        @keydown="onStickerViewTabKeydown"
      >
        <UButton
          label="Recentes"
          color="neutral"
          :variant="stickerViewFilter === 'recent' ? 'solid' : 'ghost'"
          class="min-h-11 justify-center"
          role="tab"
          :aria-selected="stickerViewFilter === 'recent'"
          @click="setStickerView('recent')"
        />
        <UButton
          label="Favoritas"
          color="neutral"
          :variant="stickerViewFilter === 'favorites' ? 'solid' : 'ghost'"
          class="min-h-11 justify-center"
          role="tab"
          :aria-selected="stickerViewFilter === 'favorites'"
          @click="setStickerView('favorites')"
        />
      </div>

      <UInput
        ref="searchInput"
        v-model="query"
        class="mt-3 min-w-0"
        placeholder="Buscar figurinha"
        aria-label="Buscar figurinha"
        icon="i-lucide-search"
      />

      <div class="mt-3 grid gap-2 sm:grid-cols-2">
        <UButton
          label="Usar arquivo local"
          icon="i-lucide-hard-drive-upload"
          color="neutral"
          variant="outline"
          class="min-h-11 justify-center"
          @click="chooseLocalSticker"
        />
        <UButton
          label="Importar para a biblioteca"
          icon="i-lucide-library-big"
          color="neutral"
          variant="soft"
          class="min-h-11 justify-center"
          :loading="stickerImporting"
          @click="chooseLibraryImport"
        />
      </div>
      <input
        ref="localStickerInput"
        class="sr-only"
        type="file"
        accept="image/webp"
        @change="selectFile($event, 'STICKER')"
      >
      <input
        ref="libraryImportInput"
        class="sr-only"
        type="file"
        accept="image/webp,.webp"
        @change="selectFile($event, 'IMPORT_STICKER')"
      >

      <p class="sr-only" aria-live="polite">
        {{ stickerAnnouncement }}
      </p>
      <UAlert
        v-if="stickerView?.reason"
        class="mt-3"
        :title="stickerView.status === 'error' ? 'Biblioteca indisponível' : 'Sincronização parcial'"
        :description="stickerView.reason"
        :color="stickerView.status === 'error' || stickerView.status === 'unavailable' ? 'warning' : 'info'"
        variant="subtle"
        :icon="stickerView.status === 'error' ? 'i-lucide-triangle-alert' : 'i-lucide-info'"
      >
        <template v-if="stickerView.status === 'error'" #actions>
          <UButton
            label="Tentar novamente"
            color="neutral"
            variant="soft"
            class="min-h-11"
            @click="emit('loadStickers', stickerViewFilter)"
          />
        </template>
      </UAlert>
      <p v-if="stickerImportError" class="mt-2 text-sm text-error" role="alert">
        {{ stickerImportError }}
      </p>

      <div
        v-if="stickerView?.status === 'loading' && !stickerView.items.length"
        class="mt-3 grid grid-cols-3 gap-2"
        aria-hidden="true"
      >
        <USkeleton v-for="index in 6" :key="index" class="aspect-square min-h-11 rounded-lg" />
      </div>
      <div
        v-else-if="stickerView && (stickerView.status === 'empty' || stickerView.status === 'unavailable') && !stickerView.items.length"
        class="mt-3 rounded-lg border border-dashed border-default p-4 text-center"
      >
        <UIcon name="i-lucide-sticker" class="mx-auto size-6 text-muted" />
        <p class="mt-2 text-sm font-medium text-highlighted">
          {{ stickerViewFilter === 'recent' ? 'Nenhuma figurinha recente observada' : 'Nenhuma favorita observada' }}
        </p>
        <p class="mt-1 text-xs text-muted">
          Você ainda pode usar um arquivo WebP local sem depender do dispositivo.
        </p>
      </div>
      <div
        v-if="filteredStickers.length"
        class="mt-3 grid grid-cols-3 gap-2"
        role="grid"
        :aria-label="stickerViewFilter === 'recent' ? 'Figurinhas recentes' : 'Figurinhas favoritas'"
        @keydown="onGridKeydown"
      >
        <article
          v-for="(sticker, index) in filteredStickers"
          :key="sticker.id"
          role="gridcell"
          class="group relative min-w-0 rounded-lg border border-default bg-default p-1 focus-within:ring-2 focus-within:ring-primary"
        >
          <button
            type="button"
            :data-expression-index="index"
            data-expression-item
            class="flex min-h-16 w-full flex-col items-center justify-center gap-1 rounded-md p-1 text-center motion-reduce:transition-none disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="!sticker.available || selectionLoadingId === sticker.id"
            :aria-label="sticker.available
              ? `Selecionar ${stickerLabel(sticker)}. ${stickerSourceLabel(sticker)}`
              : `${stickerLabel(sticker)} indisponível. ${sticker.unavailable_reason || 'Mídia não materializada'}`"
            @click="emit('selectSticker', sticker)"
          >
            <img
              v-if="stickerPreviewUrls.get(sticker.id)"
              :src="stickerPreviewUrls.get(sticker.id)"
              alt=""
              class="size-11 object-contain"
            >
            <UIcon
              v-else
              :name="selectionLoadingId === sticker.id ? 'i-lucide-loader-circle' : 'i-lucide-sticker'"
              class="size-6 motion-reduce:animate-none"
              :class="selectionLoadingId === sticker.id && 'animate-spin'"
            />
            <span class="line-clamp-2 text-[11px] text-muted">{{ stickerSourceLabel(sticker) }}</span>
          </button>
          <UButton
            :icon="sticker.app_favorite ? 'i-lucide-heart-off' : 'i-lucide-heart'"
            color="neutral"
            variant="soft"
            size="xs"
            class="absolute top-1 right-1 min-h-11 min-w-11 justify-center rounded-full opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100"
            :loading="stickerMutatingIds.has(sticker.id)"
            :aria-label="sticker.app_favorite
              ? `Remover ${stickerLabel(sticker)} dos favoritos do KontiveHub`
              : `Adicionar ${stickerLabel(sticker)} aos favoritos do KontiveHub`"
            @click="emit('toggleStickerFavorite', sticker)"
          />
          <span
            v-if="sticker.device_favorite"
            class="absolute bottom-1 left-1 rounded bg-elevated px-1 text-[10px] text-muted"
          >
            No dispositivo
          </span>
        </article>
      </div>
      <UButton
        v-if="stickerView && stickerView.page < stickerView.lastPage"
        label="Carregar mais"
        color="neutral"
        variant="ghost"
        block
        class="mt-3 min-h-11"
        :loading="stickerView.loadingMore"
        @click="emit('loadStickers', stickerViewFilter, true)"
      />
    </div>
  </section>
</template>

<style scoped>
.emoji-mart-host :deep(em-emoji-picker) {
  --em-rgb-background: transparent;
  border: none;
  width: 100%;
  min-height: 360px;
}

@media (max-width: 639px) {
  :deep(input) {
    font-size: 1rem;
  }
}
</style>
