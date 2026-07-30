## Context

O frontend usa `nuxt-auth-sanctum` em modo cookie e encaminha chamadas
same-origin por `/api/sanctum`. O interceptor do módulo só executa
`/sanctum/csrf-cookie` quando não encontra `XSRF-TOKEN`. Se o cookie existe, mas
a sessão correspondente foi regenerada, removida ou recriada, o `POST /login`
envia um token que não corresponde à sessão e o Laravel responde `419`.

A proteção `PreventRequestForgery` deve continuar fail-closed. A correção é
exclusivamente client-side e não altera Fortify, Sanctum, CORS ou o contrato
público da API.

## Goals / Non-Goals

**Goals:**

- Reemparelhar sessão e token CSRF antes de toda submissão de credenciais.
- Impedir o envio das credenciais quando a renovação CSRF falhar.
- Preservar o fluxo existente de login, carregamento da identidade e
  redirecionamento por papel.
- Manter o comportamento verificável em teste unitário sem depender de um
  browser real.

**Non-Goals:**

- Desabilitar, excluir rotas ou afrouxar a proteção CSRF.
- Repetir automaticamente um `POST /login` que já foi enviado.
- Alterar sessão, rate limit, credenciais, CORS, tenancy ou autorização.
- Tratar acesso direto do browser ao backend fora do proxy Nuxt.

## Decisions

### Renovar antes do POST, em vez de reagir ao 419

A SPA fará um `GET /sanctum/csrf-cookie` imediatamente antes do `POST /login`,
mesmo que um cookie XSRF já exista. Depois da resposta, chamará
`refreshCookie('XSRF-TOKEN')` para sincronizar a referência client-side usada
pelo interceptor do módulo.

Alternativa considerada: capturar `419`, apagar o cookie e repetir o login.
Essa opção reenviaria credenciais, consumiria outra tentativa do rate limiter e
seria mais difícil distinguir uma falha de transporte de uma autenticação já
processada.

### Encapsular o handshake em um composable focado

Um composable do frontend combinará `useSanctumClient`, `refreshCookie` e
`useSanctumAuth`. A página continuará responsável apenas pelo estado visual,
carregamento da identidade e redirecionamento.

Alternativa considerada: chamar o endpoint diretamente em `pages/login.vue`.
Foi rejeitada porque deixaria a ordem de segurança implícita na página e
dificultaria o teste isolado.

### Falhar antes de enviar credenciais

Se o endpoint CSRF falhar, o composable propagará o erro e não chamará
`login`. A página exibirá o tratamento de erro já existente, sem inventar
estado de sucesso ou bypass.

## Risks / Trade-offs

- [Uma requisição GET adicional em cada tentativa de login] → Custo pequeno e
  restrito a uma operação pouco frequente; prioriza consistência e segurança.
- [A referência de cookie do Nuxt permanecer em cache] → Chamar
  `refreshCookie('XSRF-TOKEN')` explicitamente antes do POST.
- [Regressão no fluxo de redirecionamento] → Manter `refreshIdentity` e a
  seleção de destino na página, alterando apenas a etapa de autenticação.
- [Tentativas concorrentes] → O botão já permanece em loading durante o
  handshake completo.

## Migration Plan

1. Adicionar o composable e teste focado da ordem do handshake.
2. Substituir a chamada direta de login na página.
3. Executar testes, lint, typecheck e geração do frontend.
4. Validar o fluxo remoto com cookies novos e com estado obsoleto.

Rollback: reverter o composable e restaurar a chamada direta a
`useSanctumAuth().login`; nenhuma migração ou dado persistente precisa ser
revertido.

## Open Questions

Nenhuma para este escopo.
