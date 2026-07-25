<script setup lang="ts">
/**
 * Configuração do escritório (contador).
 * Superfície mínima: perfil · certificado A1 (aceite no modal) · agendas.
 * SERPRO (Termo/token/procurações) roda automático após o upload — sem onboarding visível.
 */
import type { AccordionItem } from '@nuxt/ui'
import type {
  OfficeCanonicalCredential,
  OfficeInstitutionalProfile,
  OfficeMonitorSchedulePolicy
} from '~/types/api'
import { actionableOfficeError } from '~/utils/office-settings'

const api = useApi()
const toast = useToast()
const { sessionEpoch, canManageCredentials } = useDashboard()

const loading = ref(true)
const saving = ref(false)
const refreshing = ref(false)
const savingScheduleKey = ref<string | null>(null)
const loadError = ref<string | null>(null)

const profile = ref<OfficeInstitutionalProfile | null>(null)
const credential = ref<OfficeCanonicalCredential | null>(null)
const policies = ref<OfficeMonitorSchedulePolicy[]>([])

const readonly = computed(() => !canManageCredentials.value)

const officeAccordionItems: AccordionItem[] = [
  {
    label: 'Perfil',
    icon: 'i-lucide-building-2',
    value: 'perfil',
    slot: 'perfil'
  },
  {
    label: 'Certificado A1',
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
      api.office.profile.show(),
      api.office.canonicalCredential.show(),
      api.office.monitorSchedules.list()
    ])
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return

    if (profileRes.status === 'fulfilled') {
      profile.value = profileRes.value.data
    } else if (isNotFound(profileRes.reason)) {
      try {
        const legacy = await api.officeFiscal.get()
        if (seq !== loadSeq || epoch !== sessionEpoch.value) return
        const id = legacy.data.identity
        profile.value = {
          cnpj: id?.cnpj != null ? String(id.cnpj) : null,
          legal_name: id?.legal_name != null ? String(id.legal_name) : null,
          institutional_email: id?.institutional_email != null ? String(id.institutional_email) : null,
          institutional_phone: id?.institutional_phone != null ? String(id.institutional_phone) : null
        }
        const cred = legacy.data.credential
        if (cred && !credential.value) {
          credential.value = {
            id: cred.id != null ? Number(cred.id) : null,
            status: String(cred.status || 'ACTIVE'),
            subject_name: cred.subject_name != null ? String(cred.subject_name) : null,
            holder_cnpj: cred.holder_cnpj != null ? String(cred.holder_cnpj) : (profile.value.cnpj),
            fingerprint_sha256: cred.fingerprint_sha256 != null ? String(cred.fingerprint_sha256) : null,
            valid_to: cred.valid_to != null ? String(cred.valid_to) : null,
            expires_alert_30: Boolean(cred.expires_alert_30),
            expires_alert_7: Boolean(cred.expires_alert_7),
            expires_alert_1: Boolean(cred.expires_alert_1)
          }
        }
      } catch {
        profile.value = null
      }
    } else {
      profile.value = null
      loadError.value = actionableOfficeError(
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
    loadError.value = actionableOfficeError(
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
    try {
      const res = await api.office.profile.update(payload)
      profile.value = res.data
    } catch (err) {
      if (!isNotFound(err)) throw err
      const res = await api.officeFiscal.upsertIdentity({
        cnpj: payload.cnpj,
        legal_name: payload.legal_name
      })
      profile.value = {
        cnpj: res.data.cnpj != null ? String(res.data.cnpj) : payload.cnpj,
        legal_name: res.data.legal_name != null ? String(res.data.legal_name) : payload.legal_name,
        institutional_email: payload.institutional_email,
        institutional_phone: payload.institutional_phone
      }
    }
    toast.add({ title: 'Perfil salvo', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({
      title: actionableOfficeError(apiErrorMessage(caught, 'Falha ao salvar perfil.')),
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
      title: 'Cadastre o CNPJ no perfil institucional antes de enviar o certificado A1.',
      color: 'warning'
    })
    return
  }

  saving.value = true
  try {
    try {
      const res = credential.value
        ? await api.office.canonicalCredential.replace(payload.file, payload.password, {
            consent_accepted: payload.consent_accepted
          })
        : await api.office.canonicalCredential.upload(payload.file, payload.password, {
            consent_accepted: payload.consent_accepted
          })
      credential.value = res.data
    } catch (err) {
      if (!isNotFound(err)) throw err
      await api.officeFiscal.uploadCredential(payload.file, payload.password)
      await load()
      toast.add({ title: 'Certificado A1 armazenado', color: 'success' })
      return
    }
    toast.add({
      title: 'Certificado ativo',
      description: 'O escritório fica pronto automaticamente — sem etapas extras.',
      color: 'success'
    })
    await load()
  } catch (caught) {
    toast.add({
      title: actionableOfficeError(apiErrorMessage(caught, 'Falha ao enviar A1.')),
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}

async function removeCredential(_payload: { reconfirm_password?: string } = {}) {
  saving.value = true
  try {
    await api.office.canonicalCredential.remove({ confirm: true })
    credential.value = null
    toast.add({ title: 'Certificado removido', color: 'warning' })
    await load()
  } catch (caught) {
    toast.add({
      title: actionableOfficeError(apiErrorMessage(caught, 'Falha ao remover A1.')),
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}

async function refreshIntegration() {
  refreshing.value = true
  try {
    const res = await api.office.canonicalCredential.refreshIntegration()
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
      title: actionableOfficeError(apiErrorMessage(caught, 'Falha ao atualizar a integração.')),
      color: 'error'
    })
  } finally {
    refreshing.value = false
  }
}

async function saveSchedule(payload: { monitor_key: string, day_of_month: number }) {
  savingScheduleKey.value = payload.monitor_key
  try {
    const res = await api.office.monitorSchedules.update(payload.monitor_key, {
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
        : actionableOfficeError(apiErrorMessage(caught, 'Falha ao salvar agenda.')),
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
  <div data-testid="settings-office-unified">
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
        :items="officeAccordionItems"
        multiple
        :default-open="['perfil', 'certificado']"
        test-id="settings-office-accordion"
      >
        <template #perfil-body>
          <SettingsOfficeProfileSection
            :profile="profile"
            :loading="loading"
            :saving="saving"
            :readonly="readonly"
            :show-header="false"
            @save="saveProfile"
          />
        </template>
        <template #certificado-body>
          <SettingsOfficeCredentialSection
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
          <SettingsOfficeSchedulesSection
            :policies="policies"
            :loading="loading"
            :saving-key="savingScheduleKey"
            :readonly="readonly"
            :show-header="false"
            @save="saveSchedule"
          />
        </template>
      </ShellPanelAccordion>

      <SettingsDteCanaryOfficeCard />
    </div>
  </div>
</template>
