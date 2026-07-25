<script setup lang="ts">
import type { RegimeCalendarPayload } from '~/types/fiscal-modules'

const props = defineProps<{
  open: boolean
  clientId: number | null
  clientName?: string | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const { fetchHistory } = useRegimeCalendarMonitoring()
const loading = ref(false)
const error = ref<string | null>(null)
const history = ref<RegimeCalendarPayload | null>(null)
let generation = 0

function regimeLabel(value: string): string {
  return value === 'CAIXA' ? 'Caixa' : 'Competência'
}

async function load() {
  if (!props.clientId) return
  const requestGeneration = ++generation
  loading.value = true
  error.value = null
  try {
    const response = await fetchHistory(props.clientId)
    if (requestGeneration === generation) history.value = response
  } catch (caught) {
    if (requestGeneration !== generation) return
    history.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico local de regimes.')
  } finally {
    if (requestGeneration === generation) loading.value = false
  }
}

watch(
  () => [props.open, props.clientId] as const,
  ([open]) => {
    if (open) {
      void load()
      return
    }
    generation += 1
    loading.value = false
    error.value = null
    history.value = null
  },
  { immediate: true }
)
</script>

<template>
  <ShellScrollableModal
    :open="open"
    title="Histórico de regimes"
    description="Dados já armazenados localmente; abrir este modal não consulta a SERPRO."
    content-class="w-[calc(100vw-1rem)] sm:max-w-xl"
    test-id="regime-calendar-modal"
    :show-default-footer="false"
    @update:open="emit('update:open', $event)"
    @cancel="emit('update:open', false)"
  >
    <template #body>
      <div class="space-y-4">
        <p class="font-medium text-highlighted">
          {{ clientName || `Cliente #${clientId || '—'}` }}
        </p>

        <UAlert v-if="error" color="error" :title="error">
          <template #actions>
            <UButton
              size="xs"
              color="neutral"
              variant="outline"
              label="Tentar novamente"
              @click="load"
            />
          </template>
        </UAlert>

        <ShellLoadingModalBody v-else-if="loading" :rows="2" />

        <template v-else>
          <div v-if="history?.data.length" class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="text-xs text-muted">
                <tr>
                  <th class="pb-2 pr-3 font-medium">
                    Ano-calendário
                  </th><th class="pb-2 font-medium">
                    Regime
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in history.data" :key="item.calendar_year" class="border-t border-default">
                  <td class="py-2 pr-3 tabular-nums">
                    {{ item.calendar_year }}
                  </td>
                  <td class="py-2">
                    <UBadge color="primary" variant="subtle" :label="regimeLabel(item.regime_apuracao)" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-sm text-muted">
            Nenhum ano-calendário armazenado para este cliente.
          </p>
          <p class="text-xs text-muted">
            Origem: {{ history?.provenance?.source || 'projeção local' }}.
          </p>
        </template>
      </div>
    </template>
    <template #footer>
      <ShellModalFooter
        cancel-label="Fechar"
        :show-submit="false"
        @cancel="emit('update:open', false)"
      />
    </template>
  </ShellScrollableModal>
</template>
