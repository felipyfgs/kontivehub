<script setup lang="ts">
/**
 * Cadastro do cliente — dossiê RFB (grid + QSA / filiais / origem).
 * Somente-leitura; edição via ClientFormModal no shell da ficha (ou emit edit).
 */
import type { AccordionItem } from '@nuxt/ui'
import type { Client, CnaePayload, ShareholderPayload, StateRegistrationPayload } from '~/types/api'
import { clientDetailKey } from '~/composables/useClientDetail'
import { clientCrmHref } from '~/utils/client-cross-links'
import { formatCnpj, formatCurrency, formatDate } from '~/utils/format'
import {
  formatSourceDate,
  registrationSourceLabel,
  registrationStatusLabel
} from '~/utils/registration-labels'

const props = withDefaults(defineProps<{
  client: Client
  canManageClients: boolean
  /** Painel: dados | contatos | all (modal / legado). */
  panel?: 'dados' | 'contatos' | 'all'
}>(), {
  panel: 'all'
})

const useAccordion = computed(() => props.panel === 'all')
const showDados = computed(() => props.panel === 'all' || props.panel === 'dados')
const showContatos = computed(() => props.panel === 'contatos')

const emit = defineEmits<{
  updated: []
  edit: []
}>()

const detailCtx = inject(clientDetailKey, null)

const primaryEstablishment = computed(() =>
  props.client.establishments?.find(e => e.is_matrix)
  || props.client.establishments?.[0]
  || null
)

const shareholders = computed((): ShareholderPayload[] =>
  (primaryEstablishment.value?.shareholders || []) as ShareholderPayload[]
)

const branchRows = computed(() => {
  const branches = props.client.branches || []
  return branches.map(b => ({
    id: b.id,
    label: b.display_name || b.legal_name || b.name,
    cnpj: b.cnpj || '—'
  }))
})

const cnpjLabel = computed(() => {
  const raw = props.client.cnpj || primaryEstablishment.value?.cnpj || props.client.root_cnpj
  return raw ? formatCnpj(raw) : '—'
})

const registrationStatus = computed(() => primaryEstablishment.value?.registration_status || null)

const mainCnaeCode = computed(() => primaryEstablishment.value?.main_cnae_code || '—')
const mainCnaeName = computed(() => primaryEstablishment.value?.main_cnae_name || '—')

const secondaryCnaes = computed((): CnaePayload[] =>
  (primaryEstablishment.value?.secondary_cnaes || []) as CnaePayload[]
)

const stateRegistrations = computed((): StateRegistrationPayload[] => {
  const rows = (primaryEstablishment.value?.state_registrations || []) as StateRegistrationPayload[]
  return [...rows].sort((a, b) => {
    const aActive = a.active === true ? 0 : a.active === false ? 1 : 2
    const bActive = b.active === true ? 0 : b.active === false ? 1 : 2
    if (aActive !== bActive) return aActive - bActive
    return String(a.state || '').localeCompare(String(b.state || ''), 'pt-BR')
  })
})

const companyFields = computed(() => [
  { label: 'CNPJ', value: cnpjLabel.value },
  { label: 'Razão social', value: props.client.legal_name || props.client.name || '—' },
  { label: 'ID interno', value: String(props.client.id) },
  { label: 'Nome fantasia', value: props.client.trade_name || '—' },
  {
    label: 'Início da atividade',
    value: formatDate(primaryEstablishment.value?.activity_started_at)
  },
  {
    label: 'Situação cadastral',
    value: registrationStatusLabel(registrationStatus.value)
  },
  {
    label: 'Capital social',
    value: formatCurrency(props.client.capital_social)
  }
])

const fiscalFields = computed(() => [
  {
    label: 'Regime tributário',
    value: props.client.tax_regime_label || props.client.tax_regime || '—'
  },
  {
    label: 'Porte',
    value: props.client.company_size_name || props.client.company_size_code || '—'
  },
  {
    label: 'Natureza jurídica',
    value: props.client.legal_nature_name
      ? `${props.client.legal_nature_code || ''} ${props.client.legal_nature_name}`.trim()
      : (props.client.legal_nature_code || '—')
  }
])

const adicionaisHref = computed(() => clientCrmHref(props.client.id, 'dados-adicionais'))

const accordionItems = computed((): AccordionItem[] => [
  {
    label: 'Quadro societário',
    icon: 'i-lucide-users',
    value: 'socios',
    slot: 'socios' as const
  },
  {
    label: 'Filiais',
    icon: 'i-lucide-map-pin-house',
    value: 'filiais',
    slot: 'filiais' as const
  },
  {
    label: 'Origem dos dados',
    icon: 'i-lucide-database',
    value: 'origem',
    slot: 'origem' as const
  }
])

function onEdit() {
  if (!props.canManageClients) return
  if (detailCtx?.openClientEdit) {
    detailCtx.openClientEdit()
    return
  }
  emit('edit')
}
</script>

<template>
  <div
    class="space-y-4"
    data-testid="client-registration"
  >
    <ClientsClientContactsSection
      v-if="showContatos"
      :client="client"
      :can-manage-clients="canManageClients"
      @updated="emit('updated')"
    />

    <template v-else-if="showDados">
      <div
        v-if="canManageClients"
        class="flex flex-wrap justify-end gap-2"
      >
        <UButton
          icon="i-lucide-pencil"
          label="Editar"
          color="primary"
          variant="soft"
          data-testid="client-registration-edit"
          @click="onEdit"
        />
      </div>

      <UCard
        variant="subtle"
        :ui="{ body: 'space-y-4 p-4 sm:p-5' }"
        data-testid="client-registration-company"
      >
        <h3 class="text-sm font-semibold text-highlighted">
          Dados da empresa
        </h3>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <UFormField
            v-for="field in companyFields"
            :key="field.label"
            :label="field.label"
          >
            <UInput
              :model-value="field.value"
              readonly
              class="w-full"
            />
          </UFormField>
        </div>
      </UCard>

      <UCard
        variant="subtle"
        :ui="{ body: 'space-y-4 p-4 sm:p-5' }"
        data-testid="client-registration-fiscal"
      >
        <h3 class="text-sm font-semibold text-highlighted">
          Informações fiscais
        </h3>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <UFormField
            v-for="field in fiscalFields"
            :key="field.label"
            :label="field.label"
          >
            <UInput
              :model-value="field.value"
              readonly
              class="w-full"
            />
          </UFormField>
        </div>
      </UCard>

      <UCard
        variant="subtle"
        :ui="{ body: 'space-y-4 p-4 sm:p-5' }"
        data-testid="client-registration-activities"
      >
        <h3 class="text-sm font-semibold text-highlighted">
          Atividades
        </h3>
        <div class="space-y-3 text-sm">
          <div>
            <p class="text-muted">
              CNAE principal
            </p>
            <p class="font-medium text-highlighted">
              {{ mainCnaeCode !== '—' ? `${mainCnaeCode} — ${mainCnaeName !== '—' ? mainCnaeName : ''}` : '—' }}
            </p>
          </div>
          <div v-if="secondaryCnaes.length">
            <p class="mb-1 text-muted">
              CNAEs secundários
            </p>
            <ul
              class="max-h-40 space-y-1 overflow-y-auto"
              data-testid="client-registration-secondary-cnaes"
            >
              <li
                v-for="cnae in secondaryCnaes"
                :key="cnae.code"
                class="font-medium text-highlighted"
              >
                {{ cnae.code }} — {{ cnae.name || '' }}
              </li>
            </ul>
          </div>
          <p
            v-else
            class="text-muted"
          >
            Nenhum CNAE secundário informado.
          </p>
        </div>
      </UCard>

      <UCard
        variant="subtle"
        :ui="{ body: 'space-y-4 p-4 sm:p-5' }"
        data-testid="client-registration-state-registrations"
      >
        <h3 class="text-sm font-semibold text-highlighted">
          Inscrições estaduais
        </h3>
        <ul
          v-if="stateRegistrations.length"
          class="space-y-2 text-sm"
          data-testid="client-registration-ies"
        >
          <li
            v-for="(ie, index) in stateRegistrations"
            :key="`${ie.number}-${index}`"
            class="flex flex-wrap items-baseline gap-x-2 gap-y-1"
            :class="ie.active === true ? 'font-medium text-highlighted' : 'text-muted'"
          >
            <span>{{ ie.state || '—' }} · {{ ie.number }}</span>
            <span
              v-if="ie.active === true"
              class="text-xs text-success"
            >(ativa)</span>
            <span
              v-else-if="ie.active === false"
              class="text-xs"
            >(inativa)</span>
          </li>
        </ul>
        <p
          v-else
          class="text-sm text-muted"
        >
          Nenhuma inscrição estadual no cadastro.
        </p>
      </UCard>

      <ShellPanelAccordion
        v-if="useAccordion"
        :items="accordionItems"
        type="multiple"
        :default-value="[]"
        test-id="client-registration-accordion"
      >
        <template #socios-body>
          <div
            v-if="client.responsible_qualification_name"
            class="mb-3 rounded-lg bg-elevated/50 px-3 py-3 ring ring-inset ring-default"
          >
            <p class="text-xs font-medium text-muted">
              Qualificação do responsável
            </p>
            <p class="font-medium text-highlighted">
              {{ client.responsible_qualification_name }}
              <span
                v-if="client.responsible_qualification_code"
                class="text-sm text-muted"
              >
                ({{ client.responsible_qualification_code }})
              </span>
            </p>
          </div>
          <div
            v-if="shareholders.length"
            class="grid gap-3 sm:grid-cols-2"
          >
            <div
              v-for="(socio, index) in shareholders"
              :key="`${socio.name}-${index}`"
              class="rounded-lg bg-elevated/50 px-3 py-3 ring ring-inset ring-default"
            >
              <p class="font-medium text-highlighted">
                {{ socio.name || 'Sócio' }}
              </p>
              <p class="mt-1 text-sm text-muted">
                {{ socio.qualification_name || socio.qualification_code || socio.type || '—' }}
              </p>
              <p
                v-if="socio.document_masked"
                class="mt-1 font-mono text-xs text-muted"
              >
                {{ socio.document_masked }}
              </p>
              <p
                v-if="socio.entered_at"
                class="mt-1 text-xs text-muted"
              >
                Desde {{ socio.entered_at }}
              </p>
            </div>
          </div>
          <UEmpty
            v-else
            icon="i-lucide-users"
            title="Sem sócios no cadastro"
            description="QSA aparece após consulta ou atualização cadastral RFB."
          />
        </template>

        <template #filiais-body>
          <div
            v-if="branchRows.length"
            class="grid gap-3 sm:grid-cols-2"
          >
            <UPageCard
              v-for="branch in branchRows"
              :key="branch.id"
              :to="clientCrmHref(branch.id, 'cadastro')"
              :title="branch.label"
              :description="branch.cnpj"
              icon="i-lucide-building-2"
              variant="subtle"
            />
          </div>
          <UEmpty
            v-else
            icon="i-lucide-map-pin-house"
            title="Sem filiais vinculadas"
            description="Matriz e filiais aparecem aqui quando houver vínculo."
          />
        </template>

        <template #origem-body>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <p class="text-xs font-medium text-muted">
                Fonte
              </p>
              <p class="font-medium">
                {{ registrationSourceLabel(client.registration_source) }}
              </p>
            </div>
            <div>
              <p class="text-xs font-medium text-muted">
                Atualizado em
              </p>
              <p class="font-medium">
                {{ formatSourceDate(client.registration_refreshed_at) }}
              </p>
            </div>
          </div>
          <p class="mt-3 text-sm text-muted">
            Certificado A1 fica no painel lateral; campos extras em
            <NuxtLink
              :to="adicionaisHref"
              class="font-medium text-primary"
            >
              Dados adicionais
            </NuxtLink>.
          </p>
        </template>
      </ShellPanelAccordion>
    </template>
  </div>
</template>
