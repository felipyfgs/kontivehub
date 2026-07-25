<script setup lang="ts">
/**
 * Filtros estruturados controlados: chips + seletor responsivo + editor.
 * Desktop: UPopover; mobile: UModal fullscreen (evita drawer inferior vs teclado).
 * Confirmação explícita; fechar descarta rascunho.
 */
import { breakpointsTailwind, useBreakpoints, useResizeObserver } from '@vueuse/core'
import type {
  DataTableFilterDefinition,
  DataTableFilterModel
} from '~/types/data-table-filter'
import { findDefinition } from '~/utils/data-table-filters'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'
import { useDataTableFilters } from '~/composables/useDataTableFilters'
import DataTableFilterChip from '~/components/data-table-filter/Chip.vue'
import DataTableFilterEditor from '~/components/data-table-filter/Editor.vue'
import DataTableFilterSelector from '~/components/data-table-filter/Selector.vue'

const props = withDefaults(defineProps<{
  definitions: DataTableFilterDefinition[]
  modelValue?: DataTableFilterModel[]
  /** sessionEpoch / office — limpa rascunho e rótulos de cliente. */
  resetKey?: string | number | null
  addLabel?: string
  clearLabel?: string
  showClear?: boolean
}>(), {
  modelValue: () => [],
  addLabel: 'Filtro',
  clearLabel: 'Limpar',
  showClear: true
})

const emit = defineEmits<{
  'update:modelValue': [models: DataTableFilterModel[]]
  'clear': []
}>()

const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('lg')

const {
  applied,
  draft,
  draftDefinition,
  inactive,
  selectorOpen,
  editorOpen,
  canConfirm,
  startAdd,
  startEdit,
  setDraftValue,
  confirmDraft,
  remove,
  clear,
  openSelector,
  backToSelector,
  closeEditor,
  discardDraft
} = useDataTableFilters({
  definitions: () => props.definitions,
  modelValue: () => props.modelValue,
  resetKey: () => props.resetKey,
  onUpdate: models => emit('update:modelValue', models),
  onClear: () => emit('clear')
})

const hasApplied = computed(() => applied.value.length > 0)
const canAdd = computed(() => inactive.value.length > 0)
/** Voltar à lista de campos ao adicionar (não no edit de chip). */
const showEditorBack = computed(() => draft.value?.mode === 'add')

/**
 * Quando os chips não cabem ao lado de Filtro/Limpar, colapsa em contador.
 * Contador fica colado no botão Filtro (justify-end), não na busca.
 */
const rootRef = useTemplateRef<HTMLElement>('filtersRoot')
const controlsRef = useTemplateRef<HTMLElement>('filtersControls')
const chipsMeasureRef = useTemplateRef<HTMLElement>('chipsMeasure')
const chipsCollapsed = ref(false)
const collapsedListOpen = ref(false)

const collapsedCountLabel = computed(() => {
  const n = applied.value.length
  return n === 1 ? '1 filtro' : `${n} filtros`
})

function measureChipsOverflow() {
  const root = rootRef.value
  const measure = chipsMeasureRef.value
  const controls = controlsRef.value
  if (!root || !measure || applied.value.length === 0) {
    chipsCollapsed.value = false
    return
  }
  const gap = 6 // gap-1.5
  const budget = Math.max(0, root.clientWidth - (controls?.offsetWidth ?? 0) - gap)
  // +1 evita oscilação por arredondamento subpixel.
  chipsCollapsed.value = measure.scrollWidth > budget + 1
}

useResizeObserver(rootRef, () => {
  void nextTick(measureChipsOverflow)
})
useResizeObserver(chipsMeasureRef, () => {
  void nextTick(measureChipsOverflow)
})
useResizeObserver(controlsRef, () => {
  void nextTick(measureChipsOverflow)
})

watch(applied, async () => {
  collapsedListOpen.value = false
  await nextTick()
  measureChipsOverflow()
}, { deep: true })

onMounted(() => {
  void nextTick(measureChipsOverflow)
})

function onCollapsedChipEdit(key: string) {
  collapsedListOpen.value = false
  startEdit(key)
}

function onCollapsedChipRemove(key: string) {
  remove(key)
}

/** Overlay unificado: seletor ou editor (mesmo contêiner desktop/mobile). */
const overlayOpen = computed({
  get: () => selectorOpen.value || editorOpen.value,
  set: (open: boolean) => {
    if (!open) {
      discardDraft()
      return
    }
    // Trigger "Adicionar filtro" pediu abertura sem editor ativo → seletor.
    if (!editorOpen.value) openSelector()
  }
})

const overlayMode = computed<'selector' | 'editor' | null>(() => {
  if (editorOpen.value && draftDefinition.value) return 'editor'
  if (selectorOpen.value) return 'selector'
  return null
})

const overlayTitle = computed(() => {
  if (overlayMode.value === 'editor') return draftDefinition.value?.label || 'Editar filtro'
  return 'Adicionar filtro'
})

/** Painel flutuante: um pouco mais largo no editor de cliente multi. */
const panelClass = computed(() => {
  const wide = overlayMode.value === 'editor' && draftDefinition.value?.kind === 'client'
  return [
    wide ? 'w-96' : 'w-80',
    // Preenche a altura disponível do Floating UI (não 100dvh — isso estoura abaixo do fold).
    'flex max-h-full min-h-0 min-w-0 max-w-[min(24rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-lg border border-default bg-default shadow-lg'
  ].join(' ')
})

function definitionFor(key: string) {
  return findDefinition(props.definitions, key)
}

function onDraftValue(value: string | number | boolean | null) {
  setDraftValue(value)
}

function onDraftValueTo(value: string | null) {
  setDraftValue(draft.value?.value ?? null, undefined, value)
}

function onDraftLabel(label: string | undefined) {
  setDraftValue(draft.value?.value ?? null, label)
}

/**
 * Popover content props.
 * Cliente multi: bloqueia dismiss por clique fora (Confirmar/Cancelar fecham).
 * Não usa onFocusOutside — travar focus quebra a barra de busca do picker.
 */
const popoverContent = computed(() => ({
  align: 'start' as const,
  side: 'bottom' as const,
  sideOffset: 6,
  collisionPadding: 12,
  onInteractOutside: (e: Event) => {
    if (overlayMode.value !== 'editor' || draftDefinition.value?.kind !== 'client') return
    e.preventDefault()
  }
}))
</script>

<template>
  <!--
    Grupo compacto à direita: [chips|contador] + Filtro + Limpar.
    justify-end mantém o contador colado no ícone de filtro, não na busca.
  -->
  <div
    ref="filtersRoot"
    class="flex w-full min-w-0 items-center justify-end gap-1.5"
    data-testid="data-table-filters"
  >
    <div
      v-if="hasApplied"
      class="relative flex min-w-0 items-center justify-end gap-1.5"
      data-testid="data-table-filter-chips-viewport"
    >
      <div
        v-show="!chipsCollapsed"
        class="flex min-w-0 items-center justify-end gap-1.5 overflow-hidden"
        data-testid="data-table-filter-chips"
      >
        <DataTableFilterChip
          v-for="model in applied"
          :key="`chip-${model.key}`"
          :definition="definitionFor(model.key)!"
          :model="model"
          @edit="startEdit(model.key)"
          @remove="remove(model.key)"
        />
      </div>

      <UPopover
        v-if="chipsCollapsed"
        v-model:open="collapsedListOpen"
        :portal="true"
        :content="{ align: 'end', side: 'bottom', sideOffset: 6 }"
      >
        <UButton
          class="shrink-0"
          color="neutral"
          variant="outline"
          icon="i-lucide-list-filter"
          :label="collapsedCountLabel"
          :aria-label="`${collapsedCountLabel} aplicados — abrir lista`"
          data-testid="data-table-filter-collapsed"
        />
        <template #content>
          <div
            class="flex max-h-72 min-w-56 max-w-[min(24rem,calc(100vw-2rem))] flex-col gap-1.5 overflow-y-auto overscroll-y-contain p-2"
            data-testid="data-table-filter-collapsed-list"
          >
            <DataTableFilterChip
              v-for="model in applied"
              :key="`collapsed-${model.key}`"
              :definition="definitionFor(model.key)!"
              :model="model"
              @edit="onCollapsedChipEdit(model.key)"
              @remove="onCollapsedChipRemove(model.key)"
            />
          </div>
        </template>
      </UPopover>

      <div
        ref="chipsMeasure"
        class="pointer-events-none absolute top-0 right-0 flex w-max items-center gap-1.5 opacity-0"
        aria-hidden="true"
        data-testid="data-table-filter-chips-measure"
      >
        <DataTableFilterChip
          v-for="model in applied"
          :key="`measure-${model.key}`"
          :definition="definitionFor(model.key)!"
          :model="model"
        />
      </div>
    </div>

    <div
      ref="filtersControls"
      class="flex shrink-0 items-center gap-1.5"
      data-testid="data-table-filter-controls"
    >
      <!-- Desktop: popover com seletor ou editor (portal + painel elevado). -->
      <UPopover
        v-if="!isMobile"
        v-model:open="overlayOpen"
        :portal="true"
        :content="popoverContent"
        :ui="{
          content: 'flex max-h-(--reka-popover-content-available-height) flex-col overflow-hidden p-0 bg-transparent ring-0 shadow-none'
        }"
      >
        <UButton
          v-if="canAdd"
          class="shrink-0"
          color="neutral"
          variant="outline"
          icon="i-lucide-funnel-plus"
          :label="addLabel"
          :aria-label="addLabel"
          :ui="COMPACT_BUTTON_LABEL_UI"
          data-testid="data-table-filter-add"
        />
        <span
          v-else
          class="sr-only"
          data-testid="data-table-filter-anchor"
        >Filtros</span>
        <template #content>
          <div
            :class="panelClass"
            data-testid="data-table-filter-overlay"
          >
            <DataTableFilterSelector
              v-if="overlayMode === 'selector'"
              :definitions="inactive"
              @select="startAdd"
            />
            <DataTableFilterEditor
              v-else-if="overlayMode === 'editor' && draftDefinition"
              :definition="draftDefinition"
              :model-value="draft?.value ?? null"
              :value-to="draft?.valueTo ?? null"
              :label="draft?.label"
              :can-confirm="canConfirm"
              :show-back="showEditorBack"
              @update:model-value="onDraftValue"
              @update:value-to="onDraftValueTo"
              @update:label="onDraftLabel"
              @confirm="confirmDraft"
              @cancel="closeEditor"
              @back="backToSelector"
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
            </DataTableFilterEditor>
          </div>
        </template>
      </UPopover>

      <!-- Mobile: modal fullscreen — busca/edição no topo, longe do teclado. -->
      <template v-else>
        <UButton
          v-if="canAdd"
          class="shrink-0"
          color="neutral"
          variant="outline"
          icon="i-lucide-funnel-plus"
          :label="addLabel"
          :aria-label="addLabel"
          :ui="COMPACT_BUTTON_LABEL_UI"
          data-testid="data-table-filter-add"
          @click="openSelector"
        />
        <UModal
          v-model:open="overlayOpen"
          :title="overlayTitle"
          fullscreen
          :ui="{ body: 'p-0' }"
        >
          <template #body>
            <div
              class="min-h-0 min-w-0 w-full max-w-full flex-1 overflow-x-hidden overflow-y-auto"
              data-testid="data-table-filter-overlay"
            >
              <DataTableFilterSelector
                v-if="overlayMode === 'selector'"
                :definitions="inactive"
                @select="startAdd"
              />
              <DataTableFilterEditor
                v-else-if="overlayMode === 'editor' && draftDefinition"
                :definition="draftDefinition"
                :model-value="draft?.value ?? null"
                :value-to="draft?.valueTo ?? null"
                :label="draft?.label"
                :can-confirm="canConfirm"
                :show-back="showEditorBack"
                @update:model-value="onDraftValue"
                @update:value-to="onDraftValueTo"
                @update:label="onDraftLabel"
                @confirm="confirmDraft"
                @cancel="closeEditor"
                @back="backToSelector"
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
              </DataTableFilterEditor>
            </div>
          </template>
        </UModal>
      </template>

      <UButton
        v-if="showClear && hasApplied"
        class="shrink-0"
        color="neutral"
        variant="ghost"
        :label="clearLabel"
        :aria-label="clearLabel"
        :ui="COMPACT_BUTTON_LABEL_UI"
        icon="i-lucide-filter-x"
        data-testid="data-table-filter-clear"
        @click="clear"
      />
    </div>
  </div>
</template>
