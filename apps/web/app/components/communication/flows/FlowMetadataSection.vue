<script setup lang="ts">
import type { CommunicationFlowDetail } from '~/composables/useCommunicationFlowDetail'
import {
  communicationFlowStatusColor,
  communicationFlowStatusLabel
} from '~/utils/communication-flows'

const { detail } = defineProps<{
  detail: CommunicationFlowDetail
}>()

const editName = detail.editName
const editStatus = detail.editStatus
</script>

<template>
  <section v-if="detail.flow.value">
    <ShellSectionHeader
      title="Metadados"
      description="Nome e situação do fluxo no escritório. Novo fluxo inicia pausado."
    >
      <UBadge
        size="md"
        variant="subtle"
        :color="communicationFlowStatusColor(detail.flow.value)"
        :label="communicationFlowStatusLabel(detail.flow.value)"
      />
    </ShellSectionHeader>
    <ShellSectionCard>
      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField
          label="Nome"
          name="name"
          class="sm:col-span-2"
          required
        >
          <UInput
            v-model="editName"
            :disabled="Boolean(detail.mutationBlocked.value)"
            class="w-full"
            data-testid="communication-flow-name"
          />
        </UFormField>
        <UFormField
          label="Situação"
          name="status"
        >
          <USelect
            v-model="editStatus"
            :items="[
              { label: 'Pausado', value: 'paused' },
              { label: 'Ativo', value: 'active' }
            ]"
            :disabled="Boolean(detail.mutationBlocked.value)"
            class="w-full"
            data-testid="communication-flow-status"
          />
        </UFormField>
        <UFormField
          label="Versão de concorrência"
          name="lock_version"
        >
          <UInput
            :model-value="String(detail.flow.value.lock_version)"
            disabled
            class="w-full font-mono"
          />
        </UFormField>
      </div>
      <UAlert
        v-if="detail.metaError.value"
        class="mt-4"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-x"
        :title="detail.metaError.value"
      />
      <div
        v-if="detail.canManage.value"
        class="mt-4 flex justify-end"
      >
        <UButton
          icon="i-lucide-save"
          label="Salvar metadados"
          :loading="detail.metaBusy.value"
          :disabled="Boolean(detail.mutationBlocked.value)"
          data-testid="communication-flow-save-meta"
          @click="detail.saveMetadata"
        />
      </div>
      <UAlert
        v-else
        class="mt-4"
        color="neutral"
        variant="subtle"
        icon="i-lucide-lock"
        title="Somente leitura"
        description="É necessária a permissão communication.manage_flows para alterar este fluxo."
      />
    </ShellSectionCard>
  </section>
</template>
