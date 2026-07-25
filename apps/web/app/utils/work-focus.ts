export async function restoreWorkSelectionFocus(
  origin: HTMLElement | null,
  fallback?: () => void | Promise<void>
): Promise<boolean> {
  if (origin?.isConnected) {
    origin.focus()
    origin.scrollIntoView?.({ block: 'nearest' })
    return true
  }

  await fallback?.()
  return false
}
