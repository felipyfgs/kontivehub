<script setup lang="ts">
import type { ClientCategoryColor } from '~/types/api'
import {
  clientCategoryColorItem,
  clientCategorySoftStyle
} from '~/utils/client-category-colors'

const props = withDefaults(defineProps<{
  label: string
  color?: ClientCategoryColor | string | null
  archived?: boolean
  size?: 'xs' | 'sm' | 'md'
}>(), {
  color: 'neutral',
  archived: false,
  size: 'sm'
})

const item = computed(() => clientCategoryColorItem(props.color))

const sizeClass = computed(() => ({
  xs: 'px-1.5 py-0.5 text-[11px]',
  sm: 'px-2 py-0.5 text-xs',
  md: 'px-2.5 py-1 text-sm'
}[props.size]))

const style = computed(() => {
  if (props.archived) {
    return undefined
  }
  return clientCategorySoftStyle(item.value.hex)
})
</script>

<template>
  <span
    class="inline-flex max-w-full min-w-0 items-center truncate rounded-md font-medium leading-tight"
    :class="[
      sizeClass,
      archived ? 'bg-elevated text-muted ring ring-inset ring-default' : ''
    ]"
    :style="style"
    :title="label"
  >
    {{ label }}
  </span>
</template>
