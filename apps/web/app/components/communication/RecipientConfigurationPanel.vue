<script setup lang="ts">
import type {
  CommunicationClientReference,
  CommunicationRecipientConfiguration,
  CommunicationRecipientMode
} from '~/types/communication'

const props = defineProps<{
  clients: CommunicationClientReference[]
  scopes: string[]
}>()

const workspace = useCommunicationWorkspace()
const clientId = ref<number>(props.clients[0]?.id ?? 0)
const scope = ref(props.scopes[0] ?? 'simples_mei:pgdasd')
const configuration = ref<CommunicationRecipientConfiguration | null>(null)
const recipientMode = ref<CommunicationRecipientMode>('PRIMARY')
const selectedIds = ref<number[]>([])
const loading = ref(false)
const saving = ref(false)

const clientItems = computed(() => props.clients.map(client => ({ label: client.name, value: client.id })))
const scopeLabels: Record<string, string> = {
  'simples_mei:pgdasd': 'Simples Nacional · PGDAS-D',
  'simples_mei:pgmei': 'MEI · PGMEI',
  'dctfweb:dctfweb': 'DCTFWeb',
  'fgts:fgts': 'FGTS Digital'
}
const scopeItems = computed(() => props.scopes.map(value => ({
  label: scopeLabels[value] || value,
  value
})))
const recipientItems = [
  { label: 'Contato principal', value: 'PRIMARY' },
  { label: 'Todos os elegíveis', value: 'ALL_ELIGIBLE' },
  { label: 'Selecionados', value: 'SELECTED' }
]

watch(() => props.clients, (clients) => {
  if (!clients.some(client => client.id === clientId.value)) clientId.value = clients[0]?.id ?? 0
}, { deep: true })

function scopeParts(): [string, string] {
  const [moduleKey = '', submoduleKey = ''] = scope.value.split(':')
  return [moduleKey, submoduleKey]
}

async function load() {
  if (!clientId.value) return
  loading.value = true
  const [moduleKey, submoduleKey] = scopeParts()
  configuration.value = await workspace.loadRecipients(clientId.value, moduleKey, submoduleKey)
  if (configuration.value) {
    recipientMode.value = configuration.value.recipient_mode
    selectedIds.value = [...configuration.value.selected_identity_ids]
  }
  loading.value = false
}

function toggleIdentity(id: number, selected: boolean | 'indeterminate') {
  const next = new Set(selectedIds.value)
  if (selected === true) next.add(id)
  else next.delete(id)
  selectedIds.value = [...next]
}

async function save() {
  if (!configuration.value) return
  saving.value = true
  const [moduleKey, submoduleKey] = scopeParts()
  const updated = await workspace.saveRecipients(
    configuration.value,
    moduleKey,
    submoduleKey,
    recipientMode.value,
    selectedIds.value
  )
  if (updated) configuration.value = updated
  saving.value = false
}
</script>

<template>
  <div
    data-testid="communication-recipient-configuration"
    class="space-y-4"
  >
    <UAlert
      title="A seleção é versionada por cliente e módulo"
      description="Somente identidades WhatsApp ativas e vinculadas aparecem. Ausência de destinatário impede o envio automático."
      icon="i-lucide-contact-round"
      color="info"
      variant="subtle"
    />

    <div
      v-if="clients.length"
      class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]"
    >
      <UFormField label="Cliente vinculado">
        <USelectMenu
          v-model="clientId"
          :items="clientItems"
          value-key="value"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Módulo fiscal">
        <USelectMenu
          v-model="scope"
          :items="scopeItems"
          value-key="value"
          class="w-full"
        />
      </UFormField>
      <div class="flex items-end">
        <UButton
          label="Carregar"
          icon="i-lucide-refresh-cw"
          color="neutral"
          variant="outline"
          :loading="loading"
          @click="load"
        />
      </div>
    </div>

    <UAlert
      v-else
      title="Selecione uma conversa vinculada a um cliente"
      description="O contexto da conversa determina quais preferências podem ser administradas."
      color="warning"
      variant="subtle"
      icon="i-lucide-link-2-off"
    />

    <UCard
      v-if="configuration"
      variant="subtle"
    >
      <div class="space-y-4">
        <UFormField label="Modo de resolução">
          <USelectMenu
            v-model="recipientMode"
            :items="recipientItems"
            value-key="value"
            class="w-full"
          />
        </UFormField>

        <div>
          <p class="mb-2 text-sm font-medium text-highlighted">
            Identidades elegíveis
          </p>
          <div class="space-y-2 rounded-md border border-default p-3">
            <div
              v-for="identity in configuration.identities"
              :key="identity.id"
              class="flex items-center justify-between gap-3"
            >
              <UCheckbox
                :model-value="selectedIds.includes(identity.id)"
                :label="identity.masked"
                :disabled="recipientMode !== 'SELECTED'"
                @update:model-value="toggleIdentity(identity.id, $event)"
              />
              <div class="flex gap-1">
                <UBadge
                  v-if="identity.is_primary"
                  label="Principal"
                  color="primary"
                  variant="soft"
                  size="sm"
                />
                <UBadge
                  v-if="!identity.receives_automatic"
                  label="Automático bloqueado"
                  color="warning"
                  variant="soft"
                  size="sm"
                />
              </div>
            </div>
            <p
              v-if="!configuration.identities.length"
              class="text-sm text-muted"
            >
              Nenhuma identidade elegível. Vincule um WhatsApp ao cliente primeiro.
            </p>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex justify-end">
          <UButton
            label="Salvar destinatários"
            icon="i-lucide-save"
            :loading="saving"
            :disabled="recipientMode === 'SELECTED' && !selectedIds.length"
            @click="save"
          />
        </div>
      </template>
    </UCard>
  </div>
</template>
