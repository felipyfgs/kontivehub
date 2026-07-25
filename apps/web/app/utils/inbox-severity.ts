/** Cores semânticas unificadas da inbox (home, /health, notificações). */
export type InboxSeverityColor = 'error' | 'warning' | 'info' | 'neutral'

export function inboxSeverityColor(severity: string): InboxSeverityColor {
  if (severity === 'critical') return 'error'
  if (severity === 'high') return 'warning'
  if (severity === 'medium') return 'info'
  return 'neutral'
}
