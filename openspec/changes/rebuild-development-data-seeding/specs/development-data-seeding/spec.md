## ADDED Requirements

### Requirement: Baselines separados por ambiente

O sistema SHALL carregar uma fixture sintética mínima em `testing` e os dados
estruturados de desenvolvimento somente em `local`.

#### Scenario: Testes sem dataset local

- **WHEN** `DatabaseSeeder` roda em `testing`
- **THEN** nenhum arquivo de `.local/dados` é acessado

#### Scenario: Ambiente não permitido

- **WHEN** o baseline é solicitado fora de `local` ou `testing`
- **THEN** o seeder falha antes de escrever

### Requirement: Base de desenvolvimento idempotente

O sistema SHALL reconciliar tenants, perfis, assinaturas, usuários, memberships,
cliente, estabelecimento e contato pelas respectivas chaves naturais.

#### Scenario: Primeira carga

- **WHEN** o banco local está vazio
- **THEN** todos os agregados estruturados são criados

#### Scenario: Segunda carga

- **WHEN** o mesmo seed é executado novamente
- **THEN** nenhuma entidade lógica é duplicada

### Requirement: Certificados protegidos

O sistema SHALL ler somente o segredo e os PFX de `.local/dados`, associar cada
certificado pelo CNPJ validado e ativá-lo pelos serviços de domínio existentes.

#### Scenario: Certificados novos

- **WHEN** os três PFX correspondem às entidades declaradas
- **THEN** duas credenciais de tenant e uma de cliente são ativadas no vault

#### Scenario: Reexecução

- **WHEN** a fingerprint já está ativa
- **THEN** nenhum novo objeto de certificado é criado

#### Scenario: Dataset incompatível

- **WHEN** falta recurso ou o CNPJ não corresponde
- **THEN** a carga falha com código genérico, sem revelar senha, bytes ou caminho

### Requirement: Validação PostgreSQL

O baseline SHALL ser validado por teste automatizado, `migrate:fresh --seed` e
segunda execução de `db:seed`.

#### Scenario: Banco novo

- **WHEN** a carga completa roda no PostgreSQL
- **THEN** não existem migrations pendentes
