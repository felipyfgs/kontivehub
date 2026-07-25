<script setup lang="ts">
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
    data-testid="client-page-contato"
  >
    <ShellSectionHeader
      title="Contatos"
      description="Contatos operacionais internos do escritório para este cliente."
      test-id="client-section-contato"
    />

    <UPageCard variant="subtle">
      <ClientsClientContactsSection
        :client="item"
        :can-manage-clients="canManageClients"
        @updated="load"
      />
    </UPageCard>
  </div>
</template>
