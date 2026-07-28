## Why

O pedido atual autoriza corrigir regressões observadas no navegador: os
endpoints PGDAS-D falham quando o PHP-FPM não consegue ler um novo Form Request,
um deep link anterior de certificado não é resolvido e o Crosshair do dashboard
de clientes é montado sem accessor horizontal. A correção deve preservar o
`401` esperado de uma sessão ausente e não transformar mensagens internas do
Nuxt DevTools em mudanças de produto.

## What Changes

- Tornar o Form Request fiscal e seu diretório legíveis pelo usuário do PHP-FPM.
- Configurar o Crosshair com o mesmo accessor `x` da série e do eixo.
- Resolver `/clients/:id/certificado` como alias da página de cadastro e
  substituí-lo pela URL canônica.
- Cobrir os comportamentos com testes focados e gates dos apps afetados.
- Não relaxar Sanctum, tenant, CORS, flags fiscais nem executar egress real.

## Capabilities

### New Capabilities

- `browser-console-regression-safety`: disponibilidade runtime dos endpoints
  usados pela SPA, compatibilidade de deep links e configuração válida das
  interações do gráfico.

### Modified Capabilities

Nenhuma.

## Impact

- `apps/api`: permissões locais do Form Request fiscal e testes HTTP existentes.
- `apps/web`: gráfico de clientes, alias/redirect do cadastro, teste unitário e
  matriz de paridade.
- Sem mudança de contrato `/api/v1`, banco, fila, flag, rollout ou integração
  externa.
