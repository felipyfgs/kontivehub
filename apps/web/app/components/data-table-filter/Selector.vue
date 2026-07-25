<script setup lang="ts">
/**
 * Lista de campos ainda inativos para adicionar filtro.
 * Usado dentro do popover (desktop) ou modal fullscreen (mobile).
 */
import type { DataTableFilterDefinition } from '~/types/data-table-filter'
import type { CommandPaletteGroup, CommandPaletteItem } from '@nuxt/ui'
import { filterKindIcon } from '~/utils/data-table-filters'

const props = defineProps<{
  definitions: readonly DataTableFilterDefinition[]
}>()

const emit = defineEmits<{
  select: [key: string]
}>()

const groups = computed<CommandPaletteGroup[]>(() => [{
  id: 'fields',
  label: 'Campos',
  items: props.definitions.map((definition): CommandPaletteItem & { key: string } => ({
    key: definition.key,
    label: definition.label,
    icon: filterKindIcon(definition),
    onSelect: () => emit('select', definition.key)
  }))
}])

function onSelect(item: CommandPaletteItem | CommandPaletteItem[] | undefined | null) {
  if (!item || Array.isArray(item)) return
  const key = (item as CommandPaletteItem & { key?: string }).key
  if (key) emit('select', key)
}
</script>

<template>
  <UCommandPalette
    :groups="groups"
    placeholder="Buscar campo…"
    class="min-h-0 w-full min-w-0 max-h-full"
    :ui="{
      root: 'min-w-0',
      input: 'border-b border-default'
    }"
    data-testid="data-table-filter-selector"
    @update:model-value="onSelect"
  />
</template>
