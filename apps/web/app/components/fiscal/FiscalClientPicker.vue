<script setup lang="ts">
/**
 * Busca server-side de cliente (razão social, fantasia, CNPJ).
 *
 * Multi: padrão bazza/ui data-table-filter (FilterValue multiOption) —
 * Command-style: busca + checklist (selecionados no topo) + ação "Selecionar todos".
 * Embutido no painel (sem portal) para não fechar o UPopover do filtro.
 *
 * Single: UInputMenu.
 * Query: UInput livre.
 */
import type { Client } from '~/types/api'
import { formatCnpj, normalizeCnpj } from '~/utils/format'

type ClientItem = { label: string, value: number, client: Client }

const props = withDefaults(defineProps<{
  /** Single: number | null. Multiple: number[]. */
  modelValue?: number | number[] | null
  multiple?: boolean
  searchMode?: 'select' | 'query'
  placeholder?: string
  disabled?: boolean
  class?: string
  /** Multi: quantos carregar na abertura (sem digitar). */
  initialPerPage?: number
}>(), {
  modelValue: null,
  multiple: false,
  searchMode: 'select',
  placeholder: 'Buscar…',
  initialPerPage: 40
})

const emit = defineEmits<{
  'update:modelValue': [value: number | number[] | null]
  'update:query': [value: string]
  'select': [client: Client | null]
  'select-many': [clients: Client[]]
}>()

const api = useApi()

const searchTerm = ref('')
const items = ref<ClientItem[]>([])
const selectedCache = ref<Map<number, ClientItem>>(new Map())
const loading = ref(false)
let debounceTimer: ReturnType<typeof setTimeout> | null = null
let requestGen = 0

function toItem(c: Client): ClientItem {
  const cnpj = c.cnpj || c.root_cnpj
  const name = c.display_name || c.legal_name || c.name
  const cnpjLabel = cnpj ? formatCnpj(cnpj) : ''
  return {
    label: cnpjLabel ? `${name} · ${cnpjLabel}` : String(name || `Cliente #${c.id}`),
    value: c.id,
    client: c
  }
}

const selectedIds = computed(() => {
  if (!props.multiple) return [] as number[]
  const v = props.modelValue
  if (Array.isArray(v)) return v.filter(id => Number.isFinite(id) && id >= 1).map(id => Math.floor(id))
  if (typeof v === 'number' && v >= 1) return [v]
  return []
})

const selectedSingle = computed<number | undefined>({
  get: () => {
    if (props.multiple) return undefined
    const v = props.modelValue
    return typeof v === 'number' && v >= 1 ? v : undefined
  },
  set: (v: number | undefined) => {
    emit('update:modelValue', v ?? null)
    const found = items.value.find(i => i.value === v) || selectedCache.value.get(v ?? 0)
    emit('select', found?.client ?? null)
  }
})

function matchesSearch(item: ClientItem, term: string): boolean {
  const raw = term.trim()
  if (!raw) return true
  const hay = item.label.toLowerCase()
  if (hay.includes(raw.toLowerCase())) return true
  const digits = normalizeCnpj(raw)
  if (digits.length >= 3) {
    const cnpj = normalizeCnpj(item.client.cnpj || item.client.root_cnpj || '')
    if (cnpj.includes(digits)) return true
  }
  return false
}

/** Itens visíveis (API + cache de selecionados), filtrados pelo termo. */
const visibleItems = computed(() => {
  const byId = new Map(items.value.map(item => [item.value, item]))
  for (const id of selectedIds.value) {
    const cached = selectedCache.value.get(id)
    if (cached && !byId.has(id)) byId.set(id, cached)
  }
  let list = [...byId.values()]
  if (props.multiple && searchTerm.value.trim()) {
    list = list.filter(item => matchesSearch(item, searchTerm.value))
  }
  list.sort((a, b) => a.label.localeCompare(b.label, 'pt-BR'))
  return list
})

/**
 * Padrão bazza: grupo de já selecionados (ordem estável) + demais.
 * Agrupa pelo estado atual (não “initialSelected”) para o checklist refletir o toggle.
 */
const selectedVisible = computed(() =>
  visibleItems.value.filter(item => selectedIds.value.includes(item.value))
)
const unselectedVisible = computed(() =>
  visibleItems.value.filter(item => !selectedIds.value.includes(item.value))
)

// Single InputMenu ainda usa lista plana
const listItems = computed(() => {
  const byId = new Map(items.value.map(item => [item.value, item]))
  for (const id of selectedIds.value) {
    const cached = selectedCache.value.get(id)
    if (cached && !byId.has(id)) byId.set(id, cached)
  }
  return [...byId.values()].sort((a, b) => a.label.localeCompare(b.label, 'pt-BR'))
})

function emitMulti(ids: number[]) {
  const unique = [...new Set(ids.map(id => Math.floor(Number(id))).filter(id => id >= 1))]
  emit('update:modelValue', unique)
  const clients = unique
    .map(id => selectedCache.value.get(id)?.client || items.value.find(i => i.value === id)?.client)
    .filter((c): c is Client => c != null)
  emit('select-many', clients)
  emit('select', clients[clients.length - 1] ?? null)
}

function toggleClient(item: ClientItem) {
  if (props.disabled) return
  selectedCache.value.set(item.value, item)
  const set = new Set(selectedIds.value)
  if (set.has(item.value)) set.delete(item.value)
  else set.add(item.value)
  emitMulti([...set])
}

function _isSelected(id: number) {
  return selectedIds.value.includes(id)
}

const allVisibleSelected = computed(() => {
  const visible = visibleItems.value
  if (!visible.length) return false
  return visible.every(item => selectedIds.value.includes(item.value))
})

const canToggleAll = computed(() => visibleItems.value.length > 0 && !props.disabled)

/** Marca/desmarca todos os visíveis (respeita busca) — ação da lista Command. */
function toggleSelectAllVisible() {
  if (!canToggleAll.value) return
  const visible = visibleItems.value
  if (allVisibleSelected.value) {
    const drop = new Set(visible.map(item => item.value))
    emitMulti(selectedIds.value.filter(id => !drop.has(id)))
    return
  }
  for (const item of visible) selectedCache.value.set(item.value, item)
  const set = new Set(selectedIds.value)
  for (const item of visible) set.add(item.value)
  emitMulti([...set])
}

async function fetchClients(term: string, opts?: { initial?: boolean }) {
  const gen = ++requestGen
  const raw = term.trim()
  if (!opts?.initial && raw.length === 0) {
    return fetchClients('', { initial: true })
  }

  loading.value = true
  try {
    const digits = normalizeCnpj(raw)
    const q = digits.length >= 8 && /^[A-Z0-9]+$/i.test(digits) && digits.length <= 14
      ? digits
      : raw

    const res = await api.clients.list({
      ...(q ? { q } : {}),
      per_page: opts?.initial ? props.initialPerPage : 30,
      page: 1
    })
    if (gen !== requestGen) return
    items.value = (res.data || []).map(toItem)
    for (const item of items.value) {
      if (selectedIds.value.includes(item.value) || selectedSingle.value === item.value) {
        selectedCache.value.set(item.value, item)
      }
    }
  } catch {
    if (gen !== requestGen) return
    items.value = []
  } finally {
    if (gen === requestGen) loading.value = false
  }
}

function onSearchChange(term: string | number) {
  const next = String(term ?? '')
  searchTerm.value = next
  emit('update:query', next)
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    void fetchClients(next, { initial: next.trim().length === 0 })
  }, 220)
}

function onQueryInput(value: string | number) {
  const term = String(value ?? '')
  searchTerm.value = term
  emit('update:query', term)
}

onMounted(() => {
  if (props.searchMode === 'select') {
    void fetchClients('', { initial: true })
  }
})

onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
})
</script>

<template>
  <div
    data-testid="fiscal-client-picker"
    :class="props.class || 'w-56 sm:w-72'"
  >
    <!--
      Multi — bazza FilterValue multiOption:
      CommandInput + CommandList (selecionados | demais) + item de bulk.
    -->
    <div
      v-if="searchMode === 'select' && multiple"
      class="flex min-w-0 flex-col overflow-hidden rounded-md border border-default"
      data-testid="fiscal-client-picker-multi"
    >
      <UInput
        :model-value="searchTerm"
        type="search"
        :placeholder="placeholder || 'Buscar…'"
        icon="i-lucide-search"
        :loading="loading"
        :disabled="disabled"
        autocomplete="off"
        variant="none"
        class="w-full min-w-0"
        :ui="{
          base: 'rounded-none border-0 border-b border-default focus-visible:ring-0'
        }"
        aria-label="Buscar clientes"
        data-testid="fiscal-client-picker-search"
        @update:model-value="onSearchChange"
      />

      <div
        class="max-h-60 overflow-y-auto"
        role="listbox"
        aria-multiselectable="true"
        aria-label="Clientes"
        data-testid="fiscal-client-picker-list"
      >
        <p
          v-if="loading && !visibleItems.length"
          class="px-3 py-6 text-center text-sm text-muted"
        >
          Carregando…
        </p>
        <p
          v-else-if="!visibleItems.length"
          class="px-3 py-6 text-center text-sm text-muted"
        >
          Nenhum resultado
        </p>

        <template v-else>
          <!-- Bulk: primeira ação da lista (não vira chip/texto solto). -->
          <button
            type="button"
            class="flex w-full min-w-0 items-center gap-2 px-2.5 py-2 text-left text-sm text-muted hover:bg-elevated/80 focus-visible:bg-elevated focus-visible:outline-none"
            :disabled="!canToggleAll"
            data-testid="fiscal-client-picker-select-all"
            @click.stop.prevent="toggleSelectAllVisible"
          >
            <UCheckbox
              :model-value="allVisibleSelected
                ? true
                : (selectedVisible.length > 0 ? 'indeterminate' : false)"
              :disabled="!canToggleAll"
              tabindex="-1"
              class="pointer-events-none shrink-0"
            />
            <span class="min-w-0 flex-1 truncate">
              {{ allVisibleSelected ? 'Limpar' : 'Selecionar todos' }}
            </span>
            <span
              v-if="selectedIds.length"
              class="shrink-0 text-xs tabular-nums text-dimmed"
            >
              {{ selectedIds.length }}
            </span>
          </button>

          <div
            class="mx-2 border-t border-default"
            role="separator"
          />

          <!-- Grupo: selecionados (bazza selectedOptions) -->
          <template v-if="selectedVisible.length">
            <button
              v-for="item in selectedVisible"
              :key="`s-${item.value}`"
              type="button"
              role="option"
              :aria-selected="true"
              class="group flex w-full min-w-0 items-center gap-2 px-2.5 py-2 text-left text-sm hover:bg-elevated/80 focus-visible:bg-elevated focus-visible:outline-none"
              :disabled="disabled"
              data-testid="fiscal-client-picker-option"
              @click.stop.prevent="toggleClient(item)"
            >
              <UCheckbox
                :model-value="true"
                :disabled="disabled"
                tabindex="-1"
                class="pointer-events-none shrink-0"
              />
              <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
            </button>
            <div
              v-if="unselectedVisible.length"
              class="mx-2 border-t border-default"
              role="separator"
            />
          </template>

          <!-- Grupo: não selecionados -->
          <button
            v-for="item in unselectedVisible"
            :key="`u-${item.value}`"
            type="button"
            role="option"
            :aria-selected="false"
            class="group flex w-full min-w-0 items-center gap-2 px-2.5 py-2 text-left text-sm hover:bg-elevated/80 focus-visible:bg-elevated focus-visible:outline-none"
            :disabled="disabled"
            data-testid="fiscal-client-picker-option"
            @click.stop.prevent="toggleClient(item)"
          >
            <UCheckbox
              :model-value="false"
              :disabled="disabled"
              tabindex="-1"
              class="pointer-events-none shrink-0 opacity-40 group-hover:opacity-100"
            />
            <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
          </button>
        </template>
      </div>
    </div>

    <UInputMenu
      v-else-if="searchMode === 'select'"
      v-model="selectedSingle"
      v-model:search-term="searchTerm"
      :items="listItems"
      value-key="value"
      label-key="label"
      :loading="loading"
      :disabled="disabled"
      :placeholder="placeholder"
      icon="i-lucide-user-search"
      trailing-icon="i-lucide-chevrons-up-down"
      ignore-filter
      class="w-full"
      aria-label="Selecionar cliente"
      :ui="{ content: 'z-[100]' }"
      @update:search-term="onSearchChange"
      @update:open="(open: boolean) => { if (open && !items.length) void fetchClients('', { initial: true }) }"
    >
      <template #empty>
        <span class="text-sm text-muted">
          {{ loading ? 'Carregando…' : 'Nenhum resultado' }}
        </span>
      </template>
    </UInputMenu>

    <UInput
      v-else
      :model-value="searchTerm"
      :placeholder="placeholder"
      icon="i-lucide-search"
      :disabled="disabled"
      class="w-full"
      aria-label="Buscar cliente"
      data-testid="fiscal-client-query"
      @update:model-value="onQueryInput"
      @keyup.enter="emit('update:query', searchTerm)"
    />
  </div>
</template>
