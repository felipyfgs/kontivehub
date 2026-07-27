## 1. Runtime local

- [x] 1.1 Normalizar a leitura dos arquivos PHP novos pelo PHP-FPM.
- [x] 1.2 Confirmar que login e `/api/v1/me` retornam JSON pelo proxy Nuxt.

## 2. Contrato do seletor

- [x] 2.1 Fazer o cliente Nuxt consumir `/platform/tenants/selector`.
- [x] 2.2 Cobrir a URL e o envelope canônico em teste de regressão.

## 3. Identidade e navegação

- [x] 3.1 Cobrir o relacionamento do proprietário e o payload `/me` sem membership tenant.
- [x] 3.2 Cobrir os módulos globais com contexto privilegiado selecionado.

## 4. Validação

- [x] 4.1 Executar testes focados do Laravel e Nuxt.
- [x] 4.2 Executar os gates completos dos apps alterados e validar o OpenSpec.
