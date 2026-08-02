import { computed, shallowRef, toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import type { CommandPaletteGroup } from '@nuxt/ui'

type ContextualCommandRegistration = {
  owner: string
  token: symbol
  groups: MaybeRefOrGetter<CommandPaletteGroup[]>
}

export function createDashboardContextualCommandRegistry() {
  const registrations = shallowRef<ContextualCommandRegistration[]>([])

  const groups = computed<CommandPaletteGroup[]>(() =>
    registrations.value.flatMap(registration => toValue(registration.groups)))

  function register(
    owner: string,
    commandGroups: MaybeRefOrGetter<CommandPaletteGroup[]>
  ): () => void {
    const token = Symbol(owner)
    registrations.value = [
      ...registrations.value.filter(registration => registration.owner !== owner),
      { owner, token, groups: commandGroups }
    ]

    return () => {
      registrations.value = registrations.value.filter(
        registration => registration.token !== token
      )
    }
  }

  function clear(): void {
    registrations.value = []
  }

  return {
    groups,
    register,
    clear
  }
}
