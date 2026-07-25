<script setup lang="ts">
import type { CcmeiRegistrationStatusHistoryPayload } from '~/types/fiscal-modules'
import { useCcmeiRegistrationStatusMonitoring } from '~/composables/useCcmeiRegistrationStatusMonitoring'
import { formatDateTime } from '~/utils/format'

const props = defineProps<{ clientId: number, canConsult: boolean }>()
const toast = useToast()
const { fetchHistory, requestConsult } = useCcmeiRegistrationStatusMonitoring()
const loading = ref(true)
const requesting = ref(false)
const error = ref<string | null>(null)
const history = ref<CcmeiRegistrationStatusHistoryPayload | null>(null)
let generation = 0

const current = computed(() => history.value?.current || null)
const observations = computed(() => history.value?.history || [])
const statusColor = (situation?: string | null): 'success' | 'warning' | 'neutral' => situation === 'UP_TO_DATE' ? 'success' : situation === 'ATTENTION' ? 'warning' : 'neutral'

async function load() {
  const requestGeneration = ++generation
  loading.value = true
  error.value = null
  try {
    const payload = await fetchHistory(props.clientId)
    if (requestGeneration === generation) history.value = payload
  } catch (caught) {
    if (requestGeneration !== generation) return
    history.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico cadastral do CCMEI.')
  } finally {
    if (requestGeneration === generation) loading.value = false
  }
}

async function consult() {
  if (!props.canConsult || requesting.value) return
  requesting.value = true
  try {
    await requestConsult(props.clientId)
    toast.add({ title: 'Consulta cadastral CCMEI enfileirada', description: 'O histórico será atualizado quando a execução terminar.', color: 'success', icon: 'i-lucide-clock-3' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível enfileirar a consulta cadastral CCMEI.'), color: 'error' })
  } finally {
    requesting.value = false
  }
}

watch(() => props.clientId, () => void load(), { immediate: true })
</script>

<template>
  <div class="space-y-4" data-testid="client-ccmei-registration-status-panel">
    <UPageCard
      title="Situação cadastral do MEI"
      description="Consulta cadastral CCMEI. O painel mostra somente o resumo autorizado, sem identificadores fiscais."
      icon="i-lucide-landmark"
      variant="subtle"
    >
      <template #default>
        <div class="space-y-4">
          <UAlert
            v-if="error"
            color="error"
            icon="i-lucide-circle-alert"
            title="Histórico indisponível"
          >
            <template #description>
              {{ error }}
            </template>
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
          <div v-else-if="loading" class="space-y-3" aria-label="Carregando situação cadastral CCMEI">
            <USkeleton class="h-8 w-48" />
            <USkeleton class="h-20 w-full" />
          </div>
          <template v-else>
            <div v-if="current" class="flex flex-wrap items-center gap-2">
              <UBadge
                :color="statusColor(current.situation)"
                icon="i-lucide-building-2"
                :label="current.status"
                variant="subtle"
              />
              <UBadge :color="current.enquadrado_mei ? 'success' : 'warning'" :label="current.enquadrado_mei ? 'Enquadrado no MEI' : 'Não enquadrado no MEI'" variant="outline" />
              <span class="text-xs text-muted">Última consulta: {{ formatDateTime(current.observed_at) }}</span>
              <UBadge
                v-if="current.source_provenance === 'SIMULATED'"
                color="warning"
                label="Histórico não verificável"
                variant="outline"
              />
            </div>
            <UEmpty
              v-else
              icon="i-lucide-database"
              title="Sem consulta cadastral registrada"
              description="Ainda não há uma evidência local para este cliente."
              :ui="{ root: 'py-4' }"
            />
            <section v-if="observations.length" aria-label="Histórico da situação cadastral CCMEI">
              <h3 class="mb-2 text-sm font-medium text-highlighted">
                Histórico de consultas
              </h3>
              <ul class="space-y-2">
                <li v-for="observation in observations" :key="`${observation.observed_at}-${observation.status}`" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default px-3 py-2">
                  <UBadge :color="statusColor(observation.situation)" :label="observation.status" variant="subtle" />
                  <span class="text-xs text-muted">{{ formatDateTime(observation.observed_at) }}</span>
                </li>
              </ul>
            </section>
          </template>
        </div>
      </template>
      <template #footer>
        <UButton
          v-if="canConsult"
          color="primary"
          icon="i-lucide-refresh-cw"
          label="Consultar situação cadastral"
          :loading="requesting"
          :disabled="loading"
          @click="consult"
        />
        <p v-else class="text-sm text-muted">
          Seu perfil pode consultar o histórico, mas não iniciar uma nova consulta.
        </p>
      </template>
    </UPageCard>
  </div>
</template>
