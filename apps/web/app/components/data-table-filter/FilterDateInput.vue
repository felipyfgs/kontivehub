<script setup lang="ts">
/**
 * Seletor de data/competência dos filtros — Nuxt UI, sem `<input type="date|month">`.
 *
 * - month → USelect ano + mês (competência AAAA-MM)
 * - date / date_range → UInputDate + UCalendar (padrão docs Nuxt UI)
 *
 * Contrato externo permanece string AAAA-MM / AAAA-MM-DD.
 */
import { CalendarDate, type DateValue } from '@internationalized/date'
import { isValidDateValue, isValidMonthValue } from '~/utils/data-table-filters'

const props = withDefaults(defineProps<{
  mode: 'month' | 'date' | 'date_range'
  modelValue?: string | null
  valueTo?: string | null
  ariaLabel?: string
  testId?: string
}>(), {
  modelValue: '',
  valueTo: '',
  ariaLabel: 'Data',
  testId: 'data-table-filter-date'
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'update:valueTo': [value: string]
}>()

const inputDate = useTemplateRef<{ inputsRef?: Array<{ $el?: HTMLElement }> }>('inputDate')

const MONTH_ITEMS: Array<{ label: string, value: string }> = [
  { label: 'Janeiro', value: '01' },
  { label: 'Fevereiro', value: '02' },
  { label: 'Março', value: '03' },
  { label: 'Abril', value: '04' },
  { label: 'Maio', value: '05' },
  { label: 'Junho', value: '06' },
  { label: 'Julho', value: '07' },
  { label: 'Agosto', value: '08' },
  { label: 'Setembro', value: '09' },
  { label: 'Outubro', value: '10' },
  { label: 'Novembro', value: '11' },
  { label: 'Dezembro', value: '12' }
]

const currentYear = new Date().getFullYear()
const yearItems = Array.from({ length: 16 }, (_, index) => {
  const year = String(currentYear + 1 - index)
  return { label: year, value: year }
})

function parseYmd(text: string): CalendarDate | null {
  if (!isValidDateValue(text)) return null
  const [y, m, d] = text.split('-').map(Number)
  return new CalendarDate(y!, m!, d!)
}

function formatYmd(value: DateValue): string {
  return `${value.year}-${String(value.month).padStart(2, '0')}-${String(value.day).padStart(2, '0')}`
}

function emitMonth(year: string, month: string) {
  if (!year || !month) {
    emit('update:modelValue', '')
    return
  }
  const next = `${year}-${month}`
  emit('update:modelValue', isValidMonthValue(next) ? next : '')
}

const monthYear = computed({
  get: () => {
    const raw = String(props.modelValue ?? '').trim()
    if (!isValidMonthValue(raw)) return ''
    return raw.slice(0, 4)
  },
  set: (year: string) => emitMonth(year, monthPart.value || '01')
})

const monthPart = computed({
  get: () => {
    const raw = String(props.modelValue ?? '').trim()
    if (!isValidMonthValue(raw)) return ''
    return raw.slice(5, 7)
  },
  set: (month: string) => emitMonth(monthYear.value || String(currentYear), month)
})

const singleModel = computed<CalendarDate | undefined>({
  get: () => {
    const raw = String(props.modelValue ?? '').trim()
    if (!raw) return undefined
    return parseYmd(raw) ?? undefined
  },
  set: (value) => {
    emit('update:modelValue', value ? formatYmd(value) : '')
  }
})

const rangeModel = computed<{ start: CalendarDate, end: CalendarDate } | undefined>({
  get: () => {
    const from = parseYmd(String(props.modelValue ?? '').trim())
    const to = parseYmd(String(props.valueTo ?? '').trim())
    if (!from && !to) return undefined
    if (from && to) return { start: from, end: to }
    if (from) return { start: from, end: from }
    return { start: to!, end: to! }
  },
  set: (value) => {
    if (!value?.start && !value?.end) {
      emit('update:modelValue', '')
      emit('update:valueTo', '')
      return
    }
    emit('update:modelValue', value?.start ? formatYmd(value.start) : '')
    emit('update:valueTo', value?.end ? formatYmd(value.end) : '')
  }
})

const calendarReference = computed(() => {
  const refs = inputDate.value?.inputsRef
  if (!refs?.length) return undefined
  return refs[refs.length - 1]?.$el
})
</script>

<template>
  <div
    class="min-w-0"
    :data-testid="testId"
    :data-mode="mode"
  >
    <!-- Competência: ano + mês tipados (sem native month picker) -->
    <div
      v-if="mode === 'month'"
      class="grid min-w-0 grid-cols-2 gap-2"
      data-testid="data-table-filter-month-parts"
    >
      <USelect
        v-model="monthYear"
        :items="yearItems"
        value-key="value"
        placeholder="Ano"
        color="neutral"
        class="min-w-0 w-full"
        :aria-label="`${ariaLabel} (ano)`"
        :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
      />
      <USelect
        v-model="monthPart"
        :items="MONTH_ITEMS"
        value-key="value"
        placeholder="Mês"
        color="neutral"
        class="min-w-0 w-full"
        :aria-label="`${ariaLabel} (mês)`"
        :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
      />
    </div>

    <UInputDate
      v-else-if="mode === 'date_range'"
      ref="inputDate"
      v-model="rangeModel"
      range
      color="neutral"
      variant="outline"
      locale="pt-BR"
      class="w-full min-w-0"
      :aria-label="ariaLabel"
      separator-icon="i-lucide-arrow-right"
    >
      <template #trailing>
        <UPopover
          :reference="calendarReference"
          :content="{ align: 'end', side: 'bottom', sideOffset: 8 }"
        >
          <UButton
            color="neutral"
            variant="link"
            size="sm"
            icon="i-lucide-calendar"
            class="px-0"
            :aria-label="`Abrir calendário: ${ariaLabel}`"
          />
          <template #content>
            <UCalendar
              v-model="rangeModel"
              range
              :number-of-months="2"
              class="p-2"
            />
          </template>
        </UPopover>
      </template>
    </UInputDate>

    <UInputDate
      v-else
      ref="inputDate"
      v-model="singleModel"
      color="neutral"
      variant="outline"
      locale="pt-BR"
      class="w-full min-w-0"
      :aria-label="ariaLabel"
    >
      <template #trailing>
        <UPopover
          :reference="calendarReference"
          :content="{ align: 'end', side: 'bottom', sideOffset: 8 }"
        >
          <UButton
            color="neutral"
            variant="link"
            size="sm"
            icon="i-lucide-calendar-days"
            class="px-0"
            :aria-label="`Abrir calendário: ${ariaLabel}`"
          />
          <template #content>
            <UCalendar
              v-model="singleModel"
              class="p-2"
            />
          </template>
        </UPopover>
      </template>
    </UInputDate>
  </div>
</template>
