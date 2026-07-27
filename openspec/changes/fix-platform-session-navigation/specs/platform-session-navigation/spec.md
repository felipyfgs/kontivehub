## ADDED Requirements

### Requirement: Identidade HTTP íntegra do administrador da plataforma
O sistema SHALL devolver JSON válido em `/api/v1/me` para o administrador global
e SHALL expor `platform_role`, o contexto tenant resolvido e as permissões
efetivas sem exigir uma `tenant_membership` fictícia.

#### Scenario: Proprietário com tenant padrão
- **WHEN** um usuário ativo possui `platform_membership` ativa com
  `default_tenant_id` apontando para um tenant ativo
- **THEN** `/api/v1/me` informa `platform_admin`, contexto
  `platform_privileged`, tenant atual e permissões efetivas

### Requirement: Seletor global usa o contrato canônico
O frontend SHALL carregar o seletor do administrador global pelo endpoint
`/api/v1/platform/tenants/selector` e SHALL interpretar o envelope
`tenants`, `selected_tenant_id` e `default_tenant_id`.

#### Scenario: Listagem de tenants da plataforma
- **WHEN** o administrador global abre o seletor de escritórios
- **THEN** todos os tenants selecionáveis do envelope canônico são apresentados

### Requirement: Navegação global permanece disponível
O frontend SHALL exibir os destinos globais de administração para toda
identidade com `platform_role=platform_admin`, com ou sem contexto tenant
privilegiado ativo.

#### Scenario: Plataforma com tenant selecionado
- **WHEN** o administrador global possui um tenant privilegiado selecionado
- **THEN** a navegação inclui Escritórios, Módulos fiscais e SERPRO

### Requirement: Separação entre memberships
O sistema MUST manter `platform_memberships` como fonte da autorização global e
MUST NOT criar `tenant_memberships` apenas para permitir seleção privilegiada.

#### Scenario: Seleção privilegiada sem vínculo tenant
- **WHEN** o administrador global seleciona um tenant disponível
- **THEN** o contexto muda pela seleção privilegiada e nenhuma linha é criada em
  `tenant_memberships`
