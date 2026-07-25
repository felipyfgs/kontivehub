<script setup lang="ts">
type DashboardContentWidth = 'comfortable' | 'wide' | 'full'

const props = withDefaults(defineProps<{
  width?: DashboardContentWidth
}>(), {
  width: 'comfortable'
})

const widthClasses: Record<DashboardContentWidth, string> = {
  comfortable: 'max-w-5xl',
  wide: 'max-w-6xl',
  full: 'max-w-none'
}
</script>

<template>
  <div
    class="mx-auto flex w-full min-w-0 flex-col"
    :class="widthClasses[props.width]"
    :data-content-width="props.width"
  >
    <slot />
  </div>
</template>
