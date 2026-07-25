<script setup lang="ts">
/**
 * Corpo do editor de filtro (option / month / client / text / boolean / date / date_range).
 * Contêiner (popover/modal) fica no Root; handlers e markup são compartilhados.
 */
import FilterDateInput from '~/components/data-table-filter/FilterDateInput.vue'
import type { DataTableFilterDefinition } from '~/types/data-table-filter'
import {
  decodeClientIds,
  decodeOptionValues,
  encodeClientIds,
  encodeOptionValues,
  isValidDateValue,
  isValidMonthValue,
  optionLabel
} from '~/utils/data-table-filters'

const props = withDefaults(defineProps<{
  definition: DataTableFilterDefinition
  modelValue: string | number | boolean | null
  /** Fim do intervalo (date_range). */
  valueTo?: string | null
  label?: string
  canConfirm: boolean
  /** Exibe "Voltar" para a lista de campos (modo add). */
  showBack?: boolean
}>(), {
  showBack: false
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number | boolean | null]
  'update:valueTo': [value: string | null]
  'update:label': [label: string | undefined]
  'confirm': []
  'cancel': []
  'back': []
}>()

const isOptionMultiple = computed(
  () => props.definition.kind === 'option' && props.definition.multiple === true
)

const optionItems = computed(() => {
  if (props.definition.kind !== 'option') return []
  const empty = props.definition.emptyValue ?? 'all'
  return props.definition.items.filter(item => item.value !== empty)
})

const multiOptionModel = computed({
  get: () => decodeOptionValues(props.modelValue),
  set: (values: string[]) => {
    // Valor vazio estável: '' (não null) — createFilterModel trata igual.
    const encoded = encodeOptionValues(values)
    emit('update:modelValue', encoded || '')
    if (!encoded) {
      emit('update:label', undefined)
      return
    }
    if (props.definition.kind !== 'option') return
    const def = props.definition
    const labels = decodeOptionValues(encoded)
      .map(value => optionLabel(def.items, value))
    emit('update:label', labels.join(', '))
  }
})

/** Busca local do checklist multiOption (padrão bazza CommandInput). */
const optionSearch = ref('')

watch(
  () => props.definition.key,
  () => { optionSearch.value = '' }
)

const filteredOptionItems = computed(() => {
  const q = optionSearch.value.trim().toLowerCase()
  if (!q) return optionItems.value
  return optionItems.value.filter(item =>
    item.label.toLowerCase().includes(q)
    || String(item.value).toLowerCase().includes(q)
  )
})

const allFilteredOptionsSelected = computed(() => {
  const list = filteredOptionItems.value
  if (!list.length) return false
  const set = new Set(multiOptionModel.value)
  return list.every(item => set.has(item.value))
})

const someFilteredOptionsSelected = computed(() => {
  const set = new Set(multiOptionModel.value)
  return filteredOptionItems.value.some(item => set.has(item.value))
})

function toggleOptionItem(value: string) {
  const set = new Set(multiOptionModel.value)
  if (set.has(value)) set.delete(value)
  else set.add(value)
  multiOptionModel.value = [...set]
}

function toggleAllOptions() {
  const filtered = filteredOptionItems.value.map(item => item.value)
  if (!filtered.length) return
  if (allFilteredOptionsSelected.value) {
    const drop = new Set(filtered)
    multiOptionModel.value = multiOptionModel.value.filter(v => !drop.has(v))
    return
  }
  multiOptionModel.value = [...new Set([...multiOptionModel.value, ...filtered])]
}

const booleanItems = computed(() => {
  if (props.definition.kind !== 'boolean') return []
  return [
    { label: props.definition.trueLabel || 'Sim', value: 'true' },
    { label: props.definition.falseLabel || 'Não', value: 'false' }
  ]
})

const monthError = computed(() => {
  if (props.definition.kind !== 'month') return undefined
  const value = String(props.modelValue ?? '').trim()
  if (!value) return undefined
  if (isValidMonthValue(value)) return undefined
  return 'Use uma competência válida no formato AAAA-MM.'
})

const dateError = computed(() => {
  if (props.definition.kind !== 'date') return undefined
  const value = String(props.modelValue ?? '').trim()
  if (!value) return undefined
  if (isValidDateValue(value)) return undefined
  return 'Use uma data válida no formato AAAA-MM-DD.'
})

const dateRangeError = computed(() => {
  if (props.definition.kind !== 'date_range') return undefined
  const from = String(props.modelValue ?? '').trim()
  const to = String(props.valueTo ?? '').trim()
  if (!from && !to) return undefined
  if (from && !isValidDateValue(from)) return 'Data inicial inválida (AAAA-MM-DD).'
  if (to && !isValidDateValue(to)) return 'Data final inválida (AAAA-MM-DD).'
  if (from && to && from > to) return 'A data inicial deve ser anterior ou igual à final.'
  return undefined
})

const fieldError = computed(() => monthError.value || dateError.value || dateRangeError.value)

const booleanModel = computed({
  get: () => {
    if (typeof props.modelValue === 'boolean') return props.modelValue ? 'true' : 'false'
    if (props.modelValue === 'true' || props.modelValue === 'false') return String(props.modelValue)
    return ''
  },
  set: (value: string) => {
    if (value === 'true') emit('update:modelValue', true)
    else if (value === 'false') emit('update:modelValue', false)
    else emit('update:modelValue', null)
  }
})

function onOption(value: string) {
  emit('update:modelValue', value)
  const item = optionItems.value.find(entry => entry.value === value)
  emit('update:label', item?.label)
}

function _onMultiOption(value: unknown) {
  const list = Array.isArray(value)
    ? value.map(item => String(item ?? '')).filter(Boolean)
    : value == null || value === ''
      ? []
      : [String(value)]
  multiOptionModel.value = list
}

function onMonth(value: string | number) {
  emit('update:modelValue', String(value ?? ''))
  emit('update:label', undefined)
}

function onText(value: string | number) {
  emit('update:modelValue', String(value ?? ''))
  emit('update:label', undefined)
}

function onDate(value: string | number) {
  emit('update:modelValue', String(value ?? ''))
  emit('update:label', undefined)
}

function onDateFrom(value: string | number) {
  emit('update:modelValue', String(value ?? ''))
  emit('update:label', undefined)
}

function onDateTo(value: string | number) {
  emit('update:valueTo', String(value ?? ''))
  emit('update:label', undefined)
}

function onBoolean(value: string) {
  booleanModel.value = value
  const item = booleanItems.value.find(entry => entry.value === value)
  emit('update:label', item?.label)
}

const isClientMultiple = computed(
  () => props.definition.kind === 'client' && props.definition.multiple === true
)

const multiClientModel = computed({
  get: () => decodeClientIds(props.modelValue),
  set: (ids: number[]) => {
    emit('update:modelValue', encodeClientIds(ids) || '')
  }
})

function onClientId(value: number | number[] | null) {
  if (Array.isArray(value)) {
    emit('update:modelValue', value[0] ?? null)
    return
  }
  emit('update:modelValue', value)
}

function onClientSelect(client: {
  display_name?: string | null
  legal_name?: string | null
  name?: string | null
  cnpj?: string | null
} | null) {
  if (!client) {
    emit('update:label', undefined)
    return
  }
  const name = client.display_name || client.legal_name || client.name || 'Cliente'
  emit('update:label', name)
}

function onClientSelectMany(clients: Array<{
  display_name?: string | null
  legal_name?: string | null
  name?: string | null
}>) {
  if (!clients.length) {
    emit('update:label', undefined)
    return
  }
  const names = clients.map(c => c.display_name || c.legal_name || c.name || 'Cliente')
  emit('update:label', names.join(', '))
}
</script>

<template>
  <!--
    Altura vem do popover (--reka-popover-content-available-height).
    Lista rola no meio; Cancelar/Confirmar ficam fixos no rodapé.
  -->
  <div
    class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden"
    data-testid="data-table-filter-editor"
  >
    <div class="shrink-0 px-3 pt-3">
      <p class="text-sm font-medium text-highlighted">
        {{ definition.label }}
      </p>
    </div>

    <!--
      multiOption (bazza): checklist embutida — não USelect multiple.
      Busca local via UInput + linhas com checkbox (Command-style).
    -->
    <div
      v-if="definition.kind === 'option' && isOptionMultiple"
      class="mx-3 mt-3 flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-md border border-default"
      data-testid="data-table-filter-option-multi"
    >
      <UInput
        v-model="optionSearch"
        type="search"
        icon="i-lucide-search"
        placeholder="Buscar…"
        variant="none"
        autocomplete="off"
        class="w-full min-w-0 shrink-0"
        :aria-label="`Buscar ${definition.label}`"
        :ui="{
          base: 'rounded-none border-0 border-b border-default focus-visible:ring-0'
        }"
      />
      <div
        class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain"
        data-testid="data-table-filter-option-list"
      >
        <p
          v-if="!filteredOptionItems.length"
          class="px-3 py-6 text-center text-sm text-muted"
        >
          Nenhum resultado
        </p>
        <template v-else>
          <button
            type="button"
            class="flex w-full min-w-0 items-center gap-2 px-2.5 py-2 text-left text-sm text-muted hover:bg-elevated/80 focus-visible:bg-elevated focus-visible:outline-none"
            data-testid="data-table-filter-option-select-all"
            @click="toggleAllOptions"
          >
            <UCheckbox
              :model-value="allFilteredOptionsSelected
                ? true
                : (someFilteredOptionsSelected ? 'indeterminate' : false)"
              tabindex="-1"
              class="pointer-events-none shrink-0"
            />
            <span class="min-w-0 flex-1 truncate">
              {{ allFilteredOptionsSelected ? 'Limpar' : 'Selecionar todos' }}
            </span>
          </button>
          <div
            class="mx-2 border-t border-default"
            role="separator"
          />
          <button
            v-for="item in filteredOptionItems"
            :key="item.value"
            type="button"
            class="group flex w-full min-w-0 items-center gap-2 px-2.5 py-2 text-left text-sm hover:bg-elevated/80 focus-visible:bg-elevated focus-visible:outline-none"
            data-testid="data-table-filter-option-item"
            @click="toggleOptionItem(item.value)"
          >
            <UCheckbox
              :model-value="multiOptionModel.includes(item.value)"
              tabindex="-1"
              class="pointer-events-none shrink-0"
              :class="multiOptionModel.includes(item.value) ? '' : 'opacity-40 group-hover:opacity-100'"
            />
            <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
          </button>
        </template>
      </div>
    </div>

    <div
      v-else
      class="min-w-0 shrink-0 space-y-3 px-3 pt-3"
    >
      <div
        v-if="definition.kind === 'option'"
        class="min-w-0"
      >
        <USelect
          :model-value="String(modelValue ?? '')"
          :items="optionItems"
          value-key="value"
          class="w-full min-w-0"
          :aria-label="definition.label"
          data-testid="data-table-filter-option"
          :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
          @update:model-value="onOption(String($event))"
        />
      </div>

      <UFormField
        v-else-if="definition.kind === 'month'"
        :error="monthError"
        class="min-w-0"
      >
        <FilterDateInput
          :model-value="String(modelValue ?? '')"
          mode="month"
          :aria-label="definition.label"
          test-id="data-table-filter-month"
          @update:model-value="onMonth($event)"
        />
      </UFormField>

      <div
        v-else-if="definition.kind === 'client' && isClientMultiple"
        class="min-w-0"
      >
        <slot
          name="client"
          :model-value="multiClientModel"
          :multiple="true"
          :update="(v: number | number[] | null) => {
            multiClientModel = Array.isArray(v) ? v : (v != null ? [v] : [])
          }"
          :select="onClientSelect"
          :select-many="onClientSelectMany"
        >
          <FiscalClientPicker
            :model-value="multiClientModel"
            multiple
            search-mode="select"
            placeholder="Buscar…"
            class="w-full min-w-0"
            data-testid="data-table-filter-client-multi"
            @update:model-value="(v) => {
              multiClientModel = Array.isArray(v) ? v : (typeof v === 'number' ? [v] : [])
            }"
            @select="onClientSelect"
            @select-many="onClientSelectMany"
          />
        </slot>
      </div>

      <div
        v-else-if="definition.kind === 'client'"
        class="min-w-0"
      >
        <slot
          name="client"
          :model-value="typeof modelValue === 'number' ? modelValue : null"
          :multiple="false"
          :update="onClientId"
          :select="onClientSelect"
        >
          <FiscalClientPicker
            :model-value="typeof modelValue === 'number' ? modelValue : null"
            search-mode="select"
            placeholder="Cliente"
            class="w-full min-w-0"
            data-testid="data-table-filter-client"
            @update:model-value="onClientId"
            @select="onClientSelect"
          />
        </slot>
      </div>

      <div
        v-else-if="definition.kind === 'text'"
        class="min-w-0"
      >
        <UInput
          :model-value="String(modelValue ?? '')"
          type="text"
          class="w-full min-w-0"
          :aria-label="definition.label"
          data-testid="data-table-filter-text"
          @update:model-value="onText($event)"
        />
      </div>

      <div
        v-else-if="definition.kind === 'boolean'"
        class="min-w-0"
      >
        <USelect
          :model-value="booleanModel"
          :items="booleanItems"
          value-key="value"
          class="w-full min-w-0"
          :aria-label="definition.label"
          data-testid="data-table-filter-boolean"
          :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
          @update:model-value="onBoolean(String($event))"
        />
      </div>

      <UFormField
        v-else-if="definition.kind === 'date'"
        :error="dateError"
        class="min-w-0"
      >
        <FilterDateInput
          :model-value="String(modelValue ?? '')"
          mode="date"
          :aria-label="definition.label"
          test-id="data-table-filter-date"
          @update:model-value="onDate($event)"
        />
      </UFormField>

      <UFormField
        v-else-if="definition.kind === 'date_range'"
        :error="dateRangeError"
        class="min-w-0"
      >
        <FilterDateInput
          :model-value="String(modelValue ?? '')"
          :value-to="String(valueTo ?? '')"
          mode="date_range"
          :aria-label="definition.label"
          test-id="data-table-filter-date-range"
          @update:model-value="onDateFrom($event)"
          @update:value-to="onDateTo($event)"
        />
      </UFormField>
    </div>

    <div class="flex shrink-0 flex-wrap items-center justify-between gap-2 border-t border-default bg-default p-3">
      <UButton
        v-if="showBack"
        type="button"
        color="neutral"
        variant="ghost"
        icon="i-lucide-arrow-left"
        square
        aria-label="Voltar aos campos"
        data-testid="data-table-filter-back"
        @click="emit('back')"
      />
      <div class="ms-auto flex flex-wrap items-center justify-end gap-2">
        <UButton
          type="button"
          color="neutral"
          variant="ghost"
          label="Cancelar"
          data-testid="data-table-filter-cancel"
          @click="emit('cancel')"
        />
        <UButton
          type="button"
          color="primary"
          label="Confirmar"
          :disabled="!canConfirm || Boolean(fieldError)"
          data-testid="data-table-filter-confirm"
          @click="emit('confirm')"
        />
      </div>
    </div>
  </div>
</template>
