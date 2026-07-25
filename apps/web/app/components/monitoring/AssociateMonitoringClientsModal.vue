<script setup lang="ts">
/**
 * Associar / excluir clientes da carteira de monitoramento (opt-out por módulo).
 */
import type { Client } from '~/types/api'
import { formatCnpj } from '~/utils/format'
import { apiErrorMessage } from '~/utils/api-error'
import {
  monitoringAssociateClientListFilters,
  monitoringAssociateScopeLabel
} from '~/utils/monitoring-associate-filters'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  moduleKey: string
  submodule?: string | null
  /** Rótulo amigável (ex.: DAS do Simples). */
  surfaceLabel?: string
}>()

const emit = defineEmits<{
  success: []
}>()

const api = useApi()
const toast = useToast()

const query = ref('')
const loading = ref(false)
const busy = ref(false)
const loadError = ref<string | null>(null)
const clients = ref<Client[]>([])
const excludedIds = ref<Set<number>>(new Set())
const selected = ref<Record<string, boolean>>({})

const scopeLabel = computed(() =>
  monitoringAssociateScopeLabel(props.moduleKey, props.submodule)
)

const title = computed(() => 'Associar clientes')
const description = computed(() =>
  `Adicione ou remova clientes do monitoramento de ${props.surfaceLabel || props.moduleKey} (${scopeLabel.value}).`
)

const selectedIds = computed(() =>
  Object.entries(selected.value)
    .filter(([, v]) => v)
    .map(([id]) => Number(id))
    .filter(id => id >= 1)
)

async function loadExclusions() {
  const res = await api.fiscal.monitoringMembership.list({
    module: props.moduleKey,
    submodule: props.submodule
  })
  excludedIds.value = new Set(
    (res.data || [])
      .map(row => Number(row.client_id))
      .filter(id => id >= 1)
  )
}

async function searchClients() {
  loading.value = true
  loadError.value = null
  try {
    const res = await api.clients.list({
      q: query.value.trim() || undefined,
      per_page: 40,
      page: 1,
      ...monitoringAssociateClientListFilters(props.moduleKey, props.submodule)
    })
    clients.value = res.data || []
    await loadExclusions()
  } catch (caught) {
    loadError.value = apiErrorMessage(caught, 'Falha ao buscar clientes.')
    clients.value = []
  } finally {
    loading.value = false
  }
}

function isExcluded(clientId: number): boolean {
  return excludedIds.value.has(clientId)
}

async function includeIds(ids: number[]) {
  if (!ids.length) return
  busy.value = true
  try {
    const res = await api.fiscal.monitoringMembership.include({
      module: props.moduleKey,
      submodule: props.submodule,
      client_ids: ids
    })
    const errors = res.data?.errors || []
    if (errors.length) {
      toast.add({
        title: 'Inclusão parcial',
        description: errors[0]?.message || 'Alguns clientes não puderam ser incluídos.',
        color: 'warning'
      })
    } else {
      toast.add({ title: 'Clientes incluídos no monitoramento', color: 'success' })
    }
    selected.value = {}
    await searchClients()
    emit('success')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao incluir.'),
      color: 'error'
    })
  } finally {
    busy.value = false
  }
}

async function excludeIds(ids: number[]) {
  if (!ids.length) return
  busy.value = true
  try {
    await api.fiscal.monitoringMembership.exclude({
      module: props.moduleKey,
      submodule: props.submodule,
      client_ids: ids
    })
    toast.add({ title: 'Clientes removidos do monitoramento', color: 'success' })
    selected.value = {}
    await searchClients()
    emit('success')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao excluir.'),
      color: 'error'
    })
  } finally {
    busy.value = false
  }
}

function toggleAllVisible() {
  const allSelected = clients.value.every(c => selected.value[String(c.id)])
  const next = { ...selected.value }
  for (const c of clients.value) {
    next[String(c.id)] = !allSelected
  }
  selected.value = next
}

watch(open, (isOpen) => {
  if (isOpen) {
    query.value = ''
    selected.value = {}
    void searchClients()
  }
})

let debounce: ReturnType<typeof setTimeout> | null = null
watch(query, () => {
  if (debounce) {
    clearTimeout(debounce)
  }
  debounce = setTimeout(() => {
    void searchClients()
  }, 280)
})
</script>

<template>
  <ShellScrollableModal
    v-model:open="open"
    :title="title"
    :description="description"
    content-class="w-[calc(100vw-1rem)] sm:max-w-2xl"
    :show-default-footer="false"
    data-testid="associate-monitoring-clients-modal"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="loadError"
          color="error"
          :title="loadError"
        />

        <div class="flex flex-wrap items-center gap-2">
          <UButton
            size="sm"
            color="neutral"
            variant="soft"
            icon="i-lucide-check-check"
            label="Selecionar todos"
            data-testid="associate-select-all"
            @click="toggleAllVisible"
          />
          <UInput
            v-model="query"
            class="min-w-0 flex-1"
            icon="i-lucide-search"
            placeholder="Buscar por nome ou CNPJ..."
            data-testid="associate-search"
          />
        </div>

        <div
          class="max-h-80 overflow-auto rounded-lg border border-default"
          data-testid="associate-client-list"
        >
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-elevated text-left text-xs text-muted">
              <tr>
                <th class="w-10 px-3 py-2" />
                <th class="px-3 py-2">
                  CNPJ
                </th>
                <th class="px-3 py-2">
                  Razão social
                </th>
                <th class="w-24 px-3 py-2 text-right">
                  Ação
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td
                  colspan="4"
                  class="px-3 py-8 text-center text-muted"
                >
                  Carregando…
                </td>
              </tr>
              <tr v-else-if="!clients.length">
                <td
                  colspan="4"
                  class="px-3 py-8 text-center text-muted"
                >
                  Nenhum cliente encontrado
                </td>
              </tr>
              <tr
                v-for="c in clients"
                :key="c.id"
                class="border-t border-default"
              >
                <td class="px-3 py-2">
                  <UCheckbox v-model="selected[String(c.id)]" />
                </td>
                <td class="px-3 py-2 font-mono text-xs">
                  {{ formatCnpj(c.cnpj || c.root_cnpj) }}
                </td>
                <td class="px-3 py-2">
                  {{ c.display_name || c.legal_name || c.name || `Cliente #${c.id}` }}
                  <UBadge
                    v-if="isExcluded(c.id)"
                    class="ml-2"
                    size="xs"
                    color="neutral"
                    variant="subtle"
                    label="Removido"
                  />
                </td>
                <td class="px-3 py-2 text-right">
                  <UButton
                    v-if="isExcluded(c.id)"
                    size="xs"
                    color="success"
                    variant="soft"
                    icon="i-lucide-plus"
                    square
                    aria-label="Incluir no monitoramento"
                    :loading="busy"
                    data-testid="associate-row-include"
                    @click="includeIds([c.id])"
                  />
                  <UButton
                    v-else
                    size="xs"
                    color="error"
                    variant="soft"
                    icon="i-lucide-minus"
                    square
                    aria-label="Excluir do monitoramento"
                    :loading="busy"
                    data-testid="associate-row-exclude"
                    @click="excludeIds([c.id])"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Fechar"
          @click="() => { open = false }"
        />
        <UButton
          color="error"
          variant="soft"
          icon="i-lucide-user-minus"
          label="Excluir selecionados"
          :disabled="!selectedIds.length || busy"
          data-testid="associate-exclude-selected"
          @click="excludeIds(selectedIds)"
        />
        <UButton
          color="primary"
          icon="i-lucide-plus"
          label="Adicionar selecionados"
          :disabled="!selectedIds.length || busy"
          :loading="busy"
          data-testid="associate-include-selected"
          @click="includeIds(selectedIds)"
        />
      </div>
    </template>
  </ShellScrollableModal>
</template>
