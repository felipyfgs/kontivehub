<script setup lang="ts">
/** Legado: redireciona para a IA de 4 páginas. */
import { legacyClientPathToHref } from '~/utils/client-detail-tabs'

const route = useRoute()
const href = legacyClientPathToHref(route.params.id as string, 'renuncias') || `/clients/${route.params.id}/cadastro`
await navigateTo(href, { replace: true })
</script>

<template>
  <div />
</template>
