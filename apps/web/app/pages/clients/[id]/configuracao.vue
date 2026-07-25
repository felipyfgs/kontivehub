<script setup lang="ts">
/**
 * Redirect legado: Configuração → Dados adicionais.
 */
import { clientDetailHref } from '~/utils/client-detail-tabs'

const route = useRoute()
const id = route.params.id as string

await navigateTo(clientDetailHref(id, 'dados-adicionais'), { replace: true })
</script>

<template>
  <div />
</template>
