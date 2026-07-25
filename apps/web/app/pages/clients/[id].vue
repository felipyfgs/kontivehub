<script setup lang="ts">
/**
 * Shell do detalhe do cliente — header de identidade + abas + sidebar.
 * Layout master (main ~2/3 + aside ~1/3), não settings puro.
 */
import type { Client, ClientCredential, Establishment } from '~/types/api'
import { clientDetailKey, clientSectionPath } from '~/composables/useClientDetail'
import {
  clientDetailHref,
  legacySectionToHref,
  queryToClientDetailHref
} from '~/utils/client-detail-tabs'
import { clientNavigationMenu } from '~/utils/client-detail-navigation'
import type { ClientDetailPanel, ClientDetailTab } from '~/utils/client-detail-tabs'

const route = useRoute()
const router = useRouter()
const api = useApi()
const toast = useToast()
const {
  canManageClients,
  canManageCredentials,
  canTriggerSync
} = useDashboard()

const clientId = computed(() => Number(route.params.id))
const item = ref<Client | null>(null)
const credential = ref<ClientCredential | null>(null)
const loading = ref(true)
const triggeringId = ref<number | null>(null)
const triggeredIds = ref<number[]>([])
const clientFormOpen = ref(false)
const credentialSlideoverOpen = ref(false)

const establishments = computed(() => item.value?.establishments || [])

const shareholdersCount = computed(() => {
  const primary = item.value?.establishments?.find(e => e.is_matrix)
    || item.value?.establishments?.[0]
  return (primary?.shareholders || []).length
})

const links = computed(() =>
  clientNavigationMenu(clientId.value, route.path)
)

async function load() {
  loading.value = true
  const id = clientId.value
  if (!Number.isInteger(id) || id <= 0) {
    item.value = null
    loading.value = false
    return
  }

  try {
    item.value = (await api.clients.get(id)).data
    if (canManageCredentials.value) {
      credential.value = (await api.credentials.get(id)).data
    } else {
      credential.value = null
    }
  } catch (caught) {
    item.value = null
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível carregar o cliente.'), color: 'error' })
  } finally {
    loading.value = false
  }
}

async function triggerSync(establishment: Establishment) {
  if (!canTriggerSync.value) {
    return
  }

  triggeringId.value = establishment.id
  try {
    await api.sync.trigger(establishment.id)
    if (!triggeredIds.value.includes(establishment.id)) {
      triggeredIds.value.push(establishment.id)
    }
    toast.add({ title: `Sincronização de ${establishment.cnpj} enfileirada.`, color: 'success' })
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível iniciar a sincronização.'), color: 'error' })
  } finally {
    triggeringId.value = null
  }
}

function onCredentialActivated(value: ClientCredential) {
  credential.value = value
  if (item.value) {
    item.value = {
      ...item.value,
      credential_summary: {
        status: value.status,
        valid_to: value.valid_to,
        expires_alert_30: value.expires_alert_30,
        expires_alert_7: value.expires_alert_7,
        expires_alert_1: value.expires_alert_1
      }
    }
  }
}

function sectionPath(section?: string) {
  return clientSectionPath(clientId.value, section)
}

function goToTab(tab: ClientDetailTab, panel?: ClientDetailPanel) {
  void navigateTo(clientDetailHref(clientId.value, tab, panel))
}

function openClientEdit() {
  if (!item.value || !canManageClients.value) return
  clientFormOpen.value = true
}

async function onClientFormSaved() {
  clientFormOpen.value = false
  await load()
}

provide(clientDetailKey, {
  clientId,
  item,
  credential,
  loading,
  establishments,
  triggeringId,
  triggeredIds,
  canManageClients,
  canManageCredentials,
  canTriggerSync,
  load,
  triggerSync,
  onCredentialActivated,
  sectionPath,
  goToTab,
  openClientEdit
})

/** `/clients/:id` → `/clients/:id/cadastro` */
async function ensureCanonicalChild() {
  const path = route.path.replace(/\/+$/, '')
  if (!/^\/clients\/\d+$/.test(path)) return

  if (typeof route.query.tab === 'string' || typeof route.query.section === 'string') {
    const fromSection = typeof route.query.section === 'string'
      ? legacySectionToHref(clientId.value, route.query.section)
      : null
    const href = fromSection || queryToClientDetailHref(clientId.value, route.query as Record<string, unknown>)
    await router.replace(href)
    return
  }

  await router.replace(clientDetailHref(clientId.value, 'cadastro'))
}

/** Query legada em qualquer filho → path. */
async function migrateLegacyQuery() {
  if (typeof route.query.tab !== 'string' && typeof route.query.section !== 'string') return
  const fromSection = typeof route.query.section === 'string'
    ? legacySectionToHref(clientId.value, route.query.section)
    : null
  const href = fromSection || queryToClientDetailHref(clientId.value, route.query as Record<string, unknown>)
  await router.replace(href)
}

watch(clientId, () => {
  triggeredIds.value = []
  load()
})

watch(
  () => route.fullPath,
  async () => {
    await ensureCanonicalChild()
    await migrateLegacyQuery()
  }
)

onMounted(async () => {
  await ensureCanonicalChild()
  await migrateLegacyQuery()
  await load()
})
</script>

<template>
  <ShellPagePanel
    id="client-detail"
    test-id="client-detail-panel"
    body-class="lg:py-8"
  >
    <template #header>
      <ShellPageNavbar title="Cliente">
        <template #leading>
          <ShellNavbarBack
            to="/clients"
            label="Voltar aos clientes"
            aria-label="Voltar aos clientes"
            test-id="client-detail-back"
          />
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar
        v-if="item"
        data-testid="client-section-tabs"
      >
        <UNavigationMenu
          :items="links"
          highlight
          class="-mx-1 flex-1"
          data-testid="client-section-navigation"
          aria-label="Navegação do cliente"
        />
      </UDashboardToolbar>
    </template>

    <div
      v-if="loading && !item"
      class="mx-auto w-full max-w-none space-y-4 px-4 py-4 sm:px-6"
      role="status"
      aria-label="Carregando cliente"
    >
      <USkeleton class="h-24 w-full rounded-xl" />
      <div class="grid gap-4 lg:grid-cols-12">
        <USkeleton class="h-96 rounded-xl lg:col-span-8" />
        <USkeleton class="h-96 rounded-xl lg:col-span-4" />
      </div>
    </div>

    <UEmpty
      v-else-if="!item"
      icon="i-lucide-building-2"
      title="Cliente não encontrado"
      description="O registro não existe ou pertence a outro escritório."
      class="mx-auto max-w-lg py-16"
    >
      <UButton
        to="/clients"
        label="Voltar para clientes"
      />
    </UEmpty>

    <div
      v-else
      class="mx-auto flex w-full max-w-none flex-col gap-4 px-4 py-4 sm:gap-6 sm:px-6 lg:px-8"
    >
      <ClientsClientIdentityHeader
        :client="item"
        :can-manage-clients="canManageClients"
        @edit="openClientEdit"
      />

      <div class="grid min-w-0 gap-4 lg:grid-cols-12 lg:gap-6">
        <div class="min-w-0 lg:col-span-8">
          <NuxtPage />
        </div>
        <div class="min-w-0 lg:col-span-4">
          <ClientsClientDetailAside
            :client="item"
            :credential="credential"
            :can-manage-credentials="canManageCredentials"
            :shareholders-count="shareholdersCount"
            @manage-credential="credentialSlideoverOpen = true"
          />
        </div>
      </div>
    </div>

    <USlideover
      v-model:open="credentialSlideoverOpen"
      title="Certificado A1"
      description="Upload e gestão do certificado digital deste cliente."
    >
      <template #body>
        <ClientsClientCredentialPanel
          v-if="item"
          :client-id="item.id"
          :credential="credential"
          :credential-summary="item.credential_summary"
          :can-manage-credentials="canManageCredentials"
          :show-header="false"
          @activated="onCredentialActivated"
        />
      </template>
    </USlideover>

    <ClientsClientFormModal
      v-model:open="clientFormOpen"
      :client="item"
      :can-manage-clients="canManageClients"
      :can-manage-credentials="false"
      @saved="onClientFormSaved"
    />
  </ShellPagePanel>
</template>
