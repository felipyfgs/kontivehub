<script setup lang="ts">
import type {
  PgmeiClientSummary,
  PgmeiDebtItem,
  PgmeiHistoryPayload
} from '~/types/fiscal-modules'

const props = defineProps<{
  open: boolean
  clientId: number | null
  clientName?: string | null
  cnpjMasked?: string | null
  year: number
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const { fetchHistory } = usePgmeiMonitoring()
const loading = ref(false)
const error = ref<string | null>(null)
const history = ref<PgmeiHistoryPayload | null>(null)
let generation = 0

const observations = computed(() =>
  [...pgmeiHistoryObservations(history.value)].sort((left, right) => {
    const l = Date.parse(left.observed_at || left.queried_at || '') || 0
    const r = Date.parse(right.observed_at || right.queried_at || '') || 0
    return r - l
  })
)

const current = computed<PgmeiClientSummary | null>(() => {
  if (history.value?.current) return history.value.current
  const latest = observations.value[0]
  if (!latest) return null
  return {
    year: latest.year,
    debt_state: latest.debt_state || 'UNVERIFIED',
    freshness_state: latest.freshness_state || 'OUTDATED',
    debt_count: Number(latest.debt_count || latest.items?.length || 0),
    total_cents: Number(latest.total_cents || 0),
    last_valid_query_at: latest.observed_at || latest.queried_at || null
  }
})

async function load() {
  const clientId = props.clientId
  if (!clientId) return
  const requestGeneration = ++generation
  loading.value = true
  error.value = null
  try {
    const response = await fetchHistory(clientId, props.year)
    if (requestGeneration === generation) history.value = response
  } catch (caught) {
    if (requestGeneration !== generation) return
    history.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico local do PGMEI.')
  } finally {
    if (requestGeneration === generation) loading.value = false
  }
}

watch(
  () => [props.open, props.clientId, props.year] as const,
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

function itemPeriod(item: PgmeiDebtItem): string {
  return item.period_key || item.periodo_apuracao || '—'
}

function itemTribute(item: PgmeiDebtItem): string {
  return item.tribute || item.tributo || '—'
}

function itemEntity(item: PgmeiDebtItem): string {
  return item.federated_entity || item.ente_federado || '—'
}

function itemStatus(item: PgmeiDebtItem): string {
  return item.original_status || item.situacao_original || '—'
}
</script>

<template>
  <ShellScrollableModal
    :open="open"
    :title="`Dívida ativa PGMEI · ${year}`"
    description="Histórico armazenado localmente; abrir este modal não consulta a SERPRO."
    content-class="w-[calc(100vw-1rem)] sm:max-w-5xl"
    test-id="pgmei-history-modal"
    :show-default-footer="false"
    @update:open="emit('update:open', $event)"
    @cancel="emit('update:open', false)"
  >
    <template #body>
      <div class="space-y-4">
        <div>
          <p class="font-medium text-highlighted">
            {{ history?.client?.legal_name || clientName || `Cliente #${clientId || '—'}` }}
          </p>
          <p class="text-xs text-muted">
            CNPJ {{ history?.client?.cnpj_masked || cnpjMasked || '—' }}
          </p>
        </div>

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

        <ShellLoadingModalBody v-if="loading" :rows="3" />

        <template v-else-if="history">
          <div v-if="current" class="flex flex-wrap items-center gap-2">
            <UBadge
              :color="pgmeiDebtMeta(current.debt_state).color"
              :icon="pgmeiDebtMeta(current.debt_state).icon"
              :label="pgmeiDebtMeta(current.debt_state).label"
              variant="subtle"
            />
            <UBadge
              :color="pgmeiFreshnessMeta(current.freshness_state).color"
              :icon="pgmeiFreshnessMeta(current.freshness_state).icon"
              :label="pgmeiFreshnessMeta(current.freshness_state).label"
              variant="outline"
            />
            <span class="text-sm font-medium tabular-nums text-highlighted">
              {{ pgmeiTotalLabel(current) }} · {{ current.debt_count }} item(ns)
            </span>
            <span class="text-xs text-muted">
              Consulta: {{ formatDateTime(current.last_valid_query_at) }}
            </span>
          </div>

          <UAlert
            v-if="current && pgmeiFreshnessState(current.freshness_state) === 'OUTDATED'"
            color="warning"
            variant="subtle"
            icon="i-lucide-clock-alert"
            title="Consulta com mais de sete dias"
          >
            <template #description>
              O aviso de frescor não esconde nem descarta dívidas já encontradas.
            </template>
          </UAlert>

          <section>
            <h3 class="mb-2 text-sm font-medium text-highlighted">
              Histórico anual
            </h3>
            <div v-if="observations.length" class="space-y-3">
              <UCard
                v-for="observation in observations"
                :key="observation.id || `${observation.year}-${observation.observed_at || observation.queried_at}`"
              >
                <template #header>
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="font-medium text-highlighted">Ano {{ observation.year }}</span>
                      <UBadge
                        :color="pgmeiDebtMeta(observation.debt_state).color"
                        :label="pgmeiDebtMeta(observation.debt_state).label"
                        variant="subtle"
                      />
                    </div>
                    <span class="text-xs text-muted">
                      {{ formatDateTime(observation.observed_at || observation.queried_at) }}
                    </span>
                  </div>
                </template>

                <div v-if="observation.items?.length" class="overflow-x-auto">
                  <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="text-xs text-muted">
                      <tr>
                        <th class="pb-2 pr-3 font-medium">
                          PA
                        </th>
                        <th class="pb-2 pr-3 font-medium">
                          Tributo
                        </th>
                        <th class="pb-2 pr-3 font-medium">
                          Valor
                        </th>
                        <th class="pb-2 pr-3 font-medium">
                          Ente federado
                        </th>
                        <th class="pb-2 font-medium">
                          Situação original
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr
                        v-for="(item, index) in observation.items"
                        :key="item.id || `${itemPeriod(item)}-${itemTribute(item)}-${index}`"
                        class="border-t border-default"
                      >
                        <td class="py-2 pr-3 tabular-nums">
                          {{ itemPeriod(item) }}
                        </td>
                        <td class="py-2 pr-3">
                          {{ itemTribute(item) }}
                        </td>
                        <td class="py-2 pr-3 tabular-nums">
                          {{ formatAmountCents(item.amount_cents) }}
                        </td>
                        <td class="py-2 pr-3">
                          {{ itemEntity(item) }}
                        </td>
                        <td class="py-2">
                          {{ itemStatus(item) }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p v-else class="text-sm text-muted">
                  Nenhum item de dívida armazenado nesta observação.
                </p>
              </UCard>
            </div>
            <div v-else class="rounded-md border border-dashed border-default py-8 text-center">
              <UIcon name="i-lucide-database" class="mx-auto mb-2 size-8 text-dimmed" />
              <p class="font-medium text-highlighted">
                Sem histórico local para {{ year }}
              </p>
              <p class="text-sm text-muted">
                Nenhuma consulta SERPRO foi disparada ao abrir este modal.
              </p>
            </div>
          </section>

          <section>
            <h3 class="mb-2 text-sm font-medium text-highlighted">
              DAS existentes na Central de Guias
            </h3>
            <ul v-if="history.guides?.length" class="space-y-2">
              <li
                v-for="guide in history.guides"
                :key="guide.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default p-3 text-sm"
              >
                <div>
                  <p class="font-medium text-highlighted">
                    {{ guide.label || `DAS · ${guide.period_key || 'sem PA'}` }}
                  </p>
                  <p class="text-xs text-muted">
                    {{ guide.status || 'Situação não informada' }} · {{ formatAmountCents(guide.amount_cents) }}
                  </p>
                </div>
                <UButton
                  v-if="guide.download_href || guide.href"
                  size="xs"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  label="Baixar existente"
                  :to="guide.download_href || guide.href || undefined"
                  external
                  target="_blank"
                  rel="noopener noreferrer"
                />
              </li>
            </ul>
            <p v-else class="text-sm text-muted">
              Nenhum DAS já armazenado para este cliente e ano.
            </p>
          </section>
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
