<script setup lang="ts">
/**
 * Sincronização do único CNPJ do cliente (1 cliente = 1 estabelecimento).
 */
import type { ClientCredential, ClientCredentialSummary, Establishment } from '~/types/api'

const props = defineProps<{
  establishments: Establishment[]
  credential: ClientCredential | null
  /** Summary do show — evita bloquear OPERATOR por falta do detalhe ADMIN. */
  credentialSummary?: ClientCredentialSummary | null
  canTriggerSync: boolean
  canManageCredentials: boolean
  triggeringId: number | null
  triggeredIds: number[]
}>()

const emit = defineEmits<{
  sync: [establishment: Establishment]
}>()

const primary = computed(() => props.establishments[0] || null)
const hasCredential = computed(() => !!props.credential || !!props.credentialSummary)

function canSync(establishment: Establishment) {
  if (!props.canTriggerSync || !establishment.is_active) {
    return false
  }
  if (establishment.capture_enabled === false) {
    return false
  }
  if (establishment.capture_eligibility && !establishment.capture_eligibility.eligible) {
    return false
  }
  // ADMIN precisa do detalhe; demais papéis usam summary do show.
  if (props.canManageCredentials && !hasCredential.value) {
    return false
  }
  return true
}

function syncHint(establishment: Establishment): string | null {
  if (establishment.capture_eligibility && !establishment.capture_eligibility.eligible) {
    return establishment.capture_eligibility.reasons[0] || 'Inelegível para captura.'
  }
  if (establishment.capture_enabled === false) {
    return 'Captura desabilitada.'
  }
  if (!establishment.is_active) {
    return 'Cliente inativo para captura.'
  }
  if (props.canManageCredentials && !hasCredential.value) {
    return 'Credencial A1 necessária.'
  }
  return null
}
</script>

<template>
  <div class="space-y-4">
    <UPageCard
      variant="naked"
      title="Sincronização"
      description="A captura ADN é deste CNPJ. A primeira sincronização começa no NSU zero."
    />

    <UPageCard variant="subtle">
      <div v-if="!canTriggerSync" class="space-y-3">
        <UAlert
          color="neutral"
          variant="subtle"
          icon="i-lucide-eye"
          title="Somente leitura"
        />
      </div>

      <div v-else-if="!primary">
        <UEmpty
          icon="i-lucide-building-2"
          title="CNPJ não encontrado"
        />
      </div>

      <div v-else class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
          <p class="font-medium text-highlighted">
            {{ primary.trade_name || primary.cnpj }}
          </p>
          <p class="font-mono text-sm text-muted">
            {{ primary.cnpj }}
          </p>
          <p v-if="triggeredIds.includes(primary.id)" class="mt-1 flex items-center gap-1 text-xs text-success">
            <UIcon name="i-lucide-check" class="size-3" aria-hidden="true" />
            Sincronização já solicitada nesta sessão
          </p>
          <p v-else-if="syncHint(primary)" class="mt-1 text-xs text-warning">
            {{ syncHint(primary) }}
          </p>
        </div>
        <UButton
          color="primary"
          icon="i-lucide-refresh-cw"
          label="Sincronizar agora"
          :loading="triggeringId === primary.id"
          :disabled="!canSync(primary)"
          @click="emit('sync', primary)"
        />
      </div>
    </UPageCard>
  </div>
</template>
