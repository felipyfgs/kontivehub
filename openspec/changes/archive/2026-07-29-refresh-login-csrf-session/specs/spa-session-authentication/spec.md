## ADDED Requirements

### Requirement: Renovação CSRF antes do login

A SPA SHALL solicitar um novo cookie CSRF pelo proxy Sanctum imediatamente
antes de enviar credenciais de login, independentemente de já existir um
`XSRF-TOKEN` no navegador.

#### Scenario: Login com token obsoleto

- **WHEN** o navegador possui um token CSRF associado a uma sessão ausente,
  expirada ou regenerada
- **THEN** a SPA renova o cookie CSRF e somente depois envia as credenciais

#### Scenario: Cookie existente ainda válido

- **WHEN** o navegador já possui sessão e token CSRF correspondentes
- **THEN** a SPA renova o cookie antes do login sem alterar o contrato de
  autenticação

### Requirement: Falha fechada na preparação do login

A SPA MUST preservar a proteção CSRF do Laravel e MUST NOT enviar credenciais
se a renovação do cookie CSRF falhar.

#### Scenario: Endpoint CSRF indisponível

- **WHEN** a solicitação de renovação CSRF falha
- **THEN** a tentativa de login é interrompida e o erro é apresentado pelo
  tratamento existente

### Requirement: Continuidade do fluxo autenticado

Após um login aceito, a SPA SHALL carregar a identidade autenticada e aplicar o
redirecionamento autorizado existente.

#### Scenario: Login concluído

- **WHEN** a renovação CSRF e a autenticação são concluídas com sucesso
- **THEN** a SPA atualiza a identidade e navega para o destino permitido ao
  papel do usuário
