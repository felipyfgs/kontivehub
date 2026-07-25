<script setup lang="ts">
/**
 * Slideover global do assistente — espelha `NotificationsSlideover`.
 * Layout: USlideover flex height + UChatPalette (prompt no #prompt).
 * Disponibilidade fail-closed: triggers/atalho gated; sheet monta no shell.
 */

/** Sugestões de leitura Work — sem mutação (create só via approval). */
const WORK_SUGGESTIONS = [
  'Quais modelos de processo temos?',
  'Liste os departamentos Work',
  'Quais módulos de monitoramento posso usar?'
] as const

const {
  isAssistantSlideoverOpen,
  me,
  sessionEpoch
} = useDashboard()

const {
  messages,
  pendingApprovals,
  status,
  errorMessage,
  approvingToken,
  bootstrapping,
  previewApprovalArgs,
  reset,
  bootstrapOnOpen,
  sendMessage,
  approvePending,
  denyPending
} = useAssistantChat()

const input = ref('')

watch(isAssistantSlideoverOpen, (open) => {
  if (open) {
    void bootstrapOnOpen()
  }
})

watch(() => me.value?.id, (next, prev) => {
  if (prev !== undefined && next !== prev) {
    reset()
    if (isAssistantSlideoverOpen.value) {
      void bootstrapOnOpen()
    }
  }
  if (!next) {
    reset()
  }
})

watch(sessionEpoch, () => {
  reset()
  if (isAssistantSlideoverOpen.value) {
    void bootstrapOnOpen()
  }
})

async function onSubmit() {
  const text = input.value
  input.value = ''
  await sendMessage(text)
}

async function onSuggestion(label: string) {
  if (chatBusy.value) return
  await sendMessage(label)
}

const chatBusy = computed(() =>
  status.value === 'submitted' || bootstrapping.value || approvingToken.value != null
)

/** UChatPrompt tipa `error` como `Error` (não string). */
const promptError = computed(() =>
  errorMessage.value ? new Error(errorMessage.value) : undefined
)

const messagesStatus = computed(() =>
  status.value === 'submitted' ? 'submitted' as const : 'ready' as const
)
</script>

<template>
  <USlideover
    v-model:open="isAssistantSlideoverOpen"
    title="Assistente"
    description="Consulta o catálogo Work e propõe modelos de processo com confirmação."
    data-testid="assistant-slideover"
    :ui="{
      content: 'max-w-lg w-full sm:max-w-lg flex flex-col',
      body: 'flex-1 flex flex-col min-h-0 overflow-hidden p-0 sm:p-0'
    }"
  >
    <template #body>
      <UChatPalette class="min-h-0 flex-1 h-full">
        <div
          v-if="bootstrapping && !messages.length"
          class="space-y-3 px-2.5"
          role="status"
          aria-label="Preparando assistente"
          data-testid="assistant-bootstrap-loading"
        >
          <USkeleton
            v-for="index in 3"
            :key="index"
            class="h-12 w-full"
          />
        </div>

        <UAlert
          v-else-if="errorMessage && !messages.length && status === 'error'"
          color="error"
          icon="i-lucide-circle-x"
          :title="errorMessage"
          class="mx-2.5"
        />

        <div
          v-else-if="!messages.length && !bootstrapping && !pendingApprovals.length"
          class="flex flex-1 flex-col items-center justify-center gap-4 px-4 py-6"
          data-testid="assistant-empty"
        >
          <UEmpty
            icon="i-lucide-sparkles"
            title="Como posso ajudar?"
            description="Pergunte sobre modelos, departamentos ou módulos de monitoramento. Para criar um modelo, confirme a proposta na interface."
          />
          <div
            class="flex flex-wrap justify-center gap-2"
            data-testid="assistant-suggestions"
          >
            <UButton
              v-for="suggestion in WORK_SUGGESTIONS"
              :key="suggestion"
              :label="suggestion"
              color="neutral"
              variant="soft"
              size="sm"
              :disabled="chatBusy"
              @click="onSuggestion(suggestion)"
            />
          </div>
        </div>

        <template v-else-if="messages.length">
          <UChatMessages
            :messages="messages"
            :status="messagesStatus"
            should-auto-scroll
            :assistant="{
              avatar: { icon: 'i-lucide-sparkles' }
            }"
            :user="{
              side: 'right',
              variant: 'soft'
            }"
          >
            <template #content="{ message }">
              <p
                v-for="(part, index) in message.parts"
                :key="`${message.id}-${part.type}-${index}`"
                class="whitespace-pre-wrap text-sm leading-6"
              >
                {{ part.type === 'text' ? part.text : '' }}
              </p>
            </template>
            <template #indicator>
              <div class="flex items-center gap-2 px-2.5 py-3">
                <UChatShimmer text="Pensando…" />
              </div>
            </template>
          </UChatMessages>

          <UAlert
            v-if="errorMessage"
            color="warning"
            icon="i-lucide-triangle-alert"
            class="mx-2.5 mb-2"
            :title="errorMessage"
          />
        </template>

        <div
          v-if="pendingApprovals.length"
          class="space-y-2 px-2.5 pb-2"
          data-testid="assistant-pending-approvals"
        >
          <UChatTool
            v-for="approval in pendingApprovals"
            :key="approval.approval_token"
            variant="card"
            icon="i-lucide-file-plus"
            text="Criar modelo de processo"
            :suffix="typeof approval.args.name === 'string' ? String(approval.args.name) : undefined"
            :default-open="true"
            :loading="approvingToken === approval.approval_token"
            :actions="[
              {
                label: 'Aprovar',
                color: 'primary',
                loading: approvingToken === approval.approval_token,
                disabled: approvingToken != null && approvingToken !== approval.approval_token,
                onClick: () => { void approvePending(approval) }
              },
              {
                label: 'Negar',
                color: 'neutral',
                variant: 'soft',
                disabled: approvingToken != null,
                onClick: () => { void denyPending(approval) }
              }
            ]"
          >
            <pre class="text-xs whitespace-pre-wrap break-words">{{ previewApprovalArgs(approval.args) }}</pre>
          </UChatTool>
        </div>

        <template #prompt>
          <UChatPrompt
            v-model="input"
            placeholder="Pergunte sobre Work ou peça para criar um modelo…"
            :error="promptError"
            :disabled="chatBusy"
            :autofocus="true"
            data-testid="assistant-prompt"
            @submit="onSubmit"
          >
            <UChatPromptSubmit
              :status="status === 'submitted' ? 'submitted' : 'ready'"
              :disabled="!input.trim() || chatBusy"
            />
          </UChatPrompt>
        </template>
      </UChatPalette>
    </template>
  </USlideover>
</template>
