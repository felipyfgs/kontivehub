<script setup lang="ts">
/**
 * Agrupa ações secundárias da navbar em «Mais ações».
 * A ação primária permanece no slot default do pai; este componente só o menu.
 */
export interface NavbarActionItem {
  id: string
  label: string
  icon?: string
  to?: string
  disabled?: boolean
  color?: 'error' | 'primary' | 'neutral' | 'success' | 'warning' | 'secondary' | 'info'
  onSelect?: () => void | Promise<void>
}

const props = withDefaults(defineProps<{
  items: NavbarActionItem[]
  label?: string
  testId?: string
}>(), {
  label: 'Mais ações',
  testId: 'navbar-more-actions'
})

const menuItems = computed(() =>
  props.items.map(item => ({
    label: item.label,
    icon: item.icon,
    to: item.to,
    disabled: item.disabled,
    color: item.color,
    onSelect: item.onSelect
  }))
)
</script>

<template>
  <UDropdownMenu
    v-if="items.length"
    :items="menuItems"
    :content="{ align: 'end' }"
  >
    <UButton
      color="neutral"
      variant="ghost"
      icon="i-lucide-ellipsis"
      :label="label"
      :aria-label="label"
      :data-testid="testId"
    />
  </UDropdownMenu>
</template>
