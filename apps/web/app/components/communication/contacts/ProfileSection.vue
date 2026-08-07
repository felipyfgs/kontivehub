<script setup lang="ts">
import type { Contact } from '~/types/communication/contacts'
import {
  COMMUNICATION_CONTACT_SOLID_ACTION_CLASS,
  communicationContactDisplayName,
  communicationContactInitials,
  communicationContactPrimaryPhone,
  communicationContactStatusColor,
  communicationContactStatusContrastClass,
  communicationContactStatusLabel
} from '~/utils/communication-contacts'

defineProps<{
  contact: Contact
  editName: string
  editActive: boolean
  canMutate: boolean
  canManage: boolean
  saving: boolean
}>()

const emit = defineEmits<{
  'update:editName': [value: string]
  'update:editActive': [value: boolean]
  'save': []
}>()
</script>

<template>
  <section data-testid="communication-contact-profile-section">
    <div class="mb-5 flex items-center gap-3">
      <CommunicationProfileAvatar
        :subject="contact"
        :text="communicationContactInitials(contact)"
        :alt="communicationContactDisplayName(contact)"
        size="lg"
        class="size-[42px] shrink-0 rounded-lg"
        :ui="{ root: 'rounded-lg' }"
        data-testid="communication-contact-profile-avatar"
      />
      <div class="min-w-0">
        <p class="truncate font-medium text-highlighted">
          {{ communicationContactDisplayName(contact) }}
        </p>
        <div class="flex min-w-0 items-center gap-1">
          <p class="truncate font-mono text-sm text-muted">
            {{ communicationContactPrimaryPhone(contact) || 'Número indisponível' }}
          </p>
          <CommunicationContactsPhoneCopy
            v-if="communicationContactPrimaryPhone(contact)"
            :phone="communicationContactPrimaryPhone(contact)!"
            :contact-name="communicationContactDisplayName(contact)"
          />
        </div>
      </div>
    </div>
    <ShellSectionHeader
      title="Perfil"
      description="Nome exibido e situação do contato no escritório."
    >
      <UBadge
        size="md"
        variant="subtle"
        :color="communicationContactStatusColor(contact)"
        :label="communicationContactStatusLabel(contact)"
        :class="communicationContactStatusContrastClass(contact)"
        data-testid="communication-contact-status-badge"
      />
    </ShellSectionHeader>
    <div class="border-y border-default py-5">
      <div class="space-y-4">
        <UFormField
          label="Nome"
          name="name"
        >
          <UInput
            :model-value="editName"
            :disabled="!canMutate"
            placeholder="Sem nome (provisório)"
            class="w-full"
            data-testid="communication-contact-name-input"
            @update:model-value="emit('update:editName', String($event ?? ''))"
          />
        </UFormField>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <UFormField
            label="Ativo"
            name="is_active"
            class="min-w-0 flex-1"
          >
            <USwitch
              :model-value="editActive"
              :disabled="!canMutate"
              label="Contato ativo para atendimento"
              data-testid="communication-contact-active-switch"
              @update:model-value="emit('update:editActive', Boolean($event))"
            />
          </UFormField>
          <UButton
            v-if="canMutate"
            class="shrink-0"
            icon="i-lucide-save"
            label="Salvar"
            :class="COMMUNICATION_CONTACT_SOLID_ACTION_CLASS"
            :loading="saving"
            data-testid="communication-contact-save"
            @click="emit('save')"
          />
        </div>
      </div>
      <UAlert
        v-if="!canManage"
        class="mt-4"
        color="neutral"
        variant="subtle"
        icon="i-lucide-lock"
        title="Somente leitura"
        description="É necessária a permissão communication.manage_contacts para alterar este contato."
      />
      <UAlert
        v-else-if="contact.purged_at"
        class="mt-4"
        color="warning"
        variant="subtle"
        icon="i-lucide-shield-off"
        title="Contato expurgado"
        description="O tombstone permanece somente leitura; perfil e identidades não podem ser alterados."
      />
    </div>
  </section>
</template>
