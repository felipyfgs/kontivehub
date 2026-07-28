## Context

Os erros foram observados na SPA executada pelo container de desenvolvimento.
A investigação separou sinais esperados de defeitos reais:

- `/api/sanctum/api/v1/me` é a composição correta do proxy Sanctum e deve
  responder `401` quando não existe sessão válida;
- mensagens de filesystem e nó ausente não existem no código do produto e
  pertencem ao Nuxt DevTools/inspector;
- o PHP-FPM executa como `www-data`, enquanto o novo
  `ViewFiscalModulePortfolioRequest.php` foi criado com modo `0600` e dono
  diferente, tornando a classe invisível ao autoloader no runtime;
- o deep link `/clients/:id/certificado` não faz parte da taxonomia atual, que
  gerencia certificado em um slideover;
- o `VisCrosshair` não recebeu o accessor horizontal exigido pelo Unovis.

A mudança atravessa Laravel e Nuxt, mas não altera domínio, contrato público,
persistência ou autorização.

## Goals / Non-Goals

**Goals:**

- Restaurar a leitura da carteira PGDAS-D pelo usuário real do PHP-FPM.
- Eliminar o warning do Crosshair preservando dados reais e o arquétipo
  analítico vigente.
- Preservar deep links anteriores de certificado por redirecionamento para a rota
  canônica do cliente.
- Cobrir as regressões com verificações focadas e executar os gates aplicáveis.

**Non-Goals:**

- Suprimir ou converter em sucesso o `401` de uma requisição sem sessão.
- Desabilitar Nuxt DevTools para esconder mensagens internas de desenvolvimento.
- Reintroduzir certificado como aba primária do cliente.
- Alterar schema, contratos HTTP, permissões, tenant, flags fiscais ou executar
  egress real.

## Decisions

### Normalizar somente a fonte exigida pelos endpoints observados

O arquivo `ViewFiscalModulePortfolioRequest.php` será tornado legível pelo
usuário do PHP-FPM e a verificação será executada explicitamente como
`www-data`. A alternativa de executar PHP-FPM como o usuário do host ampliaria
o impacto operacional; copiar a validação de volta ao controller violaria o
boundary arquitetural da refatoração em andamento.

### Reusar o accessor horizontal da série no Crosshair

`VisCrosshair` receberá `:x="x"`, o mesmo accessor de `VisLine`, `VisArea` e
`VisAxis`. Isso mantém todos os marks no mesmo domínio e representa o menor
delta em relação ao arquétipo analítico.

### Redirecionar a rota anterior em vez de restaurar uma página

Uma página compatível para `/clients/:id/certificado` fará redirect substitutivo
para `/clients/:id/cadastro`. A gestão continua no slideover do detalhe; assim,
o deep link deixa de gerar warning sem recriar uma aba removida nem duplicar a
UI de credenciais. A matriz de paridade registrará a rota como compatibilidade
sem bundle visual próprio.

## Risks / Trade-offs

- [Permissões de novos arquivos podem voltar a `0600` durante trabalho local]
  → validar a classe como `www-data` e normalizar os arquivos desta change para
  `0644`; o handoff deve preservar essa propriedade.
- [O redirect não abre automaticamente o slideover] → direcionar para a
  superfície canônica estável e manter o botão explícito “Gerenciar
  certificado”, evitando estado de overlay codificado na URL sem contrato.
- [Warnings do DevTools podem continuar aparecendo] → tratá-los como ruído da
  ferramenta, documentando que `NUXT_DEVTOOLS=false` é uma confirmação
  diagnóstica, não uma mudança de produto.
- [Worktree contém refatorações concorrentes] → limitar o conteúdo alterado e
  não reverter arquivos ou contratos não relacionados.
