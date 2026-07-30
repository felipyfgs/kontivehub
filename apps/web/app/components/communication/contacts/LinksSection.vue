<script setup lang="ts">
import type { CommunicationIdentityLinkEntry } from '~/types/communication'
import { COMMUNICATION_CONTACT_DANGER_SOFT_CLASS } from '~/utils/communication-contacts'

defineProps<{
  identityLinks: CommunicationIdentityLinkEntry[]
  canMutate: boolean
  unlinkingKey: string | null
}>()

const emit = defineEmits<{
  unlink: [identityId: number, linkId: number]
}>()
</script>

<template>
  <section data-testid="communication-contact-links-section">
    <ShellSectionHeader
      title="Vínculos com clientes"
      description="Associações com o cadastro fiscal do escritório."
    />
    <ShellSectionCard>
      <UEmpty
        v-if="!identityLinks.length"
        icon="i-lucide-unplug"
        title="Sem vínculos"
        description="Nenhuma identidade está ligada a um cliente."
        class="py-6"
      />
      <ul
        v-else
        class="divide-y divide-default rounded-lg border border-default"
        data-testid="communication-contact-links"
      >
        <li
          v-for="{ identity, link } in identityLinks"
          :key="link.id"
          class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
        >
          <div class="min-w-0">
            <p class="truncate font-medium text-highlighted">
              {{ link.client_name || `Cliente #${link.client_id}` }}
            </p>
            <p class="truncate text-xs text-muted">
              {{ identity.phone || 'Número indisponível' }}
              <template v-if="link.client_contact_name">
                · {{ link.client_contact_name }}
              </template>
              <template v-if="link.is_primary">
                · Principal
              </template>
              <template v-if="link.receives_automatic">
                · Automático
              </template>
            </p>
          </div>
          <UButton
            v-if="canMutate"
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-unlink"
            label="Desvincular"
            :class="COMMUNICATION_CONTACT_DANGER_SOFT_CLASS"
            :loading="unlinkingKey === `${identity.id}:${link.id}`"
            :data-testid="`communication-contact-unlink-${link.id}`"
            @click="emit('unlink', identity.id, link.id)"
          />
        </li>
      </ul>
    </ShellSectionCard>
  </section>
</template>
