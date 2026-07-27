## 1. Configuração

- [x] 1.1 Configurar o caminho e o mount somente leitura de `.local/dados`.
- [x] 1.2 Separar os baselines `testing` e `local` no `DatabaseSeeder`.

## 2. Seed de desenvolvimento

- [x] 2.1 Declarar os dados cadastrais conferidos diretamente no `DevelopmentSeeder`.
- [x] 2.2 Persistir tenants, perfis, assinaturas, usuários e memberships.
- [x] 2.3 Persistir cliente, estabelecimento e contato pelas chaves naturais.
- [x] 2.4 Implementar o seeder simples de certificados com associação pelo CNPJ e idempotência por fingerprint.

## 3. Validação

- [x] 3.1 Cobrir baseline de testes e idempotência do seed estruturado.
- [x] 3.2 Validar `migrate:fresh --seed` no PostgreSQL com os três certificados.
- [x] 3.3 Reexecutar `db:seed` e confirmar três fingerprints inalteradas.
- [x] 3.4 Rodar gates completos da API e auditar o diff por material sensível.
