<script setup lang="ts">
import type { NuxtError } from '#app'
import { pt_br } from '@nuxt/ui/locale'

defineProps<{
  error: NuxtError
}>()

useSeoMeta({
  title: 'Página não encontrada',
  description: 'A página solicitada não existe ou foi removida.'
})

useHead({
  htmlAttrs: {
    lang: 'pt-BR'
  }
})
</script>

<template>
  <UApp :locale="pt_br">
    <UError :error="error" />
  </UApp>
</template>
