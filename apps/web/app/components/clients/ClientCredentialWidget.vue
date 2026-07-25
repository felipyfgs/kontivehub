<script setup lang="ts">
/**
 * Widget compacto: Certificado A1 | Procuração e-CAC.
 */
import type { Client, ClientCredential } from '~/types/api'
import { formatDate } from '~/utils/format'
import { procuracaoLabel, procuracaoTone } from '~/utils/procuracao'

const props = defineProps<{
  client: Client
  credential: ClientCredential | null
  canManageCredentials: boolean
}>()

const emit = defineEmits<{
  manage: []
}>()

const activeTab = ref<'certificado' | 'procuracao'>('certificado')

const tabItems = [
  { label: 'Certificado', value: 'certificado' },
  { label: 'Procuração e-CAC', value: 'procuracao' }
]

const certStatus = computed(() =>
  props.credential?.status
  || props.client.credential_summary?.status
  || 'missing'
)

const validTo = computed(() =>
  props.credential?.valid_to
  || props.client.credential_summary?.valid_to
  || null
)

const certLabel = computed(() => {
  switch (certStatus.value) {
    case 'active': return 'Ativo'
    case 'expired': return 'Expirado'
    case 'revoked': return 'Revogado'
    default: return 'Não cadastrado'
  }
})

const certColor = computed(() => {
  switch (certStatus.value) {
    case 'active': return 'success' as const
    case 'expired':
    case 'revoked': return 'error' as const
    default: return 'neutral' as const
  }
})

const validityBadge = computed(() => {
  if (!validTo.value) return null
  return `Válido até ${formatDate(validTo.value)}`
})
</script>

<template>
  <UCard
    variant="subtle"
    :ui="{ body: 'space-y-3 p-4 sm:p-4' }"
    data-testid="client-credential-widget"
  >
    <div class="flex items-start justify-between gap-2">
      <UTabs
        v-model="activeTab"
        :items="tabItems"
        :content="false"
        size="xs"
        color="neutral"
        variant="pill"
        class="min-w-0 flex-1"
      />
      <UBadge
        v-if="activeTab === 'certificado' && validityBadge && certStatus === 'active'"
        color="success"
        variant="subtle"
        size="sm"
        class="shrink-0"
      >
        {{ validityBadge }}
      </UBadge>
    </div>

    <div
      v-if="activeTab === 'certificado'"
      class="space-y-3"
    >
      <div class="flex items-start gap-3 rounded-lg bg-default/50 p-3 ring ring-inset ring-default">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-elevated">
          <UIcon
            name="i-lucide-file-badge-2"
            class="size-4 text-muted"
          />
        </div>
        <div class="min-w-0 flex-1 space-y-1">
          <p class="text-sm font-medium text-highlighted">
            Certificado Digital
          </p>
          <p class="text-xs text-muted">
            <template v-if="validTo">
              Validade: {{ formatDate(validTo) }}
            </template>
            <template v-else>
              {{ certLabel }}
            </template>
          </p>
          <UBadge
            :color="certColor"
            variant="subtle"
            size="sm"
          >
            {{ certLabel }}
          </UBadge>
        </div>
      </div>
      <UButton
        v-if="canManageCredentials"
        block
        size="sm"
        color="neutral"
        variant="soft"
        icon="i-lucide-settings-2"
        label="Gerenciar certificado"
        data-testid="client-credential-widget-manage"
        @click="emit('manage')"
      />
    </div>

    <div
      v-else
      class="space-y-3"
    >
      <div class="flex items-start gap-3 rounded-lg bg-default/50 p-3 ring ring-inset ring-default">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-elevated">
          <UIcon
            name="i-lucide-file-key-2"
            class="size-4 text-muted"
          />
        </div>
        <div class="min-w-0 flex-1 space-y-1">
          <p class="text-sm font-medium text-highlighted">
            Procuração e-CAC
          </p>
          <p
            v-if="client.procuracao_valid_to"
            class="text-xs text-muted"
          >
            Validade: {{ formatDate(client.procuracao_valid_to) }}
          </p>
          <ClientsClientProcuracaoBadge
            :status="client.procuracao_status"
            :valid-to="client.procuracao_valid_to"
            :checked-at="client.procuracao_checked_at"
          />
        </div>
      </div>
      <UBadge
        :color="procuracaoTone(client.procuracao_status)"
        variant="subtle"
        size="sm"
      >
        {{ procuracaoLabel(client.procuracao_status) }}
      </UBadge>
    </div>
  </UCard>
</template>
