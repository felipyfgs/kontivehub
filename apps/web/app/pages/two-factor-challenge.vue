<script setup lang="ts">
/**
 * TOTP descontinuado — redireciona para login / home.
 */
definePageMeta({ layout: 'auth' })

const { isAuthenticated } = useSanctumAuth()
await navigateTo(isAuthenticated.value ? '/' : '/login', { replace: true })
</script>

<template>
  <div
    class="w-full min-w-0 space-y-3 text-center sm:text-left"
    role="status"
    aria-live="polite"
    data-testid="two-factor-redirect"
  >
    <p class="text-sm text-muted">
      Redirecionando…
    </p>
  </div>
</template>
