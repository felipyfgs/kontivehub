<script setup lang="ts">
import type { Client } from '~/types/api'
import { formatCnpj } from '~/utils/format'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  /** null = criar; Client = editar com o mesmo formulário */
  client?: Client | null
  canManageCredentials?: boolean
  canManageClients?: boolean
  /** Pré-vínculo matriz ao criar filial */
  matrixClientId?: number | null
  matrixLabel?: string | null
}>()

const emit = defineEmits<{
  saved: [payload: { id: number, mode: 'create' | 'edit', section?: 'resumo' | 'certificado' }]
  openExisting: [id: number]
}>()

const formRef = ref<{ reset: () => void, clearSensitive: () => void } | null>(null)

const isEdit = computed(() => !!props.client?.id)

const editLegalName = computed(() =>
  props.client?.legal_name || props.client?.display_name || props.client?.name || null
)

const editCnpjLabel = computed(() => {
  const raw = props.client?.cnpj
    || props.client?.establishments?.find(e => e.is_matrix)?.cnpj
    || props.client?.establishments?.[0]?.cnpj
    || props.client?.root_cnpj
  return raw ? formatCnpj(raw) : null
})

const title = computed(() => {
  if (isEdit.value) {
    return 'Editar cliente'
  }
  return props.matrixClientId ? 'Nova filial (cliente próprio)' : 'Novo cliente'
})

const description = computed(() => {
  if (isEdit.value) {
    const parts = [editLegalName.value, editCnpjLabel.value].filter(Boolean)
    return parts.length ? parts.join(' · ') : 'Atualize o cadastro. CNPJ não pode ser alterado.'
  }
  if (props.matrixClientId) {
    return 'Cadastre a filial com CNPJ completo. Ela terá cadastro, certificado e sync próprios e aparecerá na matriz.'
  }
  return 'Cadastre os dados essenciais. A consulta do CNPJ preenche sugestões editáveis.'
})

function onSaved(payload: { id: number, mode: 'create' | 'edit', section?: 'resumo' | 'certificado' }) {
  open.value = false
  emit('saved', payload)
}

function onCancel() {
  open.value = false
}

function onOpenExisting(id: number) {
  open.value = false
  emit('openExisting', id)
}

watch(open, (value) => {
  if (value) {
    nextTick(() => formRef.value?.reset())
  } else {
    // Espelha ClientCredentialModal: limpa PFX/senha/SECRET ao fechar.
    formRef.value?.clearSensitive()
    formRef.value?.reset()
  }
})
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    :title="title"
    :description="description"
    content-class="w-[calc(100vw-1.5rem)] sm:max-w-4xl max-h-[min(92dvh,52rem)] overflow-hidden flex flex-col"
    :show-default-footer="false"
    test-id="client-form-modal"
    @cancel="onCancel"
  >
    <template #body>
      <ClientsClientForm
        ref="formRef"
        form-id="client-form-modal"
        :client="client"
        :can-manage-credentials="canManageCredentials"
        :can-manage-clients="canManageClients === true"
        :matrix-client-id="matrixClientId"
        :matrix-label="matrixLabel"
        @saved="onSaved"
        @cancel="onCancel"
        @open-existing="onOpenExisting"
      />
    </template>
  </ShellFormModal>
</template>
