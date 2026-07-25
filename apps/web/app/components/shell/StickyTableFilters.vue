<script setup lang="ts">
/**
 * Wrapper neutro para a faixa canônica de filtros no body.
 * O layout interno vem de `LIST_FILTER_TOOLBAR_STACK`.
 * Sem Teleport / sticky (não existem no template).
 */
defineOptions({ inheritAttrs: false })
</script>

<template>
  <div
    v-bind="$attrs"
    data-dashboard-table-filters
    class="w-full min-w-0"
  >
    <slot />
  </div>
</template>
