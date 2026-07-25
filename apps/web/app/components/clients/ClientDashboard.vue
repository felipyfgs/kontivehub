<script setup lang="ts">
/**
 * Aba Resumo: overview leve (status + próximos passos).
 * Sem tiles de atalho que duplicam a SectionNavigation.
 */
import type { Client, ClientCredential, Establishment } from '~/types/api'
import {
  formatSourceDate,
  registrationSourceLabel,
  registrationStatusColor,
  registrationStatusIcon,
  registrationStatusLabel
} from '~/utils/registration-labels'

const props = defineProps<{
  client: Client
  credential: ClientCredential | null
  establishments: Establishment[]
  triggeredIds: number[]
  canManageCredentials: boolean
  canManageClients?: boolean
  canTriggerSync?: boolean
  inModal?: boolean
}>()

const emit = defineEmits<{
  navigateSection: [section: 'resumo' | 'cadastro' | 'estabelecimentos' | 'certificado' | 'sincronizacao']
}>()

const primary = computed(() => props.establishments[0] || null)
const branches = computed(() => props.client.branches || [])
const fullCnpj = computed(() => props.client.cnpj || primary.value?.cnpj || props.client.root_cnpj)

const hasCredential = computed(() => !!props.credential || !!props.client.credential_summary)
const hasContacts = computed(() => (props.client.contacts?.length || 0) > 0)
const hasCnpj = computed(() => !!primary.value || !!props.client.cnpj)

const captureReady = computed(() => {
  const e = primary.value
  if (!e) return false
  return e.is_active
    && e.capture_enabled !== false
    && (e.capture_eligibility?.eligible !== false)
})

const certColor = computed((): 'success' | 'warning' | 'error' | 'neutral' => {
  const c = props.credential
  const s = props.client.credential_summary
  if (!c && !s) return 'neutral'
  if (c) {
    if (c.expires_alert_1 || c.status === 'EXPIRED') return 'error'
    if (c.expires_alert_7 || c.expires_alert_30) return 'warning'
    return 'success'
  }
  if (s!.expires_alert_1 || s!.status === 'EXPIRED') return 'error'
  if (s!.expires_alert_7 || s!.expires_alert_30) return 'warning'
  return 'success'
})

const certLabel = computed(() => {
  const c = props.credential
  const s = props.client.credential_summary
  if (!c && !s) return 'Sem certificado'
  const validTo = c?.valid_to || s?.valid_to
  if (validTo) return `Até ${formatDateTime(validTo)}`
  return c?.status || s?.status || 'Ativo'
})

const certCritical = computed(() => {
  const c = props.credential
  const s = props.client.credential_summary
  if (!c && !s) return false
  if (c) return !!(c.expires_alert_1 || c.status === 'EXPIRED')
  return !!(s!.expires_alert_1 || s!.status === 'EXPIRED')
})

function go(section: 'cadastro' | 'estabelecimentos' | 'certificado' | 'sincronizacao') {
  if (props.inModal) {
    emit('navigateSection', section)
    return
  }
  navigateTo(clientSectionPath(props.client.id, section))
}

type NextStep = {
  key: string
  label: string
  description: string
  section: 'cadastro' | 'estabelecimentos' | 'certificado' | 'sincronizacao'
  actionLabel: string
}

const nextSteps = computed((): NextStep[] => {
  const steps: NextStep[] = []
  if (!hasCnpj.value) {
    steps.push({
      key: 'cnpj',
      label: 'CNPJ do estabelecimento',
      description: 'Complete o vínculo do estabelecimento para liberar captura.',
      section: 'estabelecimentos',
      actionLabel: 'Abrir estabelecimentos'
    })
  }
  if (!hasCredential.value) {
    steps.push({
      key: 'a1',
      label: 'Certificado A1',
      description: 'Ative o e-CNPJ A1 para captura ADN e integrações fiscais.',
      section: 'certificado',
      actionLabel: 'Ir ao certificado'
    })
  }
  if (!hasContacts.value) {
    steps.push({
      key: 'contact',
      label: 'Contato interno',
      description: 'Cadastre ao menos um contato operacional do cliente.',
      section: 'cadastro',
      actionLabel: 'Abrir cadastro'
    })
  }
  if (hasCredential.value && !captureReady.value) {
    steps.push({
      key: 'capture',
      label: 'Captura ADN',
      description: 'Revise elegibilidade e sincronização do estabelecimento.',
      section: 'sincronizacao',
      actionLabel: 'Abrir sincronização'
    })
  }
  return steps
})

const statusItems = computed(() => [
  {
    key: 'a1',
    label: 'Certificado A1',
    value: hasCredential.value ? certLabel.value : 'Pendente',
    icon: 'i-lucide-badge-check',
    color: certColor.value
  },
  {
    key: 'capture',
    label: 'Captura ADN',
    value: captureReady.value ? 'Elegível' : 'Bloqueada',
    icon: captureReady.value ? 'i-lucide-radio' : 'i-lucide-radio-off',
    color: (captureReady.value ? 'success' : 'warning') as 'success' | 'warning'
  },
  {
    key: 'branches',
    label: 'Filiais vinculadas',
    value: String(branches.value.length),
    icon: 'i-lucide-git-branch',
    color: 'primary' as const
  }
])
</script>

<template>
  <div class="space-y-4" data-testid="client-dashboard">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
      <UPageCard
        v-for="item in statusItems"
        :key="item.key"
        variant="subtle"
        class="min-w-0"
      >
        <div class="flex items-start gap-3">
          <div
            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-elevated ring ring-inset ring-default"
          >
            <UIcon :name="item.icon" class="size-4 text-primary" />
          </div>
          <div class="min-w-0">
            <p class="text-xs text-muted">
              {{ item.label }}
            </p>
            <p class="truncate font-semibold text-highlighted">
              {{ item.value }}
            </p>
          </div>
        </div>
      </UPageCard>
    </div>

    <UPageCard
      title="Identidade"
      description="Visão rápida — edição completa na aba Cadastro."
      variant="subtle"
    >
      <dl class="space-y-3 text-sm">
        <div>
          <dt class="text-muted">
            Razão social
          </dt>
          <dd class="font-medium text-highlighted">
            {{ client.legal_name || client.name }}
          </dd>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-muted">
              CNPJ
            </dt>
            <dd class="font-mono text-highlighted">
              {{ fullCnpj }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Raiz
            </dt>
            <dd class="font-mono text-highlighted">
              {{ client.root_cnpj }}
            </dd>
          </div>
        </div>
        <div v-if="primary" class="flex flex-wrap gap-2">
          <UBadge
            :color="registrationStatusColor(primary.registration_status)"
            variant="subtle"
            :icon="registrationStatusIcon(primary.registration_status)"
          >
            {{ registrationStatusLabel(primary.registration_status) }}
          </UBadge>
          <UBadge color="neutral" variant="subtle">
            {{ registrationSourceLabel(client.registration_source) }}
          </UBadge>
          <UBadge
            v-if="client.registration_refreshed_at"
            color="neutral"
            variant="outline"
          >
            Atualizado {{ formatSourceDate(client.registration_refreshed_at) }}
          </UBadge>
        </div>
        <p
          v-if="triggeredIds.length"
          class="text-xs text-muted"
        >
          Sincronização solicitada nesta sessão.
        </p>
      </dl>
    </UPageCard>

    <UPageCard
      v-if="nextSteps.length"
      title="Próximos passos"
      description="Pendências para deixar a integração operacional."
      variant="subtle"
      :ui="{ body: 'space-y-3' }"
    >
      <div
        v-for="step in nextSteps"
        :key="step.key"
        class="flex flex-col gap-2 rounded-lg bg-elevated/50 p-3 ring ring-inset ring-default sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="min-w-0">
          <p class="font-medium text-highlighted">
            {{ step.label }}
          </p>
          <p class="text-sm text-muted">
            {{ step.description }}
          </p>
        </div>
        <UButton
          size="sm"
          color="primary"
          variant="soft"
          :label="step.actionLabel"
          class="shrink-0"
          @click="go(step.section)"
        />
      </div>
    </UPageCard>

    <UAlert
      v-if="certCritical"
      color="error"
      variant="subtle"
      icon="i-lucide-badge-alert"
      title="Certificado A1 crítico ou expirado"
      :description="certLabel"
    >
      <template #actions>
        <UButton
          size="sm"
          color="error"
          variant="soft"
          label="Gerenciar certificado"
          @click="go('certificado')"
        />
      </template>
    </UAlert>
  </div>
</template>
