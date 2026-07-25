<script setup lang="ts">
/**
 * Cabeçalho de seção settings — naked horizontal (template settings/index).
 * Slot default = ações (Salvar, Novo…); backTo opcional para folhas de hub.
 * crumbs complementa backTo (orientação hierárquica), não substitui.
 */
import type { BreadcrumbItem } from '@nuxt/ui'
import type { RouteLocationRaw } from 'vue-router'

export type SectionHeaderCrumb = {
  label: string
  to?: RouteLocationRaw
}

const props = withDefaults(defineProps<{
  title: string
  description?: string
  testId?: string
  /** Link “voltar” (ex.: KontiveHub / Integrações). */
  backTo?: RouteLocationRaw
  backLabel?: string
  /** Trilha hierárquica opcional (ex.: Cliente › Fiscal › CCMEI). */
  crumbs?: SectionHeaderCrumb[]
}>(), {
  description: undefined,
  testId: undefined,
  backTo: undefined,
  backLabel: 'Voltar',
  crumbs: undefined
})

const slots = useSlots()
const hasActions = computed(() => Boolean(slots.default))

const breadcrumbItems = computed((): BreadcrumbItem[] =>
  (props.crumbs ?? []).map(crumb => ({
    label: crumb.label,
    ...(crumb.to !== undefined ? { to: crumb.to } : {})
  }))
)
</script>

<template>
  <div
    class="mb-4 min-w-0"
    :data-testid="testId"
  >
    <UBreadcrumb
      v-if="breadcrumbItems.length"
      :items="breadcrumbItems"
      class="mb-2"
      data-testid="section-header-crumbs"
    />
    <UPageCard
      :title="title"
      :description="description"
      variant="naked"
      orientation="horizontal"
      class="min-w-0"
    >
      <div
        v-if="backTo || hasActions"
        class="flex w-full flex-wrap items-center gap-2 lg:ms-auto lg:w-fit"
      >
        <UButton
          v-if="backTo"
          :to="backTo"
          color="neutral"
          variant="ghost"
          size="sm"
          icon="i-lucide-arrow-left"
          :label="backLabel"
          class="w-fit"
          data-testid="section-header-back"
        />
        <div
          v-if="hasActions"
          class="flex flex-wrap items-center gap-2"
          :class="backTo ? 'ms-auto' : undefined"
        >
          <slot />
        </div>
      </div>
    </UPageCard>
  </div>
</template>
