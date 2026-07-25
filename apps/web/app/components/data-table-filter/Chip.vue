<script setup lang="ts">
/**
 * Chip de filtro ativo — UFieldGroup + UButton outline (mesmo padrão da toolbar).
 * Rótulo compacto: "Campo : Valor" (operador curto; aria usa a frase completa).
 */
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import { filterKindIcon, formatChipDisplay } from '~/utils/data-table-filters'

const props = defineProps<{
  definition: DataTableFilterDefinition
  model: DataTableFilterModel
}>()

const emit = defineEmits<{
  edit: []
  remove: []
}>()

const display = computed(() => formatChipDisplay(props.definition, props.model))
const kindIcon = computed(() => filterKindIcon(props.definition))

/** Texto legível com espaços reais (acessibilidade + fallback visual). */
const labelText = computed(
  () => `${display.value.fieldLabel} ${display.value.operatorLabel} ${display.value.valueLabel}`
)

const editAria = computed(() => `Editar filtro ${labelText.value}`)
const removeAria = computed(() => `Remover filtro ${display.value.fieldLabel}`)
</script>

<template>
  <UFieldGroup
    data-testid="data-table-filter-chip"
    :data-filter-key="model.key"
  >
    <UButton
      color="neutral"
      variant="outline"
      :aria-label="editAria"
      data-testid="data-table-filter-chip-edit"
      @click="emit('edit')"
    >
      <span class="inline-flex max-w-[min(12rem,calc(100vw-8rem))] min-w-0 items-center gap-1 sm:max-w-56 sm:gap-1.5 md:max-w-72">
        <UIcon
          :name="kindIcon"
          class="size-3.5 shrink-0 text-muted"
          aria-hidden="true"
        />
        <span class="truncate text-muted">{{ display.fieldLabel }}</span>
        <span class="hidden shrink-0 text-dimmed sm:inline">{{ display.operatorLabel }}</span>
        <span class="truncate font-medium text-highlighted">{{ display.valueLabel }}</span>
      </span>
    </UButton>
    <UButton
      color="neutral"
      variant="outline"
      icon="i-lucide-x"
      square
      :aria-label="removeAria"
      data-testid="data-table-filter-chip-remove"
      @click="emit('remove')"
    />
  </UFieldGroup>
</template>
