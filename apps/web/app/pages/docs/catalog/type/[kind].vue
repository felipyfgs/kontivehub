<script setup lang="ts">
import {
  DOCUMENTS_CATALOG_PATH,
  documentCatalogTypePath,
  normalizeDocumentContextKind
} from '~/utils/document-routes'

definePageMeta({
  middleware: [(to) => {
    const kind = normalizeDocumentContextKind(to.params.kind)
    if (!kind) return navigateTo(DOCUMENTS_CATALOG_PATH, { replace: true })
    if (String(to.params.kind) !== kind) return navigateTo(documentCatalogTypePath(kind), { replace: true })
  }]
})
</script>

<template>
  <NuxtPage />
</template>
