## Context

O dataset local já está organizado por plataforma, escritório e cliente. Os dados
cadastrais necessários foram conferidos nos documentos fornecidos; não há
necessidade de criar infraestrutura de extração documental dentro da aplicação.

## Decisions

### Dados estruturados no seeder

`DevelopmentSeeder` contém os valores cadastrais necessários para os agregados de
desenvolvimento. A carga usa chaves naturais (`slug`, e-mail, CNPJ raiz e CNPJ
completo) e `updateOrCreate`, dentro de transação.

### Certificados separados

`DevelopmentCertificateSeeder` percorre `.local/dados` somente para localizar um
arquivo TXT e três PFX. O conteúdo dos PDFs não é lido. Cada PFX é aberto por
`PfxReaderInterface`, associado ao CNPJ esperado e ativado por
`TenantCredentialService` ou `CredentialService`.

Antes de ativar, o seeder verifica a fingerprint ativa. Assim, a segunda execução
não grava outro objeto no vault. Senha e bytes do PFX permanecem apenas em memória
e no `SecureObjectStore`.

### Baseline por ambiente

`DatabaseSeeder` carrega os catálogos e escolhe exatamente um baseline:

- `TestingBaselineSeeder` em `testing`, sem `.local`;
- `DevelopmentSeeder` e `DevelopmentCertificateSeeder` em `local`;
- falha fechada nos demais ambientes.

### Saída

Os seeders exibem somente contagens. Senha, conteúdo dos certificados, caminhos e
IDs de vault nunca são emitidos.

## Risks / Trade-offs

- Alterações cadastrais exigem editar explicitamente o seeder.
- O seed local falha se faltar um PFX, a senha ou a correspondência de CNPJ.
- Os dados cadastrais públicos ficam no código de desenvolvimento; certificados e
  credenciais permanecem exclusivamente em `.local` e no vault.

## Migration Plan

1. Trocar a orquestração do `DatabaseSeeder`.
2. Criar os baselines de teste e desenvolvimento.
3. Montar `.local/dados` como somente leitura.
4. Validar `migrate:fresh --seed` e uma segunda execução no PostgreSQL.
