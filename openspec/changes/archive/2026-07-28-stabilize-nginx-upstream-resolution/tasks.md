## 1. Resolução do upstream PHP

- [x] 1.1 Alterar o `fastcgi_pass` de desenvolvimento para resolver `php:9000` dinamicamente pelo DNS interno, preservando todos os parâmetros e restrições atuais.
- [x] 1.2 Aplicar a mesma política no servidor de API da configuração de produção, sem expor `/up` nem ampliar rotas públicas.
- [x] 1.3 Validar a sintaxe dos dois arquivos com a imagem Nginx usada pelo projeto.

## 2. Regressão operacional

- [x] 2.1 Adicionar uma verificação local com timeout que registra os IDs dos containers, confirma `/up`, recria somente o PHP e exige recuperação do mesmo Nginx.
- [x] 2.2 Garantir que a verificação não remove volumes ou outros serviços e emite somente diagnóstico sanitizado quando falhar.
- [x] 2.3 Cobrir indisponibilidade real do PHP: `/up` deve falhar enquanto o upstream não estiver acessível e recuperar sem restart manual do Nginx.

## 3. Compatibilidade e handoff

- [x] 3.1 Executar `docker compose config`, os testes focados de infraestrutura e os gates aplicáveis da API.
- [x] 3.2 Confirmar que rotas, contratos HTTP, allowlists, timeouts e configuração do Reverb não mudaram.
- [x] 3.3 Validar a change em modo strict e registrar a evidência de convergência; não executar rollout ou comando produtivo.
