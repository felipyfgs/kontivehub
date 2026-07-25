<script setup lang="ts">
/**
 * Catálogo de documentos — UTable no arquétipo lista admin (customers.vue).
 * Fonte: .local/reference/nuxt-dashboard-template/app/pages/customers.vue
 * + adaptação produto em apps/web/app/pages/clients/index.vue
 *
 * Seleção nativa TanStack (rowSelection + getRowId = access_key).
 * Ordem: cronologia global fixa fornecida pelo cursor; sem sorting local enganoso.
 * Navegação: lotes incrementais por cursor (`next_cursor`), sem simular offset.
 */
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import type { Row } from '@tanstack/table-core'
import type { NfseNote } from '~/types/api'
import ShellDataTable from '~/components/shell/DataTable.vue'
import { documentKindLabel } from '~/utils/document-kinds'

const props = withDefaults(defineProps<{
  notes: NfseNote[]
  loading?: boolean
  error?: string | null
  selectedAccessKey?: string | null
  pageSize?: number
  total?: number
  hasMore?: boolean
  selectable?: boolean
  selectedKeys?: string[]
}>(), {
  pageSize: 25,
  total: 0,
  hasMore: false
})

const emit = defineEmits<{
  'select': [note: NfseNote]
  'update:pageSize': [size: number]
  'loadMore': []
  'retry': []
  'update:selectedKeys': [keys: string[]]
}>()

const UCheckbox = resolveComponent('UCheckbox')
const UButton = resolveComponent('UButton')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()

/** Seleção nativa UTable (id da linha = access_key). */
const rowSelection = ref<Record<string, boolean>>({})

watch(
  () => props.selectedKeys,
  (keys) => {
    const next: Record<string, boolean> = {}
    for (const k of keys || []) {
      next[k] = true
    }
    const prev = rowSelection.value
    const same
      = Object.keys(prev).length === Object.keys(next).length
        && Object.keys(next).every(k => prev[k] === true)
    if (!same) {
      rowSelection.value = next
    }
  },
  { immediate: true, deep: true }
)

watch(
  rowSelection,
  (sel) => {
    if (!props.selectable) return
    const keys = Object.entries(sel)
      .filter(([, on]) => !!on)
      .map(([k]) => k)
    const current = props.selectedKeys || []
    if (
      keys.length === current.length
      && keys.every(k => current.includes(k))
    ) {
      return
    }
    emit('update:selectedKeys', keys)
  },
  { deep: true }
)

function shortKey(accessKey: string) {
  if (accessKey.length <= 18) return accessKey
  return `${accessKey.slice(0, 8)}…${accessKey.slice(-6)}`
}

/** Rótulo acessível completo (tipo + número). */
function noteTitle(note: NfseNote) {
  const kind = documentKindLabel(note.kind || 'NFSE')
  if (note.number) return `${kind} nº ${note.number}`
  return shortKey(note.access_key)
}

/** Célula Documento: só número (ou chave curta). */
function documentCellLabel(note: NfseNote) {
  if (note.number) return String(note.number)
  return shortKey(note.access_key)
}

function partyCell(name?: string | null, cnpj?: string | null): { name: string, cnpj: string | null } {
  return {
    name: truncateText(name, 34) || formatCnpj(cnpj) || '—',
    cnpj: name && cnpj ? formatCnpj(cnpj) : null
  }
}

/** Situação fiscal na grade — nunca usar “XML completo” como situação. */
function documentSituationLabel(note: NfseNote): string {
  const raw = (note.status_label || '').trim()
  if (raw && !/^XML\b/i.test(raw) && !/resumo/i.test(raw) && !/completo/i.test(raw)) {
    return raw
  }
  return statusLabel(note.status)
}

function xmlCompletenessHint(note: NfseNote): string | null {
  if (note.has_full_xml === false || note.is_summary === true || note.xml_completeness === 'SUMMARY_ONLY') {
    return 'Somente resumo'
  }
  return null
}

async function copyAccessKey(note: NfseNote) {
  try {
    await navigator.clipboard.writeText(note.access_key)
    toast.add({
      title: 'Chave copiada',
      description: shortKey(note.access_key),
      color: 'success'
    })
  } catch {
    toast.add({
      title: 'Não foi possível copiar a chave',
      color: 'error'
    })
  }
}

function getRowItems(row: Row<NfseNote>): DropdownMenuItem[][] {
  const note = row.original
  return [
    [
      {
        type: 'label',
        label: noteTitle(note)
      },
      {
        label: 'Abrir detalhe',
        icon: 'i-lucide-panel-right-open',
        onSelect: () => emit('select', note)
      },
      {
        label: 'Copiar chave de acesso',
        icon: 'i-lucide-copy',
        onSelect: () => {
          void copyAccessKey(note)
        }
      }
    ]
  ]
}

const columns = computed<TableColumn<NfseNote>[]>(() => {
  const cols: TableColumn<NfseNote>[] = []

  if (props.selectable) {
    cols.push({
      id: 'select',
      enableHiding: false,
      enableSorting: false,
      header: ({ table: t }) =>
        h(UCheckbox, {
          'modelValue': t.getIsSomePageRowsSelected()
            ? 'indeterminate'
            : t.getIsAllPageRowsSelected(),
          'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
            t.toggleAllPageRowsSelected(!!value),
          'aria-label': 'Selecionar todas as notas carregadas'
        }),
      cell: ({ row }) =>
        h(UCheckbox, {
          'modelValue': row.getIsSelected(),
          'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
            row.toggleSelected(!!value),
          'aria-label': `Selecionar ${noteTitle(row.original)}`,
          'onClick': (e: Event) => e.stopPropagation()
        }),
      meta: {
        class: {
          th: 'w-10',
          td: 'w-10'
        }
      }
    })
  }

  cols.push(
    {
      id: 'kind',
      accessorFn: row => row.kind_label || documentKindLabel(row.kind || 'NFSE'),
      header: 'Tipo',
      enableSorting: false,
      meta: {
        class: {
          th: 'w-24',
          td: 'w-24'
        }
      }
    },
    {
      id: 'number',
      accessorFn: row => row.number || row.access_key,
      header: 'Documento',
      enableHiding: false,
      enableSorting: false,
      meta: {
        class: {
          th: 'w-36 min-w-28',
          td: 'w-36 min-w-28'
        }
      }
    },
    {
      id: 'issued_at',
      accessorFn: row => row.issued_at || '',
      header: 'Emissão',
      enableSorting: false,
      meta: {
        class: {
          th: 'w-32',
          td: 'w-32'
        }
      }
    },
    {
      accessorKey: 'competence',
      header: 'Competência',
      enableSorting: false,
      meta: {
        class: {
          th: 'hidden md:table-cell w-32',
          td: 'hidden md:table-cell w-32'
        }
      }
    },
    {
      id: 'issuer',
      accessorFn: row => row.issuer_name || row.issuer_cnpj || '',
      // NF-e/NFC-e: emit · NFS-e: prestador
      header: 'Emitente / Prestador',
      enableSorting: false,
      meta: {
        class: {
          th: 'min-w-36 w-full',
          td: 'min-w-36 w-full overflow-hidden'
        }
      }
    },
    {
      id: 'recipient',
      accessorFn: row => row.taker_name || row.taker_cnpj || '',
      // NF-e/NFC-e: dest · NFS-e: tomador
      header: 'Destinatário / Tomador',
      enableSorting: false,
      meta: {
        class: {
          th: 'w-52 min-w-36 hidden sm:table-cell',
          td: 'w-52 min-w-36 overflow-hidden hidden sm:table-cell'
        }
      }
    },
    {
      id: 'service_amount',
      accessorKey: 'service_amount',
      header: 'Valor',
      enableSorting: false,
      meta: {
        class: {
          th: 'w-32',
          td: 'w-32'
        }
      }
    },
    {
      id: 'status',
      accessorKey: 'status',
      header: 'Situação',
      enableSorting: false,
      meta: {
        class: {
          th: 'w-36',
          td: 'w-36'
        }
      }
    },
    {
      id: 'cte_context',
      accessorFn: row => row.kind === 'CTE'
        ? `${row.fiscal_role || ''} ${row.direction || ''} ${row.artifact_quality || ''}`
        : '',
      header: 'Papel / origem',
      enableSorting: false,
      meta: {
        class: {
          th: 'hidden xl:table-cell w-44',
          td: 'hidden xl:table-cell w-44'
        }
      }
    },
    {
      id: 'actions',
      header: '',
      enableHiding: false,
      enableSorting: false,
      cell: ({ row }) =>
        h(
          'div',
          { class: 'text-right' },
          h(
            UDropdownMenu,
            {
              content: { align: 'end' },
              items: getRowItems(row)
            },
            () =>
              h(UButton, {
                'icon': 'i-lucide-ellipsis-vertical',
                'color': 'neutral',
                'variant': 'ghost',
                'square': true,
                'class': 'ml-auto',
                'aria-label': `Ações de ${noteTitle(row.original)}`
              })
          )
        ),
      meta: {
        class: {
          th: 'w-12',
          td: 'w-12'
        }
      }
    }
  )

  return cols
})
</script>

<template>
  <div data-testid="data-table" class="flex min-h-0 w-full flex-col gap-4">
    <UAlert
      v-if="error"
      :color="notes.length ? 'warning' : 'error'"
      variant="subtle"
      icon="i-lucide-wifi-off"
      :title="error"
      class="shrink-0"
      :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: () => emit('retry') }]"
    />

    <ShellDataTable
      v-model:row-selection="rowSelection"
      ui-preset="dashboard"
      test-id="docs-catalog-table"
      primary-column-id="number"
      status-column-id="status"
      :summary-column-ids="['kind', 'issued_at', 'competence', 'issuer', 'service_amount']"
      :selection-enabled="true"
      :data="notes"
      :columns="columns"
      :loading="!!loading"
      :page="1"
      :total="total || notes.length"
      :items-per-page="pageSize"
      :show-footer="false"
      :get-row-id="(row: NfseNote) => row.access_key"
      @retry="emit('retry')"
    >
      <template #kind-cell="{ row }">
        <UBadge
          color="neutral"
          variant="soft"
          size="sm"
          class="font-normal"
        >
          {{ row.original.kind_label || documentKindLabel(row.original.kind || 'NFSE') }}
        </UBadge>
      </template>

      <template #number-cell="{ row }">
        <button
          type="button"
          class="block w-full truncate text-left font-medium tabular-nums text-highlighted hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :class="selectedAccessKey === row.original.access_key ? 'text-primary' : ''"
          :title="row.original.access_key"
          :aria-label="noteTitle(row.original)"
          :aria-current="selectedAccessKey === row.original.access_key ? 'true' : undefined"
          @click="emit('select', row.original)"
        >
          {{ documentCellLabel(row.original) }}
        </button>
      </template>

      <template #issued_at-cell="{ row }">
        <span class="tabular-nums text-default">
          {{ formatDate(row.original.issued_at) }}
        </span>
      </template>

      <template #competence-cell="{ row }">
        <span class="tabular-nums text-muted">
          {{ row.original.competence || '—' }}
        </span>
      </template>

      <template #issuer-cell="{ row }">
        <div class="min-w-0 max-w-full">
          <p
            class="truncate font-medium text-highlighted"
            :title="row.original.issuer_name || formatCnpj(row.original.issuer_cnpj) || undefined"
          >
            {{ partyCell(row.original.issuer_name, row.original.issuer_cnpj).name }}
          </p>
          <p
            v-if="partyCell(row.original.issuer_name, row.original.issuer_cnpj).cnpj"
            class="truncate font-mono text-xs text-muted"
          >
            {{ partyCell(row.original.issuer_name, row.original.issuer_cnpj).cnpj }}
          </p>
          <!-- Destinatário/Tomador sob o emitente no mobile (coluna some em xs) -->
          <p
            v-if="partyCell(row.original.taker_name, row.original.taker_cnpj).name !== '—'"
            class="mt-0.5 truncate text-xs text-dimmed sm:hidden"
            :title="row.original.taker_name || formatCnpj(row.original.taker_cnpj) || undefined"
          >
            → {{ partyCell(row.original.taker_name, row.original.taker_cnpj).name }}
          </p>
        </div>
      </template>

      <template #recipient-cell="{ row }">
        <div class="min-w-0 max-w-full">
          <p
            class="truncate font-medium text-highlighted"
            :title="row.original.taker_name || formatCnpj(row.original.taker_cnpj) || undefined"
          >
            {{ partyCell(row.original.taker_name, row.original.taker_cnpj).name }}
          </p>
          <p
            v-if="partyCell(row.original.taker_name, row.original.taker_cnpj).cnpj"
            class="truncate font-mono text-xs text-muted"
          >
            {{ partyCell(row.original.taker_name, row.original.taker_cnpj).cnpj }}
          </p>
        </div>
      </template>

      <template #service_amount-cell="{ row }">
        <div class="text-right font-medium tabular-nums text-highlighted">
          {{ formatCurrency(row.original.service_amount) }}
        </div>
      </template>

      <template #status-cell="{ row }">
        <div class="min-w-0 w-full">
          <ShellStatusBadge
            fill
            :status="row.original.status"
            :label="documentSituationLabel(row.original)"
          />
          <p
            v-if="xmlCompletenessHint(row.original)"
            class="mt-0.5 truncate text-xs text-muted"
            :title="xmlCompletenessHint(row.original) || undefined"
          >
            {{ xmlCompletenessHint(row.original) }}
          </p>
        </div>
      </template>

      <template #cte_context-cell="{ row }">
        <div v-if="row.original.kind === 'CTE'" class="space-y-1 text-xs">
          <p class="text-highlighted">
            {{ statusLabel(row.original.fiscal_role) }} · {{ row.original.direction_label || row.original.direction }}
          </p>
          <p class="truncate text-muted" :title="row.original.acquisition_source_label || row.original.acquisition_source || undefined">
            {{ row.original.acquisition_source_label || row.original.acquisition_source || 'Origem não informada' }}
          </p>
          <UBadge
            v-if="row.original.artifact_quality"
            :color="row.original.is_autxml_redacted ? 'warning' : 'success'"
            variant="subtle"
            size="sm"
          >
            {{ row.original.artifact_quality_label || row.original.artifact_quality }}
          </UBadge>
        </div>
        <span v-else class="text-muted">—</span>
      </template>
      <template #empty>
        <UEmpty
          v-if="!error"
          icon="i-lucide-file-search"
          title="Nenhum documento encontrado"
          description="Revise os filtros ou aguarde a próxima sincronização do ADN."
        />
      </template>
    </ShellDataTable>

    <div
      v-if="hasMore"
      class="flex justify-center border-t border-default pt-4"
    >
      <UButton
        color="neutral"
        variant="subtle"
        label="Carregar mais"
        :loading="!!loading"
        @click="emit('loadMore')"
      />
    </div>
  </div>
</template>
