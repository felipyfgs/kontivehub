<script setup lang="ts">
/**
 * Faixa de ações de modal — Cancelar + Submit (padrão SaveFilterModal).
 */
withDefaults(defineProps<{
  cancelLabel?: string
  submitLabel?: string
  /** Cor do botão primário (primary | error | …). */
  submitColor?: 'primary' | 'error' | 'neutral' | 'success' | 'warning' | 'info' | 'secondary'
  submitIcon?: string
  loading?: boolean
  disabled?: boolean
  cancelDisabled?: boolean
  showSubmit?: boolean
  showCancel?: boolean
  cancelTestId?: string
  submitTestId?: string
}>(), {
  cancelLabel: 'Cancelar',
  submitLabel: 'Salvar',
  submitColor: 'primary',
  submitIcon: undefined,
  loading: false,
  disabled: false,
  cancelDisabled: false,
  showSubmit: true,
  showCancel: true,
  cancelTestId: 'shell-modal-cancel',
  submitTestId: 'shell-modal-submit'
})

const emit = defineEmits<{
  cancel: []
  submit: []
}>()
</script>

<template>
  <div
    class="flex w-full justify-end gap-2"
    data-testid="shell-modal-footer"
  >
    <slot>
      <UButton
        v-if="showCancel"
        color="neutral"
        variant="ghost"
        :label="cancelLabel"
        :disabled="cancelDisabled || loading"
        :data-testid="cancelTestId"
        @click="emit('cancel')"
      />
      <UButton
        v-if="showSubmit"
        :color="submitColor"
        :label="submitLabel"
        :icon="submitIcon"
        :loading="loading"
        :disabled="disabled"
        :data-testid="submitTestId"
        @click="emit('submit')"
      />
      <slot name="trailing" />
    </slot>
  </div>
</template>
