<script setup lang="ts">
/**
 * Chrome único da fila de trabalho — compartilhado por Fila, Lista e Kanban.
 * A navbar mantém apenas identidade/contagem; seletores de entidade e visão
 * vivem na toolbar, na mesma região usada por Processos.
 */
import type { WorkEntityLevel } from '~/types/work'
import type { WorkQueueView } from '~/composables/useWorkQueueFilters'

defineProps<{
  total: number
  view: WorkQueueView
  q?: string
  clientId?: number | null
  departmentId?: number | null
  detailOpen?: boolean
  showDetailToggle?: boolean
}>()

const emit = defineEmits<{
  'update:entityLevel': [level: WorkEntityLevel]
  'update:view': [view: WorkQueueView]
  'toggleDetail': []
}>()

const viewOptions: Array<{
  value: WorkQueueView
  label: string
  icon: string
}> = [
  { value: 'fila', label: 'Fila', icon: 'i-lucide-messages-square' },
  { value: 'lista', label: 'Lista', icon: 'i-lucide-list' },
  { value: 'kanban', label: 'Kanban', icon: 'i-lucide-columns-3' }
]
</script>

<template>
  <ShellPageNavbar
    title="Tarefas"
    test-id="page-navbar"
    class="shrink-0"
  >
    <template #trailing>
      <UBadge
        :label="String(total)"
        variant="subtle"
        data-testid="work-queue-total"
      />
    </template>
  </ShellPageNavbar>

  <UDashboardToolbar
    data-testid="work-queue-toolbar"
    class="shrink-0"
    :ui="{
      root: 'min-h-0 flex-col items-stretch justify-start gap-2 overflow-x-visible py-2'
    }"
  >
    <div class="flex w-full min-w-0 flex-wrap items-center justify-between gap-2">
      <WorkEntityLevelToggle
        model-value="task"
        :q="q"
        :client-id="clientId"
        :department-id="departmentId"
        @update:model-value="emit('update:entityLevel', $event)"
      />

      <div class="ml-auto flex min-w-0 max-w-full flex-wrap items-center justify-end gap-1">
        <UFieldGroup data-testid="work-queue-view-toggle">
          <UButton
            v-for="option in viewOptions"
            :key="option.value"
            :label="option.label"
            :icon="option.icon"
            size="sm"
            :variant="view === option.value ? 'solid' : 'outline'"
            :color="view === option.value ? 'primary' : 'neutral'"
            :aria-pressed="view === option.value"
            @click="emit('update:view', option.value)"
          />
        </UFieldGroup>

        <UTooltip
          v-if="showDetailToggle"
          :text="detailOpen ? 'Fechar detalhe' : 'Abrir detalhe'"
          class="hidden lg:inline-flex"
        >
          <UButton
            icon="i-lucide-panel-right"
            :color="detailOpen ? 'primary' : 'neutral'"
            :variant="detailOpen ? 'soft' : 'ghost'"
            :aria-label="detailOpen ? 'Fechar detalhe' : 'Abrir detalhe'"
            :aria-pressed="detailOpen"
            data-testid="work-queue-detail-toggle"
            @click="emit('toggleDetail')"
          />
        </UTooltip>
      </div>
    </div>

    <slot />
  </UDashboardToolbar>
</template>
