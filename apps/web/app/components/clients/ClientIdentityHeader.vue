<script setup lang="ts">
/**
 * Header de identidade do cliente — avatar, nome, CNPJ, regime e ações.
 */
import type { Client } from '~/types/api'
import { formatCnpj } from '~/utils/format'

const props = defineProps<{
  client: Client
  canManageClients: boolean
}>()

const emit = defineEmits<{
  edit: []
}>()

const displayName = computed(() =>
  props.client.display_name
  || props.client.legal_name
  || props.client.name
  || 'Cliente'
)

const initials = computed(() => {
  const parts = displayName.value
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  if (!parts.length) return '?'
  if (parts.length === 1) return parts[0]!.slice(0, 2).toUpperCase()
  return `${parts[0]![0] || ''}${parts[1]![0] || ''}`.toUpperCase()
})

const cnpjLabel = computed(() => {
  const raw = props.client.cnpj
    || props.client.establishments?.find(e => e.is_matrix)?.cnpj
    || props.client.establishments?.[0]?.cnpj
    || props.client.root_cnpj
  return raw ? formatCnpj(raw) : '—'
})

const regimeLabel = computed(() =>
  props.client.tax_regime_label || props.client.tax_regime || null
)
</script>

<template>
  <div
    class="flex flex-col gap-4 rounded-xl border border-default bg-elevated/40 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5"
    data-testid="client-identity-header"
  >
    <div class="flex min-w-0 items-start gap-3 sm:gap-4">
      <div
        class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-elevated text-sm font-semibold text-highlighted ring ring-inset ring-default sm:size-14 sm:text-base"
        aria-hidden="true"
      >
        {{ initials }}
      </div>
      <div class="min-w-0 space-y-1.5">
        <h1 class="text-base font-semibold text-highlighted sm:text-lg">
          {{ displayName }}
        </h1>
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-mono text-sm text-muted">
            {{ cnpjLabel }}
          </span>
          <UBadge
            v-if="regimeLabel"
            color="success"
            variant="subtle"
            size="sm"
          >
            {{ regimeLabel }}
          </UBadge>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
      <UButton
        v-if="canManageClients"
        color="neutral"
        variant="outline"
        icon="i-lucide-pencil"
        label="Editar cliente"
        class="hidden sm:inline-flex"
        data-testid="client-page-edit"
        @click="emit('edit')"
      />
      <UButton
        v-if="canManageClients"
        color="neutral"
        variant="outline"
        icon="i-lucide-pencil"
        square
        class="sm:hidden"
        aria-label="Editar cliente"
        data-testid="client-page-edit-mobile"
        @click="emit('edit')"
      />
      <UButton
        color="neutral"
        variant="solid"
        icon="i-lucide-shield-check"
        label="Checkup360"
        disabled
        class="hidden sm:inline-flex"
        data-testid="client-page-checkup360"
      >
        <template #trailing>
          <UBadge
            color="neutral"
            variant="subtle"
            size="sm"
            label="Em breve"
          />
        </template>
      </UButton>
      <UButton
        color="primary"
        icon="i-lucide-key-round"
        label="Banco de acessos"
        disabled
        class="hidden sm:inline-flex"
        data-testid="client-page-access-bank"
      >
        <template #trailing>
          <UBadge
            color="neutral"
            variant="subtle"
            size="sm"
            label="Em breve"
          />
        </template>
      </UButton>
    </div>
  </div>
</template>
