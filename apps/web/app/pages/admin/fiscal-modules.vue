<script setup lang="ts">
/**
 * Governança provider-neutral dos módulos fiscais (PLATFORM_ADMIN).
 * Arquétipo: dashboard customers.vue, adaptado para tabela global + matriz por escritório.
 */
import type { TableColumn } from '@nuxt/ui'
import type {
  FiscalModuleAdminItem,
  FiscalModuleRestrictionControl,
  PlatformOfficeSummary
} from '~/types/api'
import ShellDataTable from '~/components/shell/DataTable.vue'
import ShellFormModal from '~/components/shell/FormModal.vue'
import { apiErrorMessage } from '~/utils/api-error'
import {
  fiscalModuleStateColor,
  fiscalModuleStateLabel,
  fiscalRestrictionActor,
  fiscalRestrictionDate
} from '~/utils/fiscal-module-controls'
import { formatDateTime } from '~/utils/format'

type RestrictionScope = 'global' | 'office'
type RestrictionTarget = {
  scope: RestrictionScope
  module: FiscalModuleAdminItem
  officeId?: number
}

const api = useApi()
const toast = useToast()
const { sessionEpoch, canAccessPlatformAdmin } = useDashboard()

const loadingGlobal = ref(false)
const loadingOffices = ref(false)
const loadingOfficeModules = ref(false)
const submitting = ref(false)
const loadError = ref<string | null>(null)
const officeLoadError = ref<string | null>(null)
const profile = ref('dev')
const killSwitch = ref(false)
const globalModules = ref<FiscalModuleAdminItem[]>([])
const officeModules = ref<FiscalModuleAdminItem[]>([])
const offices = ref<PlatformOfficeSummary[]>([])
const selectedOfficeId = ref<number | null>(null)
const target = ref<RestrictionTarget | null>(null)
const restrictionOpen = ref(false)
const reason = ref('')
const password = ref('')

const officeOptions = computed(() => offices.value.map(office => ({
  id: office.id,
  label: office.name,
  description: `${office.slug} · #${office.id}`
})))
const selectedOffice = computed(() =>
  offices.value.find(office => office.id === selectedOfficeId.value) || null
)
const availableCount = computed(() => globalModules.value.filter(item => item.state === 'AVAILABLE').length)
const restrictedCount = computed(() => globalModules.value.filter(item => item.state === 'GLOBALLY_RESTRICTED').length)
const blockedJobsCount = computed(() => globalModules.value.reduce(
  (total, item) => total + item.blocked_jobs_count,
  0
))
const targetControl = computed(() => {
  if (!target.value) return null
  return target.value.scope === 'global'
    ? target.value.module.global_restriction
    : target.value.module.office_restriction
})
const targetIsRestricted = computed(() => Boolean(targetControl.value?.restricted))
const restrictionTitle = computed(() => {
  const verb = targetIsRestricted.value ? 'Liberar' : 'Restringir'
  const suffix = target.value?.scope === 'global'
    ? 'para todos'
    : `para ${selectedOffice.value?.name || 'o escritório'}`
  return `${verb} ${target.value?.module.label || 'módulo'} ${suffix}`
})
const restrictionDescription = computed(() => targetIsRestricted.value
  ? 'A liberação exige senha recente e agenda uma sincronização de recuperação.'
  : 'A restrição pausa imediatamente novas consultas, sem ocultar os dados já armazenados.'
)

const globalColumns: TableColumn<FiscalModuleAdminItem>[] = [
  { accessorKey: 'label', header: 'Módulo' },
  { id: 'state', header: 'Estado' },
  { id: 'control', header: 'Restrição' },
  { id: 'updated', header: 'Última alteração' },
  { accessorKey: 'blocked_jobs_count', header: 'Jobs bloqueados' },
  { id: 'actions', header: 'Ações', enableSorting: false, meta: { class: { th: 'w-32 text-right', td: 'w-32 text-right' } } }
]

const officeColumns: TableColumn<FiscalModuleAdminItem>[] = [
  { accessorKey: 'label', header: 'Módulo' },
  { id: 'global', header: 'Global' },
  { id: 'office', header: 'Escritório' },
  { id: 'state', header: 'Estado efetivo' },
  { id: 'control', header: 'Motivo / responsável' },
  { accessorKey: 'blocked_jobs_count', header: 'Jobs bloqueados' },
  { id: 'actions', header: 'Ações', enableSorting: false, meta: { class: { th: 'w-32 text-right', td: 'w-32 text-right' } } }
]

function activeControl(item: FiscalModuleAdminItem): FiscalModuleRestrictionControl | null {
  return item.office_restriction?.restricted
    ? item.office_restriction
    : item.global_restriction
}

function controlLabel(control: FiscalModuleRestrictionControl | null): string {
  return control?.restricted ? 'Restrito' : 'Disponível'
}

function controlColor(control: FiscalModuleRestrictionControl | null) {
  return control?.restricted ? 'error' as const : 'success' as const
}

function openRestriction(module: FiscalModuleAdminItem, scope: RestrictionScope) {
  target.value = {
    scope,
    module,
    officeId: scope === 'office' ? selectedOfficeId.value ?? undefined : undefined
  }
  reason.value = ''
  password.value = ''
  restrictionOpen.value = true
}

async function submitRestriction() {
  if (!target.value || reason.value.trim().length < 3) {
    toast.add({ title: 'Informe um motivo com pelo menos 3 caracteres.', color: 'warning' })
    return
  }
  if (targetIsRestricted.value && !password.value.trim()) {
    toast.add({ title: 'Informe sua senha para liberar o módulo.', color: 'warning' })
    return
  }

  submitting.value = true
  try {
    if (targetIsRestricted.value) {
      await api.confirmPassword(password.value.trim())
    }

    const body = {
      restricted: !targetIsRestricted.value,
      reason: reason.value.trim()
    }
    const response = target.value.scope === 'global'
      ? await api.platform.fiscalModules.setRestriction(target.value.module.module_key, body)
      : await api.platform.fiscalModules.setOfficeRestriction(
          target.value.officeId!,
          target.value.module.module_key,
          body
        )

    restrictionOpen.value = false
    toast.add({ title: response.message, color: 'success' })
    await Promise.all([loadGlobal(), selectedOfficeId.value ? loadOfficeModules() : Promise.resolve()])
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível alterar a restrição.'),
      color: 'error'
    })
  } finally {
    submitting.value = false
  }
}

let globalLoadSeq = 0
async function loadGlobal() {
  if (!canAccessPlatformAdmin.value) return
  const seq = ++globalLoadSeq
  const epoch = sessionEpoch.value
  loadingGlobal.value = true
  loadError.value = null
  try {
    const response = await api.platform.fiscalModules.list()
    if (seq !== globalLoadSeq || epoch !== sessionEpoch.value) return
    profile.value = response.data.profile
    killSwitch.value = response.data.kill_switch
    globalModules.value = response.data.modules || []
  } catch (caught) {
    if (seq !== globalLoadSeq || epoch !== sessionEpoch.value) return
    loadError.value = apiErrorMessage(caught, 'Falha ao carregar os módulos fiscais.')
    globalModules.value = []
  } finally {
    if (seq === globalLoadSeq && epoch === sessionEpoch.value) loadingGlobal.value = false
  }
}

async function loadOffices() {
  if (!canAccessPlatformAdmin.value) return
  loadingOffices.value = true
  try {
    const response = await api.platform.offices.list({ page: 1, per_page: 100 })
    offices.value = response.data.offices || []
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao listar escritórios.'), color: 'error' })
  } finally {
    loadingOffices.value = false
  }
}

let officeLoadSeq = 0
async function loadOfficeModules() {
  const officeId = selectedOfficeId.value
  if (!officeId) {
    officeModules.value = []
    officeLoadError.value = null
    return
  }
  const seq = ++officeLoadSeq
  const epoch = sessionEpoch.value
  loadingOfficeModules.value = true
  officeLoadError.value = null
  try {
    const response = await api.platform.fiscalModules.listForOffice(officeId)
    if (seq !== officeLoadSeq || epoch !== sessionEpoch.value || officeId !== selectedOfficeId.value) return
    officeModules.value = response.data.modules || []
  } catch (caught) {
    if (seq !== officeLoadSeq || epoch !== sessionEpoch.value || officeId !== selectedOfficeId.value) return
    officeLoadError.value = apiErrorMessage(caught, 'Falha ao carregar a matriz do escritório.')
    officeModules.value = []
  } finally {
    if (seq === officeLoadSeq && epoch === sessionEpoch.value) loadingOfficeModules.value = false
  }
}

function reloadAll() {
  void Promise.all([loadGlobal(), loadOffices(), loadOfficeModules()])
}

watch(selectedOfficeId, () => {
  void loadOfficeModules()
})
watch(sessionEpoch, () => {
  globalModules.value = []
  officeModules.value = []
  offices.value = []
  selectedOfficeId.value = null
  reloadAll()
})
onMounted(reloadAll)
</script>

<template>
  <ShellPagePanel
    id="admin-fiscal-modules"
    data-testid="admin-fiscal-modules-panel"
  >
    <template #header>
      <ShellPageNavbar title="Módulos fiscais">
        <template #right>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            label="Atualizar"
            :loading="loadingGlobal || loadingOfficeModules"
            @click="reloadAll"
          />
        </template>
      </ShellPageNavbar>
    </template>

    <template #body>
      <div class="space-y-6">
        <UAlert
          v-if="killSwitch"
          color="error"
          icon="i-lucide-octagon-x"
          title="Kill switch fiscal ativo"
          description="Todas as novas execuções estão pausadas, independentemente das restrições abaixo. Dados históricos continuam visíveis."
        />

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <UCard>
            <p class="text-xs font-medium text-muted">
              Perfil
            </p>
            <p class="mt-1 text-xl font-semibold uppercase">
              {{ profile }}
            </p>
          </UCard>
          <UCard>
            <p class="text-xs font-medium text-muted">
              Disponíveis globalmente
            </p>
            <p class="mt-1 text-xl font-semibold tabular-nums">
              {{ availableCount }}
            </p>
          </UCard>
          <UCard>
            <p class="text-xs font-medium text-muted">
              Restrições globais
            </p>
            <p class="mt-1 text-xl font-semibold tabular-nums">
              {{ restrictedCount }}
            </p>
          </UCard>
          <UCard>
            <p class="text-xs font-medium text-muted">
              Jobs bloqueados
            </p>
            <p class="mt-1 text-xl font-semibold tabular-nums">
              {{ blockedJobsCount }}
            </p>
          </UCard>
        </div>

        <section class="space-y-3" aria-labelledby="global-modules-title">
          <div>
            <h2 id="global-modules-title" class="text-base font-semibold">
              Controle global
            </h2>
            <p class="text-sm text-muted">
              Ausência de restrição significa disponível para todos os escritórios prontos.
            </p>
          </div>

          <ShellDataTable
            test-id="fiscal-global-modules-table"
            ui-preset="monitoring-compact"
            table-class="min-w-[70rem]"
            horizontal-scroll
            :mobile-cards="false"
            :columns="globalColumns"
            :data="globalModules"
            :loading="loadingGlobal"
            :page="1"
            :total="globalModules.length"
            :items-per-page="globalModules.length || 10"
            :show-pagination="false"
            :show-per-page="false"
            :error="loadError"
            @retry="loadGlobal"
          >
            <template #state-cell="{ row }">
              <UBadge
                :color="fiscalModuleStateColor(row.original.state)"
                variant="subtle"
                :label="fiscalModuleStateLabel(row.original.state)"
              />
            </template>
            <template #control-cell="{ row }">
              <div class="max-w-xs">
                <p class="truncate text-sm">
                  {{ row.original.global_restriction?.reason || 'Sem restrição' }}
                </p>
                <p class="text-xs text-muted">
                  {{ fiscalRestrictionActor(row.original.global_restriction) }}
                </p>
              </div>
            </template>
            <template #updated-cell="{ row }">
              {{ fiscalRestrictionDate(row.original.global_restriction)
                ? formatDateTime(fiscalRestrictionDate(row.original.global_restriction))
                : '—' }}
            </template>
            <template #actions-cell="{ row }">
              <div class="flex justify-end">
                <UButton
                  :color="row.original.global_restriction?.restricted ? 'primary' : 'error'"
                  :variant="row.original.global_restriction?.restricted ? 'soft' : 'outline'"
                  :icon="row.original.global_restriction?.restricted ? 'i-lucide-play' : 'i-lucide-pause'"
                  :label="row.original.global_restriction?.restricted ? 'Liberar' : 'Restringir'"
                  size="sm"
                  @click="openRestriction(row.original, 'global')"
                />
              </div>
            </template>
          </ShellDataTable>
        </section>

        <section class="space-y-3" aria-labelledby="office-modules-title">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 id="office-modules-title" class="text-base font-semibold">
                Matriz por escritório
              </h2>
              <p class="text-sm text-muted">
                Compare a regra global, a exceção local e o estado efetivo.
              </p>
            </div>
            <UFormField label="Escritório" class="w-full sm:w-96">
              <USelectMenu
                v-model="selectedOfficeId"
                :items="officeOptions"
                value-key="id"
                label-key="label"
                :loading="loadingOffices"
                clear
                placeholder="Buscar e selecionar escritório"
                :search-input="{ placeholder: 'Buscar por nome, slug ou ID…', icon: 'i-lucide-search' }"
                class="w-full"
              />
            </UFormField>
          </div>

          <UEmpty
            v-if="!selectedOfficeId"
            icon="i-lucide-building-2"
            title="Selecione um escritório"
            description="A matriz mostrará as dez regras globais e as exceções desse escritório."
          />

          <ShellDataTable
            v-else
            test-id="fiscal-office-modules-table"
            ui-preset="monitoring-compact"
            table-class="min-w-[78rem]"
            horizontal-scroll
            :mobile-cards="false"
            :columns="officeColumns"
            :data="officeModules"
            :loading="loadingOfficeModules"
            :page="1"
            :total="officeModules.length"
            :items-per-page="officeModules.length || 10"
            :show-pagination="false"
            :show-per-page="false"
            :error="officeLoadError"
            @retry="loadOfficeModules"
          >
            <template #global-cell="{ row }">
              <UBadge
                :color="controlColor(row.original.global_restriction)"
                variant="subtle"
                :label="controlLabel(row.original.global_restriction)"
              />
            </template>
            <template #office-cell="{ row }">
              <UBadge
                :color="controlColor(row.original.office_restriction)"
                variant="subtle"
                :label="controlLabel(row.original.office_restriction)"
              />
            </template>
            <template #state-cell="{ row }">
              <UBadge
                :color="fiscalModuleStateColor(row.original.state)"
                variant="subtle"
                :label="fiscalModuleStateLabel(row.original.state)"
              />
            </template>
            <template #control-cell="{ row }">
              <div class="max-w-xs">
                <p class="truncate text-sm">
                  {{ activeControl(row.original)?.reason || row.original.reason || 'Sem restrição' }}
                </p>
                <p class="text-xs text-muted">
                  {{ fiscalRestrictionActor(activeControl(row.original)) }}
                </p>
              </div>
            </template>
            <template #actions-cell="{ row }">
              <div class="flex justify-end">
                <UButton
                  :color="row.original.office_restriction?.restricted ? 'primary' : 'error'"
                  :variant="row.original.office_restriction?.restricted ? 'soft' : 'outline'"
                  :icon="row.original.office_restriction?.restricted ? 'i-lucide-play' : 'i-lucide-pause'"
                  :label="row.original.office_restriction?.restricted ? 'Liberar' : 'Restringir'"
                  size="sm"
                  :disabled="row.original.global_restriction?.restricted && !row.original.office_restriction?.restricted"
                  @click="openRestriction(row.original, 'office')"
                />
              </div>
            </template>
          </ShellDataTable>
        </section>
      </div>
    </template>
  </ShellPagePanel>

  <ShellFormModal
    v-model:open="restrictionOpen"
    :title="restrictionTitle"
    :description="restrictionDescription"
    :submit-label="targetIsRestricted ? 'Liberar e sincronizar' : 'Restringir agora'"
    :submit-color="targetIsRestricted ? 'primary' : 'error'"
    :submit-icon="targetIsRestricted ? 'i-lucide-play' : 'i-lucide-pause'"
    :loading="submitting"
    :disabled="reason.trim().length < 3 || (targetIsRestricted && !password.trim())"
    test-id="fiscal-module-restriction-modal"
    @submit="submitRestriction"
  >
    <template #body>
      <div class="space-y-4">
        <UFormField label="Motivo" name="reason" required>
          <UTextarea
            v-model="reason"
            :rows="3"
            maxlength="500"
            class="w-full"
            placeholder="Registre por que esta alteração é necessária"
            autofocus
          />
        </UFormField>
        <UFormField
          v-if="targetIsRestricted"
          label="Senha atual"
          name="password"
          description="A senha confirma esta liberação por até 15 minutos."
          required
        >
          <UInput
            v-model="password"
            type="password"
            autocomplete="current-password"
            class="w-full"
          />
        </UFormField>
      </div>
    </template>
  </ShellFormModal>
</template>
