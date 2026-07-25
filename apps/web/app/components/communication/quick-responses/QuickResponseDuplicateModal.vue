<script setup lang="ts">
import type { CommunicationQuickResponsesCatalog } from '~/composables/useCommunicationQuickResponsesCatalog'

const { catalog } = defineProps<{
  catalog: CommunicationQuickResponsesCatalog
}>()

const duplicateOpen = catalog.duplicateOpen
const duplicateShortcut = catalog.duplicateShortcut
</script>

<template>
  <ShellFormModal
    v-model:open="duplicateOpen"
    title="Duplicar resposta rápida"
    description="Informe um atalho novo e único para a cópia."
    submit-label="Duplicar"
    :loading="catalog.duplicateBusy.value"
    :disabled="!catalog.canManage.value || !catalog.duplicateShortcut.value.trim()"
    test-id="communication-quick-response-duplicate-modal"
    @submit="catalog.submitDuplicate"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="catalog.duplicateError.value"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-x"
          :title="catalog.duplicateError.value"
        />
        <p
          v-if="catalog.duplicateSource.value"
          class="text-sm text-muted"
        >
          Origem:
          <span class="font-mono text-highlighted">/{{ catalog.duplicateSource.value.shortcut }}</span>
          · {{ catalog.duplicateSource.value.title }}
        </p>
        <UFormField
          label="Novo atalho"
          name="shortcut"
          required
        >
          <UInput
            v-model="duplicateShortcut"
            class="w-full font-mono"
          />
        </UFormField>
      </div>
    </template>
  </ShellFormModal>
</template>
