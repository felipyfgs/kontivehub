<script setup lang="ts">
/**
 * Departamentos do escritório (catálogo operacional Work) — Filter Lite (só busca).
 * Chrome: ShellSectionHeader + ShellFilterToolbarLite.
 * Arquétipo settings/lista: .local/reference/.../settings/members.vue
 */
import type { WorkDepartment } from '~/types/work'
import { canManageWorkCatalog } from '~/utils/permissions'
import { apiErrorMessage } from '~/utils/api-error'

const api = useApi()
const toast = useToast()
const { me, sessionEpoch } = useDashboard()

if (!canManageWorkCatalog(me.value)) {
  await navigateTo('/conta/escritorio')
}

const items = ref<WorkDepartment[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const q = ref('')
const togglingId = ref<number | null>(null)

const filtered = computed(() => {
  const term = q.value.trim()
  if (!term) return items.value
  const re = new RegExp(term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i')
  return items.value.filter(d => re.test(d.name) || re.test(d.code))
})

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const res = await api.work.departments.list({ per_page: 100 })
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    items.value = res.data
  } catch (e) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    items.value = []
    loadError.value = apiErrorMessage(e, 'Falha ao listar departamentos.')
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

async function toggle(d: WorkDepartment) {
  togglingId.value = d.id
  try {
    await api.work.departments.update(d.id, { is_active: !d.is_active })
    toast.add({
      title: d.is_active ? 'Departamento desativado.' : 'Departamento reativado.',
      color: 'success'
    })
    await load()
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Falha ao atualizar.'), color: 'error' })
  } finally {
    togglingId.value = null
  }
}

watch(sessionEpoch, () => {
  items.value = []
  void load()
})
onMounted(load)
</script>

<template>
  <!--
    members.vue: UPageCard naked (título + ação) + UPageCard subtle (search + lista)
  -->
  <div data-testid="settings-departments">
    <ShellSectionHeader
      title="Departamentos"
      description="Organize a operação em áreas (Fiscal, Contábil, DP…)."
      test-id="settings-departments-header"
    >
      <SettingsDepartmentAddModal @created="load" />
    </ShellSectionHeader>

    <ShellLoadError
      v-if="loadError"
      :title="loadError"
      test-id="departments-load-error"
      @retry="load"
    />

    <!-- Lista settings: UPageCard subtle com header p-0 (exceção documentada vs SectionCard). -->
    <UPageCard
      variant="subtle"
      :ui="{
        container: 'p-0 sm:p-0 gap-y-0',
        wrapper: 'items-stretch',
        header: 'p-4 mb-0 border-b border-default'
      }"
    >
      <template #header>
        <ShellFilterToolbarLite
          :q="q"
          search-placeholder="Buscar por nome ou sigla"
          search-aria-label="Buscar por nome ou sigla"
          :loading="loading"
          test-id-prefix="departments"
          @update:q="(value) => { q = value }"
          @refresh="load"
        />
      </template>

      <div
        v-if="loading && !items.length"
        class="space-y-0 divide-y divide-default"
        data-testid="departments-loading"
      >
        <div
          v-for="i in 4"
          :key="i"
          class="flex items-center gap-3 px-4 py-3 sm:px-6"
        >
          <USkeleton class="size-9 shrink-0 rounded-lg" />
          <div class="min-w-0 flex-1 space-y-2">
            <USkeleton class="h-4 w-40" />
            <USkeleton class="h-3 w-16" />
          </div>
          <USkeleton class="h-6 w-14 rounded-full" />
        </div>
      </div>

      <UEmpty
        v-else-if="!items.length"
        icon="i-lucide-building"
        title="Nenhum departamento"
        description="Crie o primeiro para classificar processos e tarefas."
        class="py-10"
        data-testid="departments-empty"
      />

      <UEmpty
        v-else-if="!filtered.length"
        icon="i-lucide-search-x"
        title="Nenhum resultado"
        :description="`Nada encontrado para “${q.trim()}”.`"
        class="py-10"
        data-testid="departments-search-empty"
      />

      <SettingsDepartmentsList
        v-else
        :departments="filtered"
        :toggling-id="togglingId"
        @toggle="toggle"
      />
    </UPageCard>
  </div>
</template>
