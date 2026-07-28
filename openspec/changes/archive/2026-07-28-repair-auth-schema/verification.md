## Evidência local

Validado em 2026-07-28 sem limpar, reverter ou semear o banco local.

### Migration e schema

- A migration `2026_07_28_020000_align_identity_schema_for_authentication`
  está registrada como executada no banco local.
- As colunas canônicas de identidade exigidas pelo fluxo estão presentes.
- O teste de compatibilidade executado em PostgreSQL isolado passou nos 11
  cenários de schema anterior, híbrido, conflito permissivo, invariantes de papel,
  tenant padrão, falha transacional e rollback seletivo: 54 assertions.

### Fluxo Sanctum

- O cookie CSRF respondeu `204`.
- Credenciais inválidas responderam `422`, mensagem `Credenciais inválidas.` e
  `code = INVALID_CREDENTIALS`.
- A identidade de desenvolvimento já configurada autenticou com `200`, e
  `/api/v1/me` respondeu `200`; nenhum dado de credencial foi impresso ou
  alterado.
- `KontiveHubSanctumFlowTest` passou os quatro cenários de login, identidade,
  memberships inválidas/cross-tenant e bootstrap público: 54 assertions.

### Frontend

- `tests/unit/auth-sanctum-config.test.ts` passou e confirma que o módulo
  Sanctum não busca `/api/v1/me` automaticamente ao abrir a rota pública.
