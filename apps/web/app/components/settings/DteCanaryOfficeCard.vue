<script setup lang="ts">
/**
 * Confirmação Office ADMIN e resultado fiscal DTE no tenant.
 * Arquétipo Settings — card embutido em /settings.
 */
const api = useApi()
const toast = useToast()
const { sessionEpoch, me } = useDashboard()

const loading = ref(false)
const acting = ref(false)
const pending = ref<Record<string, unknown> | null>(null)
const result = ref<Record<string, unknown> | null>(null)
const error = ref<string | null>(null)

const isOfficeAdmin = computed(() => {
  const role = String(
    (me.value as { real_office_role?: string, role?: string } | null)?.real_office_role
    ?? me.value?.role
    ?? ''
  ).toUpperCase()
  return role === 'ADMIN'
})

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  error.value = null
  try {
    const res = await api.office.dteCanary.pending()
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    pending.value = res.data
    result.value = null
    if (res.data?.id && res.data.result_status) {
      try {
        const r = await api.office.dteCanary.result(Number(res.data.id))
        if (seq !== loadSeq || epoch !== sessionEpoch.value) return
        result.value = r.data
      } catch {
        // membership/cross-tenant: sem resultado
      }
    }
  } catch (e) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    error.value = apiErrorMessage(e, 'Falha ao carregar canário DTE do Office.')
    pending.value = null
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) loading.value = false
  }
}

async function confirm() {
  const id = Number(pending.value?.id)
  if (!id) return
  acting.value = true
  try {
    await api.office.dteCanary.confirm(id)
    toast.add({ title: 'Participação no canário DTE confirmada', color: 'success' })
    await load()
  } catch (e) {
    toast.add({
      title: 'Confirmação falhou',
      description: apiErrorMessage(e, 'Requer Office ADMIN, senha recente e Office piloto.'),
      color: 'error'
    })
  } finally {
    acting.value = false
  }
}

watch(sessionEpoch, () => {
  pending.value = null
  result.value = null
  void load()
})
onMounted(load)
</script>

<template>
  <UPageCard
    title="Canário DTE (Office)"
    description="Confirmação do Office ADMIN e resultado fiscal no tenant. Sem office_id do client."
    variant="subtle"
    data-testid="settings-dte-canary-card"
  >
    <UAlert
      v-if="error"
      color="error"
      icon="i-lucide-circle-x"
      :title="error"
      class="mb-3"
    />

    <div
      v-if="loading"
      class="text-sm text-muted"
    >
      Carregando…
    </div>

    <div
      v-else-if="!pending"
      class="text-sm text-muted"
      data-testid="settings-dte-canary-empty"
    >
      Nenhum canário DTE pendente para este Office.
    </div>

    <div
      v-else
      class="flex flex-col gap-3"
    >
      <dl class="grid gap-2 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-muted">
            Status
          </dt>
          <dd
            class="font-medium"
            data-testid="settings-dte-canary-status"
          >
            {{ pending.status }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Aprovações
          </dt>
          <dd class="font-medium">
            Proprietário: {{ pending.owner_approved ? 'sim' : 'não' }}
            · Office: {{ pending.office_admin_approved ? 'sim' : 'não' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Resultado (resumo)
          </dt>
          <dd class="font-medium">
            {{ pending.result_status || '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Pedido
          </dt>
          <dd class="font-medium">
            #{{ pending.id }}
          </dd>
        </div>
      </dl>

      <UButton
        v-if="isOfficeAdmin && !pending.office_admin_approved"
        label="Confirmar participação (Office ADMIN)"
        icon="i-lucide-shield-check"
        :loading="acting"
        data-testid="settings-dte-canary-confirm"
        @click="confirm"
      />

      <div
        v-if="result?.fiscal_result"
        class="rounded-lg border border-default p-3 text-sm"
        data-testid="settings-dte-canary-fiscal-result"
      >
        <p class="mb-1 font-medium">
          Resultado fiscal (tenant)
        </p>
        <p class="text-muted">
          success={{ (result.fiscal_result as Record<string, unknown>).success }}
          · code={{ (result.fiscal_result as Record<string, unknown>).error_code || '—' }}
          · state={{ (result.fiscal_result as Record<string, unknown>).attempt_state || '—' }}
        </p>
      </div>
    </div>
  </UPageCard>
</template>
