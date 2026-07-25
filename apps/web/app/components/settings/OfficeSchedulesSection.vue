<script setup lang="ts">
/**
 * Agendas mensais por monitor (dia 1–28) para a carteira do escritório.
 */
import type { OfficeMonitorSchedulePolicy } from '~/types/api'
import { DEFAULT_MONITOR_SCHEDULES } from '~/utils/office-settings'

const props = withDefaults(defineProps<{
  policies: OfficeMonitorSchedulePolicy[]
  loading?: boolean
  savingKey?: string | null
  readonly?: boolean
  showHeader?: boolean
}>(), {
  loading: false,
  savingKey: null,
  readonly: false,
  showHeader: true
})

const emit = defineEmits<{
  save: [payload: { monitor_key: string, day_of_month: number }]
}>()

const dayItems = Array.from({ length: 28 }, (_, i) => ({
  label: `Dia ${i + 1}`,
  value: i + 1
}))

const rows = computed(() => {
  const byKey = new Map(props.policies.map(p => [p.monitor_key, p]))
  return DEFAULT_MONITOR_SCHEDULES.map((m) => {
    const p = byKey.get(m.key)
    return {
      monitor_key: m.key,
      monitor_label: p?.monitor_label || m.label,
      day_of_month: p?.day_of_month ?? null,
      is_default: p?.is_default ?? true,
      next_run_at: p?.next_run_at ?? null,
      last_run_at: p?.last_run_at ?? null,
      timezone: p?.timezone ?? null
    }
  })
})

const draftDays = reactive<Record<string, number | undefined>>({})

watch(
  rows,
  (list) => {
    for (const r of list) {
      if (draftDays[r.monitor_key] == null && r.day_of_month != null) {
        draftDays[r.monitor_key] = r.day_of_month
      }
    }
  },
  { immediate: true }
)

function save(key: string) {
  const day = draftDays[key]
  if (day == null || day < 1 || day > 28) {
    useToast().add({ title: 'Informe um dia entre 1 e 28.', color: 'warning' })
    return
  }
  emit('save', { monitor_key: key, day_of_month: day })
}
</script>

<template>
  <div data-testid="settings-schedules-section">
    <ShellSectionHeader
      v-if="showHeader"
      title="Agendas"
      description="Dia do mês (1–28) por monitor."
    />

    <ShellSectionCard>
      <div
        v-if="loading && !policies.length"
        class="space-y-2"
        role="status"
        aria-label="Carregando agendas"
      >
        <USkeleton class="h-10 w-full" />
        <USkeleton class="h-10 w-full" />
      </div>
      <UEmpty
        v-else-if="!rows.length"
        icon="i-lucide-calendar"
        title="Nenhum monitor"
      />
      <ul
        v-else
        class="divide-y divide-default"
        aria-label="Agendas por monitor"
      >
        <li
          v-for="row in rows"
          :key="row.monitor_key"
          class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
          :data-testid="`settings-schedule-${row.monitor_key}`"
        >
          <div class="min-w-0">
            <p class="text-sm font-medium text-highlighted">
              {{ row.monitor_label }}
            </p>
            <p
              v-if="row.next_run_at"
              class="text-xs text-muted"
            >
              Próxima {{ formatDateTime(row.next_run_at) }}
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <USelect
              v-model="draftDays[row.monitor_key]"
              :items="dayItems"
              value-key="value"
              placeholder="Dia"
              class="w-28"
              :disabled="readonly"
              :aria-label="`Dia de ${row.monitor_label}`"
            />
            <UButton
              v-if="!readonly"
              size="sm"
              color="neutral"
              variant="soft"
              label="Salvar"
              icon="i-lucide-save"
              :loading="savingKey === row.monitor_key"
              @click="save(row.monitor_key)"
            />
          </div>
        </li>
      </ul>
    </ShellSectionCard>
  </div>
</template>
