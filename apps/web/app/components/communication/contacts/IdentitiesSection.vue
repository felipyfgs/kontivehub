<script setup lang="ts">
import type { CommunicationContact, CommunicationIdentity } from '~/types/communication'
import { COMMUNICATION_CONTACT_SOLID_ACTION_CLASS } from '~/utils/communication-contacts'

defineProps<{
  contact: CommunicationContact
  canMutate: boolean
}>()

const emit = defineEmits<{
  add: []
  link: [identity: CommunicationIdentity]
}>()
</script>

<template>
  <section data-testid="communication-contact-identities-section">
    <ShellSectionHeader
      title="Identidades WhatsApp"
      description="Identidades de WhatsApp autorizadas para este contato."
    >
      <UButton
        v-if="canMutate"
        size="sm"
        icon="i-lucide-plus"
        label="Adicionar"
        :class="COMMUNICATION_CONTACT_SOLID_ACTION_CLASS"
        data-testid="communication-contact-add-identity"
        @click="emit('add')"
      />
    </ShellSectionHeader>
    <ShellSectionCard>
      <UEmpty
        v-if="!(contact.identities || []).length"
        icon="i-lucide-smartphone"
        title="Nenhuma identidade"
        description="Adicione um WhatsApp para este contato."
        class="py-6"
      />
      <ul
        v-else
        class="divide-y divide-default rounded-lg border border-default"
        data-testid="communication-contact-identities"
      >
        <li
          v-for="identity in contact.identities"
          :key="identity.id"
          class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
        >
          <div class="min-w-0">
            <p class="font-mono text-sm font-medium text-highlighted">
              {{ identity.phone || 'Número indisponível' }}
            </p>
            <p class="text-xs text-muted">
              {{ identity.channel }}
              · {{ identity.is_active ? 'Ativa' : 'Inativa' }}
            </p>
          </div>
          <UButton
            v-if="canMutate"
            size="xs"
            color="neutral"
            variant="soft"
            icon="i-lucide-link"
            label="Vincular cliente"
            :data-testid="`communication-contact-link-${identity.id}`"
            @click="emit('link', identity)"
          />
        </li>
      </ul>
    </ShellSectionCard>
  </section>
</template>
