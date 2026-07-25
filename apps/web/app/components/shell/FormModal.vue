<script setup lang="ts">
/**
 * Casca de modal de formulário — UModal + body + footer Cancel/Submit.
 * Referência: SaveFilterModal / customers AddModal.
 */
const open = defineModel<boolean>('open', { default: false })

withDefaults(defineProps<{
  title: string
  description?: string
  /** Classes do content (ex.: sm:max-w-md). */
  contentClass?: string
  cancelLabel?: string
  submitLabel?: string
  submitColor?: 'primary' | 'error' | 'neutral' | 'success' | 'warning' | 'info' | 'secondary'
  submitIcon?: string
  loading?: boolean
  disabled?: boolean
  /** Quando false, não renderiza footer default (use #footer). */
  showDefaultFooter?: boolean
  testId?: string
}>(), {
  description: undefined,
  contentClass: 'sm:max-w-md',
  cancelLabel: 'Cancelar',
  submitLabel: 'Salvar',
  submitColor: 'primary',
  submitIcon: undefined,
  loading: false,
  disabled: false,
  showDefaultFooter: true,
  testId: 'shell-form-modal'
})

const emit = defineEmits<{
  cancel: []
  submit: []
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
    <slot />
    <template #body>
      <slot name="body" />
    </template>
    <template
      v-if="showDefaultFooter || $slots.footer"
      #footer
    >
      <slot name="footer">
        <ShellModalFooter
          :cancel-label="cancelLabel"
          :submit-label="submitLabel"
          :submit-color="submitColor"
          :submit-icon="submitIcon"
          :loading="loading"
          :disabled="disabled"
          @cancel="onCancel"
          @submit="emit('submit')"
        />
      </slot>
    </template>
  </UModal>
</template>
