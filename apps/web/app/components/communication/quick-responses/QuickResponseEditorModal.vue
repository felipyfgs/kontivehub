<script setup lang="ts">
import type { CommunicationQuickResponsesCatalog } from '~/composables/useCommunicationQuickResponsesCatalog'
import { CANNED_RESPONSE_VARIABLES } from '~/utils/communication-quick-responses'

const { catalog } = defineProps<{
  catalog: CommunicationQuickResponsesCatalog
}>()

const editorOpen = catalog.editorOpen
const editorTitle = catalog.editorTitle
const editorShortcut = catalog.editorShortcut
const editorBody = catalog.editorBody
const editorIsActive = catalog.editorIsActive
</script>

<template>
  <ShellFormModal
    v-model:open="editorOpen"
    :title="catalog.editorMode.value === 'create' ? 'Nova resposta rápida' : 'Editar resposta rápida'"
    description="Use atalho em minúsculas. Variáveis allowlist são resolvidas no envio via /atalho."
    :submit-label="catalog.editorMode.value === 'create' ? 'Criar' : 'Salvar'"
    :loading="catalog.editorBusy.value"
    :disabled="!catalog.canManage.value
      || !catalog.editorTitle.value.trim()
      || !catalog.editorShortcut.value.trim()
      || !catalog.editorBody.value.trim()"
    test-id="communication-quick-response-editor-modal"
    @submit="catalog.submitEditor"
    @cancel="catalog.resetEditor"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="catalog.editorError.value"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-x"
          :title="catalog.editorError.value"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <UFormField
            label="Título"
            name="title"
            required
          >
            <UInput
              v-model="editorTitle"
              placeholder="Ex.: Saudação inicial"
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Atalho"
            name="shortcut"
            required
            hint="Sem espaços. Digite /atalho no composer."
          >
            <UInput
              v-model="editorShortcut"
              placeholder="saudacao"
              class="w-full font-mono"
            />
          </UFormField>
        </div>
        <UFormField
          label="Corpo"
          name="body"
          required
        >
          <UTextarea
            v-model="editorBody"
            :rows="6"
            autoresize
            class="w-full"
            placeholder="Olá {{contato.nome}}, em que posso ajudar?"
          />
        </UFormField>
        <div class="flex flex-wrap gap-1.5">
          <UButton
            v-for="token in CANNED_RESPONSE_VARIABLES"
            :key="token"
            size="xs"
            color="neutral"
            variant="soft"
            :label="token"
            :aria-label="`Inserir variável ${token}`"
            @click="catalog.insertVariable(token)"
          />
        </div>
        <UFormField
          label="Situação"
          name="is_active"
        >
          <USwitch
            v-model="editorIsActive"
            label="Resposta ativa no composer"
          />
        </UFormField>
      </div>
    </template>
  </ShellFormModal>
</template>
