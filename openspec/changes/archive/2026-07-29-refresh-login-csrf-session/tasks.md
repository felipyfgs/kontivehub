## 1. Handshake de autenticação

- [x] 1.1 Criar composable que renova `/sanctum/csrf-cookie`, atualiza a referência `XSRF-TOKEN` e somente então chama o login Sanctum.
- [x] 1.2 Integrar o composable à página de login preservando identidade, mensagens e redirecionamento existentes.

## 2. Regressão

- [x] 2.1 Cobrir a ordem renovação CSRF → refresh do cookie → login e impedir o envio de credenciais quando a renovação falhar.
- [x] 2.2 Executar o teste focado, lint e typecheck do frontend.

## 3. Validação

- [x] 3.1 Validar pelo proxy remoto o fluxo CSRF → login → `/api/v1/me` sem bypass de proteção.
- [x] 3.2 Executar os gates completos do frontend: generate, test, test:fidelity e test:artifacts.

> Nota de validação: teste focado e lint dos arquivos alterados passaram. O
> lint e o typecheck globais foram executados, mas permanecem bloqueados por
> erros nas alterações preexistentes de comunicação/contatos fora desta
> change.
