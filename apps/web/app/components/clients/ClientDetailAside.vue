<script setup lang="ts">
/**
 * Coluna direita do detalhe do cliente — widgets persistentes.
 */
import type { Client, ClientCredential } from '~/types/api'

defineProps<{
  client: Client
  credential: ClientCredential | null
  canManageCredentials: boolean
  shareholdersCount: number
}>()

const emit = defineEmits<{
  manageCredential: []
}>()
</script>

<template>
  <aside
    class="flex min-w-0 flex-col gap-4"
    data-testid="client-detail-aside"
  >
    <ClientsClientIntegrationProgress
      :client="client"
      :credential="credential"
    />
    <ClientsClientCredentialWidget
      :client="client"
      :credential="credential"
      :can-manage-credentials="canManageCredentials"
      @manage="emit('manageCredential')"
    />
    <ClientsClientDocumentsWidget
      :client-id="client.id"
      :shareholders-count="shareholdersCount"
    />
    <ClientsClientQuickFolders :client-id="client.id" />
  </aside>
</template>
