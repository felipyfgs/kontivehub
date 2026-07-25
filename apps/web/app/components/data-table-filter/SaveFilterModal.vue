<script setup lang="ts">
/**
 * Modal para salvar o estado de filtro aplicado como preset nomeado.
 * Casca: ShellFormModal (padrão SaveFilter / customers AddModal).
 */
const open = defineModel<boolean>('open', { default: false })

const props = withDefaults(defineProps<{
  /** VIEWER não pode compartilhar com o Office. */
  canShare?: boolean
  loading?: boolean
  error?: string | null
}>(), {
  canShare: false,
  loading: false,
  error: null
})

const emit = defineEmits<{
  confirm: [payload: { name: string, share: boolean }]
}>()

const name = ref('')
const share = ref(false)

watch(open, (isOpen) => {
  if (!isOpen) {
    name.value = ''
    share.value = false
  }
})

const canSubmit = computed(() => name.value.trim().length > 0 && !props.loading)

function onCancel() {
  open.value = false
}

function onConfirm() {
  if (!canSubmit.value) return
  emit('confirm', {
    name: name.value.trim(),
    share: props.canShare ? share.value : false
  })
}
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Salvar filtros"
    description="Guarde o recorte atual para reutilizar depois."
    content-class="sm:max-w-md"
    submit-label="Salvar"
    :loading="loading"
    :disabled="!canSubmit"
    test-id="save-filter-modal-shell"
    @cancel="onCancel"
    @submit="onConfirm"
  >
    <template #body>
      <div
        class="space-y-4 text-sm"
        data-testid="save-filter-modal"
      >
        <UAlert
          v-if="error"
          color="error"
          icon="i-lucide-circle-x"
          :title="error"
        />
        <UFormField
          label="Nome"
          required
        >
          <UInput
            v-model="name"
            placeholder="Ex.: Bloqueados"
            class="w-full"
            :disabled="loading"
            data-testid="save-filter-name"
            @keyup.enter="onConfirm"
          />
        </UFormField>
        <div class="flex items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="text-sm font-medium text-highlighted">
              Compartilhar com o escritório
            </p>
            <p class="text-xs text-muted">
              {{ canShare
                ? 'Outros membros do Office poderão aplicar este filtro.'
                : 'Seu papel não permite publicar filtros para a equipe.' }}
            </p>
          </div>
          <USwitch
            v-model="share"
            :disabled="!canShare || loading"
            aria-label="Compartilhar com o escritório"
            data-testid="save-filter-share"
          />
        </div>
      </div>
    </template>
    <template #footer>
      <ShellModalFooter
        cancel-test-id="save-filter-cancel"
        submit-test-id="save-filter-confirm"
        submit-label="Salvar"
        :loading="loading"
        :disabled="!canSubmit"
        @cancel="onCancel"
        @submit="onConfirm"
      />
    </template>
  </ShellFormModal>
</template>
