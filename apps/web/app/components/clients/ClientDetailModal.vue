<script setup lang="ts">
/**
 * Modal de cliente — subset: Cadastro · Contato · Dados adicionais.
 */
import type { Client } from '~/types/api'
import type { ClientDetailTab, ClientModalTab } from '~/utils/client-detail-tabs'
import {
  clientDetailHref,
  clientModalTabItems
} from '~/utils/client-detail-tabs'

type ModalSection = 'resumo' | 'cadastro' | 'contato' | 'configuracao' | 'dados-adicionais' | 'estabelecimentos' | 'certificado' | 'sincronizacao'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  clientId: number | null
  initialSection?: ModalSection
}>()

const emit = defineEmits<{
  updated: []
}>()

const api = useApi()
const toast = useToast()
const { canManageClients } = useDashboard()

const activeTab = ref<ClientModalTab>('cadastro')
const item = ref<Client | null>(null)
const loading = ref(false)
const formOpen = ref(false)

const title = computed(() =>
  item.value?.display_name || item.value?.legal_name || item.value?.name || 'Cliente'
)

const description = computed(() => {
  if (!item.value) {
    return loading.value ? 'Carregando detalhes…' : 'Detalhes do cliente'
  }
  const cnpj = item.value.cnpj || item.value.establishments?.[0]?.cnpj || item.value.root_cnpj
  return `CNPJ ${cnpj}`
})

const primaryItems = clientModalTabItems()

function mapInitialSection(section?: ModalSection) {
  switch (section) {
    case 'contato':
      activeTab.value = 'contato'
      break
    case 'configuracao':
    case 'dados-adicionais':
    case 'certificado':
    case 'sincronizacao':
      activeTab.value = 'dados-adicionais'
      break
    case 'estabelecimentos':
    case 'cadastro':
    case 'resumo':
    default:
      activeTab.value = 'cadastro'
  }
}

function onPrimaryChange(value: string | number) {
  activeTab.value = value as ClientModalTab
}

async function load() {
  if (!props.clientId) {
    item.value = null
    return
  }
  loading.value = true
  try {
    item.value = (await api.clients.get(props.clientId)).data
  } catch (caught) {
    item.value = null
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível carregar o cliente.'), color: 'error' })
  } finally {
    loading.value = false
  }
}

async function reload() {
  await load()
  emit('updated')
}

function openEditForm() {
  if (!canManageClients.value || !item.value) return
  activeTab.value = 'cadastro'
  formOpen.value = true
}

async function onFormSaved() {
  formOpen.value = false
  await reload()
}

watch(
  () => [open.value, props.clientId, props.initialSection] as const,
  ([isOpen]) => {
    if (!isOpen) return
    mapInitialSection(props.initialSection)
    formOpen.value = false
    void load()
  }
)
</script>

<template>
  <ShellScrollableModal
    v-model:open="open"
    :title="title"
    :description="description"
    :ui="{ content: 'sm:max-w-4xl' }"
    test-id="client-detail-modal"
  >
    <template #body>
      <div class="space-y-3 border-b border-default px-4 py-3 sm:px-6">
        <ShellScrollableTabs
          :model-value="activeTab"
          :items="primaryItems"
          color="primary"
          variant="pill"
          class="w-full"
          aria-label="Seções do modal de cliente"
          test-id="client-modal-primary-tabs"
          @update:model-value="onPrimaryChange"
        />
      </div>

      <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4 sm:px-6 sm:py-5">
        <ShellLoadingModalBody
          v-if="loading && !item"
          :rows="3"
          test-id="client-detail-loading"
        />

        <UEmpty
          v-else-if="!item"
          icon="i-lucide-building-2"
          title="Cliente não encontrado"
          description="O registro não existe ou pertence a outro escritório."
        />

        <template v-else>
          <ClientsClientRegistration
            v-if="activeTab === 'cadastro'"
            :client="item"
            :can-manage-clients="canManageClients"
            panel="dados"
            @edit="openEditForm"
            @updated="reload"
          />

          <ClientsClientContactsSection
            v-else-if="activeTab === 'contato'"
            :client="item"
            :can-manage-clients="canManageClients"
            @updated="reload"
          />

          <ClientsClientAdditionalDataPanel
            v-else
            :client="item"
            :can-manage-clients="canManageClients"
            @updated="reload"
          />
        </template>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-muted">
          <template v-if="item">
            CNPJ {{ item.cnpj || item.establishments?.[0]?.cnpj || item.root_cnpj }}
          </template>
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <UButton
            v-if="item && canManageClients && activeTab === 'cadastro'"
            color="primary"
            variant="soft"
            size="sm"
            icon="i-lucide-pencil"
            label="Editar cadastro"
            data-testid="client-detail-edit"
            @click="openEditForm"
          />
          <UButton
            v-if="item"
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-external-link"
            label="Página"
            :to="clientDetailHref(item.id, activeTab as ClientDetailTab)"
          />
          <UButton
            color="neutral"
            variant="subtle"
            size="sm"
            label="Fechar"
            @click="() => { open = false }"
          />
        </div>
      </div>
    </template>
  </ShellScrollableModal>

  <ClientsClientFormModal
    v-model:open="formOpen"
    :client="item"
    :can-manage-clients="canManageClients"
    :can-manage-credentials="false"
    @saved="onFormSaved"
  />
</template>
