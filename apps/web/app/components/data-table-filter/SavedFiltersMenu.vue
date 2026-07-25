<script setup lang="ts">
/**
 * Menu de presets: grupos Meus / Equipe + Gerenciar.
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import type { SavedListFilter } from '~/types/saved-list-filters'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'

const props = withDefaults(defineProps<{
  items: SavedListFilter[]
  loading?: boolean
  disabled?: boolean
}>(), {
  loading: false,
  disabled: false
})

const emit = defineEmits<{
  apply: [filter: SavedListFilter]
  manage: []
  open: []
}>()

const personal = computed(() =>
  props.items.filter(item => item.visibility === 'personal')
)
const team = computed(() =>
  props.items.filter(item => item.visibility === 'office')
)

function itemLabel(filter: SavedListFilter): string {
  return filter.name
}

const menuItems = computed<DropdownMenuItem[][]>(() => {
  const groups: DropdownMenuItem[][] = []

  const mine: DropdownMenuItem[] = personal.value.length
    ? personal.value.map(filter => ({
        label: itemLabel(filter),
        icon: 'i-lucide-user',
        onSelect: () => emit('apply', filter)
      }))
    : [{
        label: 'Nenhum filtro pessoal',
        disabled: true
      }]

  const office: DropdownMenuItem[] = team.value.length
    ? team.value.map(filter => ({
        label: itemLabel(filter),
        icon: 'i-lucide-users',
        onSelect: () => emit('apply', filter)
      }))
    : [{
        label: 'Nenhum filtro da equipe',
        disabled: true
      }]

  groups.push([
    { label: 'Meus', type: 'label' as const },
    ...mine
  ])
  groups.push([
    { label: 'Equipe', type: 'label' as const },
    ...office
  ])
  groups.push([{
    label: 'Gerenciar…',
    icon: 'i-lucide-settings-2',
    onSelect: () => emit('manage')
  }])

  return groups
})

function onOpenChange(open: boolean) {
  if (open) emit('open')
}
</script>

<template>
  <UDropdownMenu
    :items="menuItems"
    :content="{ align: 'end' }"
    :ui="{ content: 'min-w-56' }"
    @update:open="onOpenChange"
  >
    <UButton
      color="neutral"
      variant="outline"
      icon="i-lucide-bookmark"
      label="Salvos"
      aria-label="Filtros salvos"
      :ui="COMPACT_BUTTON_LABEL_UI"
      :loading="loading"
      :disabled="disabled"
      data-testid="saved-filters-menu"
    />
  </UDropdownMenu>
</template>
