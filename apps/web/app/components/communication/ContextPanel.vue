<script setup lang="ts">
import type { WorkDepartment } from '~/types/work'
import type {
  CommunicationConversation,
  CommunicationConversationSignals,
  CommunicationInbox,
  CommunicationLabel
} from '~/types/communication'
import {
  COMMUNICATION_CONVERSATION_STATUS,
  communicationDisplayName,
  formatCommunicationDate
} from '~/utils/communication'

const props = defineProps<{
  conversation: CommunicationConversation
  inbox?: CommunicationInbox | null
  labels: CommunicationLabel[]
  departments: WorkDepartment[]
  canReply: boolean
  canManage: boolean
  outboundOperational?: boolean
  signals?: CommunicationConversationSignals
  mobile?: boolean
}>()

const emit = defineEmits<{
  close: []
  update: [patch: Record<string, unknown>]
  toggleLabel: [label: CommunicationLabel]
  exportContact: [contactId: number]
  purgeContact: [contactId: number]
  setDisappearing: [seconds: 0 | 86400 | 604800 | 7776000]
}>()

const priority = ref(props.conversation.priority)

watch(() => props.conversation.priority, value => priority.value = value)

const assigneeItems = computed(() => [
  { label: 'Sem responsável', value: 0 },
  ...(props.inbox?.members ?? []).map(member => ({
    label: member.name || `Membro #${member.id}`,
    value: member.id
  }))
])
const departmentItems = computed(() => [
  { label: 'Sem fila', value: 0 },
  ...props.departments.map(department => ({ label: department.name, value: department.id }))
])

function setAssignee(value: string | number | undefined) {
  if (value === undefined) return
  emit('update', { assignee_membership_id: Number(value) || null })
}

function setDepartment(value: string | number | undefined) {
  if (value === undefined) return
  emit('update', { work_department_id: Number(value) || null })
}

function savePriority() {
  const next = Math.max(0, Math.min(100, Number(priority.value) || 0))
  priority.value = next
  if (next !== props.conversation.priority) emit('update', { priority: next })
}

function snoozeUntil(date: Date) {
  emit('update', { status: 'SNOOZED', snoozed_until: date.toISOString() })
}

const snoozeItems = [[
  {
    label: 'Por 1 hora',
    icon: 'i-lucide-clock-3',
    onSelect: () => snoozeUntil(new Date(Date.now() + 60 * 60 * 1000))
  },
  {
    label: 'Até amanhã às 9h',
    icon: 'i-lucide-sunrise',
    onSelect: () => {
      const date = new Date()
      date.setDate(date.getDate() + 1)
      date.setHours(9, 0, 0, 0)
      snoozeUntil(date)
    }
  },
  {
    label: 'Por 7 dias',
    icon: 'i-lucide-calendar-days',
    onSelect: () => snoozeUntil(new Date(Date.now() + 7 * 24 * 60 * 60 * 1000))
  }
]]

const disappearingItems = [[
  {
    label: 'Desativar',
    icon: 'i-lucide-timer-off',
    onSelect: () => emit('setDisappearing', 0)
  },
  {
    label: '24 horas',
    icon: 'i-lucide-clock-3',
    onSelect: () => emit('setDisappearing', 86400)
  },
  {
    label: '7 dias',
    icon: 'i-lucide-calendar-days',
    onSelect: () => emit('setDisappearing', 604800)
  },
  {
    label: '90 dias',
    icon: 'i-lucide-calendar-range',
    onSelect: () => emit('setDisappearing', 7776000)
  }
]]
</script>

<template>
  <UDashboardPanel
    :id="`communication-context-${conversation.id}`"
    data-testid="communication-context-panel"
    class="min-w-0"
    :default-size="24"
    :min-size="20"
    :max-size="32"
    resizable
  >
    <UDashboardNavbar
      title="Contexto"
      :toggle="false"
    >
      <template #leading>
        <UButton
          v-if="mobile"
          icon="i-lucide-arrow-left"
          color="neutral"
          variant="ghost"
          aria-label="Voltar à conversa"
          @click="emit('close')"
        />
      </template>
      <template #right>
        <UTooltip text="Fechar contexto">
          <UButton
            icon="i-lucide-panel-right-close"
            color="neutral"
            variant="ghost"
            aria-label="Fechar contexto"
            data-testid="communication-context-close"
            @click="emit('close')"
          />
        </UTooltip>
      </template>
    </UDashboardNavbar>

    <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
      <div class="flex items-start gap-3">
        <UAvatar
          :alt="communicationDisplayName(conversation)"
          size="xl"
        />
        <div class="min-w-0 flex-1">
          <p class="truncate font-semibold text-highlighted">
            {{ communicationDisplayName(conversation) }}
          </p>
          <p class="text-sm text-muted">
            {{ conversation.contact?.address || conversation.contact?.address_masked || 'Telefone não disponível' }}
          </p>
          <p
            v-if="signals?.contact?.available"
            class="mt-1 flex items-center gap-1.5 text-xs text-success"
          >
            <span class="size-1.5 rounded-full bg-success" /> online
          </p>
          <p v-else-if="signals?.contact?.last_seen" class="mt-1 text-xs text-muted">
            Visto por último em {{ formatCommunicationDate(signals.contact.last_seen) }}
          </p>
          <p v-if="signals?.chat" class="mt-1 text-xs font-medium text-primary">
            {{ signals.chat.presence === 'RECORDING' ? 'Gravando áudio…' : 'Digitando…' }}
          </p>
          <UBadge
            v-if="conversation.contact?.is_provisional"
            label="Contato provisório"
            color="warning"
            variant="soft"
            class="mt-2"
          />
        </div>
      </div>

      <USeparator class="my-5" />

      <section class="space-y-3">
        <div class="flex items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-highlighted">
            Atendimento
          </h3>
          <UBadge
            :label="COMMUNICATION_CONVERSATION_STATUS[conversation.status].label"
            :color="COMMUNICATION_CONVERSATION_STATUS[conversation.status].color"
            variant="subtle"
          />
        </div>

        <UFormField label="Responsável">
          <USelectMenu
            :model-value="conversation.assignee_membership_id || 0"
            :items="assigneeItems"
            value-key="value"
            class="w-full"
            :disabled="!canReply"
            @update:model-value="setAssignee"
          />
        </UFormField>

        <UFormField label="Fila / departamento">
          <USelectMenu
            :model-value="conversation.work_department_id || 0"
            :items="departmentItems"
            value-key="value"
            class="w-full"
            :disabled="!canReply"
            @update:model-value="setDepartment"
          />
        </UFormField>

        <UFormField label="Prioridade (0–100)">
          <UInput
            v-model.number="priority"
            type="number"
            min="0"
            max="100"
            class="w-full"
            :disabled="!canReply"
            @change="savePriority"
          />
        </UFormField>

        <UDropdownMenu
          v-if="canReply"
          :items="snoozeItems"
        >
          <UButton
            label="Adiar conversa"
            icon="i-lucide-alarm-clock"
            color="neutral"
            variant="outline"
            block
          />
        </UDropdownMenu>
        <p
          v-if="conversation.snoozed_until"
          class="text-xs text-muted"
        >
          Retorna à fila em {{ formatCommunicationDate(conversation.snoozed_until) }}.
        </p>
      </section>

      <USeparator class="my-5" />

      <section>
        <h3 class="mb-2 text-sm font-semibold text-highlighted">
          WhatsApp 1:1
        </h3>
        <p class="mb-3 text-xs text-muted">
          O temporizador é aplicado pelo WhatsApp após confirmação e não altera o histórico auditável local.
        </p>
        <UDropdownMenu :items="disappearingItems">
          <UButton
            label="Mensagens temporárias"
            icon="i-lucide-timer"
            color="neutral"
            variant="outline"
            block
            :disabled="!canReply || !outboundOperational"
          />
        </UDropdownMenu>
      </section>

      <USeparator class="my-5" />

      <section>
        <h3 class="mb-3 text-sm font-semibold text-highlighted">
          Marcadores
        </h3>
        <div class="flex flex-wrap gap-2">
          <UButton
            v-for="label in labels"
            :key="label.id"
            :label="label.name"
            :icon="conversation.labels?.some(item => item.id === label.id)
              ? 'i-lucide-check'
              : 'i-lucide-plus'"
            color="neutral"
            :variant="conversation.labels?.some(item => item.id === label.id) ? 'soft' : 'outline'"
            size="xs"
            :disabled="!canReply"
            @click="emit('toggleLabel', label)"
          />
          <span
            v-if="!labels.length"
            class="text-xs text-muted"
          >Nenhum marcador cadastrado.</span>
        </div>
      </section>

      <USeparator class="my-5" />

      <section>
        <h3 class="mb-3 text-sm font-semibold text-highlighted">
          Clientes vinculados
        </h3>
        <div
          v-if="conversation.clients?.length"
          class="space-y-2"
        >
          <UButton
            v-for="client in conversation.clients"
            :key="client.id"
            :label="client.name"
            icon="i-lucide-building-2"
            :to="`/clients/${client.id}`"
            color="neutral"
            variant="ghost"
            block
            class="justify-start"
          />
        </div>
        <p
          v-else
          class="text-sm text-muted"
        >
          Esta identidade ainda não está vinculada a um cliente fiscal.
        </p>
      </section>

      <template v-if="canManage && conversation.contact?.id">
        <USeparator class="my-5" />
        <section>
          <h3 class="mb-2 text-sm font-semibold text-highlighted">
            Privacidade e retenção
          </h3>
          <p class="mb-3 text-xs text-muted">
            A exportação é privada. O expurgo remove corpos e anexos e mantém apenas o tombstone auditável.
          </p>
          <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
            <UButton
              label="Exportar contato"
              icon="i-lucide-download"
              color="neutral"
              variant="outline"
              @click="emit('exportContact', conversation.contact.id)"
            />
            <UButton
              label="Expurgar dados"
              icon="i-lucide-trash-2"
              color="error"
              variant="soft"
              @click="emit('purgeContact', conversation.contact.id)"
            />
          </div>
        </section>
      </template>
    </div>
  </UDashboardPanel>
</template>
