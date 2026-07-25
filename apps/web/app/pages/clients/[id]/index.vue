<script setup lang="ts">
/** Alias: conteúdo canônico em /cadastro. */
import { clientDetailHref } from '~/utils/client-detail-tabs'

const route = useRoute()
await navigateTo(clientDetailHref(route.params.id as string, 'cadastro'), { replace: true })
</script>

<template>
  <div class="sr-only">
    Redirecionando…
  </div>
</template>
