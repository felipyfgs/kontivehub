<script setup lang="ts">
import type { ClientCategory, ClientCategoryColor } from '~/types/api'
import { apiErrorMessage } from '~/utils/api-error'
import {
  CLIENT_CATEGORY_COLOR_PALETTE,
  clientCategoryColorItem
} from '~/utils/client-category-colors'

const NAME_MAX = 80

const props = defineProps<{
  categories: ClientCategory[]
  loading?: boolean
}>()

const emit = defineEmits<{
  updated: []
}>()

const open = defineModel<boolean>('open', { default: false })
const api = useApi()
const toast = useToast()
const submitting = ref(false)
const editingId = ref<number | null>(null)
const name = ref('')
const color = ref<ClientCategoryColor>('primary')
const colorPickerOpen = ref(false)

const colorItems = CLIENT_CATEGORY_COLOR_PALETTE

const editingCategory = computed(() =>
  props.categories.find(category => category.id === editingId.value) ?? null
)

const selectedColor = computed(() => clientCategoryColorItem(color.value))

function resetForm() {
  editingId.value = null
  name.value = ''
  color.value = 'primary'
  colorPickerOpen.value = false
}

function edit(category: ClientCategory) {
  editingId.value = category.id
  name.value = category.name
  color.value = category.color
  colorPickerOpen.value = false
}

function selectColor(value: ClientCategoryColor) {
  color.value = value
  colorPickerOpen.value = false
}

async function save() {
  const normalizedName = name.value.trim().replace(/\s+/g, ' ')
  if (!normalizedName || submitting.value) return
  submitting.value = true
  try {
    if (editingId.value) {
      await api.clientCategories.update(editingId.value, {
        name: normalizedName,
        color: color.value
      })
      toast.add({ title: 'Categoria atualizada.', color: 'success' })
    } else {
      await api.clientCategories.create({ name: normalizedName, color: color.value })
      toast.add({ title: 'Categoria criada.', color: 'success' })
    }
    resetForm()
    emit('updated')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível salvar a categoria.'),
      color: 'error'
    })
  } finally {
    submitting.value = false
  }
}

async function toggleActive(category: ClientCategory) {
  if (submitting.value) return
  submitting.value = true
  try {
    await api.clientCategories.update(category.id, { is_active: !category.is_active })
    toast.add({
      title: category.is_active ? 'Categoria arquivada.' : 'Categoria reativada.',
      description: category.is_active
        ? 'Os vínculos existentes foram preservados.'
        : undefined,
      color: 'success'
    })
    if (editingId.value === category.id) resetForm()
    emit('updated')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível atualizar a categoria.'),
      color: 'error'
    })
  } finally {
    submitting.value = false
  }
}

watch(open, (isOpen) => {
  if (!isOpen) resetForm()
})
</script>

<template>
  <ShellScrollableModal
    v-model:open="open"
    title="Categorias"
    description="Crie e organize categorias para classificar seus clientes."
    content-class="max-h-[calc(100dvh-2rem)] sm:max-w-md"
    :show-default-footer="false"
    test-id="category-manager-modal"
    @cancel="() => { open = false }"
  >
    <template #body>
      <div class="space-y-5">
        <form
          class="space-y-4 rounded-xl border border-default bg-elevated/40 p-4"
          @submit.prevent="save"
        >
          <UFormField
            label="Título"
            name="category_name"
            :hint="`${name.length}/${NAME_MAX}`"
            :description="editingCategory ? `Editando ${editingCategory.name}` : undefined"
          >
            <div class="flex items-center gap-2">
              <UInput
                v-model="name"
                :maxlength="NAME_MAX"
                placeholder="Ex: Comércio, Indústria..."
                class="min-w-0 flex-1"
                :disabled="submitting"
                autofocus
              />

              <UPopover
                v-model:open="colorPickerOpen"
                :content="{ side: 'bottom', align: 'end', sideOffset: 8 }"
              >
                <UTooltip :text="`Cor: ${selectedColor.label}`">
                  <button
                    type="button"
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-default bg-default transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                    :aria-label="`Cor da categoria: ${selectedColor.label}`"
                    :aria-expanded="colorPickerOpen"
                    :disabled="submitting"
                  >
                    <span
                      aria-hidden="true"
                      class="size-5 rounded-md ring-1 ring-inset ring-inverted/15"
                      :style="{ backgroundColor: selectedColor.hex }"
                    />
                  </button>
                </UTooltip>

                <template #content>
                  <div
                    role="radiogroup"
                    aria-label="Paleta de cores"
                    class="grid grid-cols-5 gap-2.5 p-3"
                  >
                    <UTooltip
                      v-for="item in colorItems"
                      :key="item.value"
                      :text="item.label"
                    >
                      <button
                        type="button"
                        role="radio"
                        :aria-checked="color === item.value"
                        :aria-label="item.label"
                        class="relative flex size-7 items-center justify-center rounded-full transition-transform focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-default"
                        :class="color === item.value
                          ? 'scale-110 ring-2 ring-inverted ring-offset-2 ring-offset-default'
                          : 'hover:scale-105'"
                        @click="selectColor(item.value)"
                      >
                        <span
                          aria-hidden="true"
                          class="size-full rounded-full ring-1 ring-inset ring-inverted/10"
                          :style="{ backgroundColor: item.hex }"
                        />
                        <UIcon
                          v-if="color === item.value"
                          name="i-lucide-check"
                          class="absolute size-3.5 text-white drop-shadow-sm"
                          aria-hidden="true"
                        />
                      </button>
                    </UTooltip>
                  </div>
                </template>
              </UPopover>
            </div>
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              v-if="editingId"
              type="button"
              label="Cancelar"
              color="neutral"
              variant="ghost"
              :disabled="submitting"
              @click="resetForm"
            />
            <UButton
              type="submit"
              :label="editingId ? 'Salvar' : 'Adicionar'"
              :icon="editingId ? 'i-lucide-check' : 'i-lucide-plus'"
              :loading="submitting"
              :disabled="!name.trim()"
            />
          </div>
        </form>

        <div class="space-y-2">
          <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-medium text-highlighted">
              Catálogo
            </h3>
            <span class="text-xs text-muted">{{ categories.length }} categoria(s)</span>
          </div>

          <div
            v-if="loading"
            class="space-y-2"
          >
            <USkeleton
              v-for="item in 3"
              :key="item"
              class="h-11 w-full"
            />
          </div>

          <UEmpty
            v-else-if="!categories.length"
            icon="i-lucide-tags"
            title="Nenhuma categoria criada"
            description="Use o formulário acima para criar a primeira."
            class="py-6"
          />

          <ul
            v-else
            class="max-h-64 divide-y divide-default overflow-y-auto overscroll-y-contain rounded-xl border border-default"
          >
            <li
              v-for="category in categories"
              :key="category.id"
              class="flex items-center gap-2 px-3 py-2"
              :class="category.is_active ? 'bg-default' : 'bg-elevated/40'"
            >
              <ClientsClientCategoryBadge
                :label="category.name"
                :color="category.color"
                :archived="!category.is_active"
                class="min-w-0 max-w-48"
              />
              <span class="min-w-0 flex-1 text-xs text-muted">
                {{ category.clients_count }} cliente(s)
                <template v-if="!category.is_active"> · arquivada</template>
              </span>
              <UButton
                icon="i-lucide-pencil"
                color="neutral"
                variant="ghost"
                size="sm"
                :aria-label="`Editar ${category.name}`"
                :disabled="submitting"
                @click="edit(category)"
              />
              <UButton
                :icon="category.is_active ? 'i-lucide-archive' : 'i-lucide-archive-restore'"
                :color="category.is_active ? 'neutral' : 'success'"
                variant="ghost"
                size="sm"
                :aria-label="category.is_active ? `Arquivar ${category.name}` : `Reativar ${category.name}`"
                :disabled="submitting"
                @click="toggleActive(category)"
              />
            </li>
          </ul>
        </div>
      </div>
    </template>

    <template #footer>
      <ShellModalFooter
        cancel-label="Fechar"
        :show-submit="false"
        @cancel="() => { open = false }"
      />
    </template>
  </ShellScrollableModal>
</template>
