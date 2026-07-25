<script setup lang="ts">
/**
 * Associação em lote de clientes a categorias fiscais (POST category-links/batch).
 */
import type { FiscalCategory } from '~/types/api'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  /** IDs pré-selecionados (ex.: filtro client_id ou seleção de linhas). */
  defaultClientIds?: number[]
  /** Restringe categorias ao module_key quando informado. */
  moduleKey?: string | null
}>()

const emit = defineEmits<{
  success: []
}>()

const {
  loadCategories,
  associateCategoriesBatch,
  associating
} = useMonitoringActions(computed(() => props.moduleKey || 'dashboard'))

const categories = ref<FiscalCategory[]>([])
const categoryId = ref<number | null>(null)
const clientIdsRaw = ref('')
const loadError = ref<string | null>(null)

const categoryItems = computed(() =>
  categories.value.map(c => ({
    label: `${c.code} — ${c.name}`,
    value: c.id
  }))
)

const categoryIdModel = computed<number | undefined>({
  get: () => categoryId.value ?? undefined,
  set: (value) => { categoryId.value = value ?? null }
})

watch(open, async (isOpen) => {
  if (!isOpen) {
    categoryId.value = null
    clientIdsRaw.value = ''
    loadError.value = null
    return
  }
  if (props.defaultClientIds?.length) {
    clientIdsRaw.value = props.defaultClientIds.join(', ')
  }
  try {
    const all = await loadCategories()
    const mk = props.moduleKey
    categories.value = mk
      ? all.filter(c =>
          !c.module_key
          || c.module_key === mk
          || (mk === 'dctfweb' && c.module_key === 'dctfweb_mit')
          || (mk === 'installments' && c.module_key === 'parcelamentos')
          || (mk === 'declarations' && c.module_key === 'declaracoes')
          || (mk === 'guides' && c.module_key === 'guias')
        )
      : all
    if (!categories.value.length) {
      loadError.value = 'Nenhuma categoria fiscal retornada pela API.'
    }
  } catch {
    loadError.value = 'Falha ao carregar categorias.'
  }
})

function parseClientIds(raw: string): number[] {
  return [...new Set(
    raw
      .split(/[\s,;]+/)
      .map(s => Number(s.trim()))
      .filter(n => Number.isFinite(n) && n > 0)
  )]
}

async function submit() {
  if (!categoryId.value) return
  const ids = parseClientIds(clientIdsRaw.value)
  const ok = await associateCategoriesBatch({
    fiscal_category_id: categoryId.value,
    client_ids: ids
  })
  if (ok) {
    open.value = false
    emit('success')
  }
}
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Associar categorias"
    description="Vincule clientes a uma categoria fiscal."
    content-class="sm:max-w-md"
    submit-label="Associar"
    :loading="associating"
    :disabled="!categoryId || !clientIdsRaw.trim()"
    test-id="associate-categories-modal"
    @cancel="() => { open = false }"
    @submit="submit"
  >
    <template #body>
      <div class="space-y-4 text-sm">
        <UAlert
          v-if="loadError"
          color="error"
          icon="i-lucide-circle-x"
          :title="loadError"
        />
        <UFormField
          label="Categoria"
          required
        >
          <USelect
            v-model="categoryIdModel"
            :items="categoryItems"
            value-key="value"
            placeholder="Selecione"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="IDs de clientes"
          hint="Separados por vírgula"
          required
        >
          <UTextarea
            v-model="clientIdsRaw"
            :rows="3"
            placeholder="12, 34, 56"
          />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <ShellModalFooter
        submit-label="Associar"
        submit-test-id="associate-categories-submit"
        :loading="associating"
        :disabled="!categoryId || !clientIdsRaw.trim()"
        @cancel="() => { open = false }"
        @submit="submit"
      />
    </template>
  </ShellFormModal>
</template>
