<script setup lang="ts">
import type { Contact } from '~/types/communication/contacts'
import {
  COMMUNICATION_CONTACT_ACTION_LABELS,
  COMMUNICATION_CONTACT_DANGER_SOFT_CLASS
} from '~/utils/communication-contacts'

defineProps<{
  contact: Contact
  canManage: boolean
  exporting: boolean
}>()

const emit = defineEmits<{
  export: []
  openPurge: []
}>()
</script>

<template>
  <section data-testid="communication-contact-privacy-section">
    <ShellSectionHeader
      title="Privacidade e retenção"
      description="Exportação privada e expurgo de dados pessoais recuperáveis."
    />
    <ShellSectionCard>
      <template v-if="contact.purged_at">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-shield-off"
          title="Dados pessoais já expurgados"
          description="O tombstone auditável permanece; exportação e novo expurgo não se aplicam."
        />
      </template>
      <template v-else-if="canManage">
        <p class="mb-4 text-sm text-muted">
          A exportação gera um JSON autenticado com o estado atual do contato.
          O expurgo remove identidades e conteúdo recuperável; o tombstone auditável é preservado.
        </p>
        <div class="flex flex-wrap gap-2">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-download"
            :label="COMMUNICATION_CONTACT_ACTION_LABELS.export"
            :loading="exporting"
            data-testid="communication-contact-privacy-export"
            @click="emit('export')"
          />
          <UButton
            color="error"
            variant="soft"
            icon="i-lucide-trash-2"
            :label="COMMUNICATION_CONTACT_ACTION_LABELS.purge"
            :class="COMMUNICATION_CONTACT_DANGER_SOFT_CLASS"
            data-testid="communication-contact-privacy-purge"
            @click="emit('openPurge')"
          />
        </div>
      </template>
      <UAlert
        v-else
        color="neutral"
        variant="subtle"
        icon="i-lucide-lock"
        title="Ações de privacidade restritas"
        description="É necessária a permissão communication.manage_contacts para exportar ou expurgar."
      />
    </ShellSectionCard>
  </section>
</template>
