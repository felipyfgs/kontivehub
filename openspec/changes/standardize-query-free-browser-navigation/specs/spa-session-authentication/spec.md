## MODIFIED Requirements

### Requirement: Continuidade do fluxo autenticado

Após um login aceito, a SPA SHALL carregar a identidade autenticada e consumir no máximo uma vez o destino interno autorizado guardado na sessão do navegador. O retorno SHALL NOT ser transportado em query string e SHALL ser validado pelas mesmas regras de papel antes da navegação.

#### Scenario: Login concluído
- **WHEN** a renovação CSRF e a autenticação são concluídas com sucesso
- **THEN** a SPA atualiza a identidade e navega para o destino one-shot permitido ou para o home do papel

#### Scenario: Destino é inválido ou incompatível
- **WHEN** o retorno armazenado é externo, malformado ou não permitido ao papel autenticado
- **THEN** a SPA descarta o retorno e usa o home autorizado sem refletir o valor na URL

## ADDED Requirements

### Requirement: Redefinição de senha usa fragmento one-shot

Laravel SHALL gerar o link de reset no frontend como `/reset-password#token=…&email=…`. A SPA SHALL consumir e remover o fragmento imediatamente, manter token/e-mail somente em memória e enviar o mesmo body ao endpoint Fortify. A forma anterior em query SHALL NOT ser convertida.

#### Scenario: Usuário abre o e-mail de reset
- **WHEN** o link novo é carregado no navegador
- **THEN** token/e-mail são lidos do fragmento, a URL é limpa e nenhuma query ou fragmento sensível permanece antes do submit

#### Scenario: Link anterior é aberto
- **WHEN** `/reset-password?token=…&email=…` é acessado após o ciclo de compatibilidade
- **THEN** a SPA não consome as credenciais da query e apresenta o estado de link inválido

#### Scenario: Fragmento é inválido
- **WHEN** token ou e-mail está ausente ou malformado
- **THEN** o formulário não envia credenciais e apresenta o estado de link inválido
