<script setup lang="ts">
/**
 * Card de sincronização central do escritório (NFE_AUTXML_DISTDFE).
 * Sem edição/reset de NSU — somente leitura de estado.
 */
const api = useApi()
const loading = ref(false)
const error = ref<string | null>(null)
const cursor = ref<Record<string, unknown> | null>(null)
const stream = ref<Record<string, unknown> | null>(null)
const recentRuns = ref<Array<Record<string, unknown>>>([])

async function load() {
  loading.value = true
  try {
    const res = await api.officeAutXml.cursor()
    cursor.value = res.data.cursor
    stream.value = res.data.stream as Record<string, unknown>
    recentRuns.value = res.data.recent_runs || []
    error.value = null
  } catch (caught) {
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o cursor autXML.')
  } finally {
    loading.value = false
  }
}

const statusColor = computed(() => {
  const s = String(cursor.value?.status || '')
  if (s === 'BLOCKED' || cursor.value?.circuit_open) return 'error' as const
  if (s === 'ERROR') return 'warning' as const
  if (s === 'RUNNING') return 'info' as const
  if (s === 'IDLE' || s === 'WAITING') return 'success' as const
  return 'neutral' as const
})

const cStatHint = computed(() => {
  const c = String(cursor.value?.last_cstat || '')
  if (c === '137') {
    return 'cStat 137: sem documentos novos — espera mínima de 1h (não é falha nem backfill concluído).'
  }
  if (c === '138') {
    return 'cStat 138: documentos distribuídos nesta consulta.'
  }
  if (c === '656' || cursor.value?.circuit_open) {
    return 'cStat 656 / circuito aberto: próxima tentativa só após o horário mínimo. Não há retry antecipado.'
  }
  if (c === '593') {
    return 'cStat 593: raiz do certificado divergente do CNPJ consultado.'
  }
  if (c === '618') {
    return 'cStat 618: modelo diferente de 55 rejeitado no DistDFe.'
  }
  return cursor.value?.last_xmotivo ? String(cursor.value.last_xmotivo) : null
})

onMounted(() => {
  void load()
})
</script>

<template>
  <UPageCard
    data-testid="autxml-sync-card"
    title="Sincronização central autXML (escritório)"
  >
    <div
      v-if="loading"
      class="space-y-2"
      role="status"
      aria-live="polite"
    >
      <USkeleton class="h-4 w-1/2" />
      <USkeleton class="h-4 w-2/3" />
    </div>

    <UAlert
      v-else-if="error"
      color="warning"
      icon="i-lucide-wifi-off"
      :title="error"
      :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
    />

    <div v-else-if="!cursor" class="space-y-3 text-sm text-muted">
      <p>
        Nenhum cursor central ainda. Cadastre o perfil institucional e o A1 na área do escritório.
      </p>
      <UButton
        to="/conta/escritorio"
        color="neutral"
        variant="subtle"
        icon="i-lucide-sliders-horizontal"
        label="Abrir Escritório"
        size="sm"
      />
    </div>

    <div v-else class="space-y-4">
      <div class="flex flex-wrap items-center gap-2">
        <UBadge :color="statusColor" variant="subtle">
          {{ cursor.status }}
        </UBadge>
        <UBadge
          v-if="cursor.circuit_open"
          color="error"
          variant="subtle"
          icon="i-lucide-ban"
        >
          Circuito aberto
        </UBadge>
        <UBadge color="neutral" variant="outline">
          {{ cursor.environment }}
        </UBadge>
        <UBadge color="primary" variant="subtle">
          NF-e 55 · autXML
        </UBadge>
      </div>

      <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div>
          <dt class="text-muted">
            CNPJ consulta
          </dt>
          <dd class="font-mono text-highlighted">
            {{ cursor.query_cnpj }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            last NSU
          </dt>
          <dd class="font-mono text-highlighted">
            {{ cursor.last_nsu }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            max NSU
          </dt>
          <dd class="font-mono text-highlighted">
            {{ cursor.max_nsu_seen ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            cStat
          </dt>
          <dd class="font-mono text-highlighted">
            {{ cursor.last_cstat || '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Heartbeat
          </dt>
          <dd class="text-highlighted">
            {{ cursor.last_heartbeat_at ? formatDateTime(String(cursor.last_heartbeat_at)) : '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Último sucesso
          </dt>
          <dd class="text-highlighted">
            {{ cursor.last_success_at ? formatDateTime(String(cursor.last_success_at)) : '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Próxima tentativa
          </dt>
          <dd class="text-highlighted">
            {{ cursor.next_sync_at ? formatDateTime(String(cursor.next_sync_at)) : '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Ativado em
          </dt>
          <dd class="text-highlighted">
            {{ cursor.activated_at ? formatDateTime(String(cursor.activated_at)) : '—' }}
          </dd>
        </div>
      </dl>

      <UAlert
        v-if="cStatHint"
        :color="cursor.circuit_open ? 'error' : 'neutral'"
        icon="i-lucide-info"
        :title="cStatHint"
      />

      <p class="text-xs text-muted">
        Stream apto a confirmar enrollments:
        <strong>{{ stream?.stream_ready ? 'sim' : 'não' }}</strong>
        <span v-if="stream?.stream_reason"> ({{ stream.stream_reason }})</span>.
        Não há ação de reset de NSU nesta interface.
      </p>

      <div v-if="recentRuns.length" class="space-y-2">
        <p class="text-sm font-medium text-highlighted">
          Execuções recentes do stream
        </p>
        <ul class="space-y-1 text-xs text-muted" aria-label="Execuções recentes autXML">
          <li
            v-for="run in recentRuns.slice(0, 5)"
            :key="String(run.id)"
            class="flex flex-wrap gap-2 border-b border-default/50 py-1"
          >
            <span class="font-mono">#{{ run.id }}</span>
            <span>{{ run.status }}</span>
            <span>NSU {{ run.from_nsu }}→{{ run.to_nsu }}</span>
            <span>{{ run.pages_processed ?? 0 }} pág.</span>
            <span>{{ run.documents_persisted ?? 0 }} docs</span>
            <span v-if="run.last_cstat">cStat {{ run.last_cstat }}</span>
          </li>
        </ul>
      </div>

      <div class="flex justify-end">
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          icon="i-lucide-refresh-cw"
          label="Atualizar"
          :loading="loading"
          aria-label="Atualizar estado do cursor autXML"
          @click="load"
        />
      </div>
    </div>
  </UPageCard>
</template>
