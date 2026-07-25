/**
 * Contrato controlado de filtros estruturados de tabela.
 * Independente do contrato backend-facing do Monitoramento.
 */

export type DataTableFilterOperator = 'eq' | 'contains' | 'between' | 'in'

export type DataTableFilterOptionItem = {
  label: string
  value: string
}

export type DataTableFilterDefinition
  = | {
    key: string
    kind: 'option'
    label: string
    items: DataTableFilterOptionItem[]
    /** Valor vazio/default que não forma chip (default: 'all'). */
    emptyValue?: string
    /**
     * Permite selecionar várias opções (valor serializado "a,b,c", operator `in`).
     * Default false (select único / `eq`).
     */
    multiple?: boolean
  }
  | {
    key: string
    kind: 'month'
    label: string
    emptyValue?: string
  }
  | {
    key: string
    kind: 'client'
    label: string
    emptyValue?: null
    /**
     * Vários clientes (valor "1,2,3", operator `in`).
     * Default false (um id numérico / `eq`).
     */
    multiple?: boolean
  }
  | {
    key: string
    kind: 'text'
    label: string
    emptyValue?: string
    /** Default `eq`; `contains` só em text. */
    operator?: 'eq' | 'contains'
  }
  | {
    key: string
    kind: 'boolean'
    label: string
    /** Rótulos opcionais dos lados true/false. */
    trueLabel?: string
    falseLabel?: string
    emptyValue?: null
  }
  | {
    key: string
    kind: 'date'
    label: string
    emptyValue?: string
  }
  | {
    key: string
    kind: 'date_range'
    label: string
    emptyValue?: string
  }

/**
 * Modelo aplicado (chip).
 * - option/month/text/date: string
 * - option multiple: "valor1,valor2" (operator `in`)
 * - client single: number (id)
 * - client multiple: "1,2,3" (operator `in`)
 * - boolean: boolean
 * - date_range: "YYYY-MM-DD..YYYY-MM-DD"
 */
export interface DataTableFilterModel {
  key: string
  operator: DataTableFilterOperator
  value: string | number | boolean
  /** Rótulo exibido no chip (obrigatório para cliente; option usa label do item). */
  label?: string
}

export type DataTableFilterDraft
  = | {
    mode: 'add' | 'edit'
    key: string
    value: string | number | boolean | null
    /** Para date_range: fim do intervalo (início em value). */
    valueTo?: string | null
    label?: string
  }
  | null
