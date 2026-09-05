# Chambre Rose API em PHP

Backend PHP 8.2+ do marketplace Chambre Rose. Ele suporta MySQL na EasyHost e PostgreSQL no desenvolvimento local.

## O que esta implementado

- login, cadastro de visitante, acompanhante ou loja e edicao do proprio perfil;
- aprovacao administrativa de contas profissionais, com prazo informado de 24 horas;
- ate 15 fotos e 3 videos reais por perfil, armazenados no banco;
- autenticacao JWT HS256 em cookie HttpOnly, SameSite estrito e Secure em producao, com senhas BCrypt;
- papeis `VISITOR`, `ESCORT`, `STORE` e `ADMIN` (`USER` legado e migrado para `VISITOR`);
- ativacao e desativacao de VIP no painel administrativo;
- listagem e filtros de usuarios;
- listagens publicas profissionais com filtros e paginacao;
- favoritos, conversas, mensagens, bloqueio, arquivamento e denuncia;
- recuperacao de senha por token de uso unico e limites persistentes de tentativas de login e recuperacao;
- CORS, headers de seguranca, respostas padronizadas de erro;
- migrations incrementais e contas de acesso opcionais, sem perfis ou midias ficticios;
- Apache/EasyHost, servidor embutido do PHP e Docker.

## Requisitos

- PHP 8.2 ou superior (8.3 recomendado);
- extensoes `pdo`, `pdo_mysql`, `fileinfo` e `json` na EasyHost;
- banco MySQL criado no painel da hospedagem;
- Apache com `mod_rewrite` para producao;
- `mbstring` e Composer sao recomendados, mas nao obrigatorios.

## Configuracao

O backend procura primeiro o `.env` da raiz do projeto e depois um `.env` nesta pasta. Ele entende tanto as variaveis antigas do Spring quanto as novas `DB_*`.

```powershell
Copy-Item ..\.env.example ..\.env
```

Variaveis essenciais na EasyHost:

```text
DB_DRIVER=mysql
DB_HOST=HOST_MYSQL_DA_EASYHOST
DB_PORT=3306
DB_NAME=NOME_DO_BANCO
DB_USERNAME=USUARIO_DO_BANCO
DB_PASSWORD=SENHA_DO_BANCO
DB_CHARSET=utf8mb4
JWT_SECRET=SEGREDO_ALEATORIO_COM_PELO_MENOS_32_BYTES
JWT_EXPIRATION_MINUTES=180
APP_ENV=production
AUTH_COOKIE_SECURE=true
RATE_LIMIT_SECRET=OUTRO_SEGREDO_ALEATORIO_COM_PELO_MENOS_32_BYTES
APP_TRUSTED_PROXIES=
APP_CORS_ALLOWED_ORIGINS=http://localhost:4200,https://seu-dominio.com
APP_AUTO_MIGRATE=true
SEED_MVP_CONTENT=false
```

Para instalar uma vez o catalogo inicial com cinco acompanhantes, uma loja e os
produtos de demonstracao, defina `SEED_MVP_CONTENT=true` durante o setup. O seed
e versionado e idempotente: requisicoes posteriores nao duplicam o conteudo.

Em producao, deixe `APP_AUTO_MIGRATE=true` somente ate o primeiro `/api/health`
concluir depois de um deploy com migration nova. Em seguida, volte para `false`.
Isso evita consultas de controle de schema em todas as requisicoes. O comando
`php bin/setup.php` continua sendo a opcao preferida quando houver acesso ao terminal.

Para PostgreSQL local, use `DB_DRIVER=pgsql` com `DB_SSLMODE`, ou mantenha as variaveis `SPRING_DATASOURCE_*` antigas.

O navegador recebe a sessao apenas no cookie `chambre_rose_session`; o JWT nao faz
parte do JSON e nao deve ser salvo em `localStorage`. O backend aceita temporariamente
o cabecalho Bearer para integracoes existentes. Login e recuperacao devolvem HTTP 429
e `Retry-After` quando os limites configurados em `.env.example` forem excedidos.
Se a API estiver atras de proxy reverso, `APP_TRUSTED_PROXIES` deve listar somente
enderecos ou redes CIDR controladas; cabecalhos encaminhados de outros clientes sao ignorados.

## Rodar localmente

Com Docker, a partir da raiz:

```powershell
docker compose up --build
```

Sem Docker:

```powershell
cd backend-php
php bin\setup.php
php -S localhost:8080 -t public public\router.php
```

Em outro terminal:

```powershell
cd ..
npm install
npm start
```

Abra `http://localhost:4200`. O proxy do Angular envia `/api` para `http://localhost:8080`.

## Testes

```powershell
composer test
```

Ou, sem Composer:

```powershell
php tests\run.php
```

Health check:

```powershell
Invoke-RestMethod http://localhost:8080/api/health
```

## Endpoints

### Contas, perfis profissionais e mensagens

- `POST /api/auth/register`: cria `VISITOR`, `ESCORT` ou `STORE`. Contas profissionais ficam `PENDING` para revisao em ate 24 horas.
- `POST /api/auth/login` e `POST /api/auth/logout`: criam e encerram a sessao em cookie HttpOnly.
- `POST /api/auth/forgot-password` e `POST /api/auth/reset-password`: recuperacao por token de uso unico, valido por uma hora.
- `GET|PUT /api/profiles/me`: consulta e edita o proprio perfil profissional.
- `GET|PUT /api/profiles/{userId}`: consulta ou edita qualquer perfil como administrador.
- `GET /api/listings` e `GET /api/listings/{userId}`: busca publica paginada de acompanhantes e lojas aprovadas.
- `POST /api/profiles/me/media`: upload real multipart no campo `media`; limite de 15 fotos e 3 videos por perfil.
- `GET|POST /api/conversations` e `GET|POST /api/conversations/{id}/messages`: mensagens internas autenticadas.
- `PATCH /api/conversations/{id}/read|archive` e `DELETE /api/conversations/{id}`: leitura, arquivamento e remocao do proprio inbox.
- `POST|DELETE /api/users/{userId}/block` e `POST /api/users/{userId}/reports`: bloqueio e denuncia.
- `GET /api/favorites` e `POST|DELETE /api/favorites/{profileId}`: favoritos por usuario autenticado.
- `PATCH /api/admin/users/{id}/approval`: aprovacao ou rejeicao por administrador.

Fotos aceitam JPG, PNG e WebP ate 8 MB. Videos aceitam MP4 e WebM ate 25 MB. Para que o PHP nao descarte o arquivo antes da aplicacao valida-lo, configure no painel EasyHost (ou em `php.ini`/`.user.ini) `upload_max_filesize=26M` e `post_max_size=30M`, no minimo. A API limita o corpo completo a 30 MB e devolve HTTP 413 quando esse limite e excedido.

Por privacidade, nomes originais nunca aparecem no catalogo nem no cabecalho de download e toda midia usa `Cache-Control: private, no-store`. O frontend oficial redimensiona e reencoda fotos em canvas antes do envio, removendo metadados EXIF/GPS. Integracoes que enviarem arquivos diretamente para a API tambem devem reencodar as imagens antes do upload; o backend PHP sem GD/Imagick valida tipo e tamanho, mas armazena os bytes recebidos.

Os e-mails usam `MAIL_TRANSPORT=log`, `mail` ou `smtp`. No modo `log`, nenhum envio e fingido: a API devolve resposta neutra e registra somente metadados mascarados, mantendo o conteudo no `email_outbox` para diagnostico. Em producao, configure SMTP pelas variaveis documentadas em `.env.example`.

```text
POST   /api/auth/login
POST   /api/auth/logout
POST   /api/auth/register
GET    /api/auth/me
PUT    /api/auth/me

GET    /api/listings
GET    /api/listings/{id}
GET    /api/profiles/me
PUT    /api/profiles/me
GET    /api/profiles/{id}
PUT    /api/profiles/{id}
POST   /api/profiles/me/media
DELETE /api/profiles/me/media/{id}
GET    /api/favorites
POST   /api/favorites/{profileId}
DELETE /api/favorites/{profileId}
GET    /api/conversations
POST   /api/conversations
DELETE /api/conversations/{id}

GET    /api/admin/users
PATCH  /api/admin/users/{id}/vip

GET    /api/brand/logo
GET    /api/health
```

## Estrutura segura na EasyHost

Para manter `src`, `.env` e recursos fora da area publica, use esta estrutura no diretorio da conta:

```text
home/SEU_USUARIO/
  chambre-rose-api/       conteudo desta pasta, exceto public
  public_html/
    index.html             build do Angular
    assets/
    api/
      index.php            copiado de backend-php/public
      .htaccess            copiado de backend-php/public
      .user.ini            copiado de backend-php/public
```

O `index.php` ja procura automaticamente `~/chambre-rose-api/bootstrap.php`. No `public_html`, envie o conteudo de `dist/chambre-rose/browser`, e nao a pasta `browser` em si.

No painel EasyHost:

1. selecione PHP 8.3;
2. crie um banco e um usuario MySQL e anote host, porta, nome, usuario e senha;
3. habilite `pdo`, `pdo_mysql`, `fileinfo` e, se disponivel, `mbstring`;
4. configure `upload_max_filesize=26M`, `post_max_size=30M`, `memory_limit=192M` e `display_errors=Off`;
5. confirme que arquivos `.htaccess` estao visiveis no gerenciador de arquivos;
6. crie `~/chambre-rose-api/.env` com as variaveis de producao;
7. deixe `APP_ENV=production`, `AUTH_COOKIE_SECURE=true`, `APP_DEBUG=false` e CORS somente com os dominios HTTPS reais;
8. no primeiro acesso, use `SEED_DEMO_USERS=true`, `SEED_ADMIN_EMAIL=admin@admin.com` e uma senha temporaria; depois volte `SEED_DEMO_USERS=false` e apague a senha do `.env`;
9. acesse `https://seu-dominio.com/api/health` e, depois do primeiro sucesso, defina `APP_AUTO_MIGRATE=false`.

## Permissoes sugeridas

```text
diretorios: 755
arquivos PHP/SQL/imagens: 644
.env: 600 ou a opcao mais restrita aceita pelo provedor
```

Nunca coloque `.env`, senha do banco ou `JWT_SECRET` dentro de `public_html`, no Git ou em prints.

## Adicionando migrations

Migrations novas ficam em `database/migrations`, com um arquivo para MySQL e outro
para PostgreSQL. Registre a nova versao em `DatabaseMigrator::migrations()` sem editar
uma migration que ja tenha sido aplicada. O migrator usa lock no banco para impedir
que dois processos apliquem a mesma versao ao mesmo tempo.
