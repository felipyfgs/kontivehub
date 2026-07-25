import type {
  AccountProfile,
  LoginResponse,
  MeResponse,
  TenantMembershipsPayload,
  TenantSwitchResult,
  TwoFactorQrCode,
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
      switch: (officeId: number) =>
        client<{ data: TenantSwitchResult }>('/api/v1/tenants/switch', {
          method: 'POST',
          body: { office_id: officeId }
        })
    },
    /** Reconfirmação de senha (janela server-side 15 min) — substitui TOTP. */
    confirmPassword: (password: string) =>
      client('/api/v1/auth/confirm-password', { method: 'POST', body: { password } }),
    /** @deprecated TOTP descontinuado — mantido só para compat de imports legados. */
    twoFactor: {
      confirmPassword: (password: string) =>
        client('/api/v1/auth/confirm-password', { method: 'POST', body: { password } }),
      enable: () => client('/user/two-factor-authentication', { method: 'POST' }),
      qrCode: () => client<TwoFactorQrCode>('/user/two-factor-qr-code'),
      confirm: (code: string) =>
        client('/user/confirmed-two-factor-authentication', { method: 'POST', body: { code } }),
      recoveryCodes: () => client<string[]>('/user/two-factor-recovery-codes'),
      challenge: (body: { code?: string, recovery_code?: string }) =>
        client<LoginResponse>('/two-factor-challenge', { method: 'POST', body })
    }
  }
}
