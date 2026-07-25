/** Rotas públicas de autenticação/ativação (sem sessão). */
export const AUTH_PUBLIC_PATHS = [
  '/login',
  '/two-factor-challenge',
  '/reset-password',
  '/activate',
  '/first-access',
  '/onboarding'
] as const

export function isAuthPublicPath(path: string): boolean {
  const normalized = path.replace(/\/+$/, '') || '/'
  return (AUTH_PUBLIC_PATHS as readonly string[]).includes(normalized)
    || (AUTH_PUBLIC_PATHS as readonly string[]).includes(path)
}
