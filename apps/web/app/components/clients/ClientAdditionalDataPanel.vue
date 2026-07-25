<script setup lang="ts">
/**
 * Campos personalizados + estado/canais (captura).
 * Extraído do antigo ClientConfigPanel (certificado vive na sidebar).
 */
import type { AccordionItem } from '@nuxt/ui'
import type { Client, ClientCustomField } from '~/types/api'

const props = defineProps<{
  client: Client
  canManageClients: boolean
}>()

const emit = defineEmits<{
  updated: []
}>()

const api = useApi()
const toast = useToast()

const openItems = ref<string | string[]>(['estado', 'campos'])
const savingCapture = ref(false)
const savingFieldId = ref<number | null>(null)

const primaryEstablishment = computed(() =>
  props.client.establishments?.find(e => e.is_matrix)
  || props.client.establishments?.[0]
  || null
)

const captureEnabled = computed(() => Boolean(primaryEstablishment.value?.capture_enabled))
const customFields = computed(() => props.client.custom_fields || [])

const accordionItems = computed((): AccordionItem[] => [
  {
    label: 'Estado e canais',
    icon: 'i-lucide-activity',
    value: 'estado',
    slot: 'estado' as const
  },
  {
    label: 'Campos personalizados',
    icon: 'i-lucide-list',
    value: 'campos',
    slot: 'campos' as const
  }
])

async function toggleCapture(enabled: boolean) {
  const est = primaryEstablishment.value
  if (!est || !props.canManageClients || savingCapture.value) return
  savingCapture.value = true
  try {
    await api.establishments.update(est.id, {
      capture_enabled: enabled,
      ...(enabled ? { capture_enable_reason: 'Habilitado nos dados adicionais do cliente.' } : {})
    })
    toast.add({
      title: enabled ? 'Captura de documentos habilitada.' : 'Captura de documentos desabilitada.',
      color: 'success'
    })
    emit('updated')
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível alterar a captura.'), color: 'error' })
  } finally {
    savingCapture.value = false
  }
}

async function toggleFieldActive(field: ClientCustomField, active: boolean) {
  if (!props.canManageClients) return
  savingFieldId.value = field.id
  try {
    await api.clients.updateCustomField(props.client.id, field.id, { is_active: active })
    toast.add({ title: active ? 'Campo ativado.' : 'Campo desativado.', color: 'success' })
    emit('updated')
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível atualizar o campo.'), color: 'error' })
  } finally {
    savingFieldId.value = null
  }
}
</script>

<template>
  <ShellPanelAccordion
    v-model="openItems"
    :items="accordionItems"
    type="multiple"
    :default-value="['estado', 'campos']"
    test-id="client-additional-data-accordion"
  >
    <template #estado-body>
      <div class="space-y-3 rounded-lg border border-default p-4">
        <UFormField
          label="Captura de documentos (ADN)"
          description="Habilita sincronização de documentos de entrada para o estabelecimento principal."
          class="flex items-center justify-between gap-4"
        >
          <USwitch
            :model-value="captureEnabled"
            :disabled="!canManageClients || !primaryEstablishment || savingCapture"
            @update:model-value="toggleCapture"
          />
        </UFormField>
        <p class="text-sm text-muted">
          Certificado A1 e procuração e-CAC ficam no painel lateral.
        </p>
      </div>
    </template>

    <template #campos-body>
      <div
        class="space-y-3"
        data-testid="client-custom-fields"
      >
        <div
          v-if="customFields.length"
          class="space-y-3"
        >
          <div
            v-for="field in customFields"
            :key="field.id"
            class="flex flex-col gap-3 rounded-lg bg-elevated/50 px-3 py-3 ring ring-inset ring-default sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <p class="font-medium text-highlighted">
                  {{ field.label }}
                </p>
                <UBadge
                  :color="field.type === 'SECRET' ? 'warning' : 'neutral'"
                  variant="subtle"
                  size="sm"
                >
                  {{ field.type === 'SECRET' ? 'Segredo' : 'Texto' }}
                </UBadge>
              </div>
              <p class="mt-1 text-sm text-muted">
                <template v-if="field.type === 'SECRET'">
                  {{ field.has_value ? 'Configurado' : 'Não configurado' }}
                </template>
                <template v-else>
                  {{ field.value || (field.has_value ? '—' : 'Vazio') }}
                </template>
              </p>
            </div>
            <UFormField
              label="Ativo"
              class="shrink-0"
            >
              <USwitch
                :model-value="field.is_active !== false"
                :disabled="!canManageClients || savingFieldId === field.id"
                @update:model-value="toggleFieldActive(field, $event)"
              />
            </UFormField>
          </div>
        </div>
        <UEmpty
          v-else
          icon="i-lucide-list"
          title="Nenhum campo personalizado"
          description="Campos extras (ex.: acesso a portal) são definidos na criação do cliente."
        />
      </div>
    </template>
  </ShellPanelAccordion>
</template>
