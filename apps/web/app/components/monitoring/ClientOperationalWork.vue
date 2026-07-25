<script setup lang="ts">
import type { OperationalProcess } from '~/types/work'
import {
  formatCompetence,
  formatDueDate,
  processStatusColor,
  processStatusLabel
} from '~/utils/work-labels'

const props = withDefaults(defineProps<{
  clientId: number
  items: OperationalProcess[]
  loading?: boolean
  error?: string | null
}>(), {
  loading: false,
  error: null
})

const emit = defineEmits<{
  retry: []
}>()
</script>

<template>
  <section
    class="space-y-3"
    aria-labelledby="client-operational-work-title"
    data-testid="client-operational-work"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 id="client-operational-work-title" class="text-sm font-medium text-highlighted">
          Trabalho operacional
        </h2>
        <p class="mt-0.5 text-xs text-muted">
          Processos ativos e tarefas desta empresa.
        </p>
      </div>
      <UButton
        :to="`/work/processes?client_id=${props.clientId}`"
        color="neutral"
        variant="outline"
        size="xs"
        icon="i-lucide-folder-kanban"
        label="Ver todos"
      />
    </div>

    <div v-if="loading" class="space-y-2">
      <USkeleton v-for="index in 3" :key="index" class="h-20 rounded-lg" />
    </div>

    <UAlert
      v-else-if="error"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Trabalho indisponível"
      :description="error"
      :actions="[{ label: 'Tentar novamente', onClick: () => emit('retry') }]"
    />

    <div v-else-if="items.length" class="divide-y divide-default overflow-hidden rounded-lg border border-default bg-default">
      <NuxtLink
        v-for="process in items"
        :key="process.id"
        :to="`/work/processes/${process.id}`"
        class="grid min-w-0 gap-3 p-3 transition-colors hover:bg-elevated/50 sm:grid-cols-[minmax(12rem,2fr)_8rem_9rem_8rem] sm:items-center"
        :data-testid="`client-operational-process-${process.id}`"
      >
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-highlighted">
            {{ process.title }}
          </p>
          <p class="mt-0.5 text-xs text-muted">
            {{ formatCompetence(process.competence) }} ·
            {{ process.completed_task_count ?? 0 }}/{{ process.task_count ?? process.tasks?.length ?? 0 }} tarefas
          </p>
        </div>

        <div class="flex items-center justify-between gap-2 sm:block">
          <span class="text-xs text-muted sm:hidden">Status</span>
          <UBadge
            :color="processStatusColor(process.status)"
            variant="subtle"
            :label="processStatusLabel(process.status)"
          />
        </div>

        <div class="flex min-w-0 items-center justify-between gap-3 sm:block">
          <span class="text-xs text-muted sm:hidden">Progresso</span>
          <div class="flex min-w-28 items-center gap-2">
            <UProgress
              class="flex-1"
              size="sm"
              :model-value="process.progress_percent ?? 0"
              :aria-label="`Progresso ${process.progress_percent ?? 0}%`"
            />
            <span class="text-xs tabular-nums text-muted">
              {{ process.progress_percent ?? 0 }}%
            </span>
          </div>
        </div>

        <div class="flex items-center justify-between gap-2 text-sm sm:block">
          <span class="text-xs text-muted sm:hidden">Prazo</span>
          <span>{{ formatDueDate(process.due_date) }}</span>
        </div>
      </NuxtLink>
    </div>

    <UEmpty
      v-else
      icon="i-lucide-list-checks"
      title="Nenhum processo ativo"
      description="Esta empresa não tem rotinas operacionais abertas."
      class="rounded-lg border border-default"
    />
  </section>
</template>
