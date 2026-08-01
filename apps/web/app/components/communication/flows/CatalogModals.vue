<script setup lang="ts">
import type { FlowsCatalog } from '~/composables/useCommunicationFlowsCatalog'

const { catalog } = defineProps<{
  catalog: FlowsCatalog
}>()

const createOpen = catalog.createOpen
const createName = catalog.createName
const pauseOpen = catalog.pauseOpen
</script>

<template>
  <ShellFormModal
    v-model:open="createOpen"
    title="Novo fluxo"
    description="O fluxo inicia pausado. Publique uma versão e vincule a uma inbox para ativar depois."
    submit-label="Criar"
    :loading="catalog.createBusy.value"
    :disabled="Boolean(catalog.mutationBlocked.value) || catalog.createName.value.trim().length < 2"
    test-id="communication-flow-create-modal"
    @submit="catalog.submitCreate"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="catalog.createError.value"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-x"
          :title="catalog.createError.value"
        />
        <UFormField
          label="Nome"
          name="name"
          required
        >
          <UInput
            v-model="createName"
            placeholder="Ex.: Triagem inicial"
            class="w-full"
            data-testid="communication-flow-create-name"
          />
        </UFormField>
      </div>
    </template>
  </ShellFormModal>

  <ShellConfirmModal
    v-model:open="pauseOpen"
    title="Pausar fluxo?"
    :description="catalog.pauseTarget.value
      ? `O fluxo “${catalog.pauseTarget.value.name}” ficará pausado. Bindings habilitados deixam de iniciar novas execuções.`
      : ''"
    confirm-label="Pausar"
    tone="neutral"
    :loading="catalog.pauseBusy.value"
    test-id="communication-flow-pause-modal"
    @confirm="catalog.confirmPause"
  />
</template>
