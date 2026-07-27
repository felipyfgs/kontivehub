## Why

Os seeders atuais misturam usuários genéricos, cenários demonstrativos extensos e
flags independentes. O ambiente local precisa de uma base pequena, determinística
e representativa, formada pelos dados cadastrais fornecidos para desenvolvimento.

## What Changes

- **BREAKING**: substituir a orquestração `*DemoSeeder` por baselines explícitos
  para `testing` e `local`.
- Declarar diretamente no seeder os dados cadastrais já conferidos da plataforma,
  do escritório e do cliente, sem parser ou importador de PDFs em runtime.
- Criar tenants, perfis, assinaturas, usuários, memberships, cliente,
  estabelecimento e contato de forma idempotente.
- Ler de `.local/dados` somente a senha e os três certificados PFX, associando-os
  pelo CNPJ retornado pelo leitor canônico.
- Ativar certificados pelos serviços de domínio e armazenar o material somente no
  vault.
- Manter `ReferenceDataSeeder` como fonte dos catálogos canônicos.

## Capabilities

### New Capabilities

- `development-data-seeding`: baseline local pequeno, realista, idempotente e
  protegido para desenvolvimento.

### Modified Capabilities

Nenhuma.

## Impact

- `apps/api/database/seeders`, configuração local e Compose.
- Testes de seed e validação PostgreSQL.
- Nenhum contrato HTTP é alterado; PDFs, PFX e senha continuam fora do Git.
