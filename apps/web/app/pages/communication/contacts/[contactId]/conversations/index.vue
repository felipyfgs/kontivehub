<script setup lang="ts">
/** Contexto estável de histórico de conversas de um contato. */
import { parsePositiveRouteId } from '~/utils/route-params'

const route = useRoute()
const contactId = parsePositiveRouteId(route.params.contactId)
if (!contactId) {
  await navigateTo('/communication', { replace: true })
}
</script>

<template>
  <!-- O workspace visual pertence ao outlet persistente pages/communication.vue. -->
  <NuxtPage />
</template>
