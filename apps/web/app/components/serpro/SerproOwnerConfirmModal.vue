<script setup lang="ts">
/**
 * Confirmação reforçada do proprietário (OWNER_CONFIRMATION):
 * senha recente + frase exata + motivo + janela implícita.
 * Nunca exibe PFX, OAuth secret, token ou vault.
 */
import {
  expectedOwnerConfirmationPhrase,
  validateOwnerConfirmationInput
} from '~/utils/serpro-owner-confirmation'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  action: string
  title?: string
  /** Frase esperada vinda do servidor; se ausente, deriva da action. */
  expectedPhrase?: string | null
}>()

const emit = defineEmits<{
  confirm: [payload: {
    reason: string
    confirmation_phrase: string
    password: string
  }]
}>()

const api = useApi()
const toast = useToast()

const reason = ref('')
const confirmationPhrase = ref('')
const password = ref('')
const submitting = ref(false)

const phrase = computed(() =>
  String(props.expectedPhrase || expectedOwnerConfirmationPhrase(props.action))
)

watch(open, (isOpen) => {
  if (!isOpen) {
    reason.value = ''
    confirmationPhrase.value = ''
    password.value = ''
    submitting.value = false
  }
})

function close() {
  open.value = false
}

async function submit() {
  const check = validateOwnerConfirmationInput({
    reason: reason.value,
    confirmationPhrase: confirmationPhrase.value,
    expectedPhrase: phrase.value,
    password: password.value,
    requirePassword: true
  })
  if (!check.ok) {
    toast.add({ title: check.message, color: 'warning' })
    return
  }

  submitting.value = true
  try {
    await api.confirmPassword(password.value.trim())
    emit('confirm', {
      reason: reason.value.trim(),
      confirmation_phrase: confirmationPhrase.value.trim(),
      password: password.value.trim()
    })
    close()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Senha inválida ou confirmação expirada.'),
      color: 'error'
    })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title || 'Confirmação do proprietário'"
    data-testid="serpro-owner-confirm-modal"
  >
    <template #body>
      <div class="flex flex-col gap-4 text-sm">
        <UFormField
          label="Frase de confirmação"
          :hint="`Digite exatamente: ${phrase}`"
          required
        >
          <UInput
            v-model="confirmationPhrase"
            :placeholder="phrase"
            autocomplete="off"
            data-testid="serpro-owner-confirm-phrase"
          />
        </UFormField>

        <UFormField
          label="Motivo (auditoria)"
          required
        >
          <UTextarea
            v-model="reason"
            :rows="2"
            placeholder="Por que esta mudança é necessária agora?"
            data-testid="serpro-owner-confirm-reason"
          />
        </UFormField>

        <UFormField
          label="Senha do proprietário"
          hint="Reconfirmação na sessão corrente (máx. 15 minutos)."
          required
        >
          <UInput
            v-model="password"
            type="password"
            autocomplete="current-password"
            data-testid="serpro-owner-confirm-password"
          />
        </UFormField>
      </div>
    </template>

    <template #footer>
      <ShellModalFooter
        submit-label="Confirmar operação"
        submit-color="error"
        submit-test-id="serpro-owner-confirm-submit"
        :loading="submitting"
        @cancel="close"
        @submit="submit"
      />
    </template>
  </UModal>
</template>
