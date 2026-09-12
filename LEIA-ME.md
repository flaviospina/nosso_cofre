# Nosso Cofre v2 — LEIA-ME

Aplicativo web de controle financeiro individual ou familiar, focado em **economizar**. PHP 8.2 puro (MVC próprio), MySQL/MariaDB, HTML + CSS + JS vanilla (Bootstrap 5 via CDN), PWA instalável. Feito para HostGator compartilhado + cPanel, sem terminal.

Este documento cresce a cada fase. Estado atual: **Fase 1 — Fundação** (núcleo MVC, banco, PWA mínima, telas de sistema).

## 1. Requisitos do servidor

| Item | Exigido | Onde conferir |
|---|---|---|
| PHP | 8.2 ou superior | cPanel → *Select PHP Version* |
| Extensões PHP | `pdo_mysql`, `openssl`, `mbstring`, `json`, `fileinfo`, `gd`, `zip`, `curl`; `gmp` **ou** `bcmath` (Web Push, fase 7) | cPanel → *Select PHP Version* → *Extensions* |
| Banco | MySQL 8.0 ou MariaDB 10.6+ | phpMyAdmin → *Informações do servidor* |
| Apache | `mod_rewrite` e `mod_headers` (padrão no HostGator) | — |
| HTTPS | Obrigatório (cookies `Secure`, service worker e Web Push só funcionam em HTTPS) | cPanel → *SSL/TLS Status* (AutoSSL) |
| Cron | 1 tarefa a cada 5 minutos (a partir da fase 7) | cPanel → *Cron Jobs* |

A tela `/saude` verifica tudo isso automaticamente.

## 2. Estrutura de pastas

```
cofre/                      ← raiz do projeto (= public_html/cofre no cPanel)
├── .htaccess               ← reescreve tudo para public/ e bloqueia .env, .sql, .md, .log
├── .env                    ← configuração (criado a partir de .env.example)
├── public/                 ← única pasta servida de fato
│   ├── index.php           ← front controller
│   ├── .htaccess           ← rotas amigáveis, tipos MIME da PWA, cabeçalhos de segurança
│   ├── manifest.webmanifest, sw.js
│   └── assets/ (css, js, img, sounds)
├── app/                    ← Core/, Controllers/, Models/, Services/, Views/, config.php, routes.php
├── cron/run.php            ← runner do cron (linha de comando ou URL protegida por token)
├── sql/schema.sql          ← esquema completo, idempotente
├── sql/seed.sql            ← categorias pt-BR + lar de teste (ver §5)
├── vendor/                 ← bibliotecas vendorizadas (Web Push, fase 7)
├── storage/                ← logs, cache, uploads, exports, backups, sessions (nunca servida)
├── legal/                  ← Política de Privacidade e Termos versionados (fase 3)
├── tests/run.php           ← testes (php tests/run.php)
└── tools/                  ← utilitários de desenvolvimento (não precisam ir para o servidor)
```

Todas as pastas internas (`app`, `storage`, `vendor`, `cron`, `sql`, `legal`, `tests`, `tools`) têm um `.htaccess` com `Require all denied`, então o projeto inteiro pode ficar dentro de `public_html/cofre/` com segurança.

## 3. Publicação no cPanel (passo a passo)

### 3.1 Banco de dados
1. cPanel → **MySQL® Databases** → *Criar novo banco*: por exemplo `usuario_cofre`.
2. Na mesma tela, *Adicionar novo usuário* (ex.: `usuario_cofre`) com senha forte e **adicione o usuário ao banco com TODOS OS PRIVILÉGIOS**.
3. cPanel → **phpMyAdmin** → selecione o banco → aba **Importar** → escolha `sql/schema.sql` → *Executar*.
   Pode ser importado de novo em atualizações futuras: ele não apaga nem duplica nada.
4. Importe `sql/seed.sql` **somente** se quiser o modelo de categorias e o lar de teste (ver §5). Em produção, importe o seed e depois exclua o lar de teste pela tela, ou importe só a seção 1 (categorias).

### 3.2 Arquivos
1. Compacte a pasta do projeto em `.zip` (sem o `.git`).
2. cPanel → **Gerenciador de Arquivos** → entre em `public_html` → *Upload* do zip → botão direito → *Extract*.
3. Garanta que o resultado seja `public_html/cofre/` contendo `.htaccess`, `public/`, `app/` etc. (se extraiu com uma pasta a mais, mova o conteúdo).
4. Marque *Mostrar arquivos ocultos (dotfiles)* nas configurações do Gerenciador para enxergar `.htaccess` e `.env`.
5. Permissões: pastas `755`, arquivos `644`; `storage/` e suas subpastas precisam ser graváveis pelo PHP (no HostGator `755` já basta, pois o PHP roda com o seu usuário).

### 3.3 Configuração (.env)
1. Copie `.env.example` para `.env` (botão direito → *Copy*) e edite.
2. Preencha `APP_URL=https://itthrive.com.br/cofre`, os dados do banco (`DB_*`), o e-mail SMTP (`MAIL_*` — conta `cofre@itthrive.com.br`, servidor `mail.itthrive.com.br`, porta 465, `ssl`) e os dados do controlador/encarregado (`CONTROLLER_*`, `DPO_*`).
3. Abra `https://itthrive.com.br/cofre/instalar`: a tela gera `APP_KEY`, `BACKUP_KEY` e `CRON_TOKEN`. Cole as três linhas no `.env`. **Guarde o `BACKUP_KEY` fora do servidor.** Depois que o `APP_KEY` estiver preenchido, `/instalar` deixa de existir.
4. Mantenha `APP_ENV=production` e `APP_DEBUG=false`.

### 3.4 Verificação
- Abra `https://itthrive.com.br/cofre/saude`. Todos os itens devem ficar verdes (o item SMTP pode ficar pendente até a fase 2, e gmp/bcmath até a fase 7).
- Abra `https://itthrive.com.br/cofre/` — a página inicial deve aparecer com o ícone e o tema claro/escuro funcionando.
- No celular (Chrome/Android ou Safari/iOS), *Adicionar à tela inicial* instala a PWA.

### 3.5 Cron (a partir da fase 7, mas pode ser cadastrado já)
cPanel → **Cron Jobs** → *Adicionar novo*, a cada 5 minutos (`*/5 * * * *`), com um dos comandos:

```
php -q /home/SEU_USUARIO/public_html/cofre/cron/run.php
```
ou, por URL (se preferir):
```
curl -s "https://itthrive.com.br/cofre/cron/run?token=SEU_CRON_TOKEN" > /dev/null
```
Cada execução grava uma linha em `cron_runs`; a tela `/saude` mostrará a última execução (fase 7).

## 4. Decisões técnicas que valem registrar

- **Subpasta `/cofre`**: o `.htaccess` da raiz reescreve tudo para `public/` sem `RewriteBase`, e o `Request` remove a subpasta do caminho a partir de `APP_URL`. Mover para um domínio próprio exige só trocar `APP_URL`.
- **Multi-tenant por lar**: o `Model` base injeta `household_id = <lar da sessão>` em toda consulta e lança exceção se não houver lar. Um id de outro lar simplesmente "não existe" (404).
- **Datas**: `DATETIME` sempre em UTC no banco (a conexão PDO fixa `time_zone = '+00:00'`); a exibição converte para o fuso do usuário (`users.timezone`). `DATE` de lançamentos é data de calendário.
- **Senhas**: Argon2id quando o PHP do servidor suporta; caso contrário bcrypt custo 12. O login faz *rehash* automático quando o algoritmo disponível melhora. O seed usa bcrypt por ser verificável em qualquer PHP.
- **Criptografia em repouso** (AES-256-GCM com `APP_KEY`): segredo TOTP, CPF opcional e observações dos lançamentos. Descrição e valor ficam em claro porque busca, filtros e relatórios dependem deles.
- **CSP com nonce** para scripts e estilos: nenhum `<script>` ou `style=""` inline solto nas views. Cores dinâmicas usam `data-bg`, `data-color`, `data-width`, aplicadas pelo `app.js`.
- **Sessões**: cookie `HttpOnly`, `Secure`, `SameSite=Strict`, restrito ao caminho `/cofre/`; arquivos em `storage/sessions`; expiração por inatividade (configurável) e absoluta (12 h). Na fase 2 as sessões passam para o banco (tela "Sessões ativas").
- **Logs**: `storage/logs/app-AAAA-MM-DD.log` e `security-AAAA-MM-DD.log`, com retenção de `LOG_RETENTION_DAYS`. Senhas e tokens nunca entram no log.

## 5. Dados de exemplo (seed.sql)

- **Seção 1 — categorias globais** (ids 1–21 pais, 100+ filhas): modelo pt-BR com `is_essential`. Vale para produção.
- **Seção 2 — lar de teste "Família Spina"** (usuários `flavio@exemplo.test` e `priscila@exemplo.test`, senha `Cofre@2026teste`): contas, cartões, recorrências, PLR em março, assinaturas (com Amazon Prime duplicado de propósito) e o plano de ação de economia. **Não use em produção**: importe só para testar e depois exclua o lar pela própria aplicação, ou edite o arquivo e remova a seção 2 antes de importar.

## 6. Desenvolvimento local

```
php tests/run.php                      # testes
php -S 127.0.0.1:8080 tools/dev-server.php   # servidor local (emula o .htaccess)
```
Com `APP_URL=http://127.0.0.1:8080/cofre` no `.env` o app responde em `http://127.0.0.1:8080/cofre/`.

## 7. Roadmap das fases

1. ✅ Fundação (esta entrega)
2. Contas e segurança: cadastro, confirmação de e-mail, login, 2FA, lembrar-me, rate limit, sessões, auditoria, onboarding, convites e papéis
3. LGPD: políticas versionadas, consentimentos, "Privacidade e seus dados", exportação, anonimização, exclusão com carência, retenção
4. Cadastros financeiros: contas, categorias, lançamentos, parcelas, anexos, importação CSV/OFX
5. Recorrências, radar de assinaturas, orçamento, metas, plano de ação
6. Dashboard, relatórios, exportações
7. Notificações: configuração, PWA/Web Push, sons e cores, scheduler, central, cron, backup
8. Testes, revisão final de segurança/LGPD, polimento mobile

Seções de SMTP, VAPID, restauração de backup e procedimento de incidente LGPD entram nas fases correspondentes.
