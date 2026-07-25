<script setup lang="ts">
/**
 * Casca settings: PagePanel + navbar + toolbar opcional (dentro do header) + DashboardContent.
 * Arquétipo: `.local/reference/nuxt-dashboard-template/app/pages/settings.vue`.
 * UDashboardPanel não tem slot #toolbar — a toolbar vive em #header após a navbar.
 */
type ContentWidth = 'comfortable' | 'wide' | 'full'

withDefaults(defineProps<{
  id: string
  title: string
  width?: ContentWidth
  /** Classes extras no body do UDashboardPanel (default: padding settings). */
  bodyClass?: string
  testId?: string
  toolbarTestId?: string
  /** Merge opcional no ui.root do UDashboardToolbar. */
  toolbarUi?: { root?: string }
}>(), {
  width: 'comfortable',
  bodyClass: 'lg:py-12',
  testId: undefined,
  toolbarTestId: undefined,
  toolbarUi: undefined
})
</script>

<template>
  <ShellPagePanel
    :id="id"
    :test-id="testId"
    :body-class="bodyClass"
  >
    <template #header>
      <ShellPageNavbar :title="title">
        <template
          v-if="$slots['navbar-leading']"
          #leading
        >
          <slot name="navbar-leading" />
        </template>
        <template
          v-if="$slots['navbar-right']"
          #right
        >
          <slot name="navbar-right" />
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar
        v-if="$slots.toolbar"
        :data-testid="toolbarTestId"
        :ui="toolbarUi"
      >
        <slot name="toolbar" />
      </UDashboardToolbar>
    </template>

    <DashboardContent
      :width="width"
      class="gap-4 sm:gap-6 lg:gap-12"
    >
      <slot />
    </DashboardContent>
  </ShellPagePanel>
</template>
