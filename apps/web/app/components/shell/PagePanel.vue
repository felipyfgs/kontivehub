<script setup lang="ts">
/**
 * Casca canônica de página autenticada — UDashboardPanel + slots.
 * Uso: `#header`, `#toolbar` (renderizado no header após a navbar), `#body` (ou default).
 *
 * UDashboardPanel não tem slot toolbar nativo — a toolbar vive em #header.
 */
const props = withDefaults(defineProps<{
  id: string
  /** Classes extras no `#body` via ui do painel. */
  bodyClass?: string
  testId?: string
}>(), {
  bodyClass: undefined,
  testId: undefined
})

const resolvedUi = computed(() =>
  props.bodyClass ? { body: props.bodyClass } : undefined
)
</script>

<template>
  <UDashboardPanel
    :id="id"
    :data-testid="testId"
    :ui="resolvedUi"
  >
    <template
      v-if="$slots.header || $slots.toolbar"
      #header
    >
      <slot name="header" />
      <slot name="toolbar" />
    </template>
    <template #body>
      <slot name="body">
        <slot />
      </slot>
    </template>
  </UDashboardPanel>
</template>
