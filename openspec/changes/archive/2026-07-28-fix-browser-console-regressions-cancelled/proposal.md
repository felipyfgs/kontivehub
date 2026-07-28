## Why

O navegador registra regressões que impedem ou degradam fluxos reais: os
endpoints da carteira PGDAS-D falham no PHP-FPM porque um novo Form Request não
é legível pelo usuário do runtime, um deep link anterior de certificado não possui
rota compatível e o Crosshair do dashboard de clientes é montado sem accessor
horizontal. Esses problemas precisam ser corrigidos sem mascarar o `401`
esperado de uma sessão ausente nem mensagens internas do Nuxt DevTools.

## What Changes

- Tornar o Form Request da carteira fiscal legível pelo PHP-FPM e comprovar que
  os endpoints `overview` e `clients` continuam respondendo sob autenticação e
  contexto de tenant.
- Configurar o Crosshair do gráfico de crescimento de clientes com o mesmo
  accessor `x` usado pela série e pelo eixo.
- Aceitar o deep link anterior `/clients/:id/certificado` e redirecioná-lo para a
  superfície canônica do detalhe do cliente.
- Manter fora do escopo qualquer relaxamento de Sanctum, tenant, CORS, flags
  fiscais ou egress real; o `401` sem sessão permanece fail-closed.

## Capabilities

### New Capabilities

- `browser-console-regression-safety`: cobre disponibilidade dos endpoints
  usados pela SPA, compatibilidade de deep links do detalhe do cliente e
  configuração válida das interações do gráfico.

### Modified Capabilities

Nenhuma.

## Impact

- API Laravel: permissões de leitura do novo
  `ViewFiscalModulePortfolioRequest` no runtime local e testes HTTP focados.
- SPA Nuxt: gráfico de clientes, roteamento compatível e matriz de paridade do
  template.
- Contratos `/api/v1`: nenhuma URI, payload, status ou regra de autorização é
  alterada.
- Dados e operação: sem migration, fila, flag, rollout ou chamada externa.
