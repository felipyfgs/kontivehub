import type {
  DataTableFilterDefinition,
  DataTableFilterModel
} from '~/types/data-table-filter'
import { createFilterModel } from '~/utils/data-table-filters'

type FilterModelFactory = (
  definition: DataTableFilterDefinition,
  value: string,
  label?: string
) => DataTableFilterModel | null

export function createWorkAssigneeFilterModel(
  definition: DataTableFilterDefinition | undefined,
  membershipId: number,
  label: string,
  factory: FilterModelFactory = createFilterModel
): DataTableFilterModel {
  const value = String(membershipId)
  const model = definition ? factory(definition, value, label) : null
  return model ?? {
    key: 'assignee_membership_id',
    operator: 'eq',
    value,
    label
  }
}
