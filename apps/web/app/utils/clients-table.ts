/**
 * Colunas da lista admin de clientes — mesmo contrato de ModuleDataTable
 * (cell renderers reutilizados nos cards mobile).
 */
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { h } from 'vue'
import { ClientsClientProcuracaoBadge } from '#components'
import UBadge from '@nuxt/ui/components/Badge.vue'
import UIcon from '@nuxt/ui/components/Icon.vue'
import type { Client } from '~/types/api'
import { clientCredentialInfo } from '~/utils/clients-credential'
import { clientTaxRegimeLabel } from '~/utils/clients-tax-regime'
import { formatCnpj, normalizeCnpj, truncateText } from '~/utils/format'
import {
  tableIconButton,
  tableIconGroup,
  tableIconMenu
} from '~/utils/table-icon-slots'
import { sortHeader } from '~/utils/table-sort'
import { tableCellBadgeProps } from '~/utils/table-ui'

const CLIENTS_TABLE_HEADER_CLASS = 'flex min-h-8 items-center leading-tight'

function clientsTableHeader(label: string, align: 'start' | 'center' = 'start') {
  return h('div', {
    class: `${CLIENTS_TABLE_HEADER_CLASS} ${align === 'center' ? 'justify-center text-center' : ''}`
  }, label)
}

export function buildClientsColumns(options: {
  canManageClients: boolean
  canAssignClientCategories: boolean
  canManageCredentials: boolean
  onOpenPage: (client: Client) => void
  onOpenModal: (client: Client) => void
  onEdit: (client: Client) => void
  onManageCategories: (client: Client) => void
  onAskDelete: (client: Client) => void
  onReactivate: (client: Client) => void
  onOpenCredential: (client: Client) => void
  onCopyCnpj: (value?: string | null) => void
  onCredentialToast: (title: string, description: string) => void
}): TableColumn<Client>[] {
  function credentialActions(client: Client): DropdownMenuItem[][] {
    const hasCredential = !!client.credential_summary
    const items: DropdownMenuItem[] = []

    if (options.canManageCredentials) {
      items.push({
        label: hasCredential ? 'Atualizar' : 'Enviar',
        icon: hasCredential ? 'i-lucide-refresh-cw' : 'i-lucide-upload',
        onSelect: () => options.onOpenCredential(client)
      })
    }

    items.push(
      {
        label: 'Baixar',
        icon: 'i-lucide-download',
        disabled: !hasCredential,
        onSelect: () => options.onCredentialToast(
          'Download do PFX indisponível',
          'A API não expõe o arquivo do certificado.'
        )
      },
      {
        label: 'Senha',
        icon: 'i-lucide-key-round',
        disabled: !hasCredential,
        onSelect: () => options.onCredentialToast(
          'Senha indisponível',
          'A senha do A1 não é recuperável após o upload.'
        )
      },
      {
        label: 'Remover',
        icon: 'i-lucide-trash-2',
        color: 'error',
        disabled: !hasCredential || !options.canManageCredentials,
        onSelect: () => options.onCredentialToast(
          'Remoção indisponível',
          'Ainda não há endpoint para remover o A1. Use Atualizar.'
        )
      }
    )

    return [items]
  }

  function rowActions(client: Client): DropdownMenuItem[][] {
    const items: DropdownMenuItem[] = [
      {
        label: 'Preview',
        icon: 'i-lucide-scan-eye',
        onSelect: () => options.onOpenModal(client)
      }
    ]

    if (options.canManageClients) {
      items.push({
        label: 'Editar',
        icon: 'i-lucide-pencil',
        onSelect: () => options.onEdit(client)
      })
      if (client.is_active) {
        items.push({
          label: 'Excluir',
          icon: 'i-lucide-trash-2',
          color: 'error',
          onSelect: () => options.onAskDelete(client)
        })
      } else {
        items.push({
          label: 'Reativar',
          icon: 'i-lucide-rotate-ccw',
          onSelect: () => options.onReactivate(client)
        })
      }
    }

    if (options.canAssignClientCategories) {
      items.splice(1, 0, {
        label: 'Gerenciar categorias',
        icon: 'i-lucide-tags',
        onSelect: () => options.onManageCategories(client)
      })
    }

    return [items]
  }

  return [
    {
      id: 'legal_name',
      accessorKey: 'legal_name',
      header: ({ column }) => sortHeader('Razão social / nome', column),
      enableHiding: false,
      meta: {
        class: {
          th: 'min-w-48 w-full',
          td: 'min-w-48 w-full overflow-hidden'
        }
      },
      cell: ({ row }) => {
        const client = row.original
        const label = client.legal_name || client.name
        const display = truncateText(label, 40) || label || '—'
        const rawCnpj = client.cnpj || client.root_cnpj

        return h('div', { class: 'flex min-w-0 flex-col leading-tight' }, [
          h('button', {
            type: 'button',
            class: 'min-w-0 truncate text-left font-medium text-highlighted hover:underline focus-visible:underline focus-visible:outline-none',
            title: label || undefined,
            onClick: () => options.onOpenPage(client)
          }, display),
          rawCnpj
            ? h('button', {
                type: 'button',
                class: 'group mt-0.5 inline-flex max-w-full items-center gap-1.5 font-mono text-xs tabular-nums text-muted hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                title: `Copiar ${normalizeCnpj(rawCnpj)}`,
                onClick: (event: Event) => {
                  event.stopPropagation()
                  options.onCopyCnpj(rawCnpj)
                }
              }, [
                h('span', { class: 'min-w-0 truncate' }, formatCnpj(rawCnpj)),
                h(UIcon, {
                  'name': 'i-lucide-copy',
                  'class': 'size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-70',
                  'aria-hidden': 'true'
                })
              ])
            : null
        ])
      }
    },
    {
      id: 'credential',
      accessorFn: row => row.credential_summary?.valid_to || '',
      header: () => clientsTableHeader('Certificado digital'),
      enableSorting: false,
      enableHiding: false,
      meta: {
        class: {
          th: 'w-40 min-w-32',
          td: 'w-40 min-w-32'
        }
      },
      cell: ({ row }) => {
        const client = row.original
        const info = clientCredentialInfo(client)
        const label = client.legal_name || client.name
        // Padrão Nuxt UI Dashboard (customers/MembersList):
        // UBadge + UDropdownMenu > UButton ghost, alinhados no centro da linha.
        const actionSlot = !info.hasCredential && options.canManageCredentials
          ? tableIconButton({
              label: `Enviar certificado de ${label}`,
              icon: 'i-lucide-plus',
              color: 'neutral',
              variant: 'subtle',
              testId: 'clients-credential-upload',
              onClick: () => options.onOpenCredential(client)
            })
          : tableIconMenu({
              label: `Ações do certificado de ${label}`,
              icon: 'i-lucide-ellipsis-vertical',
              color: 'neutral',
              variant: 'subtle',
              testId: 'clients-credential-menu',
              align: 'end',
              items: credentialActions(client)
            })

        return h('div', {
          'class': 'flex min-w-0 items-center gap-1.5',
          'data-testid': 'clients-credential-cell'
        }, [
          h('div', { class: 'min-w-0 flex-1' }, [
            h(UBadge, tableCellBadgeProps({
              color: info.color,
              label: info.chipLabel,
              title: info.chipLabel
            }))
          ]),
          h('div', {
            'class': 'inline-flex shrink-0 items-center justify-center',
            'data-testid': 'clients-credential-actions'
          }, [actionSlot])
        ])
      }
    },
    {
      id: 'procuracao',
      accessorFn: row => row.procuracao_status || '',
      header: () => clientsTableHeader('Procuração'),
      enableSorting: false,
      meta: {
        class: {
          th: 'w-32 min-w-28',
          td: 'w-32 min-w-28'
        }
      },
      cell: ({ row }) => h(ClientsClientProcuracaoBadge, {
        status: row.original.procuracao_status,
        validTo: row.original.procuracao_valid_to,
        compact: true
      })
    },
    {
      id: 'is_active',
      accessorKey: 'is_active',
      header: ({ column }) => sortHeader('Estado', column),
      meta: {
        class: {
          th: 'w-24 min-w-24',
          td: 'w-24 min-w-24'
        }
      },
      cell: ({ row }) => h(UBadge, tableCellBadgeProps({
        color: row.original.is_active ? 'success' : 'neutral',
        label: row.original.is_active ? 'Ativo' : 'Inativo'
      }))
    },
    {
      id: 'tax_regime',
      accessorKey: 'tax_regime',
      header: ({ column }) => sortHeader('Regime tributário', column),
      meta: {
        class: {
          th: 'w-36 min-w-28',
          td: 'w-36 min-w-28'
        }
      },
      cell: ({ row }) => {
        const label = row.original.tax_regime_label
          || clientTaxRegimeLabel(row.original.tax_regime)
        return h('span', {
          class: label ? 'text-sm text-highlighted' : 'text-sm text-muted'
        }, label || 'Não informado')
      }
    },
    {
      id: 'actions',
      header: () => clientsTableHeader('Ações', 'center'),
      enableHiding: false,
      enableSorting: false,
      meta: {
        class: {
          th: 'w-32 min-w-32 text-center',
          td: 'w-32 min-w-32'
        }
      },
      cell: ({ row }) => {
        const client = row.original
        const label = client.legal_name || client.name
        return tableIconGroup([
          tableIconButton({
            label: `Abrir ${label}`,
            icon: 'i-lucide-user-round',
            color: 'primary',
            testId: 'clients-open',
            fill: true,
            onClick: () => options.onOpenPage(client)
          }),
          tableIconMenu({
            label: `Mais ações de ${label}`,
            icon: 'i-lucide-ellipsis-vertical',
            testId: 'clients-row-menu',
            fill: true,
            align: 'end',
            items: rowActions(client)
          })
        ], 'clients-actions-group', { fill: true })
      }
    }
  ]
}
