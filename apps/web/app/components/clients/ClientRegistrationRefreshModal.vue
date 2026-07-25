<script setup lang="ts">
/**
 * Revisão de atualização cadastral RFB — busca → diff → editar → confirmar.
 */
import type { Client, CnpjLookupResult } from '~/types/api'
import { formatCnpj } from '~/utils/format'
import { registrationStatusLabel } from '~/utils/registration-labels'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  client: Client | null
  lookup: CnpjLookupResult | null
  canManageClients?: boolean
  applying?: boolean
}>()

const emit = defineEmits<{
  confirm: [lookup: CnpjLookupResult]
  cancel: []
}>()

const formRef = ref<{ reset: () => void } | null>(null)

const formKey = computed(() => {
  if (!open.value || !props.lookup) return 'closed'
  return [
    props.lookup.source,
    props.lookup.source_updated_at || '',
    props.lookup.client.legal_name,
    props.client?.id || ''
  ].join('|')
})

const primaryEstablishment = computed(() =>
  props.client?.establishments?.find(e => e.is_matrix)
  || props.client?.establishments?.[0]
  || null
)

type DiffRow = { label: string, before: string, after: string }

function display(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return String(value)
}

const diffRows = computed((): DiffRow[] => {
  if (!props.client || !props.lookup) return []
  const est = primaryEstablishment.value
  const next = props.lookup
  const rows: DiffRow[] = [
    {
      label: 'Razão social',
      before: display(props.client.legal_name || props.client.name),
      after: display(next.client.legal_name)
    },
    {
      label: 'Nome fantasia',
      before: display(props.client.trade_name || est?.trade_name),
      after: display(next.establishment.trade_name)
    },
    {
      label: 'Situação cadastral',
      before: registrationStatusLabel(est?.registration_status || null),
      after: registrationStatusLabel(next.establishment.registration_status)
    },
    {
      label: 'Natureza jurídica',
      before: display(props.client.legal_nature_name || props.client.legal_nature_code),
      after: display(next.client.legal_nature_name || next.client.legal_nature_code)
    },
    {
      label: 'Porte',
      before: display(props.client.company_size_name || props.client.company_size_code),
      after: display(next.client.company_size_name || next.client.company_size_code)
    },
    {
      label: 'CNAE principal',
      before: display(est?.main_cnae_code ? `${est.main_cnae_code} — ${est.main_cnae_name || ''}` : null),
      after: display(
        next.establishment.main_cnae_code
          ? `${next.establishment.main_cnae_code} — ${next.establishment.main_cnae_name || ''}`
          : null
      )
    },
    {
      label: 'Cidade / UF',
      before: display(
        [est?.address?.city, est?.address?.state].filter(Boolean).join(' / ') || null
      ),
      after: display(
        [next.establishment.address?.city, next.establishment.address?.state].filter(Boolean).join(' / ') || null
      )
    }
  ]

  return rows.filter(row => row.before !== row.after)
})

const description = computed(() => {
  if (!props.client) return 'Revise os dados da consulta antes de gravar.'
  const cnpj = formatCnpj(
    props.client.cnpj
    || primaryEstablishment.value?.cnpj
    || props.client.root_cnpj
  )
  return `${props.client.legal_name || props.client.name || 'Cliente'} · ${cnpj}`
})

function onConfirm(lookup: CnpjLookupResult) {
  emit('confirm', lookup)
}

function onCancel() {
  open.value = false
  emit('cancel')
}
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Revisar atualização RFB"
    :description="description"
    content-class="w-[calc(100vw-1.5rem)] sm:max-w-4xl max-h-[min(92dvh,52rem)] overflow-hidden flex flex-col"
    :show-default-footer="false"
    test-id="client-registration-refresh-modal"
    @cancel="onCancel"
  >
    <template #body>
      <div
        v-if="client && lookup"
        class="flex min-h-0 flex-1 flex-col gap-4"
      >
        <UAlert
          color="info"
          variant="subtle"
          icon="i-lucide-info"
          title="Nada foi gravado ainda"
          description="Confira o que mudou, ajuste se quiser e só então aplique a atualização. Nome interno, regime e notas do escritório são preservados."
        />

        <UCard
          v-if="diffRows.length"
          variant="subtle"
          :ui="{ body: 'space-y-3 p-4 sm:p-5' }"
          data-testid="client-refresh-diff"
        >
          <h3 class="text-sm font-semibold text-highlighted">
            O que mudou ({{ diffRows.length }})
          </h3>
          <div class="space-y-2">
            <div
              v-for="row in diffRows"
              :key="row.label"
              class="grid gap-1 rounded-lg bg-elevated/50 px-3 py-2 text-sm ring ring-inset ring-default sm:grid-cols-[10rem_1fr_1fr] sm:items-start sm:gap-3"
            >
              <p class="font-medium text-highlighted">
                {{ row.label }}
              </p>
              <p class="text-muted">
                <span class="text-xs uppercase tracking-wide">Antes</span>
                <br>
                {{ row.before }}
              </p>
              <p class="text-highlighted">
                <span class="text-xs uppercase tracking-wide text-muted">Depois</span>
                <br>
                {{ row.after }}
              </p>
            </div>
          </div>
        </UCard>

        <UAlert
          v-else
          color="neutral"
          variant="subtle"
          icon="i-lucide-equal"
          title="Sem diferenças principais"
          description="Os campos essenciais coincidem com o cadastro atual. Você ainda pode revisar e aplicar o snapshot completo."
        />

        <ClientsClientForm
          :key="formKey"
          ref="formRef"
          form-id="client-registration-refresh-form"
          :client="client"
          :can-manage-clients="canManageClients === true"
          :can-manage-credentials="false"
          review-mode
          :review-lookup="lookup"
          :locked="applying"
          @confirm-refresh="onConfirm"
          @cancel="onCancel"
        />
      </div>
    </template>
  </ShellFormModal>
</template>
