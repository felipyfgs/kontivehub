import type { Ref } from 'vue'

export interface WorkFormSubmissionGuard {
  requestSubmit: (form: HTMLFormElement | null) => boolean
  submit: (action: () => Promise<void>) => Promise<boolean>
  validationError: () => void
}

/**
 * Coordena a janela entre requestSubmit(), validação do UForm e a mutação assíncrona.
 * O lock separado impede dois requestSubmit antes de o primeiro @submit ser emitido.
 */
export function createWorkFormSubmissionGuard(
  locked: Ref<boolean>,
  busy: Ref<boolean>
): WorkFormSubmissionGuard {
  return {
    requestSubmit(form) {
      if (locked.value || busy.value || !form) return false
      locked.value = true
      try {
        form.requestSubmit()
        return true
      } catch (error) {
        locked.value = false
        throw error
      }
    },

    async submit(action) {
      if (busy.value) return false
      locked.value = true
      busy.value = true
      try {
        await action()
        return true
      } finally {
        busy.value = false
        locked.value = false
      }
    },

    validationError() {
      if (!busy.value) locked.value = false
    }
  }
}
