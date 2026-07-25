<script setup lang="ts">
/**
 * Gerenciar presets em modal: renomear, alternar compartilhamento, excluir.
 */
import type { SavedListFilter, SavedFilterVisibility } from '~/types/saved-list-filters'

const open = defineModel<boolean>('open', { default: false })

const props = withDefaults(defineProps<{
  items: SavedListFilter[]
  canShare?: boolean
  loading?: boolean
  actingId?: number | null
  error?: string | null
}>(), {
  canShare: false,
  loading: false,
  actingId: null,
  error: null
})

const emit = defineEmits<{
  'rename': [payload: { id: number, name: string }]
  'toggle-share': [payload: { id: number, visibility: SavedFilterVisibility }]
  'delete': [payload: { id: number }]
}>()

const renameId = ref<number | null>(null)
const renameDraft = ref('')
const confirmDeleteId = ref<number | null>(null)

watch(open, (isOpen) => {
  if (!isOpen) {
    renameId.value = null
    renameDraft.value = ''
    confirmDeleteId.value = null
  }
})

function startRename(filter: SavedListFilter) {
  renameId.value = filter.id
  renameDraft.value = filter.name
  confirmDeleteId.value = null
}

function cancelRename() {
  renameId.value = null
  renameDraft.value = ''
}

function submitRename() {
  const name = renameDraft.value.trim()
  if (!renameId.value || !name) return
  emit('rename', { id: renameId.value, name })
  renameId.value = null
  renameDraft.value = ''
}

function toggleShare(filter: SavedListFilter) {
  if (!props.canShare && filter.visibility === 'personal') return
  const next: SavedFilterVisibility = filter.visibility === 'office' ? 'personal' : 'office'
  emit('toggle-share', { id: filter.id, visibility: next })
}

function requestDelete(filter: SavedListFilter) {
  confirmDeleteId.value = filter.id
  renameId.value = null
}

function confirmDelete() {
  if (confirmDeleteId.value == null) return
  emit('delete', { id: confirmDeleteId.value })
  confirmDeleteId.value = null
}

function canEdit(filter: SavedListFilter): boolean {
  return filter.can_edit !== false
}

function canDelete(filter: SavedListFilter): boolean {
  return filter.can_delete !== false
}
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Gerenciar filtros salvos"
    description="Renomeie, compartilhe ou exclua presets desta lista."
    content-class="sm:max-w-lg"
    :show-default-footer="false"
    test-id="manage-saved-filters-modal"
    @cancel="() => { open = false }"
  >
    <template #body>
      <div
        class="flex max-h-[min(28rem,70vh)] min-h-0 flex-col gap-3 overflow-y-auto"
        data-testid="manage-saved-filters"
      >
        <UAlert
          v-if="error"
          color="error"
          icon="i-lucide-circle-x"
          :title="error"
        />

        <p
          v-if="loading && !items.length"
          class="text-sm text-muted"
        >
          Carregando…
        </p>

        <p
          v-else-if="!items.length"
          class="text-sm text-muted"
        >
          Nenhum filtro salvo nesta lista.
        </p>

        <ul
          v-else
          class="divide-y divide-default rounded-lg border border-default"
        >
          <li
            v-for="filter in items"
            :key="filter.id"
            class="flex flex-col gap-2 p-3"
            :data-testid="`manage-saved-filter-${filter.id}`"
          >
            <div class="flex min-w-0 items-start justify-between gap-2">
              <div class="min-w-0 flex-1">
                <template v-if="renameId === filter.id">
                  <div class="flex flex-wrap items-center gap-2">
                    <UInput
                      v-model="renameDraft"
                      class="min-w-0 flex-1"
                      data-testid="manage-saved-filter-rename-input"
                      @keyup.enter="submitRename"
                    />
                    <UButton
                      size="xs"
                      label="OK"
                      :loading="actingId === filter.id"
                      data-testid="manage-saved-filter-rename-confirm"
                      @click="submitRename"
                    />
                    <UButton
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      label="Cancelar"
                      @click="cancelRename"
                    />
                  </div>
                </template>
                <template v-else>
                  <p class="truncate text-sm font-medium text-highlighted">
                    {{ filter.name }}
                  </p>
                  <p
                    v-if="filter.author_name"
                    class="truncate text-xs text-muted"
                  >
                    {{ filter.author_name }}
                  </p>
                </template>
              </div>
              <UBadge
                size="sm"
                variant="subtle"
                :color="filter.visibility === 'office' ? 'primary' : 'neutral'"
                :label="filter.visibility === 'office' ? 'Equipe' : 'Pessoal'"
              />
            </div>

            <div
              v-if="confirmDeleteId === filter.id"
              class="flex flex-wrap items-center gap-2 rounded-md bg-elevated p-2 text-xs"
            >
              <span class="text-muted">Excluir este filtro?</span>
              <UButton
                size="xs"
                color="error"
                label="Excluir"
                :loading="actingId === filter.id"
                data-testid="manage-saved-filter-delete-confirm"
                @click="confirmDelete"
              />
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                label="Não"
                @click="() => { confirmDeleteId = null }"
              />
            </div>

            <div
              v-else
              class="flex flex-wrap items-center gap-1.5"
            >
              <UButton
                v-if="canEdit(filter)"
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-pencil"
                label="Renomear"
                :disabled="actingId === filter.id"
                data-testid="manage-saved-filter-rename"
                @click="startRename(filter)"
              />
              <UButton
                v-if="canEdit(filter) && (canShare || filter.visibility === 'office')"
                size="xs"
                color="neutral"
                variant="ghost"
                :icon="filter.visibility === 'office' ? 'i-lucide-lock' : 'i-lucide-share-2'"
                :label="filter.visibility === 'office' ? 'Tornar pessoal' : 'Compartilhar'"
                :disabled="actingId === filter.id || (!canShare && filter.visibility === 'personal')"
                data-testid="manage-saved-filter-share"
                @click="toggleShare(filter)"
              />
              <UButton
                v-if="canDelete(filter)"
                size="xs"
                color="error"
                variant="ghost"
                icon="i-lucide-trash-2"
                label="Excluir"
                :disabled="actingId === filter.id"
                data-testid="manage-saved-filter-delete"
                @click="requestDelete(filter)"
              />
            </div>
          </li>
        </ul>
      </div>
    </template>

    <template #footer>
      <ShellModalFooter
        cancel-label="Fechar"
        cancel-test-id="manage-saved-filters-close"
        :show-submit="false"
        @cancel="() => { open = false }"
      />
    </template>
  </ShellFormModal>
</template>
