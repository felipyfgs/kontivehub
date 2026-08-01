<script setup lang="ts">
/**
 * Detalhes do contato de comunicação — rota fina sobre composable e seções de domínio.
 * Visualização gated por communication.view; mutações por communication.manage_contacts.
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import type { Inbox } from '~/types/communication/inboxes'
import { apiErrorMessage } from '~/utils/api-error'
import {
  COMMUNICATION_CONTACT_DANGER_SOFT_CLASS,
  COMMUNICATION_CONTACT_SOLID_ACTION_CLASS,
  communicationContactStatusColor,
  communicationContactStatusContrastClass,
  communicationContactStatusLabel
} from '~/utils/communication-contacts'
import {
  COMMUNICATION_CONTACTS_PATH,
  communicationConversationMessagePath,
  communicationConversationPath
} from '~/utils/communication-routes'
import { canReplyCommunication, canViewCommunication } from '~/utils/permissions'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'

definePageMeta({
  middleware: [() => {
    const { me } = useDashboard()
    if (!canViewCommunication(me.value)) return navigateTo('/')
  }]
})

const { me, sessionEpoch } = useDashboard()
const breakpoints = useBreakpoints(breakpointsTailwind)
const isCompact = breakpoints.smaller('lg')
const contextOpen = ref(false)
const newConversationOpen = ref(false)
const newConversationLoading = ref(false)
const api = useApi()
const toast = useToast()
const conversationInboxes = ref<Inbox[]>([])
let conversationRequestSequence = 0
const canReply = computed(() => canReplyCommunication(me.value))
const backTo = computed(() => COMMUNICATION_CONTACTS_PATH)

function openContext() {
  contextOpen.value = true
}

async function openNewConversation() {
  if (newConversationLoading.value) return
  if (conversationInboxes.value.length) {
    newConversationOpen.value = true
    return
  }
  const requestSequence = ++conversationRequestSequence
  const epoch = sessionEpoch.value
  const contactId = contact.value?.id
  newConversationLoading.value = true
  try {
    const response = await api.communication.inboxes.list()
    if (
      requestSequence !== conversationRequestSequence
      || epoch !== sessionEpoch.value
      || contactId !== contact.value?.id
    ) return
    conversationInboxes.value = response.data
    newConversationOpen.value = true
  } catch (caught) {
    if (requestSequence !== conversationRequestSequence || epoch !== sessionEpoch.value) return
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível carregar as inboxes disponíveis.'),
      color: 'error'
    })
  } finally {
    if (requestSequence === conversationRequestSequence && epoch === sessionEpoch.value) {
      newConversationLoading.value = false
    }
  }
}

watch(sessionEpoch, () => {
  ++conversationRequestSequence
  newConversationLoading.value = false
  newConversationOpen.value = false
  conversationInboxes.value = []
})

async function jumpToMessage(input: { conversationId: number, messageId: number }) {
  await navigateTo(communicationConversationMessagePath(input.conversationId, input.messageId))
}

const {
  contact,
  loading,
  loadError,
  saving,
  editName,
  editActive,
  identityOpen,
  identityPhone,
  identityError,
  identityBusy,
  linkOpen,
  linkClientId,
  linkClientContactId,
  linkIsPrimary,
  linkReceivesAutomatic,
  linkBusy,
  linkError,
  unlinkingKey,
  purgeOpen,
  purging,
  exporting,
  displayName,
  identityLinks,
  canMutate,
  clientContactItems,
  canManage,
  load,
  saveProfile,
  openAddIdentity,
  submitIdentity,
  openLink,
  submitLink,
  unlink,
  exportContact,
  confirmPurge,
  openPurge
} = useCommunicationContactDetail()

const headerMoreActions = computed<DropdownMenuItem[]>(() => {
  const actions: DropdownMenuItem[] = []
  if (canManage.value && contact.value && !contact.value.purged_at) {
    actions.push(
      {
        label: 'Exportar',
        icon: 'i-lucide-download',
        disabled: exporting.value,
        onSelect: () => { void exportContact() }
      },
      {
        label: 'Expurgar',
        icon: 'i-lucide-trash-2',
        color: 'error',
        class: COMMUNICATION_CONTACT_DANGER_SOFT_CLASS,
        onSelect: openPurge
      }
    )
  }

  return actions
})
</script>

<template>
  <ShellPagePanel
    id="communication-contact-detail"
    body-class="gap-0 overflow-hidden p-0 sm:p-0"
    data-testid="communication-contact-detail-panel"
  >
    <template #header>
      <ShellPageNavbar :title="displayName">
        <template #leading>
          <ShellNavbarBack
            :to="backTo"
            label="Contatos"
            aria-label="Voltar para contatos"
            test-id="communication-contact-back"
          />
        </template>
        <template #right>
          <div class="flex min-w-0 items-center justify-end gap-2">
            <UBadge
              v-if="contact"
              class="hidden sm:inline-flex"
              size="md"
              variant="subtle"
              :color="communicationContactStatusColor(contact)"
              :label="communicationContactStatusLabel(contact)"
              :class="communicationContactStatusContrastClass(contact)"
              data-testid="communication-contact-header-status"
            />
            <UButton
              v-if="canReply && contact && !contact.purged_at"
              icon="i-lucide-message-circle-plus"
              label="Nova conversa"
              :loading="newConversationLoading"
              :disabled="newConversationLoading"
              :ui="{ label: 'hidden sm:inline' }"
              aria-label="Nova conversa"
              @click="openNewConversation"
            />
            <UDropdownMenu
              v-if="headerMoreActions.length"
              :items="headerMoreActions"
              :content="{ align: 'end' }"
            >
              <UButton
                color="neutral"
                variant="ghost"
                icon="i-lucide-ellipsis-vertical"
                square
                aria-label="Ações do contato"
                data-testid="communication-contact-more-actions"
              />
            </UDropdownMenu>
          </div>
        </template>
      </ShellPageNavbar>
    </template>

    <template #body>
      <h1
        data-testid="page-title"
        class="sr-only"
      >
        {{ displayName }}
      </h1>

      <ShellLoadError
        v-if="loadError && !contact"
        :title="loadError"
        class="m-4 sm:m-6"
        test-id="communication-contact-load-error"
        @retry="load"
      />

      <div
        v-else-if="loading && !contact"
        class="h-full w-full space-y-4 overflow-hidden p-4 sm:p-6"
        data-testid="communication-contact-loading"
      >
        <USkeleton class="h-28 w-full rounded-lg" />
        <USkeleton class="h-40 w-full rounded-lg" />
        <USkeleton class="h-40 w-full rounded-lg" />
      </div>

      <div
        v-else-if="contact"
        class="flex h-full min-h-0 w-full flex-col overflow-hidden lg:flex-row"
      >
        <div class="min-h-0 w-full flex-1 space-y-6 overflow-y-auto p-4 sm:p-6 lg:w-auto lg:flex-[3_1_0%]">
          <CommunicationContactsProfileSection
            v-model:edit-name="editName"
            v-model:edit-active="editActive"
            :contact="contact"
            :can-mutate="canMutate"
            :can-manage="canManage"
            :saving="saving"
            @save="saveProfile"
          />
          <UButton
            v-if="isCompact"
            icon="i-lucide-panel-right-open"
            color="neutral"
            variant="outline"
            label="Ver contexto"
            data-testid="communication-contact-context-trigger"
            @click="openContext"
          />
        </div>
        <aside
          v-if="!isCompact"
          class="min-h-0 w-full overflow-hidden border-l border-default p-4 sm:p-6 lg:w-auto lg:flex-[2_1_0%]"
          aria-label="Contexto do contato"
        >
          <CommunicationContactsContext
            :contact="contact"
            :can-mutate="canMutate"
            :can-manage="canManage"
            :identity-links="identityLinks"
            :unlinking-key="unlinkingKey"
            :exporting="exporting"
            @add-identity="openAddIdentity"
            @link="openLink"
            @unlink="unlink"
            @export="exportContact"
            @open-purge="openPurge"
            @jump-to-message="jumpToMessage"
          />
        </aside>
        <USlideover
          v-else
          v-model:open="contextOpen"
          title="Contexto do contato"
          description="Conversas, identidades, vínculos, conteúdo compartilhado e privacidade."
        >
          <template #body>
            <CommunicationContactsContext
              :contact="contact"
              :can-mutate="canMutate"
              :can-manage="canManage"
              :identity-links="identityLinks"
              :unlinking-key="unlinkingKey"
              :exporting="exporting"
              @add-identity="openAddIdentity"
              @link="openLink"
              @unlink="unlink"
              @export="exportContact"
              @open-purge="openPurge"
              @jump-to-message="jumpToMessage"
            />
          </template>
        </USlideover>
      </div>

      <ShellFormModal
        v-model:open="identityOpen"
        title="Adicionar identidade"
        description="O telefone será normalizado e armazenado com proteção."
        submit-label="Adicionar"
        :submit-class="COMMUNICATION_CONTACT_SOLID_ACTION_CLASS"
        :loading="identityBusy"
        :disabled="!canMutate || !identityPhone.trim()"
        test-id="communication-contact-identity-modal"
        @submit="submitIdentity"
        @cancel="identityPhone = ''; identityError = null"
      >
        <template #body>
          <div class="space-y-4">
            <UAlert
              v-if="identityError"
              color="error"
              variant="subtle"
              icon="i-lucide-circle-x"
              :title="identityError"
            />
            <UFormField
              label="WhatsApp"
              name="phone"
              required
            >
              <UInput
                v-model="identityPhone"
                placeholder="Ex.: 11999998888"
                autocomplete="tel"
                class="w-full"
              />
            </UFormField>
          </div>
        </template>
      </ShellFormModal>

      <ShellFormModal
        v-model:open="linkOpen"
        title="Vincular cliente"
        description="Associa a identidade a um cliente do Tenant atual."
        submit-label="Vincular"
        :submit-class="COMMUNICATION_CONTACT_SOLID_ACTION_CLASS"
        :loading="linkBusy"
        :disabled="!canMutate || !linkClientId"
        test-id="communication-contact-link-modal"
        @submit="submitLink"
      >
        <template #body>
          <div class="space-y-4">
            <UAlert
              v-if="linkError"
              color="error"
              variant="subtle"
              icon="i-lucide-circle-x"
              :title="linkError"
            />
            <UFormField
              label="Cliente"
              name="client_id"
              required
            >
              <FiscalClientPicker
                v-model="linkClientId"
                search-mode="select"
                placeholder="Selecionar cliente"
                class="w-full min-w-0"
              />
            </UFormField>
            <UFormField
              label="Contato fiscal (opcional)"
              name="client_contact_id"
            >
              <USelect
                v-model="linkClientContactId"
                :items="clientContactItems"
                value-key="value"
                placeholder="Nenhum"
                :disabled="!linkClientId || !clientContactItems.length"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Opções"
              name="link_options"
            >
              <div class="space-y-2">
                <UCheckbox
                  v-model="linkIsPrimary"
                  label="Marcar como principal do cliente"
                />
                <UCheckbox
                  v-model="linkReceivesAutomatic"
                  label="Recebe comunicações automáticas"
                />
              </div>
            </UFormField>
          </div>
        </template>
      </ShellFormModal>

      <ShellConfirmModal
        v-model:open="purgeOpen"
        title="Expurgar dados pessoais?"
        description="Remove identidades e conteúdo recuperável deste contato. O tombstone auditável é preservado. Esta ação não pode ser desfeita."
        tone="danger"
        confirm-label="Expurgar"
        confirm-icon="i-lucide-trash-2"
        :loading="purging"
        test-id="communication-contact-purge-modal"
        confirm-test-id="communication-contact-purge-confirm"
        :confirm-class="COMMUNICATION_CONTACT_SOLID_ACTION_CLASS"
        @confirm="confirmPurge"
      />

      <CommunicationNewConversationModal
        v-model:open="newConversationOpen"
        :contact="contact"
        :inboxes="conversationInboxes"
        :can-reply="canReply"
        @created="id => navigateTo({ path: communicationConversationPath(id) })"
      />
    </template>
  </ShellPagePanel>
</template>
