import { h, type VNode } from 'vue'
import USkeleton from '@nuxt/ui/components/Skeleton.vue'

/** Skeleton compacto para célula de resultado enquanto a consulta da linha processa. */
export function consultPendingSkeleton(testId: string, widthClass = 'max-w-[7rem]'): VNode {
  return h('div', {
    'class': 'flex w-full min-w-0 flex-col gap-1.5 py-0.5',
    'aria-busy': 'true',
    'aria-label': 'Consulta em andamento',
    'data-testid': testId
  }, [
    h(USkeleton, { class: `h-5 w-full ${widthClass} rounded-md` })
  ])
}
