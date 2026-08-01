<script setup lang="ts">
import type { QuickResponsesCatalog } from '~/composables/useCommunicationQuickResponsesCatalog'

const { catalog } = defineProps<{
  catalog: QuickResponsesCatalog
}>()

const deactivateOpen = catalog.deactivateOpen
</script>

<template>
  <ShellConfirmModal
    v-model:open="deactivateOpen"
    title="Desativar resposta rápida?"
    :description="catalog.deactivateTarget.value
      ? `A resposta /${catalog.deactivateTarget.value.shortcut} deixará de aparecer no composer. O registro permanece no catálogo.`
      : ''"
    confirm-label="Desativar"
    tone="danger"
    :loading="catalog.deactivateBusy.value"
    test-id="communication-quick-response-deactivate-modal"
    @confirm="catalog.confirmDeactivate"
  />
</template>
