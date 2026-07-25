<script setup lang="ts">
import type { WorkDepartment } from '~/types/work'

const {
  item,
  canManageClients,
  load
} = useClientDetail()

const api = useApi()
const toast = useToast()

const departments = ref<WorkDepartment[]>([])
const loadingDepartments = ref(true)
const saving = ref(false)
const selectedId = ref<number | undefined>(undefined)

const departmentItems = computed(() =>
  departments.value.map(d => ({
    label: d.name,
    value: d.id,
    description: d.code
  }))
)

async function loadDepartments() {
  loadingDepartments.value = true
  try {
    const res = await api.work.departments.list({ per_page: 100 })
    departments.value = (res.data || []).filter(d => d.is_active)
  } catch {
    departments.value = []
  } finally {
    loadingDepartments.value = false
  }
}

watch(
  item,
  (client) => {
    selectedId.value = client?.work_department_id ?? undefined
  },
  { immediate: true }
)

async function saveDepartment() {
  if (!item.value || !canManageClients.value) return
  saving.value = true
  try {
    await api.clients.update(item.value.id, {
      work_department_id: selectedId.value ?? null
    })
    toast.add({ title: 'Departamento vinculado.', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível salvar o departamento.'), color: 'error' })
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  void loadDepartments()
})
</script>

<template>
  <div
    v-if="item"
    class="min-w-0 space-y-4"
    data-testid="client-page-departamento"
  >
    <ShellSectionHeader
      title="Departamentos"
      description="Departamento do escritório responsável por este cliente."
      test-id="client-section-departamento"
    >
      <UButton
        v-if="canManageClients"
        color="primary"
        icon="i-lucide-save"
        label="Salvar"
        :loading="saving"
        :disabled="loadingDepartments"
        @click="saveDepartment"
      />
    </ShellSectionHeader>

    <UPageCard
      v-if="departments.length || loadingDepartments"
      variant="subtle"
    >
      <UFormField
        label="Departamento vinculado"
        description="Usado para organização da carteira e filas de trabalho."
      >
        <USelectMenu
          v-model="selectedId"
          :items="departmentItems"
          value-key="value"
          placeholder="Selecione um departamento"
          :loading="loadingDepartments"
          :disabled="!canManageClients"
          class="w-full"
        />
      </UFormField>
      <p
        v-if="item.work_department"
        class="mt-3 text-sm text-muted"
      >
        Atual: {{ item.work_department.name }}
      </p>
    </UPageCard>

    <UEmpty
      v-else
      icon="i-lucide-network"
      title="Nenhum departamento cadastrado"
      description="Crie departamentos em Conta → Departamentos para vincular clientes."
    >
      <UButton
        to="/conta/departamentos"
        label="Abrir departamentos"
        color="neutral"
        variant="soft"
      />
    </UEmpty>
  </div>
</template>
