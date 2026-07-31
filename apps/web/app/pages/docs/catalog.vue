<script setup lang="ts">
import {
  normalizeDocumentContextKind,
  parseDocumentClientId
} from '~/utils/document-routes'

const route = useRoute()
const initialKind = computed(() => normalizeDocumentContextKind(route.params.kind) ?? '')
const initialClientId = computed(() => parseDocumentClientId(route.params.clientId) ?? '')
</script>

<template>
  <DocsWorkspace
    :key="`${initialKind}:${initialClientId}`"
    initial-view="document"
    :initial-kind="initialKind"
    :initial-client-id="initialClientId"
  />
  <NuxtPage />
</template>
