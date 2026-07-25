<script setup lang="ts">
import type { MonitoringCoverageAction } from '~/types/fiscal-modules'

const props = withDefaults(defineProps<{
  surfaceKeys?: readonly string[]
  title?: string
  /** Exibe as superfícies do contrato inteiro (painel central). */
  allSurfaces?: boolean
  compact?: boolean
}>(), {
  surfaceKeys: () => [],
  title: undefined,
  allSurfaces: false,
  compact: false
})

const {
  contract,
  error,
  refresh,
  status,
  surfaces
} = useMonitoringWorkspace({
  allSurfaces: () => props.allSurfaces,
  surfaceKeys: () => props.surfaceKeys
})

const loading = computed(() => status.value === 'pending')
const resolvedTitle = computed(() => props.title
  || (props.allSurfaces ? 'Cobertura do monitor fiscal' : 'Cobertura desta página'))
const capabilityCount = computed(() => surfaces.value.reduce(
  (total, surface) => total + surface.capabilities.length,
  0
))
const actionCount = computed(() => surfaces.value.reduce(
  (total, surface) => total + surface.capabilities.reduce(
    (subtotal, capability) => subtotal + capability.actions.length,
    0
  ),
  0
))

function resultKindLabel(kind: string): string {
  switch (kind) {
    case 'STRUCTURED': return 'Dados estruturados'
    case 'PDF': return 'Documento existente'
    case 'ASYNC_PDF': return 'Documento após processamento'
    case 'AGGREGATE': return 'Visão agregada'
    case 'UNAVAILABLE': return 'Indisponível'
    default: return 'Resultado não informado'
  }
}

function actionAvailability(action: MonitoringCoverageAction) {
  if (action.operation_class !== 'READ') {
    return { color: 'neutral' as const, label: 'Fora do workspace consultivo' }
  }
  if (!action.available) {
    return { color: 'warning' as const, label: 'Consulta ainda indisponível' }
  }
  return { color: 'success' as const, label: 'Consulta disponível' }
}

function onRefresh() {
  void refresh()
}
</script>

<template>
  <UCard
    data-testid="serpro-coverage-panel"
    :ui="compact ? { body: 'p-3 sm:p-4', header: 'p-3 sm:px-4' } : undefined"
  >
    <template #header>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="font-semibold text-highlighted">
            {{ resolvedTitle }}
          </h2>
          <p class="mt-1 text-sm text-muted">
            Fonte, resultados previstos e limitações do contrato canônico.
          </p>
        </div>
        <div class="flex items-center gap-2">
          <UBadge
            v-if="contract"
            color="neutral"
            variant="soft"
            :label="`${surfaces.length} telas · ${capabilityCount} capacidades · ${actionCount} consultas`"
          />
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-refresh-cw"
            square
            aria-label="Atualizar contrato de cobertura"
            :loading="loading"
            @click="onRefresh"
          />
        </div>
      </div>
    </template>

    <UAlert
      v-if="error && !contract"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-x"
      :title="error"
    >
      <template #actions>
        <UButton
          size="xs"
          color="neutral"
          variant="outline"
          label="Tentar de novo"
          @click="onRefresh"
        />
      </template>
    </UAlert>

    <div
      v-else-if="loading && !contract"
      class="space-y-3"
      role="status"
      aria-label="Carregando cobertura do monitor"
    >
      <USkeleton class="h-16 w-full" />
      <USkeleton class="h-16 w-full" />
    </div>

    <template v-else-if="contract">
      <UAlert
        v-if="allSurfaces"
        color="warning"
        variant="subtle"
        icon="i-lucide-flask-conical"
        title="Trial valida transporte e schema, não a situação fiscal"
        :description="contract.truth_note"
        class="mb-4"
      />

      <UAlert
        v-if="error"
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="A atualização do contrato falhou; a última versão válida foi preservada."
        :description="error"
        class="mb-4"
      />

      <MonitoringTableEmptyState
        v-if="!surfaces.length"
        kind="unsupported"
        title="Cobertura não anunciada"
        description="A rota atual não está presente no contrato canônico. Nenhuma ação foi habilitada."
      />

      <div v-else class="divide-y divide-default">
        <details
          v-for="surface in surfaces"
          :key="surface.surface_key"
          class="group py-3"
          :open="surfaces.length === 1 || undefined"
        >
          <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            <div class="min-w-0">
              <p class="font-medium text-highlighted">
                {{ surface.source_label }}
              </p>
              <p class="text-xs text-muted">
                {{ surface.responsibility }}
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <UBadge color="neutral" variant="soft" :label="surface.channel_label" />
              <UBadge color="neutral" variant="outline" :label="resultKindLabel(surface.result_kind)" />
              <UIcon
                name="i-lucide-chevron-down"
                class="size-4 text-muted transition-transform group-open:rotate-180"
              />
            </div>
          </summary>

          <div class="mt-3 space-y-3">
            <UAlert
              v-if="!surface.allows_document"
              color="neutral"
              variant="subtle"
              icon="i-lucide-file-x-2"
              title="Esta superfície não publica documento"
              description="A ausência de evidência não gera botão de download nem conclusão fiscal."
            />

            <div
              v-for="capability in surface.capabilities"
              :key="`${surface.surface_key}-${capability.capability_key}`"
              class="rounded-lg border border-default p-3"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-medium text-highlighted">
                  {{ capability.label }}
                </p>
                <UBadge
                  color="neutral"
                  variant="soft"
                  :label="`${capability.available_actions}/${capability.actions_total} disponíveis`"
                />
              </div>

              <ul class="mt-2 divide-y divide-default">
                <li
                  v-for="action in capability.actions"
                  :key="action.action_key"
                  class="flex flex-wrap items-start justify-between gap-2 py-2"
                >
                  <div class="min-w-0 flex-1">
                    <p class="text-sm text-highlighted">
                      {{ action.label }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted">
                      Resultado: {{ resultKindLabel(action.result_kind) }} ·
                      {{ monitoringCoverageOutputLabel(action) }}
                    </p>
                  </div>
                  <UBadge
                    :color="actionAvailability(action).color"
                    variant="subtle"
                    :label="actionAvailability(action).label"
                  />
                </li>
              </ul>
            </div>
          </div>
        </details>
      </div>

      <p class="mt-4 text-xs text-muted">
        Catálogo {{ contract.manifest_version }} · verificado em {{ formatDateTime(contract.verified_at) }}
      </p>
    </template>
  </UCard>
</template>
