<script setup lang="ts">
/**
 * Dados adicionais — campos personalizados + captura (ex-Configuração).
 */
const {
  item,
  canManageClients,
  load
} = useClientDetail()
</script>

<template>
  <div
    v-if="item"
    class="min-w-0 space-y-4"
    data-testid="client-page-dados-adicionais"
  >
    <ShellSectionHeader
      title="Dados adicionais"
      description="Campos personalizados e canais de captura deste cliente."
      test-id="client-section-dados-adicionais"
    />

    <ClientsClientAdditionalDataPanel
      :client="item"
      :can-manage-clients="canManageClients"
      @updated="load"
    />
  </div>
</template>
