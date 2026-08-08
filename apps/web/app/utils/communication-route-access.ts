import { canViewCommunication, unwrapMeUser } from '~/utils/permissions'
import type { MeIdentity } from '~/utils/permissions'

/** Middleware de rota: impede que o setup da página crie loaders sem acesso efetivo. */
export function requireCommunicationView() {
  const { user } = useSanctumAuth<MeIdentity>()
  const identity = unwrapMeUser(user.value)

  if (!canViewCommunication(identity) || identity?.context_status !== 'ok' || !identity.current_tenant) {
    return navigateTo('/')
  }
}
