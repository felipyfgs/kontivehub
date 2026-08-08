<script setup lang="ts">
import type { ComposerLauncherAction, ComposerLauncherGroup } from '~/utils/communication-composer-launcher'

export interface ComposerAttachmentDrawerGroup {
  id: ComposerLauncherGroup
  label: string
  actions: readonly ComposerLauncherAction[]
}

const props = defineProps<{ open: boolean, groups: readonly ComposerAttachmentDrawerGroup[] }>()
const emit = defineEmits<{ 'update:open': [open: boolean], 'close': [], 'select': [action: ComposerLauncherAction] }>()
const selectedGroup = ref<ComposerLauncherGroup | null>(null)
const activeGroup = computed(() => props.groups.find(group => group.id === selectedGroup.value) ?? null)

function close() {
  selectedGroup.value = null
  emit('update:open', false)
  emit('close')
}
function back() {
  selectedGroup.value = null
}
function select(action: ComposerLauncherAction) {
  emit('select', action)
  close()
}
watch(() => props.open, (open) => {
  if (!open) selectedGroup.value = null
})
</script>

<template>
  <UDrawer :open="open" direction="bottom" @update:open="value => value ? emit('update:open', true) : close()">
    <template #content>
      <div class="max-h-[80dvh] overflow-y-auto px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-2">
        <div class="mb-2 flex items-center gap-2">
          <UButton
            v-if="activeGroup"
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            class="min-h-11 min-w-11"
            aria-label="Voltar"
            @click="back"
          />
          <p class="font-semibold text-highlighted">
            {{ activeGroup?.label || 'Adicionar conteúdo' }}
          </p>
          <UButton
            icon="i-lucide-x"
            color="neutral"
            variant="ghost"
            class="ml-auto min-h-11 min-w-11"
            aria-label="Fechar"
            @click="close"
          />
        </div>
        <div class="space-y-1">
          <UButton
            v-for="group in activeGroup ? [] : groups"
            :key="group.id"
            :label="group.label"
            icon="i-lucide-chevron-right"
            trailing
            color="neutral"
            variant="ghost"
            block
            class="min-h-11 justify-between"
            @click="() => { selectedGroup = group.id }"
          />
          <UButton
            v-for="action in activeGroup?.actions || []"
            :key="action.id"
            :label="action.label"
            :icon="action.icon"
            color="neutral"
            variant="ghost"
            block
            class="min-h-11 justify-start"
            @click="select(action)"
          />
        </div>
      </div>
    </template>
  </UDrawer>
</template>
