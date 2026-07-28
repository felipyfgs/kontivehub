## Why

A API atual exige colunas canônicas de ativação e contexto, mas instalações locais
criadas pelo schema consolidado anterior ainda usam `status`. Nessas bases, o
Fortify rejeita todo login com `422`, mesmo quando a senha confere.

## What Changes

- Adicionar uma migration reversível e condicionada ao schema anterior para alinhar
  somente as tabelas de identidade necessárias ao login e ao endpoint `/api/v1/me`.
- Derivar flags ativas dos valores `status` existentes, preservando usuários,
  tenants e memberships.
- Evitar a consulta automática de `/api/v1/me` em rotas públicas do Nuxt.
- Localizar a mensagem de credenciais inválidas para pt-BR e manter identificação
  independente de locale por `code = INVALID_CREDENTIALS`.

## Capabilities

### New Capabilities

- `authentication-schema-compatibility`: compatibilidade segura de autenticação
  entre o schema consolidado anterior e o modelo de identidade atual.

### Modified Capabilities

Nenhuma.

## Impact

Afeta o bootstrap Sanctum do Nuxt, mensagens de autenticação Laravel, as tabelas
`users`, `tenants`, `tenant_memberships`, `platform_memberships` e
`platform_settings`, além dos testes focados do fluxo Sanctum. Preserva `422` e o
envelope de erros, mas altera intencionalmente a mensagem humana e acrescenta o
código estável `INVALID_CREDENTIALS`; não desativa CSRF ou autorização.
