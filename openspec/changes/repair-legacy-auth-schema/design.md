## Context

O runtime local contém o schema consolidado anterior: entidades de identidade
usam `status = ACTIVE`, enquanto os modelos Laravel atuais consultam colunas
booleanas como `is_active`. Como o Fortify é fail-closed, a ausência da coluna é
interpretada como usuário inativo e toda senha é recusada.

O frontend também deixa o `nuxt-auth-sanctum` consultar `/api/v1/me` antes do
middleware de rotas, produzindo um `401` esperado na página pública de login.

## Goals / Non-Goals

**Goals:**

- Restaurar login e `/api/v1/me` sem apagar usuários ou tenants existentes.
- Manter autenticação, autorização, CSRF e isolamento de tenant fail-closed.
- Fazer a compatibilidade ser inócua em bancos que já usam o schema atual.
- Remover a consulta automática de identidade nas rotas públicas.

**Non-Goals:**

- Reconciliar tabelas fiscais ou operacionais fora do fluxo de identidade.
- Aceitar credenciais inválidas ou reduzir os controles do Sanctum.
- Executar `migrate:fresh`, limpar volumes ou reescrever migrations existentes.

## Decisions

1. Será adicionada uma migration nova, reversível e condicionada à presença da
   coluna legado `status`. Em instalações atuais, que não possuem essa coluna,
   ela não altera o schema.
2. A migration acrescentará somente os campos consumidos pelo login e pelo
   presenter de `/me` em `users`, `tenants`, `tenant_memberships`,
   `platform_memberships` e `platform_settings`.
3. `is_active` será derivado de `status = 'ACTIVE'`. Em tenant memberships,
   somente `tenant_admin` com `permission_profile_id` nulo ou `tenant_user` com
   perfil existente, ativo e pertencente ao mesmo tenant poderão ficar ativos.
   Em platform memberships, somente `platform_admin` é canônico. O backfill
   escreve apenas colunas criadas e registradas pela migration; valores
   canônicos preexistentes prevalecem. Um conflito restritivo permanece
   bloqueado, enquanto conflito permissivo (`status` não ativo com
   `is_active = true`) aborta a migration inteira.
4. O `client.initialRequest` do módulo Nuxt será desabilitado. O middleware
   existente continuará chamando `refreshIdentity()` apenas quando a rota exigir.
5. As falhas do Fortify continuarão usando `422`, mas a tradução `pt_BR` retornará
   uma mensagem de credenciais inválidas em vez da chave `auth.failed`.
6. A migration registrará em uma tabela técnica somente as colunas que adicionar.
   O rollback removerá esse conjunto registrado e preservará campos e dados que
   já existiam em schemas híbridos.

## Risks / Trade-offs

- [Schema legado com status ou papel desconhecido] → somente `ACTIVE` com papel
  canônico e invariantes satisfeitas vira ativo; os demais permanecem bloqueados.
- [Rollback após uso dos novos campos] → o `down` remove apenas colunas
  registradas como acrescentadas pela própria migration.
- [Campos relacionais sem integridade] → anomalias por registro — status/papel
  desconhecido, perfil ausente/inativo/cross-tenant ou tenant padrão inválido —
  mantêm a linha inativa ou o default nulo. Contrato dependente ausente também
  falha fechado sem ativação. Violações estruturais de DDL/constraint/shape e
  conflitos canônicos permissivos abortam a migration inteira.
- [Falha durante alteração do schema] → PostgreSQL executa DDL, marker técnico,
  backfill e constraints na mesma transação (`withinTransaction`); qualquer
  falha reverte todas essas etapas sem estado parcial.
- [Outras tabelas legadas divergentes] → ficam explicitamente fora do reparo e
  não são acessadas para autenticar ou apresentar a identidade básica.

## Migration Plan

1. Executar a migration focada contra a base local, sem limpar dados.
2. Reiniciar o processo PHP para recarregar traduções.
3. Validar CSRF `204`, login válido `200`, `/me` autenticado `200`, login
   inválido `422` localizado com código estável e ausência do `/me` automático
   na página pública.
4. Em rollback, executar a migration com `--path` e `--step=1`; a tabela técnica
   garante que colunas canônicas preexistentes não sejam removidas.

## Open Questions

Nenhuma para o escopo de autenticação.
