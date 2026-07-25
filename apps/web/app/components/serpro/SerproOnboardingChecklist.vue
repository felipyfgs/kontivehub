<script setup lang="ts">
/**
 * Checklist tenant: ambiente → autor → cert/Termo → token → procuração → cliente/operação.
 * Arquétipo: cards subtle em settings (members).
 */
import type { OfficeRole, OfficeSerproAuthorization, SerproPlatformHealth } from '~/types/api'
import { buildSerproOnboardingChecklist, primaryNextActions } from '~/utils/serpro-checklist'

const props = withDefaults(defineProps<{
  auth: OfficeSerproAuthorization | null
  health?: SerproPlatformHealth | null
  hasActiveProxyPower?: boolean
  hasClientOperationReady?: boolean
  role?: OfficeRole | null
  compact?: boolean
}>(), {
  hasActiveProxyPower: false,
  hasClientOperationReady: false,
  compact: false
})

const steps = computed(() => buildSerproOnboardingChecklist({
  auth: props.auth,
  health: props.health,
  hasActiveProxyPower: props.hasActiveProxyPower,
  hasClientOperationReady: props.hasClientOperationReady,
  role: props.role
}))

const nextActions = computed(() => primaryNextActions(steps.value, props.role))

function statusColor(status: string): 'success' | 'warning' | 'error' | 'neutral' | 'info' {
  switch (status) {
    case 'done': return 'success'
    case 'current': return 'warning'
    case 'blocked': return 'error'
    case 'skipped': return 'neutral'
    default: return 'neutral'
  }
}

function statusIcon(status: string): string {
  switch (status) {
    case 'done': return 'i-lucide-check-circle-2'
    case 'current': return 'i-lucide-circle-dot'
    case 'blocked': return 'i-lucide-lock'
    default: return 'i-lucide-circle'
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case 'done': return 'Concluído'
    case 'current': return 'Agora'
    case 'blocked': return 'Bloqueado'
    case 'skipped': return 'Ignorado'
    default: return 'Pendente'
  }
}
</script>

<template>
  <div data-testid="serpro-onboarding-checklist" class="space-y-4">
    <ol class="space-y-3">
      <li
        v-for="(step, index) in steps"
        :key="step.id"
        class="rounded-lg border border-default bg-elevated/30 p-3 sm:p-4"
        :data-testid="`serpro-checklist-step-${step.id}`"
        :data-status="step.status"
      >
        <div class="flex flex-wrap items-start gap-3">
          <div
            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-elevated text-sm font-medium text-muted"
            aria-hidden="true"
          >
            {{ index + 1 }}
          </div>
          <div class="min-w-0 flex-1 space-y-1">
            <div class="flex flex-wrap items-center gap-2">
              <p class="font-medium text-highlighted">
                {{ step.label }}
              </p>
              <UBadge
                :color="statusColor(step.status)"
                variant="subtle"
                size="xs"
              >
                <UIcon
                  :name="statusIcon(step.status)"
                  class="mr-1 size-3"
                  aria-hidden="true"
                />
                {{ statusLabel(step.status) }}
              </UBadge>
            </div>
            <p
              v-if="!compact"
              class="text-sm text-muted"
            >
              {{ step.description }}
            </p>
            <ul
              v-if="step.reasons.length && step.status !== 'done'"
              class="mt-1 list-disc space-y-0.5 ps-4 text-xs text-muted"
            >
              <li
                v-for="(reason, i) in step.reasons"
                :key="i"
              >
                {{ reason }}
              </li>
            </ul>
          </div>
          <UButton
            v-if="step.href && step.status !== 'done'"
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-arrow-up-right"
            :to="step.href"
            :label="compact ? undefined : 'Abrir'"
            :aria-label="`Abrir passo ${step.label}`"
          />
        </div>
      </li>
    </ol>

    <div
      v-if="nextActions.length"
      class="rounded-lg border border-warning/30 bg-warning/5 p-3"
      data-testid="serpro-checklist-next-actions"
    >
      <p class="mb-2 text-sm font-medium text-highlighted">
        Próximas ações
      </p>
      <div class="flex flex-wrap gap-2">
        <UButton
          v-for="action in nextActions"
          :key="action.code"
          size="sm"
          :color="action.severity === 'error' ? 'error' : action.severity === 'warning' ? 'warning' : 'primary'"
          variant="soft"
          :to="action.href"
          :label="action.label"
          :icon="action.requires_2fa ? 'i-lucide-shield' : 'i-lucide-chevron-right'"
        />
      </div>
    </div>
  </div>
</template>
