# CondoConnect para Android

Aplicativo com telas nativas em React Native/Expo, baseado no sistema PHP/MySQL do RAR.
A aplicação web original é preservada. O arquivo novo `api/mobile.php` conecta o app
às regras e ao banco já utilizados pelo site.

## Começar pelo celular

Abra o APK de teste entregue e instale o CondoConnect. O app sempre usa dados reais do
servidor: é preciso uma conta cadastrada no sistema.

Toque em **Entrar na minha conta**, informe o endereço HTTPS
do site e entre com sua conta existente. A raiz deve incluir a subpasta do sistema,
quando houver: `https://seu-dominio.com.br/sisc-test`.
Não inclua `/api` ou o nome de um arquivo no endereço.

## Recursos implementados

- Acesso à conta, saída e recuperação de senha; código por e-mail para contas com 2FA.
- Cadastro de moradores e prestadores, com confirmação de e-mail.
- Painel com indicadores, chamados recentes e atalhos.
- Lista, busca, filtros, detalhes e criação de chamados.
- Cancelamento pelo solicitante; aceite e conclusão pelo prestador responsável.
- Lista de contatos, abertura de conversas e envio/consulta de mensagens.
- Busca de prestadores por nome ou especialidade.
- Comunicados e confirmação de leitura.
- Áreas comuns, reservas, verificação de conflitos pelo servidor e cancelamento.
- Notificações dentro do app, perfil e atualização de nome/telefone.

### Níveis de acesso (versão 2.0)

O login é o mesmo para todos: a conta define o nível, e o app abre as telas correspondentes.

| Nível | O que o app libera |
| --- | --- |
| Morador | Abrir e cancelar chamados, reservas, comunicados com confirmação de leitura, prestadores e conversas. |
| Prestador | Chamados disponíveis, aceitar e concluir atendimentos, conversas. Sem reservas. |
| Síndico | Chamados do condomínio (cancelar e entrar na conversa), publicar/editar/excluir comunicados, agenda e cancelamento de reservas, editar apresentação, regras e contato do condomínio. Liberado após a aprovação do certificado. |
| Administrador | Painel com indicadores, usuários por nível, aprovação/rejeição de síndicos, escolha do condomínio em gestão, comunicados e reservas de qualquer condomínio, chamados em modo de consulta. |

O servidor informa o nível em `access` na operação `bootstrap` e confere a permissão em cada
operação com as mesmas funções do site (`canManageCommunity`, `managesCondominio`,
`approveSyndicVerification`). Esconder uma tela não é a proteção: uma chamada direta de
um nível sem permissão é recusada pelo PHP.

As permissões são conferidas pelo PHP usando as funções do site. O token fica no
armazenamento protegido do Android (SecureStore); a senha não é salva no aparelho.
O app funciona com conexão à internet quando usa dados reais. As mensagens são
consultadas a cada 5 segundos enquanto a conversa está aberta e o app está ativo.
As datas de reserva seguem os horários da instalação original; mantenha o fuso do
servidor PHP alinhado ao condomínio para que a agenda do site e do app coincidam.

Não foram portados anexos/fotos de chamados, propostas e orçamentos, avaliações,
pagamentos, assistente de IA, cadastro de áreas comuns e bloqueio de contas. O cadastro
de síndicos continua no site por exigir certificado, e o arquivo do certificado é
consultado no site. As notificações aparecem dentro do app, sem push em segundo plano.

### Contas para cada nível

Morador e prestador se cadastram pelo app ou pelo site. Síndico se cadastra no site e é
aprovado por um administrador. Administrador não tem cadastro público; crie no servidor:

```sh
php tools/criar-conta.php --nivel=admin --nome="Nome Completo" --email=nome@dominio.com \
    --telefone=11999990000 --condominio="Nome exato do condomínio"
```

O mesmo comando aceita `--nivel=morador`, `prestador` ou `sindico` e cria a conta já com
e-mail confirmado. A senha é pedida no terminal. Use senhas fortes e individuais.

## APK de teste com HTTP (configuração atual)

Enquanto o servidor não tiver HTTPS, este projeto gera um **APK de teste**: o endereço
`http://2.24.65.149:8080/sisc-test` já vem preenchido e o HTTP é liberado somente para esse
servidor. Em HTTP a senha trafega sem criptografia; não distribua esse APK para moradores.

Quando o servidor tiver HTTPS, siga a seção "Voltar para HTTPS" do `README.md` da raiz:
trocar o endereço em `mobile/.env`, apagar a linha `EXPO_PUBLIC_ALLOW_HTTP`, apagar a pasta
`mobile/android/app/src/release`, esvaziar `insecureHttpHosts` em `src/domain.ts` e trocar o
bloco `env` do passo "Compilar o APK" em `.github/workflows/android-apk.yml`.

Na tela de login, o botão **Testar conexão** chama `?action=ping` e `?action=health` e mostra
em que etapa a conexão para: celular sem acesso ao servidor, servidor sem a API, banco
indisponível ou tabelas faltando.

## Conectar ao servidor existente

1. No servidor PHP do site, substitua `api/mobile.php` pelo desta entrega (pasta `api/`
   existente). Faça isso sobre a mesma versão do sistema enviada no ZIP. Sem essa
   atualização o app continua entrando, mas as telas de síndico e administrador ficam ocultas.
2. Preserve seu `.env`/`vai.env`, banco, arquivos recebidos e demais configurações.
   Esta entrega não inclui seu arquivo de ambiente real.
3. O banco deve estar com as migrações da versão original já aplicadas. A API reutiliza
   a tabela `sessions` existente e não cria tabelas ou executa migrações automaticamente.
4. Configure HTTPS e mantenha o cabeçalho `Authorization` disponível no PHP. Caso
   seu Apache/FastCGI não o encaminhe, ajuste a configuração do servidor.
5. Configure `APP_URL` com a origem HTTPS correta. As confirmações e a recuperação
   de senha usam a configuração Brevo já existente no site.
6. Confira `https://seu-dominio.com.br/sisc-test/api/mobile.php?action=ping`.
   O retorno esperado é `{"app":"CondoConnect","mobile_api":1}`.
7. Informe `https://seu-dominio.com.br/sisc-test` no app e entre na sua conta.

O acesso por token expira após 7 dias, conforme a API original. A saída tenta revogar
o token no servidor e sempre apaga a sessão deste aparelho. Em uma saída sem conexão,
o token anterior no servidor permanece sujeito ao prazo normal de expiração.

## Desenvolver e testar

Requisitos: Node.js compatível com o SDK Expo usado (Node 22+), npm e Android Studio
com JDK 21, Android SDK Platform 37.0, Build Tools 37.0.0 e NDK 27.1.12297006.

```sh
cd mobile
npm ci
npm run typecheck
npm test
npm run export:android
```

Os testes PHP de contrato são executados quando `php` está no PATH, ou quando
`PHP_BINARY` aponta para o executável. Sem PHP, esses testes são marcados como
ignorados; os testes de domínio e cliente HTTP continuam disponíveis.

Para executar no emulador ou em um Android conectado com depuração USB:

```sh
npm run android
```

O endereço do servidor pode ser definido na tela inicial ou, durante desenvolvimento,
em `.env` usando o modelo `.env.example`. Uma alteração em `EXPO_PUBLIC_API_BASE_URL`
exige nova exportação/compilação para afetar um APK já gerado.

## Gerar novo APK

Com o SDK/NDK e JDK configurados, a pasta `android/` está incluída e pode ser aberta
no Android Studio. Para um APK independente do Metro em aparelhos de 64 bits:

```sh
cd android
./gradlew assembleRelease -PreactNativeArchitectures=arm64-v8a
```

Resultado: `android/app/build/outputs/apk/release/app-release.apk`.
O projeto usa a chave padrão de desenvolvimento para o APK de teste. Para publicar
na Play Store, configure uma chave de produção própria e gere o AAB.

Também há um perfil EAS que gera APK. Ele requer sua conta Expo e a configuração
do projeto nessa conta:

```sh
npx eas-cli login
npx eas-cli build --platform android --profile preview
```

Não há conta Expo, chave de produção ou credencial do servidor embutida neste projeto.

## Verificação e limites

Os testes cobrem validação de chamados e reservas, cliente HTTP, erros de conexão e os contratos públicos/sem autenticação da API.
A integração com banco real, entrega de e-mails e contas reais depende da instalação
do backend.


### Resultado desta entrega (versão 2.0)

- Testes do app e contrato da API (`npm test`): 16 aprovados, nenhum ignorado.
- `api/mobile.php` real exercitado por HTTP com login de morador, prestador, síndico,
  síndico pendente e administrador, sobre uma camada de dados de teste em memória: 8 testes
  de permissão aprovados (cada nível só executa o que lhe cabe).
- TypeScript: conferido com declarações substitutas de React Native/Expo, porque as
  dependências não puderam ser instaladas neste ambiente. Rode `npm ci && npm run typecheck`.
- **Não compilado nesta entrega:** o APK precisa ser gerado de novo (seção acima).
- **Sem banco MySQL nesta entrega:** as consultas reais das novas operações reutilizam
  funções já existentes do site, mas não foram executadas contra um banco real, nem em
  aparelho físico/emulador. Teste no seu servidor antes de liberar para os moradores.
