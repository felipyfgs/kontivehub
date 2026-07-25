<script setup lang="ts">
/**
 * Painel de detalhe da tarefa — anatomia de InboxMail.vue.
 * Ações reais (transições, comentários, evidências); sem mocks.
 */
import type { OperationalTaskDetail } from '~/types/work'
import { canDownloadWorkEvidence, canExecuteWorkTasks } from '~/utils/permissions'
import {
  formatCompetence,
  formatDueDate,
  highestRiskColor,
  taskStatusColor,
  taskStatusIcon,
  taskStatusLabel,
  workRiskLabel
} from '~/utils/work-labels'
import { apiErrorMessage } from '~/utils/api-error'

const props = defineProps<{
  detail: OperationalTaskDetail | null
  loading?: boolean
  /** Erro de carga do detalhe (distinto de empty / sem seleção). */
  error?: string | null
  /** Há id selecionado no path (deep-link), mesmo sem detalhe carregado. */
  hasSelection?: boolean
}>()

const emit = defineEmits<{
  close: []
  refreshed: []
  retry: []
}>()

const api = useApi()
const toast = useToast()
const { me } = useDashboard()

const canExecute = computed(() => canExecuteWorkTasks(me.value))
const canDownload = computed(() => canDownloadWorkEvidence(me.value))
const actionBusy = ref(false)
const blockReason = ref('')
const commentBody = ref('')
const conflictHint = ref<string | null>(null)

async function runAction(action: 'start' | 'complete' | 'resume' | 'claim' | 'block') {
  if (!props.detail || !canExecute.value) return
  actionBusy.value = true
  conflictHint.value = null
  try {
    const id = props.detail.id
    const lv = props.detail.lock_version
    if (action === 'start') await api.work.tasks.start(id, lv)
    else if (action === 'complete') await api.work.tasks.complete(id, lv)
    else if (action === 'resume') await api.work.tasks.resume(id, lv)
    else if (action === 'claim') await api.work.tasks.claim(id, lv)
    else if (action === 'block') {
      if (!blockReason.value.trim()) {
        toast.add({ title: 'Informe o motivo do impedimento.', color: 'warning' })
        return
      }
      await api.work.tasks.block(id, lv, blockReason.value.trim())
      blockReason.value = ''
    }
    toast.add({ title: 'Tarefa atualizada.', color: 'success' })
    emit('refreshed')
  } catch (e: unknown) {
    const status = (e as { statusCode?: number, status?: number })?.statusCode
      ?? (e as { status?: number })?.status
    if (status === 409) {
      conflictHint.value = 'A tarefa foi alterada por outro usuário. Recarregue o detalhe antes de continuar.'
      toast.add({ title: 'Conflito de versão (409).', color: 'warning' })
    } else {
      toast.add({ title: apiErrorMessage(e, 'Não foi possível atualizar a tarefa.'), color: 'error' })
    }
  } finally {
    actionBusy.value = false
  }
}

async function addComment() {
  if (!props.detail || !commentBody.value.trim()) return
  actionBusy.value = true
  try {
    await api.work.tasks.comment(props.detail.id, commentBody.value.trim())
    commentBody.value = ''
    emit('refreshed')
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Falha ao comentar.'), color: 'error' })
  } finally {
    actionBusy.value = false
  }
}

async function onEvidence(files: File | File[] | null | undefined) {
  const file = Array.isArray(files) ? files[0] : files
  if (!props.detail || !file) return
  actionBusy.value = true
  try {
    await api.work.tasks.uploadEvidence(props.detail.id, file)
    toast.add({ title: 'Evidência anexada.', color: 'success' })
    emit('refreshed')
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Upload rejeitado.'), color: 'error' })
  } finally {
    actionBusy.value = false
  }
}

function downloadUrl(evidenceId: number) {
  if (!props.detail) return '#'
  return api.work.tasks.downloadEvidenceUrl(props.detail.id, evidenceId)
}
</script>

<template>
  <UDashboardPanel
    id="work-task-detail"
    data-testid="work-task-detail"
    class="min-w-0 overflow-hidden"
  >
    <template #header>
      <!-- Navbar do detalhe isolado (padrão InboxMail): X + título truncado + ações -->
      <UDashboardNavbar
        :title="detail?.title || 'Tarefa'"
        data-testid="work-task-detail-navbar"
        :toggle="false"
        class="min-w-0 overflow-hidden"
        :ui="{
          root: 'min-w-0 overflow-hidden',
          left: 'min-w-0 flex-1 overflow-hidden',
          title: 'truncate min-w-0'
        }"
      >
        <template #leading>
          <UButton
            icon="i-lucide-x"
            color="neutral"
            variant="ghost"
            class="-ms-1.5 shrink-0"
            aria-label="Fechar detalhe"
            data-testid="work-task-detail-close"
            @click="emit('close')"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="loading" class="space-y-3 p-4">
        <USkeleton class="h-8 w-1/2" />
        <USkeleton class="h-24 w-full" />
      </div>

      <div
        v-else-if="error"
        class="p-4"
        data-testid="work-task-detail-error"
      >
        <UAlert color="error" :title="error">
          <template #actions>
            <UButton
              size="xs"
              variant="soft"
              label="Tentar de novo"
              data-testid="work-task-detail-retry"
              @click="emit('retry')"
            />
          </template>
        </UAlert>
      </div>

      <UEmpty
        v-else-if="!detail && hasSelection"
        data-testid="work-task-detail-missing"
        icon="i-lucide-file-question"
        title="Tarefa indisponível"
        description="O id do path não retornou detalhe neste escritório."
      />

      <UEmpty
        v-else-if="!detail"
        data-testid="work-task-detail-idle"
        icon="i-lucide-mouse-pointer-click"
        title="Selecione uma tarefa na fila"
      />

      <div v-else class="flex flex-col gap-4 p-4 sm:p-6">
        <div>
          <p class="text-sm text-muted">
            {{ detail.process?.client?.name }}
            <span v-if="detail.process?.title"> · {{ detail.process.title }}</span>
            <span v-if="detail.process?.competence"> · {{ formatCompetence(detail.process.competence) }}</span>
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <UBadge
              variant="subtle"
              :color="taskStatusColor(detail.status)"
              :icon="taskStatusIcon(detail.status)"
              :label="taskStatusLabel(detail.status)"
            />
            <UBadge
              v-for="r in detail.risks || []"
              :key="r"
              variant="subtle"
              :color="highestRiskColor([r])"
              :label="workRiskLabel(r)"
            />
            <UBadge
              v-if="detail.is_critical"
              color="warning"
              variant="subtle"
              label="Crítica"
            />
            <UBadge
              v-if="detail.requires_evidence"
              color="info"
              variant="subtle"
              label="Exige evidência"
            />
          </div>
        </div>

        <UAlert
          v-if="conflictHint"
          color="warning"
          :title="conflictHint"
          :actions="[{ label: 'Recarregar', onClick: () => emit('refreshed') }]"
        />

        <dl class="grid gap-2 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted">
              Prazo efetivo
            </dt>
            <dd class="font-medium">
              {{ formatDueDate(detail.effective_due_date || detail.due_date) }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Departamento
            </dt>
            <dd class="font-medium">
              {{ detail.department?.name || '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Responsável
            </dt>
            <dd class="font-medium">
              {{ detail.assignee?.name || 'Sem responsável' }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Processo
            </dt>
            <dd>
              <NuxtLink
                v-if="detail.process?.id"
                :to="`/work/processes/${detail.process.id}`"
                class="font-medium text-primary hover:underline"
              >
                #{{ detail.process.id }}
              </NuxtLink>
              <span v-else>—</span>
            </dd>
          </div>
        </dl>

        <p v-if="detail.description" class="text-sm text-toned whitespace-pre-wrap">
          {{ detail.description }}
        </p>
        <p v-if="detail.block_reason" class="text-sm text-warning">
          Impedimento: {{ detail.block_reason }}
        </p>

        <div v-if="canExecute" class="flex flex-wrap gap-2">
          <UButton
            v-if="detail.status === 'A_FAZER'"
            size="sm"
            :loading="actionBusy"
            label="Iniciar"
            @click="runAction('start')"
          />
          <UButton
            v-if="detail.status === 'EM_PROGRESSO' || detail.status === 'A_FAZER'"
            size="sm"
            color="success"
            :loading="actionBusy"
            label="Concluir"
            @click="runAction('complete')"
          />
          <UButton
            v-if="detail.status === 'IMPEDIDA'"
            size="sm"
            :loading="actionBusy"
            label="Retomar"
            @click="runAction('resume')"
          />
          <UButton
            v-if="!detail.assignee_membership_id && !detail.assignee"
            size="sm"
            variant="outline"
            :loading="actionBusy"
            label="Assumir"
            @click="runAction('claim')"
          />
        </div>

        <div
          v-if="canExecute && detail.status !== 'CONCLUIDA' && detail.status !== 'DISPENSADA'"
          class="flex flex-col gap-2 sm:flex-row"
        >
          <UInput
            v-model="blockReason"
            placeholder="Motivo do impedimento"
            class="flex-1"
            aria-label="Motivo do impedimento"
          />
          <UButton
            color="warning"
            variant="soft"
            :loading="actionBusy"
            label="Impedir"
            @click="runAction('block')"
          />
        </div>

        <div v-if="canExecute" class="space-y-2">
          <p class="text-sm font-medium">
            Anexar evidência
          </p>
          <UFileUpload
            accept=".pdf,.png,.jpg,.jpeg,.txt,application/pdf,image/png,image/jpeg,text/plain"
            label="Selecionar arquivo"
            description="PDF, PNG, JPEG ou texto · máx. 20 MiB"
            :disabled="actionBusy"
            @update:model-value="onEvidence"
          />
        </div>

        <section
          v-if="detail.evidences?.length"
          class="space-y-2"
          data-testid="work-task-detail-evidences"
        >
          <p class="text-sm font-medium">
            Evidências
          </p>
          <ul class="space-y-1 text-sm">
            <li v-for="ev in detail.evidences" :key="ev.id" class="flex items-center gap-2">
              <UIcon name="i-lucide-paperclip" class="size-4 text-muted" />
              <a
                v-if="canDownload"
                :href="downloadUrl(ev.id)"
                class="text-primary hover:underline"
                target="_blank"
                rel="noopener"
              >{{ ev.original_filename }}</a>
              <span v-else>{{ ev.original_filename }}</span>
              <span class="text-xs text-muted">({{ ev.mime_type }})</span>
            </li>
          </ul>
        </section>

        <section
          v-if="canExecute"
          class="space-y-2"
          data-testid="work-task-detail-comment-form"
        >
          <UTextarea
            v-model="commentBody"
            placeholder="Comentário"
            :rows="2"
            aria-label="Novo comentário"
          />
          <UButton
            size="sm"
            variant="soft"
            :loading="actionBusy"
            label="Comentar"
            @click="addComment"
          />
        </section>

        <section
          v-if="detail.comments?.length"
          class="space-y-2"
          data-testid="work-task-detail-comments"
        >
          <p class="text-sm font-medium">
            Comentários
          </p>
          <ol class="space-y-3">
            <li
              v-for="c in detail.comments"
              :key="c.id"
              class="text-sm"
            >
              <p class="whitespace-pre-wrap">
                {{ c.body }}
              </p>
              <p class="mt-1 text-xs text-muted">
                {{ c.created_at }}
              </p>
            </li>
          </ol>
        </section>
      </div>
    </template>
  </UDashboardPanel>
</template>
