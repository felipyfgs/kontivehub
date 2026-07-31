<script setup lang="ts">
/**
 * Deep-link canônico: /communication/conversations/:id
 * O shell compartilhado sincroniza route.params.id com a seleção.
 */
import { parsePositiveRouteId } from '~/utils/route-params'

definePageMeta({
  middleware: [
    (to) => {
      if (!parsePositiveRouteId(to.params.id)) {
        return navigateTo('/communication', { replace: true })
      }
    }
  ]
})
</script>

<template>
  <CommunicationWorkspacePage />
  <NuxtPage />
</template>
