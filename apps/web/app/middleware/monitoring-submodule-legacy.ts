/**
 * Redireciona paths legados `/monitoring/{modulo}/{submodule}` para o path
 * para a superfície canônica correspondente (sem query).
 *
 * Canônico: `/monitoring/simples`, `/monitoring/mei`, `/monitoring/dctfweb`
 * Legado: `/monitoring/simples-mei` (+ submodule) ainda redireciona.
 */
export default defineNuxtRouteMiddleware((to) => {
  const moduleKey = to.path.startsWith('/monitoring/dctfweb')
    ? 'dctfweb'
    : 'simples_mei'

  const segment = Array.isArray(to.params.submodule)
    ? to.params.submodule[0]
    : to.params.submodule

  return navigateTo(
    monitoringLegacySubmoduleLocation(moduleKey, to.query, segment),
    { replace: true, redirectCode: 301 }
  )
})
