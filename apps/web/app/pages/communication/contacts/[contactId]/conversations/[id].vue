<script setup lang="ts">
/** Conversa aberta dentro do contexto estável do contato. */
import { parsePositiveRouteId } from '~/utils/route-params'

const route = useRoute()
const contactId = parsePositiveRouteId(route.params.contactId)
const conversationId = parsePositiveRouteId(route.params.id)
if (!contactId) {
  await navigateTo('/communication', { replace: true })
} else if (!conversationId) {
  await navigateTo(`/communication/contacts/${contactId}/conversations`, { replace: true })
}
</script>

<template>
  <!-- O workspace visual pertence ao outlet persistente pages/communication.vue. -->
  <NuxtPage />
</template>
