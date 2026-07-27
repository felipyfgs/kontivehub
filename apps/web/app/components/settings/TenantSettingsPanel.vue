<script setup lang="ts">
/**
 * Configuração do escritório (contador).
 * Superfície mínima: perfil · certificado (aceite no modal) · agendas.
 * SERPRO (Termo/token/procurações) roda automático após o upload — sem onboarding visível.
 */
import type { AccordionItem } from '@nuxt/ui'
import type {
  TenantCertificate,
  TenantInstitutionalProfile,
  TenantMonitorSchedulePolicy
} from '~/types/api'
import { actionableTenantError } from '~/utils/tenant-settings'

const api = useApi()
const toast = useToast()
const { sessionEpoch, canManageCredentials } = useDashboard()

const loading = ref(true)
const saving = ref(false)
const refreshing = ref(false)
const savingScheduleKey = ref<string | null>(null)
const loadError = ref<string | null>(null)

const profile = ref<TenantInstitutionalProfile | null>(null)
const credential = ref<TenantCertificate | null>(null)
const policies = ref<TenantMonitorSchedulePolicy[]>([])

const readonly = computed(() => !canManageCredentials.value)

const tenantAccordionItems: AccordionItem[] = [
  {
    label: 'Perfil',
    icon: 'i-lucide-building-2',
    value: 'perfil',
    slot: 'perfil'
  },
  {
    label: 'certificado',
    icon: 'i-lucide-badge-check',
    value: 'certificado',
    slot: 'certificado'
  },
  {
    label: 'Agendas',
    icon: 'i-lucide-calendar-days',
    value: 'agendas',
    slot: 'agendas'
  }
]

let loadSeq = 0

function isNotFound(err: unknown): boolean {
  const status = (err as { status?: number, statusCode?: number, response?: { status?: number } })?.status
    ?? (err as { statusCode?: number })?.statusCode
    ?? (err as { response?: { status?: number } })?.response?.status
  return status === 404
}

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null

  try {
    const [profileRes, credRes, schedRes] = await Promise.allSettled([
      api.tenant.profile.show(),
      api.tenant.certificate.show(),
      api.tenant.monitorSchedules.list()
    ])
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return

    if (profileRes.status === 'fulfilled') {
      profile.value = profileRes.value.data
    } else {
      profile.value = null
      loadError.value = actionableTenantError(
        apiErrorMessage(profileRes.reason, 'Falha ao carregar perfil.')
      )
    }

    if (credRes.status === 'fulfilled') {
      credential.value = credRes.value.data
    }

    if (schedRes.status === 'fulfilled') {
      policies.value = schedRes.value.data || []
    } else {
      policies.value = []
    }
  } catch (caught) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    loadError.value = actionableTenantError(
      apiErrorMessage(caught, 'Falha ao carregar configuração do escritório.')
    )
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

async function saveProfile(payload: {
  cnpj: string
  legal_name: string
  institutional_email: string
  institutional_phone: string
  confirm_cnpj_change?: boolean
}) {
  saving.value = true
  try {
    const res = await api.tenant.profile.update(payload)
    profile.value = res.data
    toast.add({ title: 'Perfil salvo', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({
      title: actionableTenantError(apiErrorMessage(caught, 'Falha ao salvar perfil.')),
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}

async function uploadCredential(payload: {
  file: File
  password: string
  consent_accepted: boolean
}) {
  const cnpj = (profile.value?.cnpj || '').replace(/\D/g, '')
  if (!cnpj) {
    toast.add({
      title: 'Cadastre o CNPJ no perfil institucional antes de enviar o certificado.',
      color: 'warning'
    })
    return
  }

  saving.value = true
  try {
    const res = credential.value
      ? await api.tenant.certificate.replace(payload.file, payload.password, {
          consent_accepted: payload.consent_accepted
        })
      : await api.tenant.certificate.upload(payload.file, payload.password, {
          consent_accepted: payload.consent_accepted
        })
    credential.value = res.data
    toast.add({
      title: 'Certificado ativo',
      description: 'O escritório fica pronto automaticamente — sem etapas extras.',
      color: 'success'
    })
    await load()
  } catch (caught) {
    toast.add({
      title: actionableTenantError(apiErrorMessage(caught, 'Falha ao enviar certificado.')),
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}

async function removeCredential(_payload: { reconfirm_password?: string } = {}) {
  saving.value = true
  try {
    await api.tenant.certificate.remove({ confirm: true })
    credential.value = null
    toast.add({ title: 'Certificado removido', color: 'warning' })
    await load()
  } catch (caught) {
    toast.add({
      title: actionableTenantError(apiErrorMessage(caught, 'Falha ao remover certificado.')),
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}

async function refreshIntegration() {
  refreshing.value = true
  try {
    const res = await api.tenant.certificate.refreshIntegration()
    toast.add({
      title: 'Integração atualizada',
      description: res.data.has_procurador_token
        ? 'Token regenerado com o certificado já cadastrado.'
        : 'Solicitação enviada; acompanhe o status em alguns instantes.',
      color: 'success'
    })
    await load()
  } catch (caught) {
    toast.add({
      title: actionableTenantError(apiErrorMessage(caught, 'Falha ao atualizar a integração.')),
      color: 'error'
    })
  } finally {
    refreshing.value = false
  }
}

async function saveSchedule(payload: { monitor_key: string, day_of_month: number }) {
  savingScheduleKey.value = payload.monitor_key
  try {
    const res = await api.tenant.monitorSchedules.update(payload.monitor_key, {
      day_of_month: payload.day_of_month
    })
    const idx = policies.value.findIndex(p => p.monitor_key === payload.monitor_key)
    if (idx >= 0) policies.value[idx] = res.data
    else policies.value = [...policies.value, res.data]
    toast.add({ title: 'Agenda atualizada', color: 'success' })
  } catch (caught) {
    toast.add({
      title: isNotFound(caught)
        ? 'API de agendas ainda não disponível neste ambiente.'
        : actionableTenantError(apiErrorMessage(caught, 'Falha ao salvar agenda.')),
      color: isNotFound(caught) ? 'warning' : 'error'
    })
  } finally {
    savingScheduleKey.value = null
  }
}

watch(sessionEpoch, () => {
  profile.value = null
  credential.value = null
  policies.value = []
  void load()
})
onMounted(load)
</script>

<template>
  <div data-testid="settings-tenant-unified">
    <div class="flex flex-col gap-4 sm:gap-6">
      <UAlert
        v-if="loadError"
        color="error"
        icon="i-lucide-circle-x"
        :title="loadError"
        :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
        data-testid="settings-load-error"
      />

      <ShellPanelAccordion
        :items="tenantAccordionItems"
        multiple
        :default-open="['perfil', 'certificado']"
        test-id="settings-tenant-accordion"
      >
        <template #perfil-body>
          <SettingsTenantProfileSection
            :profile="profile"
            :loading="loading"
            :saving="saving"
            :readonly="readonly"
            :show-header="false"
            @save="saveProfile"
          />
        </template>
        <template #certificado-body>
          <SettingsTenantCredentialSection
            :credential="credential"
            :loading="loading"
            :saving="saving"
            :refreshing="refreshing"
            :readonly="readonly"
            :require-password-reconfirm="false"
            :show-header="false"
            @upload="uploadCredential"
            @remove="removeCredential"
            @refresh-integration="refreshIntegration"
          />
        </template>
        <template #agendas-body>
          <SettingsTenantSchedulesSection
            :policies="policies"
            :loading="loading"
            :saving-key="savingScheduleKey"
            :readonly="readonly"
            :show-header="false"
            @save="saveSchedule"
          />
        </template>
      </ShellPanelAccordion>

      <SettingsDteCanaryTenantCard />
    </div>
  </div>
</template>
