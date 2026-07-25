<script setup lang="ts">
import type { SicalcRevenueSupportHistoryPayload, SicalcRevenueSupportSummary } from '~/types/fiscal-modules'
import { useSicalcRevenueSupportMonitoring } from '~/composables/useSicalcRevenueSupportMonitoring'
import { formatDateTime } from '~/utils/format'

const props = defineProps<{ clientId: number, canConsult: boolean }>()
const toast = useToast()
const { fetchHistory, requestConsult } = useSicalcRevenueSupportMonitoring()
const revenueCode = ref('')
const loading = ref(true)
const requesting = ref(false)
const error = ref<string | null>(null)
const history = ref<SicalcRevenueSupportHistoryPayload | null>(null)
let generation = 0

const current = computed(() => history.value?.current || [])
const canRequest = computed(() => props.canConsult && /^[0-9]{1,16}$/.test(revenueCode.value) && !requesting.value)

function labels(record: Record<string, boolean>) {
  return Object.entries(record).filter(([, value]) => value).map(([key]) => key)
}

function info(item: SicalcRevenueSupportSummary) {
  return item.extensions.flatMap(extension => Object.entries(extension.informacoes)
    .filter(([, value]) => value !== false && value !== '')
    .map(([key, value]) => `${key}: ${String(value)}`))
}

async function load() {
  const requestGeneration = ++generation
  loading.value = true
  error.value = null
  try {
    const payload = await fetchHistory(props.clientId, revenueCode.value || undefined)
    if (requestGeneration === generation) history.value = payload
  } catch (caught) {
    if (requestGeneration !== generation) return
    history.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico de apoio SICALC.')
  } finally {
    if (requestGeneration === generation) loading.value = false
  }
}

async function consult() {
  if (!canRequest.value) return
  requesting.value = true
  try {
    await requestConsult(props.clientId, revenueCode.value)
    toast.add({ title: 'Consulta de apoio SICALC enfileirada', description: 'O metadado da receita será atualizado quando a execução terminar.', color: 'success', icon: 'i-lucide-clock-3' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível enfileirar a consulta de apoio SICALC.'), color: 'error' })
  } finally {
    requesting.value = false
  }
}

watch(() => props.clientId, () => void load(), { immediate: true })
</script>

<template>
  <div class="space-y-4" data-testid="client-sicalc-revenue-support-panel">
    <UPageCard
      title="Apoio de receitas SICALC"
      description="Consulta os campos de preenchimento do DARF para uma receita. O painel não exibe documentos ou identificadores fiscais."
      icon="i-lucide-receipt-text"
      variant="subtle"
    >
      <template #default>
        <div class="space-y-4">
          <UFormField label="Código da receita" name="codigo_receita" help="Informe de 1 a 16 algarismos para filtrar o histórico ou iniciar uma consulta.">
            <UInput
              v-model="revenueCode"
              inputmode="numeric"
              maxlength="16"
              placeholder="Ex.: 1082"
              @keyup.enter="consult"
            />
          </UFormField>
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
          <div v-else-if="loading" class="space-y-3" aria-label="Carregando apoio de receitas SICALC">
            <USkeleton class="h-8 w-48" />
            <USkeleton class="h-20 w-full" />
          </div>
          <UEmpty
            v-else-if="!current.length"
            icon="i-lucide-database"
            title="Sem apoio de receita registrado"
            description="Consulte um código para salvar o metadado sanitizado da receita."
            :ui="{ root: 'py-4' }"
          />
          <ul v-else class="space-y-3" aria-label="Receitas SICALC consultadas">
            <li v-for="item in current" :key="item.revenue_code" class="space-y-3 rounded-md border border-default p-3">
              <div class="flex flex-wrap items-center gap-2">
                <UBadge color="primary" :label="item.revenue_code" variant="subtle" />
                <span class="text-sm font-medium text-highlighted">{{ item.description }}</span>
                <span class="text-xs text-muted">Consulta: {{ formatDateTime(item.observed_at) }}</span>
                <UBadge
                  v-if="item.source_provenance === 'SIMULATED'"
                  color="warning"
                  label="Histórico não verificável"
                  variant="outline"
                />
              </div>
              <div v-for="(extension, index) in item.extensions" :key="index" class="space-y-2 text-xs text-muted">
                <p v-if="labels(extension.obrigatorios).length">
                  <span class="font-medium text-highlighted">Obrigatórios:</span> {{ labels(extension.obrigatorios).join(', ') }}
                </p>
                <p v-if="labels(extension.opcionais).length">
                  <span class="font-medium text-highlighted">Opcionais:</span> {{ labels(extension.opcionais).join(', ') }}
                </p>
              </div>
              <p v-if="info(item).length" class="text-xs text-muted">
                <span class="font-medium text-highlighted">Informações:</span> {{ info(item).join(' · ') }}
              </p>
            </li>
          </ul>
        </div>
      </template>
      <template #footer>
        <div class="flex flex-wrap items-center gap-3">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-search"
            label="Atualizar histórico"
            :loading="loading"
            @click="load"
          />
          <UButton
            v-if="canConsult"
            color="primary"
            icon="i-lucide-refresh-cw"
            label="Consultar receita"
            :loading="requesting"
            :disabled="!canRequest"
            @click="consult"
          />
          <p v-else class="text-sm text-muted">
            Seu perfil pode consultar o histórico, mas não iniciar uma nova consulta.
          </p>
        </div>
      </template>
    </UPageCard>
  </div>
</template>
