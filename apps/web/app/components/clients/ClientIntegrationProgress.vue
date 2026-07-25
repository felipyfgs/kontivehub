<script setup lang="ts">
/**
 * Progresso heurístico de integração do cliente (sem API dedicada).
 */
import type { Client, ClientCredential } from '~/types/api'

const props = defineProps<{
  client: Client
  credential: ClientCredential | null
}>()

type Step = {
  id: string
  label: string
  weight: number
  done: boolean
}

const steps = computed((): Step[] => {
  const contacts = props.client.contacts || []
  const hasContact = contacts.length > 0
  const certStatus = props.credential?.status
    || props.client.credential_summary?.status
    || 'missing'
  const hasCert = certStatus === 'active'
  const hasProcuracao = props.client.procuracao_status === 'authorized'
    || props.client.procuracao_status === 'expiring'
  const primary = props.client.establishments?.find(e => e.is_matrix)
    || props.client.establishments?.[0]
  const hasCapture = Boolean(primary?.capture_enabled)
  const hasNotes = Boolean(props.client.notes?.trim())

  return [
    { id: 'contato', label: 'Contato adicionado', weight: 20, done: hasContact },
    { id: 'certificado', label: 'Certificado digital ativo', weight: 25, done: hasCert },
    { id: 'procuracao', label: 'Procuração e-CAC', weight: 20, done: hasProcuracao },
    { id: 'captura', label: 'Captura de documentos', weight: 20, done: hasCapture },
    { id: 'observacoes', label: 'Observações preenchidas', weight: 15, done: hasNotes }
  ]
})

const progress = computed(() =>
  steps.value.filter(s => s.done).reduce((sum, s) => sum + s.weight, 0)
)

const completedSteps = computed(() => steps.value.filter(s => s.done))
</script>

<template>
  <UCard
    variant="subtle"
    :ui="{ body: 'space-y-3 p-4 sm:p-4' }"
    data-testid="client-integration-progress"
  >
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-sm font-semibold text-highlighted">
        Progresso de Integração
      </h2>
      <span class="text-sm font-semibold text-primary">
        {{ progress }}%
      </span>
    </div>

    <UProgress
      :model-value="progress"
      color="primary"
      size="md"
      :aria-label="`Progresso de integração: ${progress}%`"
    />

    <ul
      v-if="completedSteps.length"
      class="space-y-1.5"
    >
      <li
        v-for="step in completedSteps"
        :key="step.id"
        class="flex items-center justify-between gap-2 text-sm text-muted"
      >
        <span class="flex min-w-0 items-center gap-1.5">
          <UIcon
            name="i-lucide-check"
            class="size-3.5 shrink-0 text-primary"
          />
          <span class="truncate">{{ step.label }}</span>
        </span>
        <span class="shrink-0 text-xs text-primary">
          +{{ step.weight }}%
        </span>
      </li>
    </ul>
    <p
      v-else
      class="text-sm text-muted"
    >
      Complete os passos abaixo para avançar a integração.
    </p>

    <ul
      v-if="steps.some(s => !s.done)"
      class="space-y-1 border-t border-default pt-2"
    >
      <li
        v-for="step in steps.filter(s => !s.done)"
        :key="step.id"
        class="flex items-center justify-between gap-2 text-sm text-muted"
      >
        <span class="truncate">{{ step.label }}</span>
        <span class="shrink-0 text-xs">
          +{{ step.weight }}%
        </span>
      </li>
    </ul>
  </UCard>
</template>
