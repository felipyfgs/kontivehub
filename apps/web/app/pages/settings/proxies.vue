<script setup lang="ts">
/**
 * Rota legada: importação manual de procurações foi removida (OpenSpec).
 * Middleware redireciona para /settings; este arquivo existe só como fallback.
 */
await navigateTo('/conta/escritorio', { replace: true })
</script>

<template>
  <div class="text-sm text-muted">
    Redirecionando para a configuração do escritório…
  </div>
</template>
