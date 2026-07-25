<script setup lang="ts">
/**
 * Shell canônico de toolbar de lista (padrão ouro Parcelamentos):
 * busca + chips DataTableFilter + Limpar/Salvar/Filtros salvos + refresh + slots.
 *
 * Mobile: busca full-width; chips/ações em faixa com scroll touch.
 * sm+: busca flexível (max-w-sm) + ações à direita (customers.vue).
 */
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import type { SavedListFilterPayload } from '~/types/saved-list-filters'
import { useSavedListPresets } from '~/composables/useSavedListPresets'
import {
  COMPACT_BUTTON_LABEL_UI,
  LIST_FILTER_ACTIONS_ROW,
  LIST_FILTER_SEARCH_INPUT,
  LIST_FILTER_TOOLBAR_STACK
} from '~/utils/list-filter-layout'
import DataTableFilterRoot from '~/components/data-table-filter/Root.vue'
import DataTableFilterSaveFilterModal from '~/components/data-table-filter/SaveFilterModal.vue'
import DataTableFilterSavedFiltersMenu from '~/components/data-table-filter/SavedFiltersMenu.vue'
import DataTableFilterManageSavedFiltersModal from '~/components/data-table-filter/ManageSavedFiltersModal.vue'

const props = withDefaults(defineProps<{
  q?: string
  searchPlaceholder?: string
  searchAriaLabel?: string
  showSearch?: boolean
  definitions?: readonly DataTableFilterDefinition[]
  models?: readonly DataTableFilterModel[]
  loading?: boolean
  showTotal?: boolean
  total?: number
  showExport?: boolean
  canExport?: boolean
  resetKey?: string | number | null
  surface?: string | null
  canShareFilters?: boolean
  getPayload?: () => SavedListFilterPayload
  canSave?: () => boolean
  testIdPrefix?: string
}>(), {
  q: '',
  searchPlaceholder: 'Buscar…',
  searchAriaLabel: 'Buscar',
  showSearch: true,
  definitions: () => [],
  models: () => [],
  loading: false,
  showTotal: false,
  total: 0,
  showExport: false,
  canExport: false,
  resetKey: 0,
  surface: null,
  canShareFilters: undefined,
  testIdPrefix: 'list-filter'
})

const emit = defineEmits<{
  'update:q': [value: string]
  'submit-q': []
  'update:models': [models: DataTableFilterModel[]]
  'clear': []
  'refresh': []
  'export': []
  'apply-preset': [payload: SavedListFilterPayload]
}>()

const qDraft = ref(props.q)
watch(() => props.q, (value) => {
  if (value !== qDraft.value) qDraft.value = value
})

let qDebounce: ReturnType<typeof setTimeout> | null = null
function onQInput(value: string | number) {
  const next = String(value ?? '')
  qDraft.value = next
  if (qDebounce) clearTimeout(qDebounce)
  qDebounce = setTimeout(() => emit('update:q', next), 320)
}

function submitQ() {
  if (qDebounce) {
    clearTimeout(qDebounce)
    qDebounce = null
  }
  emit('update:q', qDraft.value)
  emit('submit-q')
}

onBeforeUnmount(() => {
  if (qDebounce) clearTimeout(qDebounce)
})

const hasStructured = computed(() => (props.definitions?.length ?? 0) > 0)
const hasActive = computed(() =>
  (props.models?.length ?? 0) > 0 || Boolean(qDraft.value.trim())
)

const {
  enabled: savedFiltersEnabled,
  canShare,
  canSavePreset,
  presets,
  presetsLoading,
  saveOpen,
  manageOpen,
  saveLoading,
  saveError,
  manageError,
  actingId,
  onSavedMenuOpen,
  applyPreset,
  onSaveConfirm,
  onRename,
  onToggleShare,
  onDeletePreset,
  openManage,
  openSave
} = useSavedListPresets({
  surface: () => props.surface,
  canShare: () => props.canShareFilters,
  resetKey: () => props.resetKey,
  getPayload: () => props.getPayload?.() ?? { schema_version: 1 },
  canSave: () => (props.canSave ? props.canSave() : hasActive.value),
  onApply: payload => emit('apply-preset', payload)
})

const prefix = computed(() => props.testIdPrefix)
const compactLabelUi = COMPACT_BUTTON_LABEL_UI
</script>

<template>
  <div
    class="w-full min-w-0"
    data-testid="page-toolbar"
  >
    <div :class="LIST_FILTER_TOOLBAR_STACK">
      <UInput
        v-if="showSearch"
        :model-value="qDraft"
        icon="i-lucide-search"
        :placeholder="searchPlaceholder"
        :class="LIST_FILTER_SEARCH_INPUT"
        size="md"
        :aria-label="searchAriaLabel"
        :data-testid="`${prefix}-q`"
        @update:model-value="onQInput"
        @keyup.enter="submitQ"
      />

      <div
        :class="LIST_FILTER_ACTIONS_ROW"
        :data-testid="`${prefix}-actions`"
      >
        <slot name="actions" />

        <div
          v-if="hasStructured || hasActive"
          class="flex min-w-0 flex-1 items-center justify-end gap-1.5"
          :data-testid="`${prefix}-structured`"
        >
          <DataTableFilterRoot
            class="min-w-0 w-full max-w-full"
            :definitions="[...definitions]"
            :model-value="[...models]"
            :reset-key="resetKey"
            :show-clear="hasActive"
            :data-testid="`${prefix}-chips`"
            @update:model-value="emit('update:models', $event)"
            @clear="emit('clear')"
          >
            <template
              v-if="$slots.client"
              #client="slotProps"
            >
              <slot
                name="client"
                v-bind="slotProps"
              />
            </template>
          </DataTableFilterRoot>
        </div>

        <template v-if="savedFiltersEnabled">
          <UTooltip
            v-if="canSavePreset"
            text="Salvar filtros"
          >
            <UButton
              class="shrink-0"
              color="neutral"
              variant="outline"
              icon="i-lucide-save"
              label="Salvar"
              aria-label="Salvar filtros"
              :ui="compactLabelUi"
              data-testid="save-filters-button"
              @click="openSave"
            />
          </UTooltip>
          <div class="shrink-0">
            <DataTableFilterSavedFiltersMenu
              :items="presets"
              :loading="presetsLoading"
              @apply="applyPreset"
              @manage="openManage"
              @open="onSavedMenuOpen"
            />
          </div>
        </template>

        <UTooltip text="Atualizar dados">
          <UButton
            class="shrink-0"
            color="neutral"
            variant="ghost"
            icon="i-lucide-refresh-cw"
            :loading="loading"
            aria-label="Atualizar dados"
            :data-testid="`${prefix}-refresh`"
            @click="emit('refresh')"
          />
        </UTooltip>

        <UButton
          v-if="showExport && canExport"
          color="neutral"
          variant="outline"
          icon="i-lucide-download"
          label="Exportar"
          aria-label="Exportar"
          :ui="compactLabelUi"
          :data-testid="`${prefix}-export`"
          @click="emit('export')"
        />

        <slot name="trailing" />

        <span
          v-if="showTotal"
          class="hidden shrink-0 text-right text-xs text-muted tabular-nums sm:inline"
        >
          {{ total }} registro(s)
        </span>
      </div>
    </div>

    <DataTableFilterSaveFilterModal
      v-if="savedFiltersEnabled"
      v-model:open="saveOpen"
      :can-share="canShare"
      :loading="saveLoading"
      :error="saveError"
      @confirm="onSaveConfirm"
    />

    <DataTableFilterManageSavedFiltersModal
      v-if="savedFiltersEnabled"
      v-model:open="manageOpen"
      :items="presets"
      :can-share="canShare"
      :loading="presetsLoading"
      :acting-id="actingId"
      :error="manageError"
      @rename="onRename"
      @toggle-share="onToggleShare"
      @delete="onDeletePreset"
    />
  </div>
</template>
