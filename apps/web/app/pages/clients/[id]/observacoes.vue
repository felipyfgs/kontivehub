<script setup lang="ts">
/**
 * Observações do cliente — campo notes.
 */
const {
  item,
  canManageClients,
  load
} = useClientDetail()

const api = useApi()
const toast = useToast()

const notes = ref('')
const saving = ref(false)
const dirty = ref(false)
const syncing = ref(false)

watch(
  item,
  (client) => {
    syncing.value = true
    notes.value = client?.notes || ''
    dirty.value = false
    nextTick(() => {
      syncing.value = false
    })
  },
  { immediate: true }
)

watch(notes, () => {
  if (!syncing.value) {
    dirty.value = true
  }
})

async function save() {
  if (!item.value || !canManageClients.value) return
  saving.value = true
  try {
    await api.clients.update(item.value.id, {
      notes: notes.value.trim() || null
    })
    dirty.value = false
    toast.add({ title: 'Observações salvas.', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível salvar as observações.'), color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div
    v-if="item"
    class="min-w-0 space-y-4"
    data-testid="client-page-observacoes"
  >
    <ShellSectionHeader
      title="Observações"
      description="Anotações internas do escritório sobre este cliente."
      test-id="client-section-observacoes"
    >
      <UButton
        v-if="canManageClients"
        color="primary"
        icon="i-lucide-save"
        label="Salvar"
        :loading="saving"
        :disabled="!dirty"
        data-testid="client-observacoes-save"
        @click="save"
      />
    </ShellSectionHeader>

    <UPageCard variant="subtle">
      <UFormField
        label="Observações"
        name="notes"
      >
        <UTextarea
          v-model="notes"
          :disabled="!canManageClients"
          :rows="8"
          autoresize
          placeholder="Registre informações relevantes para a equipe…"
          class="w-full"
          data-testid="client-observacoes-input"
        />
      </UFormField>
    </UPageCard>
  </div>
</template>
