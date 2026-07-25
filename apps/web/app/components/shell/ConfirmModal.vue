<script setup lang="ts">
/**
 * Confirmação simples — título + descrição + Cancelar / Confirmar.
 * Tom danger → botão error; reforçados (TOTP) ficam no domínio.
 */
const open = defineModel<boolean>('open', { default: false })

withDefaults(defineProps<{
  title: string
  description?: string
  tone?: 'neutral' | 'danger'
  cancelLabel?: string
  confirmLabel?: string
  confirmIcon?: string
  loading?: boolean
  contentClass?: string
  testId?: string
  confirmTestId?: string
}>(), {
  description: undefined,
  tone: 'neutral',
  cancelLabel: 'Cancelar',
  confirmLabel: 'Confirmar',
  confirmIcon: undefined,
  loading: false,
  contentClass: 'sm:max-w-md',
  testId: 'shell-confirm-modal',
  confirmTestId: 'shell-modal-submit'
})

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

function onCancel() {
  open.value = false
  emit('cancel')
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title"
    :description="description"
    :ui="{ content: contentClass }"
    :data-testid="testId"
  >
    <template
      v-if="$slots.body"
      #body
    >
      <slot name="body" />
    </template>
    <template #footer>
      <ShellModalFooter
        :cancel-label="cancelLabel"
        :submit-label="confirmLabel"
        :submit-color="tone === 'danger' ? 'error' : 'primary'"
        :submit-icon="confirmIcon"
        :loading="loading"
        :submit-test-id="confirmTestId"
        @cancel="onCancel"
        @submit="emit('confirm')"
      />
    </template>
  </UModal>
</template>
