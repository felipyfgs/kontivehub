<script setup lang="ts">
/**
 * Consentimento técnico versionado — compacto quando aceito.
 */
import type { OfficeTechnicalConsent } from '~/types/api'

const props = withDefaults(defineProps<{
  consent: OfficeTechnicalConsent | null
  loading?: boolean
  saving?: boolean
  readonly?: boolean
  showHeader?: boolean
}>(), {
  loading: false,
  saving: false,
  readonly: false,
  showHeader: true
})

const emit = defineEmits<{
  accept: []
  revoke: []
}>()

const accepted = ref(false)

watch(
  () => props.consent,
  (c) => {
    accepted.value = Boolean(c?.accepted) && !c?.requires_reacceptance
  },
  { immediate: true }
)

const purposes = computed(() => props.consent?.purposes || [
  { code: 'SERPRO_TERM_SIGNING', label: 'Assinatura do Termo de autorização (automatizada)' },
  { code: 'NFE_AUTXML_DISTDFE', label: 'autXML DistDFe (NFe/CTe) do escritório' }
])

const needsAction = computed(() =>
  !props.consent?.accepted || props.consent?.requires_reacceptance
)
</script>

<template>
  <div data-testid="settings-consent-section">
    <ShellSectionHeader
      v-if="showHeader"
      title="Consentimento"
    />

    <ShellSectionCard>
      <div
        v-if="loading && !consent"
        class="space-y-2"
        role="status"
        aria-label="Carregando consentimento"
      >
        <USkeleton class="h-4 w-1/2" />
        <USkeleton class="h-4 w-2/3" />
      </div>
      <div
        v-else
        class="space-y-4"
      >
        <!-- Aceito: badge compacto (sem UAlert de sucesso) -->
        <div
          v-if="!needsAction && consent?.accepted"
          class="flex flex-wrap items-center gap-2"
          data-testid="settings-consent-accepted"
        >
          <UBadge
            color="success"
            variant="subtle"
          >
            Aceito · v{{ consent.version }}
          </UBadge>
          <span
            v-if="consent.accepted_at"
            class="text-xs text-muted"
          >
            {{ formatDateTime(consent.accepted_at) }}
            <template v-if="consent.accepted_by_name"> · {{ consent.accepted_by_name }}</template>
          </span>
        </div>

        <template v-if="needsAction">
          <ul class="space-y-1 text-sm text-muted">
            <li
              v-for="p in purposes"
              :key="p.code"
            >
              {{ p.label }}
            </li>
          </ul>

          <UCheckbox
            v-if="!readonly"
            v-model="accepted"
            label="Autorizo o uso do certificado A1 nestas finalidades."
            data-testid="settings-consent-checkbox"
          />
        </template>

        <div
          v-if="!readonly"
          class="flex flex-wrap justify-end gap-2 border-t border-default pt-4"
        >
          <UButton
            v-if="consent?.accepted && !consent.requires_reacceptance"
            color="neutral"
            variant="ghost"
            label="Revogar"
            icon="i-lucide-ban"
            :loading="saving"
            data-testid="settings-consent-revoke"
            @click="emit('revoke')"
          />
          <UButton
            v-if="needsAction"
            color="primary"
            label="Aceitar"
            icon="i-lucide-check"
            :loading="saving"
            :disabled="!accepted"
            data-testid="settings-consent-accept"
            @click="emit('accept')"
          />
        </div>
      </div>
    </ShellSectionCard>
  </div>
</template>
