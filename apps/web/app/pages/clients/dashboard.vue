<script setup lang="ts">
/**
 * Subpágina Dashboard de clientes (submenu Settings-style).
 * Conteúdo: arquétipo Home (stats + chart Unovis + tabela).
 */
import type { Client, ClientListStats } from '~/types/api'
import { useClientsCatalogChrome } from '~/composables/useClientsCatalogChrome'

const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()
const catalogChrome = useClientsCatalogChrome()

const clients = ref<Client[]>([])
function emptyStats(): ClientListStats {
  return {
    total: 0,
    active: 0,
    with_credential: 0,
    credential_ok: 0,
    without_credential: 0,
    credential_expiring_30d: 0,
    credential_expired: 0,
    capture_problem: 0,
    client_growth_12m: []
  }
}

const stats = ref<ClientListStats>(emptyStats())
const loading = ref(false)
let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  if (catalogChrome) catalogChrome.loading.value = true
  try {
    const first = await api.clients.list({
      page: 1,
      per_page: 8,
      sort: 'created_at',
      direction: 'desc',
      dashboard: true
    })
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    clients.value = first.data
    stats.value = first.meta.stats || emptyStats()
  } catch (caught) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    toast.add({
      title: apiErrorMessage(caught, 'Erro ao carregar dashboard de clientes.'),
      color: 'error'
    })
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
      if (catalogChrome) catalogChrome.loading.value = false
    }
  }
}

onMounted(() => {
  catalogChrome?.registerReload(load)
  void load()
})

onBeforeUnmount(() => {
  catalogChrome?.registerReload(null)
  if (catalogChrome) catalogChrome.loading.value = false
})

watch(sessionEpoch, () => {
  loadSeq += 1
  clients.value = []
  stats.value = emptyStats()
  loading.value = false
  if (catalogChrome) catalogChrome.loading.value = false
  void load()
})
</script>

<template>
  <ClientsClientListDashboard
    :clients="clients"
    :stats="stats"
    :loading="loading"
  />
</template>
