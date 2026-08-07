export type ComposerLifecycleState
  = | 'validating'
    | 'uploading'
    | 'queued'
    | 'sent'
    | 'delivered'
    | 'read'
    | 'blocked'
    | 'cancelled'
    | 'failed'
    | 'partially_sent'

export interface ComposerLifecycleItem {
  id: string
  label: string
  state: ComposerLifecycleState
  /** Upload percentage reported by Laravel; only meaningful while uploading. */
  progress?: number
  /** Stable, operational reason returned by the current capability or delivery result. */
  cause?: string
}

export interface ComposerLifecycleCopy {
  label: string
  cause: string
  impact: string
  nextAction: string
  icon: string
  color: 'neutral' | 'primary' | 'success' | 'warning' | 'error' | 'info'
}
