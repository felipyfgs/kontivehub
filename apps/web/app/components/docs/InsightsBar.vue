<script setup lang="ts">
/**
 * Faixa de triagem: contagens reais + clique = filtra (drill-down).
 * Mobile: ShellScrollableTabs (scroll touch).
 * sm+: grid de cards (HomeStats).
 */
import type { NotesInsights } from '~/types/api'
import type { NotesTriageQueue } from '~/utils/notes-filters'
import ShellScrollableTabs from '~/components/shell/ScrollableTabs.vue'

const props = defineProps<{
  insights: NotesInsights | null
  loading?: boolean
  activeQueue?: NotesTriageQueue
}>()

const emit = defineEmits<{
  select: [queue: NotesTriageQueue]
}>()

type Chip = {
  key: NotesTriageQueue
  title: string
  icon: string
  value: number | string
  tone?: 'default' | 'warning' | 'error' | 'success'
}

const chips = computed((): Chip[] => {
  const i = props.insights
  if (!i) {
    return [
      { key: 'all', title: 'No filtro', icon: 'i-lucide-layers', value: '…' },
      { key: 'review', title: 'Em revisão', icon: 'i-lucide-file-warning', value: '…', tone: 'warning' },
      { key: 'cancelled', title: 'Canceladas', icon: 'i-lucide-ban', value: '…', tone: 'error' },
      { key: 'competence', title: 'Competência atual', icon: 'i-lucide-calendar', value: '…' },
      { key: 'missing_party', title: 'Sem nome de parte', icon: 'i-lucide-user-x', value: '…', tone: 'warning' }
    ]
  }
  return [
    {
      key: 'all',
      title: 'No filtro',
      icon: 'i-lucide-layers',
      value: i.total
    },
    {
      key: 'review',
      title: 'Em revisão',
      icon: 'i-lucide-file-warning',
      value: i.review,
      tone: 'warning'
    },
    {
      key: 'cancelled',
      title: 'Canceladas',
      icon: 'i-lucide-ban',
      value: i.cancelled,
      tone: 'error'
    },
    {
      key: 'competence',
      title: i.competence_current_label || 'Competência',
      icon: 'i-lucide-calendar',
      value: i.competence_current
    },
    {
      key: 'missing_party',
      title: 'Sem nome de parte',
      icon: 'i-lucide-user-x',
      value: i.missing_party_name,
      tone: 'warning'
    }
  ]
})

const tabItems = computed(() =>
  chips.value.map(chip => ({
    label: chip.title,
    value: chip.key,
    badge: chip.value
  }))
)

function leadingClass(tone?: Chip['tone'], active?: boolean) {
  if (active) return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col'
  if (tone === 'error') return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-error/10 ring ring-inset ring-error/25 flex-col'
  if (tone === 'warning') return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-warning/10 ring ring-inset ring-warning/25 flex-col'
  if (tone === 'success') return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-success/10 ring ring-inset ring-success/25 flex-col'
  return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col'
}

function onTabSelect(value: string | number) {
  emit('select', String(value) as NotesTriageQueue)
}
</script>

<template>
  <div
    data-testid="notes-insights"
    class="w-full min-w-0"
  >
    <div class="mb-2 flex items-center justify-between gap-2">
      <p class="text-xs font-medium uppercase tracking-wide text-muted">
        Triagem operacional
      </p>
      <p
        v-if="loading"
        class="text-xs text-dimmed"
      >
        Atualizando…
      </p>
    </div>

    <!-- Mobile: tabs com scroll touch (mesmo contrato KPI fiscal). -->
    <ShellScrollableTabs
      class="sm:hidden"
      :model-value="activeQueue || 'all'"
      :items="tabItems"
      size="sm"
      aria-label="Triagem operacional"
      test-id="notes-insights-tabs"
      @update:model-value="onTabSelect"
    />

    <!-- sm+: cards densos. -->
    <UPageGrid class="hidden grid-cols-2 gap-2 sm:grid sm:gap-4 lg:grid-cols-5 lg:gap-px">
      <UPageCard
        v-for="chip in chips"
        :key="chip.key"
        :icon="chip.icon"
        :title="chip.title"
        variant="subtle"
        :highlight="activeQueue === chip.key"
        highlight-color="primary"
        :ui="{
          container: 'min-w-0 gap-y-1.5 p-3 sm:p-6',
          wrapper: 'min-w-0 items-start',
          leading: leadingClass(chip.tone, activeQueue === chip.key),
          title: 'w-full truncate font-normal text-muted text-xs uppercase'
        }"
        class="min-w-0 cursor-pointer hover:z-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary lg:rounded-none first:rounded-l-lg last:rounded-r-lg"
        role="button"
        tabindex="0"
        :aria-pressed="activeQueue === chip.key"
        :aria-label="`Filtrar triagem: ${chip.title}`"
        @click="emit('select', chip.key)"
        @keydown.enter.prevent="emit('select', chip.key)"
        @keydown.space.prevent="emit('select', chip.key)"
      >
        <div class="flex items-center gap-2">
          <span class="text-2xl font-semibold tabular-nums text-highlighted">
            {{ chip.value }}
          </span>
        </div>
      </UPageCard>
    </UPageGrid>
  </div>
</template>
