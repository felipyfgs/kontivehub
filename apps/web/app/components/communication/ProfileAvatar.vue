<script setup lang="ts">
import type { ProfilePictureState } from '~/types/communication/contacts'
import {
  communicationProfilePictureSrc,
  rememberCommunicationProfilePictureFailure
} from '~/utils/communication'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  subject?: {
    profile_picture_url?: string | null
    profile_picture_state?: ProfilePictureState
  } | null
}>()

const apiBase = String(useRuntimeConfig().public.apiBase || '')
const src = computed(() => communicationProfilePictureSrc(props.subject, apiBase))

function onError(): void {
  rememberCommunicationProfilePictureFailure(src.value)
}
</script>

<template>
  <UAvatar
    v-bind="$attrs"
    :src="src"
    @error="onError"
  />
</template>
