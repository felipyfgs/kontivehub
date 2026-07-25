<script setup lang="ts">
// Compat: /notes/:accessKey → /docs/:accessKey
const route = useRoute()
const key = typeof route.params.accessKey === 'string' ? route.params.accessKey : ''
await navigateTo(
  key ? `/docs/${key}` : '/docs',
  { redirectCode: 301, replace: true }
)
</script>

<template>
  <div />
</template>
