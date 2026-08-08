<script setup lang="ts">
import type { ComposerLifecycleItem } from '~/types/communication/composer-lifecycle'
import {
  canRetryComposerLifecycle,
  composerLifecycleAnnouncement,
  composerLifecycleCopy,
  composerLifecycleProgress
} from '~/utils/communication-composer-lifecycle'

const props = withDefaults(defineProps<{
  items: readonly ComposerLifecycleItem[]
  previousStates?: Readonly<Record<string, ComposerLifecycleItem['state'] | null>>
}>(), {
  previousStates: () => ({})
})

const emit = defineEmits<{
  retry: [itemId: string]
}>()

const iconColors = {
  neutral: 'text-muted',
  primary: 'text-primary',
  success: 'text-success',
  warning: 'text-warning',
  error: 'text-error',
  info: 'text-info'
} as const

const announcements = computed(() => props.items
  .map((item) => {
    if (!Object.hasOwn(props.previousStates, item.id)) return null
    return composerLifecycleAnnouncement(props.previousStates[item.id] ?? null, item)
  })
  .filter((announcement): announcement is string => Boolean(announcement)))
</script>

<template>
  <section aria-label="Andamento dos itens enviados" class="space-y-2">
    <p
      v-if="announcements.length"
      class="sr-only"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      {{ announcements.join(' ') }}
    </p>

    <article
      v-for="item in items"
      :key="item.id"
      class="rounded-lg border border-default bg-elevated p-3 motion-reduce:transition-none"
      :aria-label="`${item.label}: ${composerLifecycleCopy(item).label}`"
    >
      <div class="flex items-start gap-3">
        <UIcon
          :name="composerLifecycleCopy(item).icon"
          class="mt-0.5 size-5 shrink-0"
          :class="iconColors[composerLifecycleCopy(item).color]"
          aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <p class="font-medium text-highlighted">
              {{ item.label }}
            </p>
            <UBadge :label="composerLifecycleCopy(item).label" :color="composerLifecycleCopy(item).color" variant="subtle" />
          </div>
          <p class="mt-1 text-sm text-muted">
            {{ composerLifecycleCopy(item).cause }}
          </p>
          <p class="mt-1 text-sm text-toned">
            Impacto: {{ composerLifecycleCopy(item).impact }}
          </p>
          <p class="mt-1 text-sm text-toned">
            Próxima ação: {{ composerLifecycleCopy(item).nextAction }}
          </p>
          <UProgress
            :model-value="composerLifecycleProgress(item)"
            :max="100"
            :color="composerLifecycleCopy(item).color"
            class="mt-3"
            :aria-label="`Progresso de ${item.label}: ${composerLifecycleProgress(item)}%`"
          />
        </div>
        <UButton
          v-if="canRetryComposerLifecycle(item.state)"
          label="Tentar novamente"
          icon="i-lucide-rotate-cw"
          color="primary"
          class="min-h-11 shrink-0"
          :aria-label="`Tentar novamente ${item.label}`"
          @click="emit('retry', item.id)"
        />
      </div>
    </article>
  </section>
</template>
