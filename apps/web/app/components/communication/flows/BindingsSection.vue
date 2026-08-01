<script setup lang="ts">
import type { FlowDetail } from '~/composables/useCommunicationFlowDetail'

const { detail } = defineProps<{
  detail: FlowDetail
}>()

const bindingInboxId = detail.bindingInboxId
const bindingVersionId = detail.bindingVersionId
</script>

<template>
  <section>
    <ShellSectionHeader
      title="Bindings por inbox"
      description="Novo binding inicia desabilitado. No máximo um habilitado por inbox."
    />
    <ShellSectionCard>
      <UAlert
        v-if="detail.inboxesError.value"
        class="mb-4"
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="detail.inboxesError.value"
        description="Os bindings existentes permanecem visíveis, mas novas vinculações dependem do catálogo de inboxes."
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            label="Tentar novamente"
            @click="detail.load"
          />
        </template>
      </UAlert>

      <div
        v-if="detail.canManage.value"
        class="mb-4 grid gap-3 sm:grid-cols-2"
        data-testid="communication-flow-binding-form"
      >
        <UFormField
          label="Inbox"
          name="inbox_id"
        >
          <USelect
            v-model="bindingInboxId"
            :items="detail.inboxItems.value"
            placeholder="Selecione"
            :disabled="Boolean(detail.mutationBlocked.value) || Boolean(detail.inboxesError.value)"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Versão (opcional)"
          name="published_version_id"
        >
          <USelect
            v-model="bindingVersionId"
            :items="detail.versionItems.value"
            placeholder="Nenhuma"
            :disabled="Boolean(detail.mutationBlocked.value) || !detail.versionItems.value.length"
            class="w-full"
          />
        </UFormField>
        <div class="sm:col-span-2 flex justify-end">
          <UButton
            icon="i-lucide-link"
            label="Vincular inbox"
            :loading="detail.bindingBusy.value"
            :disabled="Boolean(detail.mutationBlocked.value) || detail.bindingInboxId.value == null"
            data-testid="communication-flow-binding-create"
            @click="detail.createBinding"
          />
        </div>
        <UAlert
          v-if="detail.bindingError.value"
          class="sm:col-span-2"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-x"
          :title="detail.bindingError.value"
        />
      </div>

      <UEmpty
        v-if="!detail.bindings.value.length"
        icon="i-lucide-unplug"
        title="Nenhum binding"
        description="Vincule este fluxo a uma inbox do escritório."
        class="py-6"
      />
      <ul
        v-else
        class="divide-y divide-default rounded-lg border border-default"
        data-testid="communication-flow-bindings"
      >
        <li
          v-for="binding in detail.bindings.value"
          :key="binding.id"
          class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
        >
          <div class="min-w-0">
            <p class="text-sm font-medium text-highlighted">
              {{ detail.inboxNameById.value.get(binding.inbox_id) || `Inbox #${binding.inbox_id}` }}
            </p>
            <p class="text-xs text-muted">
              Versão {{ detail.versionLabel(binding.published_version_id) }}
              · {{ binding.enabled ? 'Habilitado' : 'Desabilitado' }}
            </p>
          </div>
          <div
            v-if="detail.canManage.value"
            class="flex gap-1"
          >
            <UButton
              v-if="!binding.enabled"
              size="xs"
              color="success"
              variant="soft"
              label="Habilitar"
              :loading="detail.bindingActionKey.value === `${binding.id}:enable`"
              :disabled="Boolean(detail.mutationBlocked.value)"
              :data-testid="`communication-flow-binding-enable-${binding.id}`"
              @click="detail.openEnable(binding)"
            />
            <UButton
              v-else
              size="xs"
              color="neutral"
              variant="soft"
              label="Desabilitar"
              :loading="detail.bindingActionKey.value === `${binding.id}:disable`"
              :disabled="Boolean(detail.mutationBlocked.value)"
              :data-testid="`communication-flow-binding-disable-${binding.id}`"
              @click="detail.disableBinding(binding)"
            />
          </div>
        </li>
      </ul>
    </ShellSectionCard>
  </section>
</template>
