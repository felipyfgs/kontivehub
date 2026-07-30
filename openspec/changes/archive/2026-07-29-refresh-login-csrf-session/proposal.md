## Why

O login da SPA pode ficar preso em `419 CSRF token mismatch` quando o navegador
mantém um `XSRF-TOKEN` associado a uma sessão que foi regenerada, expirada ou
recriada. O cliente Sanctum atual só busca um novo cookie quando o cookie XSRF
está ausente, portanto a presença de um valor obsoleto impede a recuperação
automática.

## What Changes

- Renovar explicitamente o cookie CSRF imediatamente antes de cada tentativa de
  login da SPA.
- Atualizar a visão client-side do cookie antes de enviar as credenciais.
- Manter a validação CSRF do Laravel ativa, sem exclusões, wildcards ou fallback
  permissivo.
- Cobrir a ordem `renovar CSRF → autenticar → carregar identidade` com teste de
  regressão.
- Não alterar credenciais, contratos públicos, autorização, CORS, migrations ou
  o fluxo de logout.

## Capabilities

### New Capabilities

- `spa-session-authentication`: autenticação stateful da SPA e recuperação
  segura quando sessão e token CSRF armazenados no navegador deixam de
  corresponder.

### Modified Capabilities

Nenhuma.

## Impact

- Web: página de login e teste unitário focado em autenticação Sanctum.
- API: nenhum contrato ou código alterado; `PreventRequestForgery`, Fortify e
  Sanctum continuam sendo a autoridade do handshake.
- Dependências: reutiliza `useSanctumClient` e `refreshCookie` já fornecidos pelo
  módulo/Nuxt.
- Operação: reduz a necessidade de apagar manualmente cookies após recriação de
  sessão em desenvolvimento; não habilita egress nem modifica flags.
