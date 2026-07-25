/**
 * Composable temporal do calendário operacional (Mês/Semana/Dia).
 * Sem grade horária — apenas datas civis.
 */
export type WorkCalendarView = 'month' | 'week' | 'day'

function pad(n: number) {
  return String(n).padStart(2, '0')
}

export function formatYmd(y: number, m: number, d: number): string {
  return `${y}-${pad(m)}-${pad(d)}`
}

export function parseYmd(date: string): { y: number, m: number, d: number } {
  const parts = date.split('-').map(Number)
  const y = parts[0] ?? 1970
  const m = parts[1] ?? 1
  const d = parts[2] ?? 1
  return { y, m, d }
}

export function addDays(date: string, delta: number): string {
  const { y, m, d } = parseYmd(date)
  const dt = new Date(y, m - 1, d + delta)
  return formatYmd(dt.getFullYear(), dt.getMonth() + 1, dt.getDate())
}

export function todayYmd(now = new Date()): string {
  return formatYmd(now.getFullYear(), now.getMonth() + 1, now.getDate())
}

/** Segunda → domingo da semana da data âncora. */
export function weekDates(anchor: string): string[] {
  const { y, m, d } = parseYmd(anchor)
  const dt = new Date(y, m - 1, d)
  const day = (dt.getDay() + 6) % 7
  const monday = new Date(y, m - 1, d - day)
  return Array.from({ length: 7 }, (_, i) => {
    const cur = new Date(monday)
    cur.setDate(monday.getDate() + i)
    return formatYmd(cur.getFullYear(), cur.getMonth() + 1, cur.getDate())
  })
}

export function monthBounds(y: number, m: number): { from: string, to: string, last: number } {
  const from = formatYmd(y, m, 1)
  const last = new Date(y, m, 0).getDate()
  const to = formatYmd(y, m, last)
  return { from, to, last }
}

/** Grade do mês incluindo dias fora do mês para preencher semanas (segunda–domingo). */
export function monthGrid(y: number, m: number): Array<{ date: string, inMonth: boolean }> {
  const first = new Date(y, m - 1, 1)
  const startOffset = (first.getDay() + 6) % 7
  const start = new Date(y, m - 1, 1 - startOffset)
  const cells: Array<{ date: string, inMonth: boolean }> = []
  for (let i = 0; i < 42; i++) {
    const cur = new Date(start)
    cur.setDate(start.getDate() + i)
    const date = formatYmd(cur.getFullYear(), cur.getMonth() + 1, cur.getDate())
    cells.push({ date, inMonth: cur.getMonth() + 1 === m })
  }
  return cells
}

export function rangeForView(view: WorkCalendarView, date: string): { from: string, to: string } {
  if (view === 'day') {
    return { from: date, to: date }
  }
  if (view === 'week') {
    const days = weekDates(date)
    return { from: days[0]!, to: days[6]! }
  }
  const { y, m } = parseYmd(date)
  const grid = monthGrid(y, m)
  return { from: grid[0]!.date, to: grid[grid.length - 1]!.date }
}

export function navigateDate(view: WorkCalendarView, date: string, direction: -1 | 0 | 1, today = todayYmd()): string {
  if (direction === 0) return today
  if (view === 'day') return addDays(date, direction)
  if (view === 'week') return addDays(date, direction * 7)
  const { y, m, d } = parseYmd(date)
  const dt = new Date(y, m - 1 + direction, Math.min(d, 28))
  return formatYmd(dt.getFullYear(), dt.getMonth() + 1, dt.getDate())
}

export function formatRangeLabel(view: WorkCalendarView, date: string): string {
  const { y, m, d } = parseYmd(date)
  const months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']
  if (view === 'day') return `${pad(d)} ${months[m - 1]} ${y}`
  if (view === 'week') {
    const days = weekDates(date)
    const a = parseYmd(days[0]!)
    const b = parseYmd(days[6]!)
    return `${pad(a.d)} ${months[a.m - 1]} – ${pad(b.d)} ${months[b.m - 1]} ${b.y}`
  }
  return `${months[m - 1]} ${y}`
}

export function useWorkCalendarRange() {
  const route = useRoute()
  const router = useRouter()

  const view = computed<WorkCalendarView>(() => {
    const v = String(route.query.view || 'month')
    return (['month', 'week', 'day'].includes(v) ? v : 'month') as WorkCalendarView
  })

  const date = computed(() => {
    const raw = String(route.query.date || '')
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw
    return todayYmd()
  })

  async function setView(v: WorkCalendarView) {
    await router.replace({
      query: {
        ...route.query,
        view: v === 'month' ? undefined : v,
        date: date.value
      }
    })
  }

  async function setDate(d: string) {
    await router.replace({
      query: {
        ...route.query,
        view: view.value === 'month' ? undefined : view.value,
        date: d
      }
    })
  }

  async function navigate(direction: -1 | 0 | 1) {
    await setDate(navigateDate(view.value, date.value, direction))
  }

  const range = computed(() => rangeForView(view.value, date.value))
  const label = computed(() => formatRangeLabel(view.value, date.value))

  return {
    view,
    date,
    range,
    label,
    setView,
    setDate,
    navigate,
    weekDates,
    monthGrid,
    monthBounds,
    addDays,
    todayYmd,
    parseYmd,
    formatYmd
  }
}
