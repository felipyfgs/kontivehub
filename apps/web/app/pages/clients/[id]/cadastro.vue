<script setup lang="ts">
/**
 * Cadastro do cliente — chrome: ShellSectionHeader + dossiê somente-leitura.
 * Atualizar RFB: busca → revisão/confirmação → grava.
 */
import type { CnpjLookupResult } from '~/types/api'

const {
  item,
  canManageClients,
  load
} = useClientDetail()

const api = useApi()
const toast = useToast()
const lookingUp = ref(false)
const applying = ref(false)
const refreshOpen = ref(false)
const refreshLookup = ref<CnpjLookupResult | null>(null)

function clientCnpj(): string | null {
  if (!item.value) return null
  const raw = item.value.cnpj
    || item.value.establishments?.find(e => e.is_matrix)?.cnpj
    || item.value.establishments?.[0]?.cnpj
    || null
  if (!raw) return null
  const normalized = raw.replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
  return normalized.length ? normalized : null
}

async function startRefreshLookup() {
  if (!item.value || !canManageClients.value || lookingUp.value || applying.value) return
  const cnpj = clientCnpj()
  if (!cnpj) {
    toast.add({ title: 'Cliente sem CNPJ para consultar.', color: 'error' })
    return
  }

  lookingUp.value = true
  try {
    const response = await api.cnpj.lookup(cnpj)
    refreshLookup.value = response.data
    refreshOpen.value = true
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível consultar o cadastro RFB.'), color: 'error' })
  } finally {
    lookingUp.value = false
  }
}

async function applyRefresh(lookup: CnpjLookupResult) {
  if (!item.value || !canManageClients.value || applying.value) return
  applying.value = true
  try {
    await api.clients.refreshRegistration(item.value.id, { lookup })
    toast.add({ title: 'Cadastro atualizado com a RFB.', color: 'success' })
    refreshOpen.value = false
    refreshLookup.value = null
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível aplicar a atualização.'), color: 'error' })
  } finally {
    applying.value = false
  }
}

function onRefreshCancel() {
  refreshLookup.value = null
}
</script>

<template>
  <div
    v-if="item"
    class="min-w-0"
    data-testid="client-page-cadastro"
  >
    <ShellSectionHeader
      title="Dados cadastrais"
      description="Veja as informações essenciais deste cliente de forma clara e organizada."
      test-id="client-section-cadastro"
    >
      <UButton
        v-if="canManageClients"
        color="neutral"
        variant="soft"
        icon="i-lucide-refresh-cw"
        label="Atualizar"
        :loading="lookingUp"
        data-testid="client-cadastro-refresh"
        @click="startRefreshLookup"
      />
    </ShellSectionHeader>

    <ClientsClientRegistration
      :client="item"
      :can-manage-clients="canManageClients"
      panel="all"
      @updated="load"
    />

    <ClientsClientRegistrationRefreshModal
      v-model:open="refreshOpen"
      :client="item"
      :lookup="refreshLookup"
      :can-manage-clients="canManageClients"
      :applying="applying"
      @confirm="applyRefresh"
      @cancel="onRefreshCancel"
    />
  </div>
</template>
