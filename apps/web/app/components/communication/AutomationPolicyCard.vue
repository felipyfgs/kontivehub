<script setup lang="ts">
import type {
  CommunicationAutomationPolicy,
  CommunicationInbox,
  CommunicationRecipientMode
} from '~/types/communication'

const props = defineProps<{
  scope: string
  policy?: CommunicationAutomationPolicy | null
  inboxes: CommunicationInbox[]
}>()

const workspace = useCommunicationWorkspace()
const [moduleKey, submoduleKey] = props.scope.split(':') as [string, string]
const scopeLabels: Record<string, string> = {
  'simples_mei:pgdasd': 'Simples Nacional · PGDAS-D',
  'simples_mei:pgmei': 'MEI · PGMEI',
  'dctfweb:dctfweb': 'DCTFWeb',
  'fgts:fgts': 'FGTS Digital'
}

const enabled = ref(props.policy?.is_enabled ?? false)
const inboxId = ref<number>(props.policy?.inbox_id ?? 0)
const sendDay = ref(props.policy?.send_day ?? 10)
const sendTime = ref(props.policy?.send_time ?? '09:00')
const recipientMode = ref<CommunicationRecipientMode>(props.policy?.recipient_mode ?? 'PRIMARY')
const templateKey = ref(props.policy?.template_key ?? `fiscal.${moduleKey}.${submoduleKey}`)
const templateVersion = ref(props.policy?.template_version ?? 'v1')
const saving = ref(false)

const inboxItems = computed(() => [
  { label: 'Selecione uma inbox', value: 0 },
  ...props.inboxes.map(inbox => ({
    label: `${inbox.name} · ${inbox.status === 'CONNECTED' ? 'conectada' : 'indisponível'}`,
    value: inbox.id
  }))
])
const recipientItems = [
  { label: 'Contato principal', value: 'PRIMARY' },
  { label: 'Todos os elegíveis', value: 'ALL_ELIGIBLE' },
  { label: 'Seleção por cliente', value: 'SELECTED' }
]
const selectedInbox = computed(() => props.inboxes.find(inbox => inbox.id === inboxId.value))

watch(() => props.policy, (policy) => {
  if (!policy) return
  enabled.value = policy.is_enabled
  inboxId.value = policy.inbox_id ?? 0
  sendDay.value = policy.send_day
  sendTime.value = policy.send_time
  recipientMode.value = policy.recipient_mode
  templateKey.value = policy.template_key
  templateVersion.value = policy.template_version
}, { deep: true })

async function save() {
  saving.value = true
  await workspace.savePolicy({
    module_key: moduleKey,
    submodule_key: submoduleKey,
    inbox_id: inboxId.value || null,
    is_enabled: enabled.value,
    send_day: Math.max(1, Math.min(28, Number(sendDay.value) || 1)),
    send_time: sendTime.value,
    timezone: 'America/Sao_Paulo',
    recipient_mode: recipientMode.value,
    template_key: templateKey.value.trim(),
    template_version: templateVersion.value.trim(),
    lock_version: props.policy?.lock_version ?? 0
  })
  saving.value = false
}
</script>

<template>
  <UCard
    :data-testid="`communication-policy-${scope.replace(':', '-')}`"
    variant="subtle"
  >
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="font-semibold text-highlighted">
            {{ scopeLabels[scope] || scope }}
          </p>
          <p class="text-xs text-muted">
            Envio no cutoff; a consulta fiscal não dispara mensagem imediatamente.
          </p>
        </div>
        <USwitch
          v-model="enabled"
          label="Automática"
        />
      </div>
    </template>

    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField
        label="Inbox geral"
        :error="enabled && !inboxId ? 'Obrigatória para ativar' : undefined"
      >
        <USelectMenu
          v-model="inboxId"
          :items="inboxItems"
          value-key="value"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Destinatários">
        <USelectMenu
          v-model="recipientMode"
          :items="recipientItems"
          value-key="value"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Dia de envio (1–28)">
        <UInput
          v-model.number="sendDay"
          type="number"
          min="1"
          max="28"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Horário de envio">
        <UInput
          v-model="sendTime"
          type="time"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Template">
        <UInput
          v-model="templateKey"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Versão">
        <UInput
          v-model="templateVersion"
          class="w-full"
        />
      </UFormField>
    </div>

    <UAlert
      v-if="enabled && selectedInbox?.status !== 'CONNECTED'"
      title="A política ficará salva, mas não enviará enquanto a inbox não estiver conectada."
      color="warning"
      icon="i-lucide-shield-alert"
      variant="subtle"
      class="mt-4"
    />
    <UAlert
      v-if="scope === 'fgts:fgts'"
      title="FGTS permanece SKIPPED_NO_DOCUMENT enquanto a guia local estiver sem suporte."
      color="info"
      icon="i-lucide-file-warning"
      variant="subtle"
      class="mt-4"
    />

    <template #footer>
      <div class="flex justify-end">
        <UButton
          label="Salvar política"
          icon="i-lucide-save"
          :loading="saving"
          :disabled="enabled && !inboxId || !templateKey.trim() || !templateVersion.trim()"
          @click="save"
        />
      </div>
    </template>
  </UCard>
</template>
