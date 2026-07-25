<script setup lang="ts">
import type { Client, ClientCategory } from '~/types/api'
import { apiErrorMessage } from '~/utils/api-error'

type AssignmentMode = 'replace' | 'add' | 'remove'

const props = defineProps<{
  mode: AssignmentMode
  categories: ClientCategory[]
  client?: Client | null
  clientIds?: number[]
}>()

const emit = defineEmits<{
  saved: []
}>()

const open = defineModel<boolean>('open', { default: false })
const api = useApi()
const toast = useToast()
const selectedIds = ref<number[]>([])
const submitting = ref(false)

const isBulk = computed(() => props.mode !== 'replace')
const title = computed(() => {
  if (props.mode === 'add') return 'Adicionar categorias'
  if (props.mode === 'remove') return 'Remover categorias'
  return 'Gerenciar categorias'
})
const description = computed(() => {
  if (isBulk.value) {
    return `${props.clientIds?.length ?? 0} cliente(s) selecionado(s).`
  }
  return props.client ? (props.client.legal_name || props.client.name) : undefined
})
const initiallyAssignedIds = computed(() => props.client?.categories?.map(category => category.id) ?? [])
const items = computed(() => props.categories.map(category => ({
  id: category.id,
  label: category.name,
  description: category.is_active
    ? `${category.clients_count} cliente(s)`
    : 'Arquivada — disponível apenas para remoção',
  disabled: props.mode === 'add'
    ? !category.is_active
    : props.mode === 'replace' && !category.is_active && !initiallyAssignedIds.value.includes(category.id)
})))

function hydrate() {
  selectedIds.value = props.mode === 'replace' ? [...initiallyAssignedIds.value] : []
}

async function submit() {
  if (submitting.value || (isBulk.value && selectedIds.value.length === 0)) return
  submitting.value = true
  try {
    if (props.mode === 'replace' && props.client) {
      await api.clients.replaceCategories(props.client.id, selectedIds.value)
    } else {
      const clientIds = props.clientIds ?? []
      await api.clients.bulkCategories({
        operation: props.mode as 'add' | 'remove',
        client_ids: clientIds,
        category_ids: selectedIds.value
      })
    }

    toast.add({
      title: props.mode === 'remove' ? 'Categorias removidas.' : 'Categorias atualizadas.',
      color: 'success'
    })
    open.value = false
    emit('saved')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível atualizar as categorias.'),
      color: 'error'
    })
  } finally {
    submitting.value = false
  }
}

watch(open, (isOpen) => {
  if (isOpen) hydrate()
})
watch(() => props.client?.id, hydrate)
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    :title="title"
    :description="description"
    content-class="sm:max-w-lg"
    :submit-label="mode === 'remove' ? 'Remover' : 'Aplicar'"
    :submit-color="mode === 'remove' ? 'warning' : 'primary'"
    :loading="submitting"
    :disabled="isBulk && selectedIds.length === 0"
    @cancel="() => { open = false }"
    @submit="submit"
  >
    <template #body>
      <div class="space-y-4">
        <UFormField
          label="Categorias"
          name="category_ids"
          :description="mode === 'remove'
            ? 'Categorias arquivadas também podem ser removidas.'
            : 'Pesquise e selecione uma ou mais categorias.'"
        >
          <USelectMenu
            v-model="selectedIds"
            :items="items"
            value-key="id"
            label-key="label"
            multiple
            clear
            class="w-full"
            placeholder="Selecionar categorias"
            :search-input="{ placeholder: 'Buscar categoria…', icon: 'i-lucide-search' }"
            :disabled="submitting || !items.length"
          />
        </UFormField>

        <UAlert
          v-if="!items.length"
          color="neutral"
          variant="subtle"
          icon="i-lucide-tags"
          title="Nenhuma categoria disponível"
          description="Peça a um administrador para criar categorias no catálogo."
        />
      </div>
    </template>
  </ShellFormModal>
</template>
