# Revisão de segurança, sessões e testes — 01/10/2026

Alterações locais sobre a estrutura existente, mantendo páginas, rotas de compatibilidade e layout. Não foi alterado nenhum banco de produção nem publicado um deploy.

**Causa de “Formulário expirado”.** O cookie PHP estava fixado como `Secure=true`, inclusive em HTTP: o navegador deixava de enviar a sessão que continha o token. Além disso, dashboard e assistente enviavam JSON sem CSRF a um endpoint que carregava a validação automática de POST, a qual só procurava `$_POST['csrf']`. A geração original já reutilizava o token; não havia evidência de regeneração por carregamento.

O sistema passa a usar `backend/includes/security.php`: token de 32 bytes criado com `random_bytes()`, comparação com `hash_equals()`, campo `csrf` nos formulários e `X-CSRF-Token` nas chamadas JSON. O token continua válido nas abas abertas e na renovação do ID após login. Requisições inválidas continuam recebendo 403, com JSON nas APIs. POST/PUT/PATCH/DELETE estão protegidos; nomes de tokens de recuperação de senha permanecem separados do CSRF.

**Sessões e autenticação.** Cookies usam HttpOnly, SameSite=Lax, caminho da aplicação e Secure conforme HTTPS. Headers de proxy enviados por clientes não são confiados; TLS terminado no proxy exige configuração no servidor ou `APP_HTTPS=1`. O modo estrito e cookies exclusivos permanecem ativos. O ID é renovado no login; a sessão expira após duas horas de inatividade. Logout exige POST com CSRF. Redefinição de senha consome o token em transação, revoga Bearer tokens e invalida sessões web estabelecidas com a senha anterior. O consumo de códigos 2FA é condicionado ao hash e à validade, impedindo reutilização concorrente. Falhas de envio do código agora são exibidas, sem levar o usuário a um formulário cujo código não foi entregue.

**Outras correções verificadas.**

- `backend/config/database.php` incluía `vai.env` como PHP, embora seja dotenv: isso poderia imprimir configurações e quebrar headers/redirects. Agora usa o carregador existente de `.env`, respeitando variáveis do servidor e aliases.
- A migração continha consultas de exemplo a `myDatabase`/`myTable`, variáveis indefinidas e nenhuma conexão válida em `$pdo`. Agora usa a configuração real por `getPDO()`.
- A reserva cancelada continuava ocupando uma chave única por área/usuário/início. O índice passou a ser comum; o bloqueio transacional da área continua prevenindo sobreposição. SQL principal, sua cópia e migração foram atualizados.
- Uploads de perfil/certificados passam a validar MIME real, tamanho, upload PHP e resultado da gravação. Arquivos ficam no armazenamento privado, servidos somente pelas rotas autorizadas.
- Operações OAuth/pagamento exigem sessão autorizada e CSRF. O fallback que ocultava warnings e assumia HTTP 200 foi substituído pela exigência explícita de cURL; redirects de transporte estão desabilitados.
- JSON e campos de formulário malformados são rejeitados antes de conversões que gerariam warnings.
- Removidas supressões `@` do processamento de JSON e pagamentos; falhas inesperadas são registradas pelo tratamento existente. Verificação administrativa captura também DomainException e respeita retorno falso.
- A função legada de criação de administradores com senhas fixas foi desativada explicitamente. Não havia chamadas no fluxo ativo. Contas fictícias já presentes no SQL só podem autenticar quando `APP_DEMO_LOGIN=1` estiver habilitado.
- Links de recuperação usam origem configurada ou nome do servidor, evitando confiar em `HTTP_HOST` arbitrário. Configure `APP_URL` sem caminho e `APP_BASE_PATH` para a subpasta.
- A API Netlify usava o ID público como token. Agora emite segredo aleatório independente, armazena seu hash e verifica expiração; cadastro rejeita privilégios administrativos. Renderização JS legada escapa HTML e trata armazenamento local inválido.
- O artefato estático Netlify contém somente landing page e recursos públicos. PHP, SQL, arquivos dotenv, compactados e testes deixam de ser publicados junto com a raiz.
- O Apache bloqueia diretórios privados e arquivos sensíveis; essas regras exigem mod_rewrite. Links antigos `.html` da landing page foram alinhados às páginas PHP existentes.

**Sintaxe e validação final.** Nenhum erro de sintaxe PHP foi identificado na versão original, nem ficou na versão final. Os erros originais da migração eram de execução, variáveis e consultas. A varredura final passou em 116 arquivos PHP, 6 JavaScript, 5 MJS, 14 CSS, 1 HTML, 2 JSON, 1 YAML e 1 TOML. Os scripts inline das páginas testadas também passaram por `node --check`; todos os formulários POST renderizados nesses fluxos têm CSRF. Logs dos servidores HTTP temporários foram verificados sem warnings, notices, erros fatais ou falhas SQL.

**Testes e cobertura.**

| Medida | Antes, HEAD original | Depois |
|---|---:|---:|
| Testes PHP | 36 | 66 |
| Asserções PHP | 45 | 580 |
| Testes JavaScript | 0 | 5 |
| Testes aprovados, total | 36 | 71 |
| Falhas/erros/ignorados na suíte final | — | 0 |
| Cobertura de linhas PHP | 1,82% (28/1538) | **84,68% (1299/1534)** |

Os 36 casos originais foram preservados; nenhum teste válido foi removido. A medição inicial foi executada em uma cópia do HEAD original. O escopo de cobertura continua sendo todo `api/` e `backend/`, incluindo migrações, configuração e integrações parcialmente descobertas. Frontend e JavaScript não compõem esse percentual. A coleta HTTP usa os dados reais de Xdebug do processo servidor, agregados à cobertura PHPUnit; não se atribuem linhas executadas apenas porque uma URL foi chamada. O CI exige pelo menos 80%.

| Arquivo/módulo | Cobertura final de linhas |
|---|---:|
| `backend/includes/security.php` — CSRF/sessões | 100% |
| `backend/includes/auth.php` | 85,71% |
| `backend/includes/functions.php` | 80,51% |
| `backend/includes/tickets.php` | 100% |
| `backend/includes/chat.php` | 100% |
| `backend/includes/community.php` | 97,85% |
| `backend/includes/uploads.php` | 93,33% |
| `backend/config/env.php` | 97,78% |
| `backend/config/database.php` | 100% |
| Migração de regras / comunidade / seed | 91,55% / 95,24% / 100% |
| API login / me / notificações / assistente | 100% cada |
| API cadastro / chamados / chat | 97,50% / 97,56% / 93,33% |
| API avatar / anexo | 100% / 50% |
| API Mercado Pago | 40,52% |
| Serviço OpenAI | 33,78% |

Foram exercitados autenticação, autorização por perfil e condomínio, bloqueios de conta, sessões/CSRF e múltiplos formulários, cadastro, validação de e-mail, recuperação e redefinição de senha, 2FA, perfil/uploads, chamados/propostas/orçamentos/visitas/conclusão/cancelamento, mensagens e notificações, avaliações, comunicados/histórico/leitura, reservas/conflitos/cancelamento, condomínio, administração, transações, SQL/migrações/seed e tokens Bearer. Há testes de regras com MariaDB real e testes HTTP com cookies e persistência real. Os testes da API Netlify isolam o armazenamento externo por uma implementação em memória da interface de store.

Relatórios locais: `test-results/before.xml`, `test-results/coverage.xml`, `test-results/coverage.txt`, `test-results/coverage-html/index.html` e `test-results/junit.xml`. O workflow publica os relatórios como artefato. PHP 8.3.35, PHPUnit 10.5.65, Xdebug 3.5.3 e MariaDB 11.4.9 foram usados localmente. A configuração preserva arquivos não executados no denominador, conforme a [documentação do PHPUnit](https://docs.phpunit.de/en/10.5/code-coverage.html).

**Arquivos alterados/adicionados.**

| Grupo | Arquivos |
|---|---|
| Segurança/backend | `backend/includes/security.php`, `backend/includes/uploads.php`, `backend/includes/auth.php`, `backend/includes/functions.php`, `backend/config/database.php` |
| APIs | `api/_api.php`, `api/openai.php`, `api/mercadopago.php` |
| Banco | `db_sisc.sql`, `backend/database/db_sisc.sql`, `backend/database/community_schema.sql`, `backend/database/upgrade_business_rules.php`, `backend/database/upgrade_community.php` |
| Páginas/componentes | `frontend/logout.php`, `frontend/includes/header.php`, `frontend/includes/navbar.php`, `frontend/pages/login.php`, `cadastro.php`, `perfil.php`, `dashboard.php`, `assistente.php`, `recuperar-senha.php`, `redefinir-senha.php`, `admin-verificacoes.php`, `atualizar-chamado.php` (todos os nomes curtos nesta linha ficam em `frontend/pages/`) |
| Recursos/Netlify | `frontend/assets/css/condoconnect.css`, `assets/js/app.js`, `frontend/assets/js/app.js`, `index.html`, `netlify/functions/api.mjs`, `netlify.toml` |
| Testes PHP | `tests/bootstrap.php`, `DatabaseTestCase.php`, `SecurityTest.php`, `BusinessRulesTest.php`, `EnvironmentTest.php`, `MigrationTest.php`, `HttpTestCase.php`, `HttpFlowsTest.php`, `http-coverage.php` (todos em `tests/`) |
| Testes JS/ferramentas | `tests/netlify.test.mjs`, `tests/static-build.test.mjs`, `tools/check-syntax.mjs`, `tools/check-coverage.php`, `tools/build-static.mjs` |
| Configuração/documentação | `.env.example`, `.htaccess`, `.gitignore`, `.github/workflows/build.yml`, `phpunit.xml`, `package.json`, `package-lock.json`, `README.md`, este relatório |

**Aplicação em banco existente.** Depois de backup e de conferir o `.env`, execute `php backend/database/upgrade_community.php`. Isso inclui as correções da migração de regras e do índice de reservas. As duas cópias do SQL principal são idênticas. A suíte só criou e limpou o banco reservado `sisc_test` em loopback, porta 33079.

**Limites e atenção operacional.** Pagamentos reais, respostas remotas OpenAI, SMTP/API Brevo e armazenamento Netlify real não foram acionados: exigem contas/chaves e podem gerar efeitos externos. Validaram-se proteção, contratos locais e falhas de configuração; o envio real de e-mail e a entrega do código 2FA precisam de verificação no ambiente configurado. As linhas dessas integrações continuam no relatório, sem exclusão para elevar a cobertura. A configuração Apache/TLS/proxy da instalação real e o workflow remoto não foram executados neste Windows. A aplicação completa exige PHP/MySQL; o artefato estático Netlify não executa páginas PHP. Arquivos RAR foram preservados como referências, bloqueados na publicação, sem alteração de conteúdo. Antes de produção, manter credenciais individuais e controle de tentativas de autenticação no servidor/proxy; a revisão não implementa limitação distribuída de força bruta. Tokens legados da API Netlify baseados em ID deixam de funcionar e exigem novo login.
**Correção complementar do login — 01/10/2026.** Na URL HTTP informada, duas consultas com o mesmo cookie mantiveram o token; um POST com esse token e credenciais vazias retornou a validação de campos, sem rejeição CSRF. O erro específico do navegador não foi reproduzido com sessão nova. Foram corrigidas duas fontes de inconsistência: uso do cookie genérico `PHPSESSID`, sujeito a interferência de outras aplicações no mesmo host, e restauração de formulários antigos pelo histórico do navegador. O cookie agora é `SISCSESSID`; sessões genéricas iniciadas automaticamente não são importadas, e páginas restauradas do cache do histórico são recarregadas. O cache HTTP da sessão é explicitamente desativado. Tokens continuam estáveis, com validação obrigatória e sem reaproveitar tokens de sessões encerradas.

Rejeições continuam respondendo HTTP 403. Formulários HTML oferecem um link GET seguro para obter novo formulário, sem reenviar senha ou dados. APIs preservam `csrf_invalid`. O log registra somente a categoria da rejeição (cookie ausente, token de sessão ausente, token enviado ausente ou divergência), sem valores de tokens, cookies ou senhas. Arquivos desta correção: `backend/includes/security.php`, `frontend/assets/js/main.js`, `frontend/includes/footer.php`, `tests/HttpTestCase.php`, `tests/HttpFlowsTest.php`, `tests/SecurityTest.php` e este relatório. A versão do recurso JavaScript foi atualizada para invalidar o cache.

Foram acrescentados três testes reais: convivência com cookie legado de outra aplicação, isolamento de sessão genérica iniciada automaticamente e rejeição do formulário anterior ao logout seguida de novo login válido. Resultado: **69 testes PHP, 610 verificações, 5 testes JavaScript; nenhuma falha. Cobertura PHP: 84,68% antes desta correção → 84,78% (1315/1551 linhas)**; segurança/sessões: 98,65%, autenticação: 85,71%, chamados e chat: 100%, comunidade: 97,85%. A análise dos 116 arquivos PHP não encontrou erros de sintaxe. Logs dos fluxos HTTP foram verificados automaticamente contra warnings, notices, erros fatais e erros SQL. Alterações locais ainda precisam ser publicadas no servidor; a troca do nome do cookie exige novo login.
