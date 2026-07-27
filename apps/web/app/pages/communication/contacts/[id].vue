<script setup lang="ts">
/**
 * Ficha de contato de comunicação — settings/detalhe com seções Shell.
 * Mutações gated por communication.manage_contacts.
 */
import type { ClientContact } from '~/types/api'
import type { CommunicationContact, CommunicationIdentity } from '~/types/communication'
import { apiErrorMessage } from '~/utils/api-error'
import {
  communicationContactDisplayName,
  communicationContactStatusColor,
  communicationContactStatusLabel,
  flattenCommunicationIdentityLinks
} from '~/utils/communication-contacts'
import {
  COMMUNICATION_CONTACTS_PATH,
  parseCommunicationContactId
} from '~/utils/communication-routes'
import { canManageCommunicationContacts, canViewCommunication } from '~/utils/permissions'

const api = useApi()
const route = useRoute()
const toast = useToast()
const download = useAuthenticatedDownload()
const { me, sessionEpoch } = useDashboard()

const canView = computed(() => canViewCommunication(me.value))
const canManage = computed(() => canManageCommunicationContacts(me.value))

if (!canView.value) {
  await navigateTo('/')
}

const contactId = computed(() => parseCommunicationContactId(route.params.id))
const contact = ref<CommunicationContact | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const saving = ref(false)

const editName = ref('')
const editActive = ref(true)

const identityOpen = ref(false)
const identityPhone = ref('')
const identityError = ref<string | null>(null)
const identityBusy = ref(false)

const linkOpen = ref(false)
const linkIdentity = ref<CommunicationIdentity | null>(null)
const linkClientId = ref<number | null>(null)
const linkClientContactId = ref<number | undefined>(undefined)
const linkIsPrimary = ref(false)
const linkReceivesAutomatic = ref(true)
const linkClientContacts = ref<ClientContact[]>([])
const linkBusy = ref(false)
const linkError = ref<string | null>(null)
const unlinkingKey = ref<string | null>(null)

const purgeOpen = ref(false)
const purging = ref(false)
const exporting = ref(false)

const displayName = computed(() =>
  contact.value ? communicationContactDisplayName(contact.value) : 'Contato'
)

const identityLinks = computed(() =>
  flattenCommunicationIdentityLinks(contact.value?.identities)
)

const clientContactItems = computed(() =>
  linkClientContacts.value
    .filter(item => item.is_active)
    .map(item => ({
      label: item.name,
      value: item.id
    }))
)

async function load() {
  const id = contactId.value
  if (!id) {
    loadError.value = 'Contato inválido.'
    contact.value = null
    return
  }
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const res = await api.communication.contacts.get(id)
    if (epoch !== sessionEpoch.value) return
    contact.value = res.data
    editName.value = res.data.name || ''
    editActive.value = res.data.is_active
  } catch (caught) {
    if (epoch !== sessionEpoch.value) return
    contact.value = null
    loadError.value = apiErrorMessage(caught, 'Falha ao carregar o contato.')
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

async function saveProfile() {
  if (!canManage.value || !contact.value) return
  saving.value = true
  try {
    const res = await api.communication.contacts.update(contact.value.id, {
      name: editName.value.trim() || null,
      is_active: editActive.value
    })
    contact.value = res.data
    editName.value = res.data.name || ''
    editActive.value = res.data.is_active
    toast.add({ title: 'Contato atualizado.', color: 'success' })
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao salvar o contato.'), color: 'error' })
  } finally {
    saving.value = false
  }
}

function openAddIdentity() {
  identityPhone.value = ''
  identityError.value = null
  identityOpen.value = true
}

async function submitIdentity() {
  if (!canManage.value || !contact.value) return
  const phone = identityPhone.value.trim()
  if (phone.length < 8) {
    identityError.value = 'Informe um telefone WhatsApp válido.'
    return
  }
  identityBusy.value = true
  identityError.value = null
  try {
    await api.communication.contacts.addIdentity(contact.value.id, phone)
    toast.add({ title: 'Identidade adicionada.', color: 'success' })
    identityOpen.value = false
    await load()
  } catch (caught) {
    identityError.value = apiErrorMessage(caught, 'Falha ao adicionar identidade.')
  } finally {
    identityBusy.value = false
  }
}

async function loadClientContacts(clientId: number) {
  try {
    const res = await api.contacts.list(clientId)
    linkClientContacts.value = res.data
  } catch {
    linkClientContacts.value = []
  }
}

function openLink(identity: CommunicationIdentity) {
  linkIdentity.value = identity
  linkClientId.value = null
  linkClientContactId.value = undefined
  linkIsPrimary.value = false
  linkReceivesAutomatic.value = true
  linkClientContacts.value = []
  linkError.value = null
  linkOpen.value = true
}

watch(linkClientId, (id) => {
  linkClientContactId.value = undefined
  if (id) void loadClientContacts(id)
  else linkClientContacts.value = []
})

async function submitLink() {
  if (!canManage.value || !linkIdentity.value || !linkClientId.value) {
    linkError.value = 'Selecione um cliente.'
    return
  }
  linkBusy.value = true
  linkError.value = null
  try {
    await api.communication.contacts.linkIdentity(linkIdentity.value.id, {
      client_id: linkClientId.value,
      client_contact_id: linkClientContactId.value,
      is_primary: linkIsPrimary.value,
      receives_automatic: linkReceivesAutomatic.value
    })
    toast.add({ title: 'Vínculo criado.', color: 'success' })
    linkOpen.value = false
    await load()
  } catch (caught) {
    linkError.value = apiErrorMessage(caught, 'Falha ao vincular cliente.')
  } finally {
    linkBusy.value = false
  }
}

async function unlink(identityId: number, linkId: number) {
  if (!canManage.value) return
  const key = `${identityId}:${linkId}`
  unlinkingKey.value = key
  try {
    await api.communication.contacts.unlinkIdentity(identityId, linkId)
    toast.add({ title: 'Vínculo removido.', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao remover vínculo.'), color: 'error' })
  } finally {
    unlinkingKey.value = null
  }
}

async function exportContact() {
  if (!canManage.value || !contact.value) return
  exporting.value = true
  try {
    await download.download(
      api.communication.contacts.exportUrl(contact.value.id),
      `contato-${contact.value.id}.json`
    )
    toast.add({ title: 'Exportação iniciada.', color: 'success' })
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao exportar o contato.'), color: 'error' })
  } finally {
    exporting.value = false
  }
}

async function confirmPurge() {
  if (!canManage.value || !contact.value) return
  purging.value = true
  try {
    await api.communication.contacts.purge(contact.value.id)
    toast.add({
      title: 'Dados pessoais expurgados.',
      description: 'Conteúdo recuperável foi removido; o tombstone auditável permanece.',
      color: 'success'
    })
    purgeOpen.value = false
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao expurgar os dados.'), color: 'error' })
  } finally {
    purging.value = false
  }
}

watch(contactId, () => {
  void load()
})

watch(sessionEpoch, () => {
  contact.value = null
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <ShellPagePanel
    id="communication-contact-detail"
    data-testid="communication-contact-detail-panel"
  >
    <template #header>
      <ShellPageNavbar :title="displayName">
        <template #leading>
          <ShellNavbarBack
            :to="COMMUNICATION_CONTACTS_PATH"
            label="Contatos"
            aria-label="Voltar para contatos"
            test-id="communication-contact-back"
          />
        </template>
        <template #right>
          <template v-if="canManage && contact && !contact.purged_at">
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-download"
              label="Exportar"
              :loading="exporting"
              data-testid="communication-contact-export"
              @click="exportContact"
            />
            <UButton
              color="error"
              variant="soft"
              icon="i-lucide-trash-2"
              label="Expurgar"
              data-testid="communication-contact-purge"
              @click="() => { purgeOpen = true }"
            />
          </template>
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
        test-id="communication-contact-load-error"
        @retry="load"
      />

      <div
        v-else-if="loading && !contact"
        class="space-y-4"
        data-testid="communication-contact-loading"
      >
        <USkeleton class="h-28 w-full rounded-lg" />
        <USkeleton class="h-40 w-full rounded-lg" />
        <USkeleton class="h-40 w-full rounded-lg" />
      </div>

      <div
        v-else-if="contact"
        class="mx-auto flex w-full max-w-3xl flex-col gap-6"
      >
        <section>
          <ShellSectionHeader
            title="Perfil"
            description="Nome exibido e situação do contato no escritório."
          >
            <UBadge
              size="md"
              variant="subtle"
              :color="communicationContactStatusColor(contact)"
              :label="communicationContactStatusLabel(contact)"
            />
          </ShellSectionHeader>
          <ShellSectionCard>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Nome"
                name="name"
                class="sm:col-span-2"
              >
                <UInput
                  v-model="editName"
                  :disabled="!canManage || Boolean(contact.purged_at)"
                  placeholder="Sem nome (provisório)"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Ativo"
                name="is_active"
              >
                <USwitch
                  v-model="editActive"
                  :disabled="!canManage || Boolean(contact.purged_at)"
                  label="Contato ativo para atendimento"
                />
              </UFormField>
            </div>

            <div
              v-if="canManage && !contact.purged_at"
              class="mt-4 flex justify-end"
            >
              <UButton
                icon="i-lucide-save"
                label="Salvar"
                :loading="saving"
                data-testid="communication-contact-save"
                @click="saveProfile"
              />
            </div>
            <UAlert
              v-else-if="!canManage"
              class="mt-4"
              color="neutral"
              variant="subtle"
              icon="i-lucide-lock"
              title="Somente leitura"
              description="É necessária a permissão communication.manage_contacts para alterar este contato."
            />
          </ShellSectionCard>
        </section>

        <section>
          <ShellSectionHeader
            title="Identidades WhatsApp"
            description="Números mascarados associados a este contato."
          >
            <UButton
              v-if="canManage && !contact.purged_at"
              size="sm"
              icon="i-lucide-plus"
              label="Adicionar"
              data-testid="communication-contact-add-identity"
              @click="openAddIdentity"
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
                    {{ identity.address_masked }}
                  </p>
                  <p class="text-xs text-muted">
                    {{ identity.channel }}
                    · {{ identity.is_active ? 'Ativa' : 'Inativa' }}
                  </p>
                </div>
                <UButton
                  v-if="canManage && !contact.purged_at"
                  size="xs"
                  color="neutral"
                  variant="soft"
                  icon="i-lucide-link"
                  label="Vincular cliente"
                  :data-testid="`communication-contact-link-${identity.id}`"
                  @click="openLink(identity)"
                />
              </li>
            </ul>
          </ShellSectionCard>
        </section>

        <section>
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
                    {{ identity.address_masked }}
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
                  v-if="canManage && !contact.purged_at"
                  size="xs"
                  color="error"
                  variant="ghost"
                  icon="i-lucide-unlink"
                  label="Desvincular"
                  :loading="unlinkingKey === `${identity.id}:${link.id}`"
                  :data-testid="`communication-contact-unlink-${link.id}`"
                  @click="unlink(identity.id, link.id)"
                />
              </li>
            </ul>
          </ShellSectionCard>
        </section>
      </div>

      <ShellFormModal
        v-model:open="identityOpen"
        title="Adicionar identidade"
        description="O telefone será normalizado e armazenado mascarado."
        submit-label="Adicionar"
        :loading="identityBusy"
        :disabled="!canManage || !identityPhone.trim()"
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
        :loading="linkBusy"
        :disabled="!canManage || !linkClientId"
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
        @confirm="confirmPurge"
      />
    </template>
  </ShellPagePanel>
</template>
