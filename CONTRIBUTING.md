# Fluxo de desenvolvimento

O KontiveHub mantém duas branches de longa duração:

- `main`: versão estável e pronta para produção;
- `develop`: integração contínua das mudanças em desenvolvimento.

## Fluxo de branches

1. Atualize `develop` e crie uma branch curta a partir dela (`feature/*`,
   `fix/*`, `refactor/*` ou `chore/*`).
2. Abra um pull request da branch curta para `develop`.
3. Aguarde todos os jobs do CI passarem e conclua a revisão de código.
4. Para uma entrega, abra um pull request de `develop` para `main`.
5. Depois da entrega, mantenha `develop` sincronizada com `main`.

Pull requests para `main` originados de qualquer branch diferente de `develop`
são rejeitados pelo CI. Correções urgentes podem usar `hotfix/*`, mas devem ser
integradas primeiro em `develop` antes da promoção para `main`.

## Verificações obrigatórias

O workflow `CI` executa os gates do Laravel, Nuxt e Wazync em pushes e pull
requests direcionados a `main` ou `develop`. Antes de abrir um pull request,
execute localmente os gates relacionados aos arquivos alterados.
