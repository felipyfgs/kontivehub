<script setup lang="ts">
/**
 * Seletor Cliente | Processo | Tarefa (UFieldGroup) — copy pt_BR.
 * Reutilizado em `/work/processes` e `/work/tasks`.
 * Emite o nível; o pai navega (evita navegação duplicada).
 */
import type { WorkEntityLevel } from '~/types/work'

const props = withDefaults(defineProps<{
  /** Nível ativo no seletor. */
  modelValue: WorkEntityLevel
  /** Filtros compatíveis preservados na navegação (passados pelo pai). */
  q?: string
  clientId?: number | null
  departmentId?: number | null
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'
  testId?: string
}>(), {
  q: '',
  clientId: null,
  departmentId: null,
  size: 'sm',
  testId: 'work-entity-level-toggle'
})

const emit = defineEmits<{
  'update:modelValue': [value: WorkEntityLevel]
}>()

const options: Array<{
  level: WorkEntityLevel
  label: string
  icon: string
}> = [
  { level: 'client', label: 'Cliente', icon: 'i-lucide-building-2' },
  { level: 'process', label: 'Processo', icon: 'i-lucide-folders' },
  { level: 'task', label: 'Tarefa', icon: 'i-lucide-list-checks' }
]

function select(level: WorkEntityLevel) {
  if (level === props.modelValue) return
  emit('update:modelValue', level)
}
</script>

<template>
  <UFieldGroup
    :size="size"
    :data-testid="testId"
    role="group"
    aria-label="Nível de entidade"
  >
    <UButton
      v-for="option in options"
      :key="option.level"
      :label="option.label"
      :icon="option.icon"
      :size="size"
      :variant="modelValue === option.level ? 'solid' : 'outline'"
      :color="modelValue === option.level ? 'primary' : 'neutral'"
      :aria-pressed="modelValue === option.level"
      :data-testid="`${testId}-${option.level}`"
      @click="select(option.level)"
    />
  </UFieldGroup>
</template>
