<script setup lang="ts">
/** Alias legado: configuração do escritório vive em /conta/escritorio */
await navigateTo('/conta/escritorio', { redirectCode: 301, replace: true })
</script>

<template>
  <div class="p-6 text-sm text-muted">
    Redirecionando para Conta…
  </div>
</template>
