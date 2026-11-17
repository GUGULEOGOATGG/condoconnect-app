# CondoConnect — PHP + MySQL

## Aplicativo Android

A pasta `mobile/` contém o aplicativo nativo Android em React Native/Expo, com
modo de demonstração e conexão ao backend existente. Consulte `mobile/README.md`
para instalação, conexão e geração do APK. A nova rota do app é `api/mobile.php`.
O site original é preservado.

## Aplicativo web para celular (pasta `app/`)

A pasta `app/` é o CondoConnect para o navegador do celular, com as mesmas telas do
aplicativo Android e o mesmo login por nível de acesso (morador, prestador, síndico e
administrador). Ele usa `api/mobile.php` do próprio servidor: não tem banco nem regra própria.

1. Publique as pastas `app/` e `api/` (com o `api/mobile.php` desta entrega) junto do site.
2. Abra `https://seu-dominio.com.br/SUBPASTA/app/` no celular e entre com a conta do site.
3. No menu do navegador, use "Adicionar à tela inicial" para abrir como aplicativo.

Use sempre HTTPS. O token de acesso fica no armazenamento do navegador daquele aparelho e
expira em 7 dias; "Sair da minha conta" revoga o token no servidor. A página envia uma
política de segurança de conteúdo que só permite scripts e conexões do próprio servidor.
Para criar contas de cada nível, veja "Contas para cada nível" em `mobile/README.md`.


## Instalação
1. Requisitos: PHP 8.1+ com PDO, pdo_mysql, mbstring, fileinfo, curl e openssl, Apache com PHP habilitado,
   MySQL 8+ ou MariaDB 10.6+. Não publicar em hospedagem apenas estática.
2. Extraia esta pasta. A raiz web deve conter index.php, pages/, backend/ e frontend/.
   Não aponte o DocumentRoot apenas para frontend/.
3. Importe backend/database/db_sisc.sql no phpMyAdmin ou cliente MySQL.
   O mesmo SQL foi entregue separadamente. Basta importar uma das cópias.
4. Copie `.env.example` para `.env` na raiz e configure DB_HOST, DB_PORT,
   DB_NAME, DB_USER e DB_PASS. Para aproveitar a configuração antiga, copie
   `vai.env` para `.env`. Não sobrescreva um `.env` já configurado.
   Se `.env` não existir, o sistema carrega `vai.env` automaticamente.
   Variáveis do servidor têm prioridade; `DB_DATABASE`, `DB_USERNAME` e
   `DB_PASSWORD` também são aceitos. `.env.example` é somente um modelo.
   O SQL de instalação usa `db_sisc`: ajuste seu CREATE DATABASE/USE
   para o DB_NAME escolhido antes de importar em uma instalação nova.
   Em Docker, DB_HOST normalmente é o nome do serviço do banco, e não 127.0.0.1.
   Use uma conta MySQL própria para a aplicação após a instalação.
5. Dê ao usuário do PHP permissão de escrita em backend/storage/uploads/ e na
   pasta de sessões do PHP. Mantenha backend/ bloqueado para acesso HTTP.
6. Abra pages/cadastro.php e crie sua conta. Depois teste login e um novo chamado.
   O SQL inclui perfis fictícios com senha pública. Seus logins ficam bloqueados
   por padrão; `APP_DEMO_LOGIN=1` é exclusivo para um ambiente de demonstração.
7. Configure `BREVO_API_KEY` e `BREVO_FROM_EMAIL` no servidor. A API Brevo é usada
   para confirmação de cadastro, recuperação de senha e códigos de duas etapas.
8. Mantenha `APP_AUTO_MIGRATE=0`. Essa opção cobre apenas parte do schema;
   para atualizar um banco existente, execute a migração CLI descrita abaixo.
9. Configure as variáveis do Mercado Pago antes de ativar o checkout: `MERCADOPAGO_CLIENT_ID`,
   `MERCADOPAGO_CLIENT_SECRET`, `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_PUBLIC_KEY` e
   `MERCADOPAGO_REDIRECT_URI`. O endpoint pronto está em `api/mercadopago.php`.

## Mercado Pago

A integração foi adicionada em `api/mercadopago.php` com três ações principais:
- `status`: informa se as chaves já estão configuradas.
- `oauth_token`: troca `client_id` e `client_secret` pelo token do Mercado Pago.
- `create_payment`: cria um pagamento usando `MERCADOPAGO_ACCESS_TOKEN`.

Exemplo de uso:

```bash
curl -X POST http://localhost/api/mercadopago.php \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "status"
  }'
```

Depois de configurar o ambiente, basta preencher as variáveis e chamar `create_payment` com:
- `amount`
- `description`
- `payment_method_id` (ex.: `pix` ou `credit_card`)
- `payer_email`
- `external_reference`

## Corrigir o endereço /pages/login.php
O erro “Not Found” é emitido pelo Apache antes de executar a conexão com o banco.
Ele exige corrigir a localização dos arquivos ou o DocumentRoot.

Para usar exatamente http://2.24.65.149:8080/pages/login.php, o DocumentRoot da
porta 8080 precisa apontar para a pasta deste projeto, que contém pages/login.php.
Há um modelo em deploy/apache.conf. Ajuste o caminho, habilite o site e valide
com apachectl configtest antes de recarregar o Apache. Preserve outros sites.

Se o DocumentRoot for /var/www/html e você extrair em
/var/www/html/sisc-atualizado, use:
http://2.24.65.149:8080/sisc-atualizado/pages/login.php

Se mantiver os diretórios externos do ZIP original, eles também precisam constar
na URL, ou o DocumentRoot deve apontar para a pasta interna correta.
Não foi feita alteração no servidor remoto: esta entrega contém os arquivos.

Os caminhos são calculados para raiz ou subpasta. Se houver um proxy/Alias que
altere os caminhos, defina APP_BASE_PATH=/sisc-atualizado (ou / para a raiz).
As rotas físicas não exigem mod_rewrite. O .htaccess define index.php como entrada.
Não copie por cima de um index.html antigo: ele pode prevalecer se o servidor
ignorar DirectoryIndex. Instale em pasta limpa e depois ajuste o DocumentRoot.

## Organização
- frontend/pages/: páginas PHP originais com ajustes de validação e caminhos.
- frontend/includes/: cabeçalho, menu e rodapé; pontes para funções e autenticação.
- frontend/assets/, frontend/style/, frontend/global/: estilos e scripts preservados.
- backend/config/: conexão configurável com MySQL.
- backend/includes/: funções de dados, sessão, proteção e autenticação.
- backend/database/: SQL completo.
- backend/storage/uploads/: imagens recebidas, não expostas por URL direta.
- backend/referencias/versao-alternativa-original.zip: segunda aplicação e arquivos
  de Netlify/testes do ZIP original preservados como referência, fora do fluxo ativo.
- mobile/: aplicativo nativo Android, projeto Android Studio, testes e instruções.
- pages/, includes/, config/, index.php e logout.php na raiz: entradas de
  compatibilidade que mantêm os endereços antigos sem duplicar a lógica.

## Funcionalidades desta base
Cadastro com hash de senha, login/logout, sessão autenticada, painel com chamados
do usuário, novo chamado e anexo de imagem de até 2 MB, registro de contato.
As páginas públicas mantêm o layout original. Perfil de prestador e síndico ainda
usa o painel básico do original; não há novo fluxo de gestão implementado.
As tabelas de mensagens/avaliações existem para as funções já presentes. A avaliação
é protegida no backend: o usuário não pode editar sua média nem avaliar a si próprio.
A antiga página sqlite.php agora explica a migração, sem expor dados das contas.
A recuperação de senha, a confirmação de cadastro e os códigos de duas etapas usam
tokens/códigos temporários e envio pela API Brevo. Contato registra no banco, sem
enviar email.

## Banco existente
Esta atualização aplica a migração comunitária e os dados fictícios de
demonstração uma única vez, no primeiro acesso a uma página autenticada após
o deploy do commit. O usuário MySQL da aplicação precisa poder criar/alterar
tabelas e inserir dados. As conversas e os registros existentes são preservados.
A execução usa uma trava no banco; a versão concluída fica em `app_migrations`.
Os perfis de demonstração continuam com login bloqueado por padrão.
Condomínios existentes com seções vazias recebem comunicados e áreas comuns
identificados como exemplos, além de uma reserva passada cancelada que não
bloqueia a agenda. Dados preenchidos são preservados. Revise os exemplos antes
de usar os espaços para reservas reais. Administradores podem selecionar o
condomínio nas páginas de comunicados, reservas e condomínio.

Para desativar essa atualização pelo site, configure `APP_DEPLOY_UPGRADE=0`.
Para executar manualmente no servidor (com essa variável habilitada):

```bash
php backend/database/update_application.php
```

O comando informa as quantidades de usuários, chamados, condomínios, reservas
e comunicados e termina com `APPLICATION_UPDATE_OK`.

Para verificar a chave Brevo e o remetente ativo, sem enviar e-mail nem exibir
credenciais, execute no servidor:

```bash
php tools/check-brevo.php
```

O resultado esperado é `BREVO_ACCOUNT_HTTP: 200` e `BREVO_OK`.
Confirmação, recuperação de senha e códigos de acesso usam o endpoint
[de e-mail transacional do Brevo](https://developers.brevo.com/docs/send-a-transactional-email).

Se o chat apresentar `SQLSTATE[42S02]` indicando que `chat_participantes` não
existe, execute a migração incremental no servidor, após fazer backup:

```bash
cd /var/www/html/sisc-test
php backend/database/upgrade_business_rules.php
```

O script usa o `.env` da raiz e a configuração de conexão da aplicação. Confira
que `DB_NAME` (ou `DB_DATABASE`) aponta para o banco usado pelo site (`db_sisc`
no erro informado). A conta precisa de permissão para criar e alterar tabelas.
A migração cria as tabelas ausentes, adiciona campos necessários e recupera os
participantes das conversas existentes, sem apagar conversas ou mensagens.
Ela pode ser executada novamente. Aguarde `MIGRATION_OK` e recarregue o chat.
O SQL de instalação contém `USE db_sisc`; para bancos com outro nome, ajuste
o destino. Para atualizar um banco existente, use a migração incremental.

## Conectar ao banco em um comando

Se o site, o app web ou o APK mostram "O banco de dados está indisponível", rode no
servidor, dentro da pasta do site:

```bash
cd /var/www/html/sisc-test
php tools/configurar-banco.php
```

O script pergunta endereço, porta, nome do banco, usuário e senha do MySQL, testa a
conexão e diz exatamente o que corrigir (senha errada, banco inexistente, usuário sem
permissão, MySQL desligado, driver do PHP ausente). Só grava o `.env` quando a conexão
funciona, guardando uma cópia do anterior. No fim, confere as tabelas e termina com
`BANCO_OK`.

## Configuração e diagnóstico

O `.env` é carregado também ao incluir diretamente as configurações do banco e
do Mercado Pago. Variáveis do servidor têm prioridade sobre o arquivo.
`DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` são aliases de `DB_NAME`, `DB_USER`
e `DB_PASS`. `BREVO_SENDER_EMAIL` e `BREVO_SENDER_NAME` também são aceitos.
Use uma única forma de cada variável; se houver aliases conflitantes na mesma
fonte, o nome canônico tem prioridade. Valores vazios e a senha `0` são preservados.
Aceita aspas, comentários após espaço e `export`; em aspas duplas, `\n` vira
quebra de linha. Não há interpolação de variáveis. Para valores com ` #`, use aspas.
O `.env` local é ignorado pelo Git e arquivos de ambiente são bloqueados pelo
`.htaccess` do Apache. `vai.env` é carregado somente quando `.env` não existe.

```bash
npm ci --ignore-scripts
npm run lint
npm test
phpunit
php tools/check-coverage.php
```

Os testes exigem PHP 8.3 com PHPUnit 10.5, Xdebug em modo coverage, PDO MySQL,
mbstring, fileinfo, curl, openssl e DOM, além de Node.js 22+. Use um MySQL/MariaDB
isolado: `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` e
`TEST_DB_PASS`. O nome precisa ser `sisc_test` ou `sisc_test_` seguido de letras
minúsculas/números. O padrão local é 127.0.0.1:33079, root, senha vazia.
**A suíte cria tabelas e limpa dados apenas desse banco de testes.** Não aponte
essas variáveis para a produção. O workflow fornece um MariaDB isolado.
Os relatórios ficam em `test-results/coverage.xml`, `coverage.txt`,
`coverage-html/index.html` e `junit.xml`; o gate falha abaixo de 80% de linhas PHP.

O SQL é de instalação e não apaga dados. Não converte registros SQLite nem migra
um banco da versão alternativa. Faça backup; caso condominio_connect já tenha
outra estrutura, use um banco separado e ajuste o CREATE DATABASE/USE do SQL e
DB_NAME na configuração. Não importe por cima de tabelas incompatíveis.

## Verificação
Os testes exercitam regras no banco e fluxos HTTP autenticados em um servidor
PHP temporário em loopback, verificando também CSRF, JavaScript inline e logs.
Não validam a configuração do Apache remoto nem fazem pagamentos/envios reais.
Complete a verificação funcional abaixo na instalação real:

1. Abra index.php, pages/login.php e pages/cadastro.php na instalação real.
2. Crie uma conta, saia, teste senha incorreta e depois a senha correta.
3. Abra um chamado, confirme-o no painel e consulte a tabela chamados no MySQL.
4. Teste uma imagem válida e um arquivo inválido (nenhum chamado deve ser salvo
   quando o upload for inválido).
5. Com outro usuário, confira que os chamados anteriores não aparecem.
6. Após sair, acessar dashboard.php deve redirecionar para o login.
7. Verifique que /backend/database/db_sisc.sql e /.env retornam 403 no Apache.
8. Teste os links/CSS na raiz e, se aplicável, em uma subpasta.

Para atualizar as regras de reservas e o schema comunitário em um banco
existente, após backup e conferência do `.env`, execute:

```bash
php backend/database/upgrade_community.php
```

O CSRF usa o campo `csrf` nos formulários e o header `X-CSRF-Token` em JSON.
O token é estável durante a sessão, incluindo a renovação do ID no login.
A sessão expira após duas horas de inatividade; cookies usam HttpOnly e
SameSite=Lax, com Secure quando houver HTTPS. Atrás de proxy que termina TLS,
configure HTTPS no servidor ou `APP_HTTPS=1`. Configure `APP_URL` com a origem
pública, sem caminho, para os links de e-mail; o caminho vem de APP_BASE_PATH.
As regras de bloqueio na raiz exigem mod_rewrite no Apache.

O Netlify recebe apenas `.netlify-public`, criado por `npm run build:static`.
Esse artefato preserva a landing page e os recursos públicos; a aplicação
completa e suas páginas PHP continuam exigindo hospedagem PHP/MySQL.

Resultados e arquivos alterados: [relatório da revisão](docs/auditoria-seguranca-testes.md).
