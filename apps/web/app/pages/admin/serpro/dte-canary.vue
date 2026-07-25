<script setup lang="ts">
/**
 * Canário DTE controlado (PLATFORM_ADMIN / Proprietário).
 * Sem payload fiscal — apenas status, correlação e consumo sanitizados.
 */
const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()

const loading = ref(false)
const acting = ref(false)
const loadError = ref<string | null>(null)
const summary = ref<Record<string, unknown> | null>(null)

const officeId = ref<number | null>(null)
const clientId = ref<number | null>(null)
const reconcileRef = ref('')
const reconcileSummary = ref('')
const promoteReason = ref('')
const disableReason = ref('')
const passwordModalOpen = ref(false)
const passwordInput = ref('')
const pendingActionLabel = ref('')
const pendingAction = shallowRef<null | (() => Promise<void>)>(null)
const confirmingPassword = ref(false)

const control = computed(() => (summary.value?.control ?? null) as Record<string, unknown> | null)
const request = computed(() => (summary.value?.request ?? null) as Record<string, unknown> | null)
const coordinates = computed(() => (summary.value?.coordinates ?? null) as Record<string, unknown> | null)
const gate = computed(() => (summary.value?.gate ?? null) as Record<string, unknown> | null)
const blockers = computed(() => Array.isArray(gate.value?.blockers)
  ? gate.value.blockers.map(item => String(item))
  : [])
const ownerApproved = computed(() => Boolean(request.value?.owner_approved))
const officeAdminApproved = computed(() => Boolean(request.value?.office_admin_approved))
const approvalsComplete = computed(() => ownerApproved.value && officeAdminApproved.value)
const targetSelected = computed(() => Boolean(request.value?.office_id && request.value?.client_id))
const requestStatus = computed(() => String(request.value?.status || '').toUpperCase())
const attemptFinished = computed(() => Boolean(request.value?.result_status) || [
  'SUCCEEDED',
  'FAILED',
  'UNCERTAIN',
  'RECONCILED'
].includes(requestStatus.value))
const canReconcile = computed(() => ['SUCCEEDED', 'FAILED', 'UNCERTAIN'].includes(requestStatus.value))
const canExecute = computed(() => Boolean(gate.value)
  && requestStatus.value === 'FULLY_APPROVED'
  && blockers.value.length === 0)
const canPromote = computed(() => Boolean(request.value?.reconciled) && request.value?.result_status === 'SUCCEEDED')

function controlColor(mode: unknown) {
  const normalized = String(mode || 'DISABLED').toUpperCase()
  if (normalized === 'LIMITED') return 'success' as const
  if (normalized === 'CANARY') return 'warning' as const
  return 'neutral' as const
}

function requestColor(status: unknown) {
  const normalized = String(status || '').toUpperCase()
  if (['SUCCEEDED', 'RECONCILED', 'PROMOTED'].includes(normalized)) return 'success' as const
  if (['FAILED', 'UNCERTAIN', 'EXPIRED'].includes(normalized)) return 'error' as const
  if (normalized) return 'warning' as const
  return 'neutral' as const
}

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const res = await api.platform.serpro.dteCanary.summary()
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    const data = res.data as Record<string, unknown> | null
    if (!data?.control || !data.coordinates) {
      throw new Error('Resumo DTE incompleto.')
    }
    summary.value = data
  } catch (e) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    loadError.value = apiErrorMessage(e, 'Falha ao carregar canário DTE.')
    summary.value = null
    passwordModalOpen.value = false
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) loading.value = false
  }
}

function requestRecentPassword(label: string, action: () => Promise<void>) {
  if (!summary.value || loading.value || acting.value) return
  pendingActionLabel.value = label
  pendingAction.value = action
  passwordInput.value = ''
  passwordModalOpen.value = true
}

async function submitRecentPassword() {
  if (!passwordInput.value.trim()) {
    toast.add({ title: 'Informe a senha da sessão.', color: 'warning' })
    return
  }

  confirmingPassword.value = true
  try {
    await api.confirmPassword(passwordInput.value.trim())
    const action = pendingAction.value
    pendingAction.value = null
    passwordInput.value = ''
    passwordModalOpen.value = false
    if (action) await action()
  } catch (e) {
    toast.add({
      title: apiErrorMessage(e, 'Senha inválida ou confirmação expirada.'),
      color: 'error'
    })
  } finally {
    confirmingPassword.value = false
  }
}

async function run(action: () => Promise<void>) {
  acting.value = true
  try {
    await action()
    await load()
  } catch (e) {
    toast.add({
      title: 'Ação DTE falhou',
      description: apiErrorMessage(e, 'Verifique senha recente e gates.'),
      color: 'error'
    })
  } finally {
    acting.value = false
  }
}

function createRequest() {
  return run(async () => {
    await api.platform.serpro.dteCanary.create()
    toast.add({ title: 'Pedido de canário criado', color: 'success' })
  })
}

function selectTarget() {
  const id = Number(request.value?.id)
  if (!id || !officeId.value || !clientId.value) {
    toast.add({ title: 'Informe Office e cliente', color: 'warning' })
    return Promise.resolve()
  }
  return run(async () => {
    await api.platform.serpro.dteCanary.selectTarget(id, {
      office_id: officeId.value!,
      client_id: clientId.value!
    })
    toast.add({ title: 'Alvo selecionado (server-side)', color: 'success' })
  })
}

function approveOwner() {
  const id = Number(request.value?.id)
  if (!id) return Promise.resolve()
  return run(async () => {
    await api.platform.serpro.dteCanary.approveOwner(id)
    toast.add({ title: 'Aprovação do Proprietário registrada', color: 'success' })
  })
}

function execute() {
  const id = Number(request.value?.id)
  if (!id) return Promise.resolve()
  return run(async () => {
    const res = await api.platform.serpro.dteCanary.execute(id)
    toast.add({
      title: res.replay ? 'Replay (sem novo transporte)' : 'Tentativa DTE registrada',
      description: `Status: ${String(res.data.result_status ?? res.data.status ?? '—')}`,
      color: 'success'
    })
  })
}

function reconcile() {
  const id = Number(request.value?.id)
  if (!id) return Promise.resolve()
  return run(async () => {
    await api.platform.serpro.dteCanary.reconcile(id, {
      reference: reconcileRef.value,
      summary: reconcileSummary.value
    })
    toast.add({ title: 'Reconciliação registrada', color: 'success' })
  })
}

function promoteLimited() {
  const id = Number(request.value?.id)
  if (!id) return Promise.resolve()
  return run(async () => {
    await api.platform.serpro.dteCanary.promoteLimited(id, {
      confirmation_phrase: 'CONFIRMO-DTE-LIMITED',
      reason: promoteReason.value || 'Promoção LIMITED pós-canário',
      max_quantity: 10
    })
    toast.add({ title: 'DTE promovido a LIMITED (teto 10)', color: 'success' })
  })
}

function disable() {
  return run(async () => {
    await api.platform.serpro.dteCanary.disable({
      confirmation_phrase: 'CONFIRMO-DTE-DISABLE',
      reason: disableReason.value || 'Desativação imediata'
    })
    toast.add({ title: 'DTE desativado', color: 'warning' })
  })
}

watch(sessionEpoch, () => {
  summary.value = null
  passwordModalOpen.value = false
  void load()
})
watch(passwordModalOpen, (open) => {
  if (open) return
  passwordInput.value = ''
  pendingAction.value = null
  pendingActionLabel.value = ''
})
onMounted(load)
</script>

<template>
  <div
    class="flex flex-col gap-4 sm:gap-6"
    data-testid="admin-serpro-dte-canary"
  >
    <UPageCard
      title="Canário DTE"
      variant="naked"
      orientation="horizontal"
    >
      <div class="flex w-fit flex-wrap items-center gap-2 lg:ms-auto">
        <UBadge color="neutral" variant="subtle">
          Sem payload fiscal
        </UBadge>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          label="Atualizar estado"
          :loading="loading"
          data-testid="dte-canary-refresh"
          @click="load"
        />
      </div>
    </UPageCard>

    <UAlert
      v-if="loadError"
      color="error"
      icon="i-lucide-circle-x"
      :title="loadError"
      :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
    />

    <UPageCard
      v-if="loading && !summary"
      variant="subtle"
      aria-busy="true"
      aria-label="Carregando estado do canário DTE"
    >
      <div class="space-y-3">
        <USkeleton class="h-5 w-48" />
        <USkeleton class="h-16 w-full" />
        <USkeleton class="h-16 w-full" />
      </div>
    </UPageCard>

    <template v-if="summary">
      <section aria-label="Estado atual do canário DTE">
        <UPageCard
          title="Estado atual"
          variant="naked"
          class="mb-4"
        />

        <UPageCard
          variant="subtle"
        >
          <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-muted">
                Modo
              </dt>
              <dd data-testid="dte-control-mode">
                <UBadge
                  :color="controlColor(control?.mode)"
                  variant="subtle"
                >
                  {{ control?.mode || 'DISABLED' }}
                </UBadge>
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Operação
              </dt>
              <dd class="font-medium">
                {{ coordinates?.operation_key || 'dte.consultar' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Office piloto
              </dt>
              <dd class="font-medium">
                {{ control?.pilot_office_id ?? request?.office_id ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Consumo LIMITED
              </dt>
              <dd class="font-medium">
                {{ control?.limited_used_quantity ?? 0 }} / {{ control?.limited_max_quantity ?? '—' }}
              </dd>
            </div>
          </dl>
        </UPageCard>
      </section>

      <section aria-label="Preparar pedido e alvo">
        <UPageCard
          title="1. Preparar pedido e alvo"
          variant="naked"
          class="mb-4"
        />

        <UPageCard
          variant="subtle"
        >
          <dl
            v-if="request"
            class="grid gap-3 text-sm sm:grid-cols-2"
            data-testid="dte-canary-request"
          >
            <div>
              <dt class="text-muted">
                Pedido
              </dt>
              <dd class="flex flex-wrap items-center gap-2 font-medium">
                <span>#{{ request.id }}</span>
                <UBadge
                  :color="requestColor(request.status)"
                  variant="subtle"
                >
                  {{ request.status || '—' }}
                </UBadge>
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Alvo persistido
              </dt>
              <dd class="font-medium">
                Office #{{ request.office_id || '—' }}
                · Cliente #{{ request.client_id || '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Resultado
              </dt>
              <dd class="font-medium">
                {{ request.result_status || '—' }}
                <span
                  v-if="request.consumption_quantity"
                  class="text-muted"
                > · quantidade {{ request.consumption_quantity }}</span>
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Correlação
              </dt>
              <dd class="font-mono text-xs">
                {{ request.correlation_id || '—' }}
              </dd>
            </div>
          </dl>

          <UEmpty
            v-else
            icon="i-lucide-file-plus-2"
            title="Nenhum pedido ativo"
          />

          <div
            v-if="request && !attemptFinished && !approvalsComplete"
            class="mt-6 grid gap-3 sm:grid-cols-2"
          >
            <UFormField
              label="Office piloto"
              required
            >
              <UInput
                v-model.number="officeId"
                type="number"
                min="1"
                class="w-full"
                placeholder="ID do Office"
                data-testid="dte-canary-office-id"
              />
            </UFormField>
            <UFormField
              label="Cliente do Office"
              required
            >
              <UInput
                v-model.number="clientId"
                type="number"
                min="1"
                class="w-full"
                placeholder="ID do cliente"
                data-testid="dte-canary-client-id"
              />
            </UFormField>
          </div>

          <template #footer>
            <div class="flex w-full justify-end">
              <UButton
                v-if="!request"
                label="Criar pedido"
                icon="i-lucide-plus"
                :loading="acting"
                data-testid="dte-canary-create"
                @click="requestRecentPassword('Criar pedido de canário', createRequest)"
              />
              <UButton
                v-else-if="!attemptFinished && !approvalsComplete"
                label="Definir alvo"
                icon="i-lucide-crosshair"
                :disabled="!officeId || !clientId"
                :loading="acting"
                data-testid="dte-canary-select-target"
                @click="requestRecentPassword('Definir alvo do canário', selectTarget)"
              />
            </div>
          </template>
        </UPageCard>
      </section>

      <section
        v-if="targetSelected && !attemptFinished"
        aria-label="Registrar aprovações"
      >
        <UPageCard
          title="2. Aprovações distintas"
          variant="naked"
          class="mb-4"
        />

        <UPageCard
          variant="subtle"
        >
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-default p-3">
              <p class="text-sm font-medium text-highlighted">
                Proprietário
              </p>
              <div class="mt-2">
                <UBadge
                  :color="ownerApproved ? 'success' : 'warning'"
                  variant="subtle"
                >
                  {{ ownerApproved ? 'Aprovado' : 'Pendente' }}
                </UBadge>
              </div>
            </div>
            <div class="rounded-lg border border-default p-3">
              <p class="text-sm font-medium text-highlighted">
                Office ADMIN
              </p>
              <div class="mt-2">
                <UBadge
                  :color="officeAdminApproved ? 'success' : 'warning'"
                  variant="subtle"
                >
                  {{ officeAdminApproved ? 'Aprovado' : 'Pendente no Office' }}
                </UBadge>
              </div>
            </div>
          </div>

          <template #footer>
            <div class="flex w-full flex-wrap items-center justify-between gap-3">
              <p class="max-w-xl text-sm text-muted">
                O ADMIN aprova no próprio escritório.
              </p>
              <UButton
                v-if="!ownerApproved"
                label="Aprovar como Proprietário"
                icon="i-lucide-shield-check"
                :disabled="!request?.id"
                :loading="acting"
                data-testid="dte-canary-approve-owner"
                @click="requestRecentPassword('Aprovar canário como Proprietário', approveOwner)"
              />
            </div>
          </template>
        </UPageCard>
      </section>

      <section
        v-if="approvalsComplete && !attemptFinished"
        aria-label="Validar gates e executar"
      >
        <UPageCard
          title="3. Validar gates e executar"
          variant="naked"
          class="mb-4"
        />

        <UPageCard
          variant="subtle"
        >
          <template v-if="gate">
            <UAlert
              v-if="blockers.length"
              color="warning"
              icon="i-lucide-shield-alert"
              :title="blockers.length + (blockers.length === 1 ? ' bloqueio impede o transporte' : ' bloqueios impedem o transporte')"
            />
            <div
              v-else
              class="flex items-start gap-3 rounded-lg border border-default p-3"
            >
              <UIcon
                name="i-lucide-circle-check"
                class="mt-0.5 size-5 shrink-0 text-success"
                aria-hidden="true"
              />
              <div>
                <p class="text-sm font-medium text-highlighted">
                  Nenhum bloqueio retornado
                </p>
              </div>
            </div>

            <div
              v-if="blockers.length"
              class="mt-4 flex flex-wrap gap-1.5"
              aria-label="Bloqueios do gate pré-transporte"
            >
              <UBadge
                v-for="blocker in blockers"
                :key="blocker"
                color="warning"
                variant="subtle"
                size="sm"
              >
                {{ blocker }}
              </UBadge>
            </div>
          </template>

          <UEmpty
            v-else
            icon="i-lucide-shield-question"
            title="Gate indisponível"
          />

          <template #footer>
            <div class="flex w-full justify-end">
              <UButton
                label="Executar tentativa única"
                icon="i-lucide-play"
                :disabled="!canExecute"
                :loading="acting"
                data-testid="dte-canary-execute"
                @click="requestRecentPassword('Executar tentativa única DTE', execute)"
              />
            </div>
          </template>
        </UPageCard>
      </section>

      <section
        v-if="attemptFinished"
        aria-label="Registrar reconciliação"
      >
        <UPageCard
          title="4. Conferir e reconciliar"
          variant="naked"
          class="mb-4"
        />

        <UPageCard
          v-if="request?.reconciled"
          variant="subtle"
        >
          <div class="flex items-start gap-3">
            <UIcon
              name="i-lucide-circle-check"
              class="mt-0.5 size-5 shrink-0 text-success"
              aria-hidden="true"
            />
            <div>
              <p class="text-sm font-medium text-highlighted">
                Reconciliação concluída
              </p>
              <p class="mt-1 text-sm text-muted">
                {{ request.reconciliation_reference || 'Referência registrada' }}
              </p>
            </div>
          </div>
        </UPageCard>

        <UPageCard
          v-else
          variant="subtle"
        >
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField
              label="Referência da Área do Cliente"
              required
            >
              <UInput
                v-model="reconcileRef"
                class="w-full"
                data-testid="dte-canary-reconcile-ref"
              />
            </UFormField>
            <UFormField
              label="Resumo da conferência"
              required
            >
              <UTextarea
                v-model="reconcileSummary"
                class="w-full"
                :rows="3"
                data-testid="dte-canary-reconcile-summary"
              />
            </UFormField>
          </div>

          <template #footer>
            <div class="flex w-full justify-end">
              <UButton
                label="Registrar reconciliação"
                icon="i-lucide-scale"
                :disabled="!canReconcile || !reconcileRef.trim() || !reconcileSummary.trim()"
                :loading="acting"
                data-testid="dte-canary-reconcile"
                @click="requestRecentPassword('Registrar reconciliação DTE', reconcile)"
              />
            </div>
          </template>
        </UPageCard>
      </section>

      <section
        v-if="canPromote && control?.mode !== 'LIMITED'"
        aria-label="Promover DTE para LIMITED"
      >
        <UPageCard
          title="5. Promover para LIMITED (10)"
          variant="naked"
          class="mb-4"
        />

        <UPageCard variant="subtle">
          <UFormField
            label="Motivo da promoção"
            hint="Frase: CONFIRMO-DTE-LIMITED"
            required
          >
            <UInput
              v-model="promoteReason"
              class="w-full"
              data-testid="dte-canary-promote-reason"
            />
          </UFormField>

          <template #footer>
            <div class="flex w-full justify-end">
              <UButton
                label="Promover para LIMITED (10)"
                icon="i-lucide-gauge"
                :disabled="!request?.id || !promoteReason.trim()"
                :loading="acting"
                data-testid="dte-canary-promote"
                @click="requestRecentPassword('Promover DTE para LIMITED', promoteLimited)"
              />
            </div>
          </template>
        </UPageCard>
      </section>

      <UPageCard
        v-if="control?.mode !== 'DISABLED'"
        title="Desativação de emergência"
        description="Bloqueia novas consultas."
        class="bg-linear-to-tl from-error/10 from-5% to-default"
      >
        <UFormField
          label="Motivo da desativação"
          hint="Frase: CONFIRMO-DTE-DISABLE"
          required
        >
          <UInput
            v-model="disableReason"
            class="w-full"
            data-testid="dte-canary-disable-reason"
          />
        </UFormField>

        <template #footer>
          <UButton
            label="Desativar DTE"
            color="error"
            variant="soft"
            icon="i-lucide-octagon-x"
            :disabled="!disableReason.trim()"
            :loading="acting"
            data-testid="dte-canary-disable"
            @click="requestRecentPassword('Desativar DTE', disable)"
          />
        </template>
      </UPageCard>
    </template>

    <ShellFormModal
      v-if="summary"
      v-model:open="passwordModalOpen"
      title="Reconfirmar senha"
      :description="pendingActionLabel"
      submit-label="Confirmar e continuar"
      submit-icon="i-lucide-shield-check"
      :loading="confirmingPassword"
      :show-default-footer="false"
      test-id="dte-canary-password-modal"
      @cancel="() => { passwordModalOpen = false }"
      @submit="submitRecentPassword"
    >
      <template #body>
        <UFormField
          label="Senha do Proprietário"
          hint="Válida por 15 minutos"
          required
        >
          <UInput
            v-model="passwordInput"
            type="password"
            autocomplete="current-password"
            class="w-full"
            data-testid="dte-canary-password"
            @keyup.enter="submitRecentPassword"
          />
        </UFormField>
      </template>
      <template #footer>
        <ShellModalFooter
          submit-label="Confirmar e continuar"
          submit-icon="i-lucide-shield-check"
          submit-test-id="dte-canary-password-submit"
          :loading="confirmingPassword"
          @cancel="() => { passwordModalOpen = false }"
          @submit="submitRecentPassword"
        />
      </template>
    </ShellFormModal>
  </div>
</template>
