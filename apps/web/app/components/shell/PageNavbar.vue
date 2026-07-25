<script setup lang="ts">
/**
 * Navbar canônica: SidebarCollapse + título + slots leading/right/trailing.
 */
withDefaults(defineProps<{
  title: string
  badge?: string | number | null
  testId?: string
}>(), {
  badge: null,
  testId: 'page-navbar'
})
</script>

<template>
  <UDashboardNavbar
    :title="title"
    :data-testid="testId"
  >
    <template #leading>
      <UDashboardSidebarCollapse />
      <slot name="leading" />
    </template>
    <template
      v-if="badge != null && badge !== ''"
      #trailing
    >
      <UBadge
        color="neutral"
        variant="subtle"
        :label="String(badge)"
      />
      <slot name="trailing" />
    </template>
    <template
      v-else-if="$slots.trailing"
      #trailing
    >
      <slot name="trailing" />
    </template>
    <template
      v-if="$slots.right"
      #right
    >
      <slot name="right" />
    </template>
  </UDashboardNavbar>
</template>
