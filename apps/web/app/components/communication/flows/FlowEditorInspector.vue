<script setup lang="ts">
/**
 * Inspector de propriedades do nó selecionado.
 */
import type { FlowNode } from '~/types/communication/flows'
import {
  FLOW_ACTION_KINDS,
  FLOW_CONDITION_FIELDS,
  FLOW_CONDITION_OPERATORS,
  FLOW_NODE_TYPE_META
} from '~/utils/communication-flow-graph'

const props = defineProps<{
  node: FlowNode | null
  disabled?: boolean
}>()

const emit = defineEmits<{
  update: [data: Record<string, unknown>]
  remove: []
  connectRequest: []
}>()

const body = ref('')
const cannedResponseId = ref('')
const prompt = ref('')
const optionsText = ref('')
const field = ref<string>('last_inbound_text')
const operator = ref<string>('contains')
const value = ref('')
const durationSeconds = ref('60')
const actionKind = ref<string>('status')
const labelId = ref('')
const assigneeMembershipId = ref('')
const status = ref('OPEN')

watch(
  () => props.node,
  (node) => {
    const data = node?.data ?? {}
    body.value = typeof data.body === 'string' ? data.body : ''
    cannedResponseId.value = data.canned_response_id == null ? '' : String(data.canned_response_id)
    prompt.value = typeof data.prompt === 'string' ? data.prompt : ''
    optionsText.value = Array.isArray(data.options) ? data.options.map(String).join('\n') : ''
    field.value = typeof data.field === 'string' ? data.field : 'last_inbound_text'
    operator.value = typeof data.operator === 'string' ? data.operator : 'contains'
    value.value = data.value == null ? '' : String(data.value)
    durationSeconds.value = String(data.duration_seconds ?? 60)
    actionKind.value = typeof data.kind === 'string' ? data.kind : 'status'
    labelId.value = data.label_id == null ? '' : String(data.label_id)
    assigneeMembershipId.value = data.assignee_membership_id == null
      ? ''
      : String(data.assignee_membership_id)
    status.value = typeof data.status === 'string' ? data.status : 'OPEN'
  },
  { immediate: true, deep: true }
)

function commit() {
  if (!props.node || props.disabled) return
  const type = props.node.type
  const data: Record<string, unknown> = {}
  if (type === 'message') {
    data.body = body.value
    data.canned_response_id = cannedResponseId.value === '' ? null : Number(cannedResponseId.value)
  } else if (type === 'quick_reply') {
    data.canned_response_id = cannedResponseId.value === '' ? null : Number(cannedResponseId.value)
  } else if (type === 'question') {
    data.prompt = prompt.value
    data.options = optionsText.value.split('\n').map(line => line.trim()).filter(Boolean)
  } else if (type === 'condition') {
    data.field = field.value
    data.operator = operator.value
    data.value = value.value
  } else if (type === 'delay') {
    data.duration_seconds = Number(durationSeconds.value) || 1
  } else if (type === 'action') {
    data.kind = actionKind.value
    if (actionKind.value === 'label') {
      data.label_id = labelId.value === '' ? null : Number(labelId.value)
    } else if (actionKind.value === 'assignee') {
      data.assignee_membership_id = assigneeMembershipId.value === ''
        ? null
        : Number(assigneeMembershipId.value)
    } else {
      data.status = status.value
    }
  } else if (type === 'handoff') {
    data.assignee_membership_id = assigneeMembershipId.value === ''
      ? null
      : Number(assigneeMembershipId.value)
  }
  emit('update', data)
}

const meta = computed(() =>
  props.node ? FLOW_NODE_TYPE_META[props.node.type] : null
)

const conversationStatusItems = [
  { label: 'Aberta', value: 'OPEN' },
  { label: 'Pendente', value: 'PENDING' },
  { label: 'Resolvida', value: 'RESOLVED' },
  { label: 'Adiada', value: 'SNOOZED' }
]
</script>

<template>
  <aside
    class="flex h-full flex-col gap-3 overflow-y-auto p-3"
    aria-label="Inspector do nó"
    data-testid="flow-editor-inspector"
  >
    <UEmpty
      v-if="!node"
      icon="i-lucide-mouse-pointer-click"
      title="Nenhum nó selecionado"
      description="Selecione um nó no canvas ou na lista para editar propriedades."
      class="py-8"
    />

    <template v-else>
      <div>
        <p class="text-xs font-medium uppercase tracking-wide text-muted">
          {{ meta?.label }}
        </p>
        <p class="font-mono text-xs text-muted">
          {{ node.id }}
        </p>
      </div>

      <div
        v-if="node.type === 'message'"
        class="space-y-3"
      >
        <UFormField
          label="Corpo"
          name="body"
        >
          <UTextarea
            v-model="body"
            :rows="4"
            :disabled="disabled"
            class="w-full"
            aria-label="Texto da mensagem"
            @change="commit"
          />
        </UFormField>
        <UFormField
          label="Resposta rápida (id)"
          name="canned_response_id"
        >
          <UInput
            v-model="cannedResponseId"
            type="number"
            :disabled="disabled"
            class="w-full"
            aria-label="ID da resposta rápida"
            @change="commit"
          />
        </UFormField>
      </div>

      <div
        v-else-if="node.type === 'quick_reply'"
        class="space-y-3"
      >
        <UFormField
          label="Resposta rápida (id)"
          name="canned_response_id"
          required
        >
          <UInput
            v-model="cannedResponseId"
            type="number"
            :disabled="disabled"
            class="w-full"
            aria-label="ID da resposta rápida"
            @change="commit"
          />
        </UFormField>
      </div>

      <div
        v-else-if="node.type === 'question'"
        class="space-y-3"
      >
        <UFormField
          label="Pergunta"
          name="prompt"
          required
        >
          <UTextarea
            v-model="prompt"
            :rows="3"
            :disabled="disabled"
            class="w-full"
            aria-label="Texto da pergunta"
            @change="commit"
          />
        </UFormField>
        <UFormField
          label="Opções (uma por linha)"
          name="options"
          required
        >
          <UTextarea
            v-model="optionsText"
            :rows="4"
            :disabled="disabled"
            class="w-full"
            aria-label="Opções da pergunta"
            @change="commit"
          />
        </UFormField>
      </div>

      <div
        v-else-if="node.type === 'condition'"
        class="space-y-3"
      >
        <UFormField
          label="Campo"
          name="field"
        >
          <USelect
            v-model="field"
            :items="FLOW_CONDITION_FIELDS.map(item => ({ label: item, value: item as string }))"
            :disabled="disabled"
            class="w-full"
            @update:model-value="commit"
          />
        </UFormField>
        <UFormField
          label="Operador"
          name="operator"
        >
          <USelect
            v-model="operator"
            :items="FLOW_CONDITION_OPERATORS.map(item => ({ label: item, value: item as string }))"
            :disabled="disabled"
            class="w-full"
            @update:model-value="commit"
          />
        </UFormField>
        <UFormField
          label="Valor"
          name="value"
        >
          <UInput
            v-model="value"
            :disabled="disabled"
            class="w-full"
            @change="commit"
          />
        </UFormField>
      </div>

      <div
        v-else-if="node.type === 'delay'"
        class="space-y-3"
      >
        <UFormField
          label="Duração (segundos)"
          name="duration_seconds"
          required
        >
          <UInput
            v-model="durationSeconds"
            type="number"
            :disabled="disabled"
            class="w-full"
            aria-label="Duração do delay em segundos"
            @change="commit"
          />
        </UFormField>
      </div>

      <div
        v-else-if="node.type === 'action'"
        class="space-y-3"
      >
        <UFormField
          label="Tipo de ação"
          name="kind"
        >
          <USelect
            v-model="actionKind"
            :items="FLOW_ACTION_KINDS.map(item => ({ label: item, value: item as string }))"
            :disabled="disabled"
            class="w-full"
            @update:model-value="commit"
          />
        </UFormField>
        <UFormField
          v-if="actionKind === 'label'"
          label="Label (id)"
          name="label_id"
        >
          <UInput
            v-model="labelId"
            type="number"
            :disabled="disabled"
            class="w-full"
            @change="commit"
          />
        </UFormField>
        <UFormField
          v-else-if="actionKind === 'assignee'"
          label="Assignee (membership id)"
          name="assignee_membership_id"
        >
          <UInput
            v-model="assigneeMembershipId"
            type="number"
            :disabled="disabled"
            class="w-full"
            @change="commit"
          />
        </UFormField>
        <UFormField
          v-else
          label="Status"
          name="status"
        >
          <USelect
            v-model="status"
            :items="conversationStatusItems"
            :disabled="disabled"
            class="w-full"
            @update:model-value="commit"
          />
        </UFormField>
      </div>

      <div
        v-else-if="node.type === 'handoff'"
        class="space-y-3"
      >
        <UFormField
          label="Assignee (opcional)"
          name="assignee_membership_id"
        >
          <UInput
            v-model="assigneeMembershipId"
            type="number"
            :disabled="disabled"
            class="w-full"
            @change="commit"
          />
        </UFormField>
      </div>

      <UAlert
        v-else
        color="neutral"
        variant="subtle"
        icon="i-lucide-info"
        :title="`${meta?.label || 'Nó'} sem campos extras`"
        description="Este tipo não exige configuração adicional."
      />

      <div class="mt-auto flex flex-col gap-2 pt-2">
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-git-branch-plus"
          label="Conectar a…"
          :disabled="disabled"
          aria-label="Conectar nó selecionado a outro"
          data-testid="flow-editor-inspector-connect"
          @click="emit('connectRequest')"
        />
        <UButton
          color="error"
          variant="soft"
          icon="i-lucide-trash-2"
          label="Remover nó"
          :disabled="disabled"
          aria-label="Remover nó selecionado"
          data-testid="flow-editor-inspector-remove"
          @click="emit('remove')"
        />
      </div>
    </template>
  </aside>
</template>
