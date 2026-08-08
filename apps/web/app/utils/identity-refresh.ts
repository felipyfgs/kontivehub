type RefreshIdentity = () => Promise<void>

const inFlightByScope = new WeakMap<object, Promise<void>>()

/** Compartilha o refresh de identidade em curso no mesmo Nuxt app. */
export function refreshIdentitySingleFlight(
  scope: object,
  refreshIdentity: RefreshIdentity
): Promise<void> {
  const current = inFlightByScope.get(scope)
  if (current) return current

  const request = Promise.resolve(refreshIdentity()).finally(() => {
    if (inFlightByScope.get(scope) === request) {
      inFlightByScope.delete(scope)
    }
  })
  inFlightByScope.set(scope, request)

  return request
}
