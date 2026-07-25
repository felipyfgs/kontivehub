<script setup lang="ts">
/**
 * Departamentos movidos para /settings/departments.
 * Redirect permanente para preservar bookmarks.
 */
await navigateTo('/conta/departamentos', { redirectCode: 301, replace: true })
</script>

<template>
  <div class="sr-only" role="status">
    Redirecionando para Configurações → Departamentos…
  </div>
</template>
