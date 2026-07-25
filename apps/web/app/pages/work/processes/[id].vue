<script setup lang="ts">
/**
 * Detalhe do processo — shell Settings com seções reproduzíveis na URL.
 */
import SectionNavigation from '~/components/navigation/SectionNavigation.vue'
import type { OperationalProcess } from '~/types/work'
import { apiErrorMessage } from '~/utils/api-error'
import { workProcessContextNav } from '~/utils/work-navigation'
import {
  formatCompetence,
  formatDueDate,
  highestRiskColor,
  processStatusColor,
  processStatusLabel,
  taskStatusColor,
  taskStatusLabel,
  workRiskLabel
} from '~/utils/work-labels'

const api = useApi()
const route = useRoute()
const toast = useToast()
const { sessionEpoch } = useDashboard()

const process = ref<OperationalProcess | null>(null)
const timeline = ref<Array<Record<string, unknown>>>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const notFound = ref(false)

const id = computed(() => Number(route.params.id))
const section = computed(() => {
  const s = String(route.query.section || 'resumo')
  return ['resumo', 'tarefas', 'comentarios', 'historico'].includes(s) ? s : 'resumo'
})

const links = computed(() => workProcessContextNav(id.value))

/** Preserva query de origem quando o detalhe foi aberto a partir da lista. */
const backToProcesses = computed(() => {
  const from = route.query.from
  if (typeof from === 'string' && from.startsWith('/work/processes')) {
    return from
  }
  const referrerQuery = { ...route.query }
  delete referrerQuery.section
  const keys = Object.keys(referrerQuery)
  if (keys.length && keys.every(k => ['q', 'competence', 'status', 'client_id', 'department_id', 'group', 'page', 'sort', 'direction'].includes(k))) {
    return { path: '/work/processes', query: referrerQuery }
  }
  return '/work/processes'
})

const timelineError = ref<string | null>(null)

async function load() {
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  timelineError.value = null
  notFound.value = false
  try {
    const res = await api.work.processes.get(id.value)
    if (epoch !== sessionEpoch.value) return
    process.value = res.data
    try {
      const tl = await api.work.processes.timeline(id.value)
      if (epoch === sessionEpoch.value) {
        timeline.value = tl.data || []
      }
    } catch (e) {
      if (epoch === sessionEpoch.value) {
        timeline.value = []
        timelineError.value = apiErrorMessage(e, 'Não foi possível carregar o histórico.')
      }
    }
  } catch (e: unknown) {
    if (epoch !== sessionEpoch.value) return
    const status = (e as { statusCode?: number, status?: number })?.statusCode
      ?? (e as { status?: number })?.status
    if (status === 404) {
      notFound.value = true
      loadError.value = 'Processo não encontrado neste escritório.'
    } else if (status === 403) {
      loadError.value = 'Sem permissão para ver este processo.'
    } else {
      loadError.value = apiErrorMessage(e, 'Processo indisponível.')
    }
    process.value = null
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

onMounted(load)
watch([id, sessionEpoch], load)

function formatTimelineEvent(ev: Record<string, unknown>): string {
  const kind = typeof ev.kind === 'string' ? ev.kind : 'evento'
  const detail = [ev.action, ev.body, ev.original_filename]
    .find(v => typeof v === 'string' && v.trim().length > 0)
  return detail ? `${kind} · ${detail}` : kind
}

function formatTimelineAt(value: unknown): string {
  if (typeof value !== 'string' || !value) return '—'
  try {
    return new Intl.DateTimeFormat('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    }).format(new Date(value))
  } catch {
    return value
  }
}
</script>

<template>
  <ShellSettingsShell
    id="work-process-detail"
    :title="process?.title || 'Processo'"
    width="comfortable"
    test-id="work-process-detail"
    toolbar-test-id="work-process-toolbar"
    body-class="lg:py-8"
  >
    <template v-if="process" #toolbar>
      <SectionNavigation
        :items="links"
        :path="route.fullPath"
        aria-label="Navegação do processo"
        test-id="work-process-section-navigation"
      />
    </template>

    <ShellSectionHeader
      :title="process?.title || 'Detalhe do processo'"
      description="Acompanhe contexto, tarefas, comentários e histórico operacional."
      :back-to="backToProcesses"
      back-label="Voltar para processos"
      test-id="work-process-section-header"
    />

    <div v-if="loading" class="space-y-3">
      <USkeleton class="h-8 w-1/2" />
      <USkeleton class="h-32 w-full" />
    </div>

    <UAlert
      v-else-if="loadError"
      data-testid="work-process-error"
      :color="notFound ? 'neutral' : 'error'"
      :title="loadError"
    >
      <template #actions>
        <UButton
          size="xs"
          variant="soft"
          label="Tentar de novo"
          data-testid="work-process-retry"
          @click="load"
        />
      </template>
    </UAlert>

    <template v-else-if="process">
      <!-- Resumo -->
      <section v-if="section === 'resumo'" class="space-y-4" data-testid="process-section-resumo">
        <div
          class="overflow-hidden rounded-md border border-default"
          data-testid="work-process-summary-definition"
        >
          <dl class="grid sm:grid-cols-2">
            <div class="border-b border-default px-4 py-3 sm:border-r">
              <dt class="text-xs text-muted">
                Cliente
              </dt>
              <dd class="mt-1 text-sm font-medium text-highlighted">
                {{ process.client?.name || '—' }}
              </dd>
            </div>
            <div class="border-b border-default px-4 py-3">
              <dt class="text-xs text-muted">
                Competência
              </dt>
              <dd class="mt-1 text-sm font-medium text-highlighted">
                {{ formatCompetence(process.competence) }}
              </dd>
            </div>
            <div class="border-b border-default px-4 py-3 sm:border-r">
              <dt class="text-xs text-muted">
                Status
              </dt>
              <dd class="mt-1">
                <UBadge
                  variant="subtle"
                  :color="processStatusColor(process.status)"
                  :label="processStatusLabel(process.status)"
                />
              </dd>
            </div>
            <div class="border-b border-default px-4 py-3">
              <dt class="text-xs text-muted">
                Prazo
              </dt>
              <dd class="mt-1 text-sm font-medium text-highlighted">
                {{ formatDueDate(process.due_date) }}
              </dd>
            </div>
            <div class="border-b border-default px-4 py-3 sm:border-r sm:border-b-0">
              <dt class="text-xs text-muted">
                Coordenador
              </dt>
              <dd class="mt-1 text-sm font-medium text-highlighted">
                {{ process.assignee?.name || 'Sem coordenador' }}
              </dd>
            </div>
            <div class="px-4 py-3">
              <dt class="text-xs text-muted">
                Departamento
              </dt>
              <dd class="mt-1 text-sm font-medium text-highlighted">
                {{ process.department?.name || '—' }}
              </dd>
            </div>
          </dl>

          <div class="border-t border-default px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="text-sm font-medium text-highlighted">
                Progresso das tarefas
              </p>
              <p class="text-xs text-muted tabular-nums">
                {{ process.completed_task_count ?? 0 }} de {{ process.task_count ?? 0 }} tarefas encerradas
              </p>
            </div>
            <UProgress
              class="mt-2"
              :model-value="process.progress_percent ?? 0"
              size="md"
              :aria-label="`${process.progress_percent ?? 0}% concluído`"
            />
            <div v-if="process.risks?.length" class="mt-3 flex flex-wrap gap-1">
              <UBadge
                v-for="r in process.risks"
                :key="r"
                size="sm"
                variant="subtle"
                :color="highestRiskColor([r])"
                :label="workRiskLabel(r)"
              />
            </div>
          </div>
        </div>

        <p v-if="process.description" class="text-sm text-toned whitespace-pre-wrap">
          {{ process.description }}
        </p>
      </section>

      <!-- Tarefas -->
      <section v-else-if="section === 'tarefas'" data-testid="process-section-tarefas">
        <ul class="divide-y divide-default rounded-md border border-default">
          <li
            v-for="task in process.tasks || []"
            :key="task.id"
            class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0">
              <p class="font-medium">
                {{ task.sort_order }}. {{ task.title }}
              </p>
              <div class="mt-1 flex flex-wrap gap-2 text-xs text-muted">
                <UBadge
                  size="sm"
                  variant="subtle"
                  :color="taskStatusColor(task.status)"
                  :label="taskStatusLabel(task.status)"
                />
                <span v-if="task.due_date">{{ formatDueDate(task.due_date) }}</span>
                <span v-if="task.is_critical">Crítica</span>
                <span v-if="task.requires_evidence">Exige evidência</span>
                <span v-if="task.assignee">Executor: {{ task.assignee.name }}</span>
                <span v-else>Sem executor</span>
              </div>
            </div>
            <UButton
              size="sm"
              variant="soft"
              :to="`/work/tasks/${task.id}`"
              label="Abrir na fila"
            />
          </li>
          <li v-if="!process.tasks?.length" class="p-4 text-sm text-muted">
            <UEmpty icon="i-lucide-list-todo" title="Nenhuma tarefa neste processo" size="sm" />
          </li>
        </ul>
      </section>

      <!-- Comentários -->
      <section v-else-if="section === 'comentarios'" data-testid="process-section-comentarios">
        <ul class="space-y-2">
          <li
            v-for="c in process.comments || []"
            :key="c.id"
            class="rounded-md border border-default p-3 text-sm"
          >
            <p class="whitespace-pre-wrap">
              {{ c.body }}
            </p>
            <p class="mt-1 text-xs text-muted">
              {{ c.created_at }}
            </p>
          </li>
          <li v-if="!process.comments?.length" class="text-sm text-muted">
            <UEmpty icon="i-lucide-message-square" title="Nenhum comentário ainda" size="sm" />
          </li>
        </ul>
      </section>

      <!-- Histórico -->
      <section v-else data-testid="process-section-historico">
        <div
          v-if="timelineError"
          data-testid="process-timeline-error"
        >
          <UAlert color="error" :title="timelineError">
            <template #actions>
              <UButton
                size="xs"
                variant="soft"
                label="Tentar de novo"
                @click="load"
              />
            </template>
          </UAlert>
        </div>
        <ul
          v-else
          class="space-y-2"
        >
          <li
            v-for="(ev, idx) in timeline"
            :key="idx"
            class="rounded-md border border-default p-3 text-sm"
          >
            <p class="font-medium">
              {{ formatTimelineEvent(ev) }}
            </p>
            <p class="text-xs text-muted">
              {{ formatTimelineAt(ev.created_at) }}
            </p>
          </li>
          <li v-if="!timeline.length">
            <UEmpty
              icon="i-lucide-history"
              title="Nenhum evento no histórico"
              size="sm"
            />
          </li>
        </ul>
      </section>
    </template>
  </ShellSettingsShell>
</template>
