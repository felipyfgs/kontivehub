<script setup lang="ts">
/** Alias legado: a administração da plataforma começa pela lista de escritórios. */
await navigateTo('/admin/offices', { redirectCode: 301, replace: true })
</script>

<template>
  <div class="sr-only" role="status">
    Redirecionando para Escritórios…
  </div>
</template>
