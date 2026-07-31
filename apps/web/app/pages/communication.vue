<script setup lang="ts">
import { isCommunicationWorkspacePath } from '~/utils/communication-routes'

/**
 * Outlet persistente da área de Communication.
 * O workspace permanece montado entre lista, conversa e mensagem; as demais
 * superfícies de Communication continuam sendo renderizadas pelo NuxtPage filho.
 */
const route = useRoute()
const usesConversationWorkspace = computed(() =>
  isCommunicationWorkspacePath(route.path))
</script>

<template>
  <CommunicationWorkspacePage v-if="usesConversationWorkspace" />
  <NuxtPage />
</template>
