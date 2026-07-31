<script setup lang="ts">
import type { CcmeiHistoryPayload } from '~/types/fiscal-modules'
import { useCcmeiMonitoring } from '~/composables/useCcmeiMonitoring'
import { formatDateTime } from '~/utils/format'

const props = defineProps<{
  clientId: number
  canConsult: boolean
}>()

const toast = useToast()
const { fetchHistory, requestConsult } = useCcmeiMonitoring()
const loading = ref(true)
const requesting = ref(false)
const error = ref<string | null>(null)
const history = ref<CcmeiHistoryPayload | null>(null)
let generation = 0

const current = computed(() => history.value?.current || null)
const observations = computed(() => history.value?.history || [])

function statusLabel(status?: string | null): string {
  return status?.trim() || 'Situação não informada'
}

function statusColor(situation?: string | null): 'success' | 'warning' | 'neutral' {
  if (situation === 'UP_TO_DATE') return 'success'
  if (situation === 'ATTENTION') return 'warning'
  return 'neutral'
}

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
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico local do CCMEI.')
  } finally {
    if (requestGeneration === generation) loading.value = false
  }
}

async function consult() {
  if (!props.canConsult || requesting.value) return

  requesting.value = true
  try {
    await requestConsult(props.clientId)
    toast.add({
      title: 'Consulta CCMEI enfileirada',
      description: 'O histórico será atualizado quando a execução terminar.',
      color: 'success',
      icon: 'i-lucide-clock-3'
    })
    await load()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível enfileirar a consulta CCMEI.'),
      color: 'error'
    })
  } finally {
    requesting.value = false
  }
}

watch(() => props.clientId, () => void load(), { immediate: true })
</script>

<template>
  <div class="space-y-4" data-testid="client-ccmei-panel">
    <UPageCard
      title="Consulta CCMEI"
      description="Consulta dos dados atualizados do MEI. Não emite certificado nem atesta validade jurídica."
      icon="i-lucide-badge-check"
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

          <div v-else-if="loading" class="space-y-3" aria-label="Carregando histórico CCMEI">
            <USkeleton class="h-8 w-48" />
            <USkeleton class="h-20 w-full" />
          </div>

          <template v-else>
            <div v-if="current" class="flex flex-wrap items-center gap-2">
              <UBadge
                :color="statusColor(current.situation)"
                icon="i-lucide-shield-check"
                :label="statusLabel(current.status)"
                variant="subtle"
              />
              <span class="text-xs text-muted">
                Última consulta: {{ formatDateTime(current.last_valid_query_at) }}
              </span>
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
              title="Sem consulta CCMEI registrada"
              description="Ainda não há uma evidência local para este cliente."
              :ui="{ root: 'py-4' }"
            />

            <section v-if="observations.length" aria-label="Histórico CCMEI">
              <h3 class="mb-2 text-sm font-medium text-highlighted">
                Histórico de consultas
              </h3>
              <ul class="space-y-2">
                <li
                  v-for="observation in observations"
                  :key="observation.id"
                  class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default px-3 py-2"
                >
                  <UBadge
                    :color="statusColor(observation.situation)"
                    :label="statusLabel(observation.status)"
                    variant="subtle"
                  />
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
          label="Consultar CCMEI"
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
