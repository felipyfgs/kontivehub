## Why

O login do proprietário da plataforma produz uma sessão válida no banco, mas o
frontend pode receber uma identidade quebrada e consultar o contrato errado do
seletor global. Isso oculta os módulos administrativos e apresenta uma lista
vazia de escritórios mesmo quando as relações e chaves estão válidas.

## What Changes

- Garantir que os arquivos de configuração e seed de desenvolvimento sejam
  legíveis pelo processo HTTP containerizado.
- Consumir o endpoint canônico do seletor global de tenants no Nuxt.
- Preservar a separação entre `platform_memberships` e
  `tenant_memberships`: o administrador global seleciona um tenant por contexto
  privilegiado, sem membership fictícia.
- Cobrir por regressão o payload `/me`, o envelope do seletor e a navegação
  administrativa do perfil plataforma.

## Capabilities

### New Capabilities

- `platform-session-navigation`: identidade, seleção de tenant e navegação
  coerentes para o administrador global da plataforma.

### Modified Capabilities

Nenhuma.

## Impact

- Laravel: configuração de desenvolvimento, seed, identidade `/api/v1/me` e
  seletor `/api/v1/platform/tenants/selector`.
- Nuxt: cliente do seletor global e testes de navegação.
- Ambiente local: permissões POSIX dos novos arquivos PHP versionados.
