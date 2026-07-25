<script setup lang="ts">
/**
 * Modal denso — max-height + body scroll + footer sticky.
 * Para detalhe/histórico (ClientDetail, Regime, Defis…).
 */
const open = defineModel<boolean>('open', { default: false })

withDefaults(defineProps<{
  title: string
  description?: string
  /** Classe do content (largura + altura). */
  contentClass?: string
  showDefaultFooter?: boolean
  cancelLabel?: string
  submitLabel?: string
  submitColor?: 'primary' | 'error' | 'neutral' | 'success' | 'warning' | 'info' | 'secondary'
  loading?: boolean
  disabled?: boolean
  dismissible?: boolean
  testId?: string
}>(), {
  description: undefined,
  contentClass: 'sm:max-w-2xl max-h-[min(90dvh,48rem)]',
  showDefaultFooter: false,
  cancelLabel: 'Fechar',
  submitLabel: 'Salvar',
  submitColor: 'primary',
  loading: false,
  disabled: false,
  dismissible: undefined,
  testId: 'shell-scrollable-modal'
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
    :dismissible="dismissible"
    :ui="{
      content: contentClass,
      body: 'overflow-y-auto max-h-[min(70dvh,36rem)]'
    }"
    :data-testid="testId"
  >
    <slot />
    <template
      v-if="$slots.actions"
      #actions
    >
      <slot name="actions" />
    </template>
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
          :loading="loading"
          :disabled="disabled"
          :show-submit="Boolean(submitLabel)"
          @cancel="onCancel"
          @submit="emit('submit')"
        />
      </slot>
    </template>
  </UModal>
</template>
