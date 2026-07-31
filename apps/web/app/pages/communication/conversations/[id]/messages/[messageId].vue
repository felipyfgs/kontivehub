<script setup lang="ts">
/** Deep-link canônico de uma mensagem na timeline. */
import { parsePositiveRouteId } from '~/utils/route-params'

definePageMeta({
  middleware: [
    (to) => {
      const conversationId = parsePositiveRouteId(to.params.id)
      if (!conversationId) {
        return navigateTo('/communication', { replace: true })
      }
      if (!parsePositiveRouteId(to.params.messageId)) {
        return navigateTo(`/communication/conversations/${conversationId}`, { replace: true })
      }
    }
  ]
})
</script>

<template>
  <!-- O workspace visual pertence ao outlet persistente pages/communication.vue. -->
  <NuxtPage />
</template>
