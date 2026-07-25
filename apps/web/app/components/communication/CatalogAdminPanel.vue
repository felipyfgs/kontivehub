<script setup lang="ts">
import { apiErrorMessage } from '~/utils/api-error'
import { COMMUNICATION_QUICK_RESPONSES_PATH } from '~/utils/communication-routes'
import { canManageCommunicationQuickReplies } from '~/utils/permissions'

const workspace = useCommunicationWorkspace()
const api = useApi()
const toast = useToast()
const { me } = useDashboard()
const labelName = ref('')
const labelColor = ref('neutral')
const saving = ref(false)

const canManageQuickReplies = computed(() => canManageCommunicationQuickReplies(me.value))

const colorItems = [
  'neutral', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald',
  'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'
].map(value => ({ label: value, value }))

async function createLabel() {
  if (!labelName.value.trim()) return
  saving.value = true
  try {
    await api.communication.catalog.createLabel({
      name: labelName.value.trim(),
      color: labelColor.value
    })
    labelName.value = ''
    await workspace.loadCatalog()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao criar marcador.'), color: 'error' })
  } finally {
    saving.value = false
  }
}

async function deleteLabel(id: number) {
  try {
    await api.communication.catalog.deleteLabel(id)
    await workspace.loadCatalog()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao excluir marcador.'), color: 'error' })
  }
}
</script>

<template>
  <div
    data-testid="communication-catalog-admin"
    class="grid gap-6 xl:grid-cols-2"
  >
    <UCard variant="subtle">
      <template #header>
        <div>
          <p class="font-semibold text-highlighted">
            Marcadores
          </p>
          <p class="text-xs text-muted">
            Classificação compartilhada das conversas.
          </p>
        </div>
      </template>

      <div class="space-y-3">
        <div class="grid gap-2 sm:grid-cols-[1fr_9rem_auto]">
          <UInput
            v-model="labelName"
            placeholder="Nome do marcador"
          />
          <USelectMenu
            v-model="labelColor"
            :items="colorItems"
            value-key="value"
          />
          <UButton
            icon="i-lucide-plus"
            aria-label="Criar marcador"
            :loading="saving"
            @click="createLabel"
          />
        </div>
        <div class="space-y-2">
          <div
            v-for="label in workspace.labels.value"
            :key="label.id"
            class="flex items-center justify-between rounded-md border border-default px-3 py-2"
          >
            <UBadge
              :label="label.name"
              color="neutral"
              variant="soft"
            />
            <UButton
              icon="i-lucide-trash-2"
              color="error"
              variant="ghost"
              size="xs"
              aria-label="Excluir marcador"
              @click="deleteLabel(label.id)"
            />
          </div>
        </div>
      </div>
    </UCard>

    <UCard variant="subtle">
      <template #header>
        <div>
          <p class="font-semibold text-highlighted">
            Respostas rápidas
          </p>
          <p class="text-xs text-muted">
            Gestão completa (criar, editar, duplicar e desativar) na página dedicada.
          </p>
        </div>
      </template>

      <div class="space-y-4">
        <p class="text-sm text-muted">
          Use atalhos <span class="font-mono">/saudacao</span> no composer ou o seletor touch.
          Variáveis allowlist são resolvidas no backend.
        </p>
        <div class="flex flex-wrap gap-2">
          <UButton
            :to="COMMUNICATION_QUICK_RESPONSES_PATH"
            icon="i-lucide-zap"
            :label="canManageQuickReplies ? 'Gerenciar respostas rápidas' : 'Ver respostas rápidas'"
            data-testid="communication-catalog-quick-responses-cta"
          />
        </div>
        <p
          v-if="workspace.cannedResponses.value.length"
          class="text-xs text-muted"
        >
          {{ workspace.cannedResponses.value.length }} resposta(s) ativa(s) disponíveis no composer.
        </p>
      </div>
    </UCard>
  </div>
</template>
