<script setup lang="ts">
/** Alias legado: configurações do escritório agora vivem em /conta/escritorio. */
await navigateTo('/conta/escritorio', { redirectCode: 301, replace: true })
</script>

<template>
  <div class="p-6 text-sm text-muted">
    Redirecionando para Conta…
  </div>
</template>
