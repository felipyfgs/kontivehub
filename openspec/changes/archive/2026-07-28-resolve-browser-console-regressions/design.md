## Context

A investigação confirmou três defeitos objetivos e dois sinais esperados. O
PHP-FPM roda como `www-data`, mas o novo diretório
`Http/Requests/Fiscal/Monitoring` estava em `0700` e o Form Request em `0600`;
o Unovis exige `x` no Crosshair; e a taxonomia atual removeu a página de
certificado sem compatibilidade para o deep link anterior. Em contraste,
`/api/sanctum/api/v1/me` é a URL correta do proxy e deve retornar `401` sem
sessão, enquanto as mensagens de filesystem/nó pertencem ao DevTools.

## Goals / Non-Goals

**Goals:**

- Restaurar o autoload fiscal sob o usuário real do PHP-FPM.
- Eliminar o warning do Crosshair preservando o arquétipo analítico.
- Resolver e canonicalizar o deep link anterior sem duplicar a UI.
- Validar API, Web e OpenSpec de forma proporcional ao risco.

**Non-Goals:**

- Relaxar autenticação, tenant ou contratos HTTP.
- Desabilitar DevTools para ocultar mensagens diagnósticas.
- Reintroduzir certificado como aba primária.
- Alterar banco, filas, flags ou executar egress.

## Decisions

### Normalizar diretório e arquivo exigidos pelo runtime

O diretório receberá `0755` e o Form Request `0644`, com autoload comprovado
como `www-data`. Executar todo o FPM como o usuário do host ou mover validação
para o controller ampliaria o impacto e violaria os boundaries vigentes.

### Reusar o accessor horizontal existente

`VisCrosshair` receberá `:x="x"`, alinhado a `VisLine`, `VisArea` e `VisAxis`.
É o menor delta em relação ao componente e à referência visual.

### Usar alias na página canônica

`cadastro.vue` declarará o alias `/clients/:id/certificado` e middleware inline
fará `replace` para `/clients/:id/cadastro`, preservando query e hash. Isso evita
uma página redirect-only — proibida pelo inventário — e não recria a aba
removida.

## Risks / Trade-offs

- [Novos arquivos locais podem voltar a `0600`] → validar explicitamente como
  `www-data` e manter os artefatos desta change em `0644`.
- [O redirect não abre o slideover] → levar à superfície canônica estável, onde
  a ação “Gerenciar certificado” continua explícita.
- [Refatorações concorrentes podem sobrepor arquivos] → limitar os hunks e
  conferir o diff antes de cada gate.
