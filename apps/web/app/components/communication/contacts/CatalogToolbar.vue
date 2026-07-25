<script setup lang="ts">
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'

defineProps<{
  q: string
  definitions: readonly DataTableFilterDefinition[]
  models: readonly DataTableFilterModel[]
  loading: boolean
  resetKey: number
}>()

const emit = defineEmits<{
  'update:q': [value: string]
  'update:models': [models: DataTableFilterModel[]]
  'clear': []
  'refresh': []
}>()
</script>

<template>
  <UDashboardToolbar data-testid="communication-contacts-toolbar">
    <ShellListFilterToolbar
      :q="q"
      search-placeholder="Buscar por nome ou número mascarado"
      search-aria-label="Buscar contatos"
      :definitions="definitions"
      :models="models"
      :loading="loading"
      :reset-key="resetKey"
      test-id-prefix="communication-contacts"
      @update:q="emit('update:q', $event)"
      @update:models="emit('update:models', $event)"
      @clear="emit('clear')"
      @refresh="emit('refresh')"
    />
  </UDashboardToolbar>
</template>
