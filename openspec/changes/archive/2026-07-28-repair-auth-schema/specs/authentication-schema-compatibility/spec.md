## ADDED Requirements

### Requirement: Compatibilidade fail-closed do schema de identidade
O sistema SHALL alinhar instalações com colunas anteriores `status` aos campos de
identidade atuais sem remover registros existentes e SHALL considerar ativo
somente o registro cujo status anterior seja `ACTIVE` e cujas invariantes canônicas
de papel estejam satisfeitas. A autorização efetiva SHALL continuar exigindo
usuário ativo, membership ativa/canônica e tenant ativo/operacional.

#### Scenario: Identidade ativa no schema anterior
- **WHEN** a migration encontra usuário ou tenant com `status = ACTIVE`
- **THEN** o campo `is_active` correspondente é preenchido com verdadeiro

#### Scenario: Membership ativa e canônica
- **WHEN** a migration encontra `tenant_admin` `ACTIVE` sem perfil, `tenant_user` `ACTIVE` com perfil existente, ativo e do mesmo tenant, ou `platform_admin` `ACTIVE`
- **THEN** o campo `is_active` correspondente é preenchido com verdadeiro

#### Scenario: Membership anterior incompleta
- **WHEN** a migration encontra papel desconhecido, `tenant_admin` com perfil, `tenant_user` sem perfil ou com perfil ausente, inativo ou de outro tenant
- **THEN** ela permanece inativa sem ampliar autorização

#### Scenario: Tenant padrão da plataforma
- **WHEN** uma platform membership anterior canônica possui `tenant_id` de tenant existente, ativo e operacional e usuário associado ativo
- **THEN** a migration reconcilia esse valor em `default_tenant_id` sem criar membership de tenant

#### Scenario: Tenant padrão inválido
- **WHEN** o tenant anterior é ausente, inativo ou não operacional, o usuário está inativo ou a platform membership não é canônica
- **THEN** `default_tenant_id` permanece nulo e nenhuma membership de tenant é fabricada

#### Scenario: Registro anterior não ativo
- **WHEN** a migration encontra qualquer status diferente de `ACTIVE`
- **THEN** o campo `is_active` correspondente permanece falso

#### Scenario: Schema já atualizado
- **WHEN** a migration encontra uma tabela sem a coluna anterior `status`
- **THEN** ela não altera os campos canônicos existentes

#### Scenario: Schema híbrido com valor canônico
- **WHEN** a coluna canônica já existe junto de `status`
- **THEN** a migration preserva seu valor, mantém conflitos restritivos bloqueados e aborta atomicamente se o conflito existente ampliaria autorização

#### Scenario: Rollback de schema híbrido
- **WHEN** a migration é revertida em tabela que já possuía parte das colunas canônicas
- **THEN** somente as colunas registradas como adicionadas por ela são removidas

### Requirement: Bootstrap público sem consulta de identidade
O frontend SHALL deixar o middleware de rotas decidir quando carregar a
identidade e SHALL NOT consultar `/api/v1/me` automaticamente ao abrir uma rota
pública de autenticação.

#### Scenario: Visitante abre o login
- **WHEN** um visitante sem sessão abre `/login`
- **THEN** o frontend não dispara a consulta automática de `/api/v1/me`

### Requirement: Resultado seguro do login
O sistema SHALL manter CSRF e validação de senha no fluxo Sanctum, SHALL
autenticar credenciais válidas de usuário ativo e SHALL retornar mensagem pt-BR
neutra para credenciais inválidas com código estável independente de locale.

#### Scenario: Credenciais válidas
- **WHEN** um usuário ativo envia e-mail e senha válidos após obter o cookie CSRF
- **THEN** o login responde com sucesso e `/api/v1/me` retorna a identidade

#### Scenario: Credenciais inválidas
- **WHEN** o e-mail ou a senha não confere
- **THEN** o login responde `422` com a mensagem `Credenciais inválidas.` em pt-BR e `code = INVALID_CREDENTIALS` em qualquer locale
