<script setup lang="ts">
import type { CommunicationFlowDetail } from '~/composables/useCommunicationFlowDetail'
import { communicationFlowEditorPath } from '~/utils/communication-routes'

const { detail } = defineProps<{
  detail: CommunicationFlowDetail
}>()

const draftJson = detail.draftJson
const showAdvancedJson = detail.showAdvancedJson

function toggleAdvancedJson(): void {
  showAdvancedJson.value = !showAdvancedJson.value
}
</script>

<template>
  <section v-if="detail.flow.value">
    <ShellSectionHeader
      title="Draft do grafo"
      description="Monte o robô no editor visual. Validação e publicação usam o draft salvo no servidor."
    >
      <div class="flex flex-wrap gap-2">
        <UButton
          color="primary"
          icon="i-lucide-workflow"
          label="Abrir editor visual"
          :to="communicationFlowEditorPath(detail.flow.value.id)"
          data-testid="communication-flow-open-editor-section"
        />
        <UButton
          v-if="detail.canManage.value"
          color="neutral"
          variant="outline"
          icon="i-lucide-shield-check"
          label="Validar"
          :loading="detail.validateBusy.value"
          :disabled="Boolean(detail.mutationBlocked.value)"
          data-testid="communication-flow-validate"
          @click="detail.validateDraft"
        />
        <UButton
          v-if="detail.canManage.value"
          color="neutral"
          variant="outline"
          icon="i-lucide-save"
          label="Salvar draft JSON"
          :loading="detail.draftBusy.value"
          :disabled="Boolean(detail.mutationBlocked.value) || !detail.showAdvancedJson.value"
          data-testid="communication-flow-save-draft"
          @click="detail.saveDraft"
        />
      </div>
    </ShellSectionHeader>
    <ShellSectionCard>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm text-muted">
            Preferência: editar no canvas Vue Flow. O JSON avançado fica opcional para inspeção ou ajuste fino.
          </p>
          <p
            v-if="detail.draftDigest.value"
            class="mt-2 break-all font-mono text-xs text-muted"
            data-testid="communication-flow-draft-digest"
          >
            digest {{ detail.draftDigest.value }} · lock {{ detail.draftLockVersion.value }}
          </p>
        </div>
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          :icon="detail.showAdvancedJson.value ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          :label="detail.showAdvancedJson.value ? 'Ocultar JSON' : 'JSON avançado'"
          data-testid="communication-flow-toggle-json"
          @click="toggleAdvancedJson"
        />
      </div>
      <div
        v-if="detail.showAdvancedJson.value"
        class="mt-4"
      >
        <UFormField
          label="Grafo (JSON avançado)"
          name="graph"
        >
          <UTextarea
            v-model="draftJson"
            :rows="12"
            :disabled="Boolean(detail.mutationBlocked.value)"
            class="w-full font-mono text-xs"
            data-testid="communication-flow-draft-json"
            aria-label="Editor JSON do grafo do draft"
          />
        </UFormField>
      </div>
      <UAlert
        v-if="detail.draftError.value"
        class="mt-4"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-x"
        :title="detail.draftError.value"
      />
      <UAlert
        v-if="detail.validateMessage.value"
        class="mt-4"
        :color="detail.validateOk.value ? 'success' : 'error'"
        variant="subtle"
        :icon="detail.validateOk.value ? 'i-lucide-check-circle' : 'i-lucide-circle-x'"
        :title="detail.validateMessage.value"
        data-testid="communication-flow-validate-result"
      />
    </ShellSectionCard>
  </section>
</template>
