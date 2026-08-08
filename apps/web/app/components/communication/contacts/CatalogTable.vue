<script setup lang="ts">
import type { Contact } from '~/types/communication/contacts'
import {
  communicationContactDisplayName,
  communicationContactDisplaySourceColor,
  communicationContactDisplaySourceLabel,
  communicationContactIdentityCount,
  communicationContactInitials,
  communicationContactLinkedClientNames,
  communicationContactPrimaryPhone,
  communicationContactStatusColor,
  communicationContactStatusContrastClass,
  communicationContactStatusLabel
} from '~/utils/communication-contacts'

const props = defineProps<{
  items: Contact[]
  loading: boolean
  stale: boolean
  error: string | null
  emptyKind: 'empty' | 'filtered' | 'error'
  page: number
  total: number
  perPage: number
  canManage: boolean
  canReply: boolean
  updatingId: number | null
  updateError: string | null
}>()

const emit = defineEmits<{
  'update:page': [page: number]
  'update:perPage': [perPage: number]
  'open': [contact: Contact]
  'retry': []
  'clear': []
  'create': []
  'newConversation': [contact: Contact]
  'save': [contact: Contact, body: { name: string | null, is_active: boolean }, done: (ok: boolean) => void]
}>()

const expandedId = ref<number | null>(null)
const editName = ref('')
const editActive = ref(true)
const discardOpen = ref(false)
const pendingExpansion = ref<{ id: number | null } | null>(null)
const lastPage = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))
const expandedContact = computed(() => props.items.find(item => item.id === expandedId.value) ?? null)
const dirty = computed(() => {
  const contact = expandedContact.value
  if (!contact) return false
  return editName.value.trim() !== (contact.name?.trim() ?? '')
    || editActive.value !== contact.is_active
})

function applyExpansion(id: number | null) {
  expandedId.value = id
  const contact = props.items.find(item => item.id === id)
  editName.value = contact?.name ?? ''
  editActive.value = contact?.is_active ?? true
}

function requestExpansion(contact: Contact) {
  const target = expandedId.value === contact.id ? null : contact.id
  if (dirty.value) {
    pendingExpansion.value = { id: target }
    discardOpen.value = true
    return
  }
  applyExpansion(target)
}

function confirmDiscard() {
  const target = pendingExpansion.value?.id ?? null
  discardOpen.value = false
  pendingExpansion.value = null
  applyExpansion(target)
}

function cancelDiscard() {
  discardOpen.value = false
  pendingExpansion.value = null
}

function cancelEdit() {
  if (dirty.value) {
    pendingExpansion.value = { id: null }
    discardOpen.value = true
    return
  }
  applyExpansion(null)
}

function save(contact: Contact) {
  emit('save', contact, {
    name: editName.value.trim() || null,
    is_active: editActive.value
  }, (ok) => {
    if (ok) applyExpansion(null)
  })
}

watch(() => props.items, (next, previous) => {
  const id = expandedId.value
  if (id === null || next.some(contact => contact.id === id)) return
  const previousContact = previous.find(contact => contact.id === id)
  const hasUnsavedDraft = previousContact !== undefined && (
    editName.value.trim() !== (previousContact.name?.trim() ?? '')
    || editActive.value !== previousContact.is_active
  )
  if (hasUnsavedDraft) {
    pendingExpansion.value = { id: null }
    discardOpen.value = true
    return
  }
  applyExpansion(null)
})

watch(discardOpen, (open) => {
  if (!open) pendingExpansion.value = null
})
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col" data-testid="communication-contacts-list">
    <div class="w-full px-4 pt-4 sm:px-6">
      <UAlert
        v-if="stale"
        color="info"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        title="Atualizando contatos"
        description="A última leitura válida permanece disponível."
      />
      <ShellLoadError
        v-else-if="error && items.length"
        :title="error"
        description="A última leitura válida permanece disponível."
        color="warning"
        test-id="communication-contacts-stale-error"
        @retry="emit('retry')"
      />
    </div>

    <div
      v-if="loading && !items.length"
      class="min-h-0 w-full flex-1 space-y-4 overflow-y-auto p-4 sm:px-6"
      role="status"
      aria-label="Carregando contatos"
    >
      <div v-for="row in 6" :key="row" class="flex items-center gap-3 rounded-xl border border-default p-4">
        <USkeleton class="size-[42px] shrink-0 rounded-lg" />
        <div class="min-w-0 flex-1 space-y-2">
          <USkeleton class="h-4 w-2/5" />
          <USkeleton class="h-3 w-3/5" />
        </div>
        <USkeleton class="h-8 w-8 rounded-md" />
      </div>
    </div>

    <ShellLoadError
      v-else-if="error && !items.length"
      :title="error"
      class="w-full px-4 sm:px-6"
      test-id="communication-contacts-load-error"
      @retry="emit('retry')"
    />

    <ShellListEmpty
      v-else-if="!items.length"
      :kind="emptyKind"
      :title="emptyKind === 'filtered' ? 'Nenhum contato encontrado' : 'Nenhum contato cadastrado'"
      :description="emptyKind === 'filtered' ? 'Ajuste a busca ou limpe os filtros para ver outros contatos.' : 'Crie o primeiro contato para começar o atendimento.'"
      class="min-h-0 w-full flex-1 px-4 sm:px-6"
      test-id="communication-contacts-empty"
      @retry="emit('retry')"
    >
      <template #actions>
        <UButton
          v-if="emptyKind === 'empty' && canManage"
          icon="i-lucide-plus"
          label="Novo contato"
          @click="emit('create')"
        />
        <UButton
          v-else
          color="neutral"
          variant="outline"
          icon="i-lucide-filter-x"
          label="Limpar filtros"
          @click="emit('clear')"
        />
      </template>
    </ShellListEmpty>

    <ul v-else class="min-h-0 w-full flex-1 space-y-4 overflow-y-auto p-4 sm:px-6" aria-label="Contatos de comunicação">
      <li
        v-for="contact in items"
        :id="`communication-contact-card-${contact.id}`"
        :key="contact.id"
        class="group overflow-hidden rounded-xl border border-default bg-default transition-colors hover:border-accented"
        :class="expandedId === contact.id ? 'border-primary/50 ring-1 ring-primary/20' : ''"
        data-testid="communication-contact-card"
      >
        <div class="flex min-w-0 items-center gap-3 p-4 sm:px-5">
          <CommunicationProfileAvatar
            :subject="contact"
            :alt="communicationContactDisplayName(contact)"
            :text="communicationContactInitials(contact)"
            class="size-[42px] shrink-0 rounded-lg"
            :ui="{ root: 'rounded-lg' }"
            :data-testid="`communication-contact-avatar-${contact.id}`"
          />

          <div class="grid min-w-0 flex-1 grid-cols-1 gap-x-5 gap-y-1 md:grid-cols-[minmax(11rem,1fr)_minmax(10rem,1fr)] md:items-center xl:grid-cols-[minmax(13rem,1.1fr)_minmax(10rem,0.75fr)_minmax(12rem,0.9fr)_auto]">
            <div class="min-w-0">
              <div class="flex min-w-0 flex-wrap items-center gap-2">
                <span class="truncate font-medium text-highlighted">{{ communicationContactDisplayName(contact) }}</span>
                <UBadge
                  v-if="communicationContactDisplaySourceLabel(contact)"
                  class="shrink-0"
                  size="sm"
                  variant="subtle"
                  :color="communicationContactDisplaySourceColor(contact)"
                  :label="communicationContactDisplaySourceLabel(contact)!"
                  :data-testid="`communication-contact-name-source-${contact.id}`"
                />
                <UBadge
                  v-if="communicationContactIdentityCount(contact) > 1"
                  size="sm"
                  color="neutral"
                  variant="subtle"
                  :label="`${communicationContactIdentityCount(contact)} IDs`"
                />
                <UBadge
                  class="shrink-0"
                  size="sm"
                  variant="subtle"
                  :color="communicationContactStatusColor(contact)"
                  :label="communicationContactStatusLabel(contact)"
                  :class="communicationContactStatusContrastClass(contact)"
                />
              </div>
              <p v-if="contact.is_provisional" class="text-xs text-muted">
                {{ contact.display_name_state === 'OBSERVED'
                  ? 'Nome observado nesta inbox; cadastro ainda provisório'
                  : 'Sem nome definitivo' }}
              </p>
            </div>
            <span class="flex min-w-0 items-center gap-1 font-mono text-sm tabular-nums text-toned">
              <span class="truncate">{{ communicationContactPrimaryPhone(contact) || 'Número indisponível' }}</span>
              <CommunicationContactsPhoneCopy
                v-if="communicationContactPrimaryPhone(contact)"
                :phone="communicationContactPrimaryPhone(contact)!"
                :contact-name="communicationContactDisplayName(contact)"
              />
            </span>
            <span class="truncate text-sm text-muted">{{ communicationContactLinkedClientNames(contact).join(', ') || 'Sem vínculo' }}</span>
            <UButton
              color="primary"
              variant="link"
              size="xs"
              label="Detalhes"
              class="justify-self-start px-0 md:justify-self-end"
              :aria-label="`Ver detalhes de ${communicationContactDisplayName(contact)}`"
              @click="emit('open', contact)"
            />
          </div>

          <div class="flex shrink-0 items-center gap-1">
            <UButton
              v-if="canReply"
              icon="i-lucide-message-circle-plus"
              color="neutral"
              variant="ghost"
              size="sm"
              :aria-label="`Nova conversa com ${communicationContactDisplayName(contact)}`"
              @click="emit('newConversation', contact)"
            />
            <UButton
              color="neutral"
              variant="ghost"
              size="sm"
              icon="i-lucide-chevron-down"
              square
              class="transition-transform"
              :class="expandedId === contact.id ? 'rotate-180' : ''"
              :aria-expanded="expandedId === contact.id"
              :aria-controls="expandedId === contact.id
                ? `communication-contact-summary-${contact.id}`
                : undefined"
              :aria-label="`${expandedId === contact.id
                ? 'Recolher'
                : (canManage && !contact.purged_at ? 'Editar' : 'Ver resumo de')} ${communicationContactDisplayName(contact)}`"
              @click="requestExpansion(contact)"
            />
          </div>
        </div>

        <div
          v-if="expandedId === contact.id"
          :id="`communication-contact-summary-${contact.id}`"
          class="border-t border-default bg-elevated/40 p-4 sm:p-5"
          data-testid="communication-contact-card-expanded"
        >
          <UAlert
            v-if="updateError"
            class="mb-4"
            color="error"
            variant="subtle"
            :title="updateError"
          />

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Nome" name="contact_name">
              <UInput
                v-model="editName"
                class="w-full"
                placeholder="Sem nome (provisório)"
                :disabled="!canManage || Boolean(contact.purged_at)"
              />
            </UFormField>
            <UFormField label="Situação" name="contact_active">
              <div class="flex min-h-8 items-center">
                <USwitch
                  v-model="editActive"
                  label="Contato ativo para atendimento"
                  :disabled="!canManage || Boolean(contact.purged_at)"
                />
              </div>
            </UFormField>
          </div>

          <div class="mt-4 grid gap-4 border-y border-default py-4 sm:grid-cols-2">
            <div>
              <p class="text-xs font-medium text-muted">
                WhatsApp principal
              </p>
              <p class="mt-1 font-mono text-sm tabular-nums text-highlighted">
                {{ communicationContactPrimaryPhone(contact) || 'Número indisponível' }}
              </p>
            </div>
            <div>
              <p class="text-xs font-medium text-muted">
                Identidades e vínculos
              </p>
              <p class="mt-1 text-sm text-highlighted">
                {{ communicationContactIdentityCount(contact) }} identidade{{ communicationContactIdentityCount(contact) === 1 ? '' : 's' }} ·
                {{ communicationContactLinkedClientNames(contact).length }} vínculo{{ communicationContactLinkedClientNames(contact).length === 1 ? '' : 's' }}
              </p>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              label="Cancelar"
              :disabled="updatingId === contact.id"
              @click="cancelEdit"
            />
            <UButton
              v-if="canManage && !contact.purged_at"
              icon="i-lucide-save"
              label="Salvar"
              :loading="updatingId === contact.id"
              :disabled="!dirty || updatingId !== null"
              @click="save(contact)"
            />
          </div>
        </div>
      </li>
    </ul>

    <div
      v-if="items.length"
      class="flex w-full shrink-0 flex-wrap items-center justify-between gap-3 border-t border-default px-4 py-4 sm:px-6"
      data-testid="communication-contacts-footer"
    >
      <span class="text-sm text-muted">{{ total }} contato{{ total === 1 ? '' : 's' }}</span>
      <div class="flex items-center gap-2">
        <USelect
          :model-value="perPage"
          :items="[10, 20, 50]"
          class="w-20"
          aria-label="Contatos por página"
          @update:model-value="emit('update:perPage', Number($event))"
        />
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-chevron-left"
          aria-label="Página anterior"
          :disabled="page <= 1"
          @click="emit('update:page', page - 1)"
        />
        <span class="text-sm tabular-nums">{{ page }} / {{ lastPage }}</span>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-chevron-right"
          aria-label="Próxima página"
          :disabled="page >= lastPage"
          @click="emit('update:page', page + 1)"
        />
      </div>
    </div>

    <ShellConfirmModal
      v-model:open="discardOpen"
      title="Descartar alterações?"
      description="O nome ou a situação deste contato foi alterado e ainda não foi salvo."
      confirm-label="Descartar"
      confirm-icon="i-lucide-undo-2"
      @confirm="confirmDiscard"
      @cancel="cancelDiscard"
    />
  </div>
</template>
