import type {
  AccountProfile,
  MeResponse,
  TenantMembershipsPayload,
  TenantSwitchResult,
  UpdateAccountProfileBody
} from '~/types/api'
import type { ApiClient } from './types'

export function createAuthApi(client: ApiClient) {
  return {
    me: () => client<MeResponse>('/api/v1/me'),
    account: {
      update: (body: UpdateAccountProfileBody) =>
        client<{ data: AccountProfile }>('/api/v1/account', {
          method: 'PATCH',
          body
        })
    },
    tenants: {
      memberships: () =>
        client<{ data: TenantMembershipsPayload }>('/api/v1/tenants/memberships'),
      switch: (tenantId: number) =>
        client<{ data: TenantSwitchResult }>('/api/v1/tenants/switch', {
          method: 'POST',
          body: { tenant_id: tenantId }
        })
    },
    /** Reconfirmação de senha em janela controlada pelo servidor. */
    confirmPassword: (password: string) =>
      client('/api/v1/auth/confirm-password', { method: 'POST', body: { password } })
  }
}
