<script setup lang="ts">
import {
  DOCUMENTS_CATALOG_PATH,
  documentCatalogClientPath,
  parseDocumentClientId
} from '~/utils/document-routes'

definePageMeta({
  middleware: [(to) => {
    const clientId = parseDocumentClientId(to.params.clientId)
    if (!clientId) return navigateTo(DOCUMENTS_CATALOG_PATH, { replace: true })
    if (String(to.params.clientId) !== String(clientId)) {
      return navigateTo(documentCatalogClientPath(clientId), { replace: true })
    }
  }]
})
</script>

<template>
  <NuxtPage />
</template>
