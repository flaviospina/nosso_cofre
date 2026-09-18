# Nosso Cofre v2 — LEIA-ME

Aplicativo web de controle financeiro individual ou familiar, focado em **economizar**. PHP 8.2 puro (MVC próprio), MySQL/MariaDB, HTML + CSS + JS vanilla (Bootstrap 5 via CDN), PWA instalável. Feito para HostGator compartilhado + cPanel, sem terminal.

Este documento cresce a cada fase. Estado atual: **Fase 3 — LGPD** (consentimentos, área "Privacidade e seus dados", exportação, anonimização, exclusão com carência, incidentes, retenção).

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
├── storage/                ← logs, cache (importações temporárias), uploads (comprovantes), exports, backups, sessions (nunca servida)
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

### 3.2 Arquivos: dois layouts possíveis

**Layout B (recomendado no HostGator)** — só o conteúdo de `public/` fica na pasta pública; o restante fica fora de `public_html`. Não usa o `.htaccess` da raiz do projeto, que alguns planos rejeitam sem registrar nada no log.

```
/home1/SEU_USUARIO/nossocofre_app/     ← app/, storage/, sql/, cron/, vendor/, legal/, tests/, .env
/home1/SEU_USUARIO/public_html/nossocofre/   ← index.php, .htaccess, sw.js, manifest, assets/ e app-root.php
```

1. Gerenciador de Arquivos → vá para a **home** (`/home1/SEU_USUARIO`, um nível acima de `public_html`) → *Upload* de `nossocofre_app.zip` → *Extract*. Surge a pasta `nossocofre_app/`.
2. Vá para `public_html/nossocofre/` (crie se não existir; se já tiver a tentativa anterior, apague tudo dentro dela) → *Upload* de `nossocofre_web.zip` → *Extract*.
3. Abra `public_html/nossocofre/app-root.php` e confira o caminho: `return '/home1/SEU_USUARIO/nossocofre_app';` (o caminho da home aparece à direita na tela inicial do cPanel).
4. O `.env` fica em `nossocofre_app/.env`.

**Layout A** — projeto inteiro em `public_html/nossocofre/` com `public/` dentro; o `.htaccess` da raiz reescreve tudo para `public/`. Passos: zip do projeto → *Upload* em `public_html` → *Extract* → conferir que o resultado é `public_html/nossocofre/` com `.htaccess`, `public/`, `app/` etc. Sem `app-root.php`.

Em ambos: pastas `755`, arquivos `644`, nunca `777`; marque *Mostrar arquivos ocultos* para enxergar `.htaccess` e `.env`.

### 3.3 Configuração (.env)
1. Copie `.env.example` para `.env` (botão direito → *Copy*) e edite. No layout B ele fica em `nossocofre_app/.env`.
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

### 3.6 Se der "Internal Server Error" (500) logo na primeira página
Erro 500 do **Apache** (página branca em inglês citando *ErrorDocument*) não é o código PHP: ou uma diretiva de `.htaccess` foi rejeitada, ou as permissões de arquivo/pasta não agradam ao suPHP/suEXEC do HostGator. Todos os `.htaccess` do projeto usam somente `mod_rewrite`, `mod_headers` e `AddType` (categoria *FileInfo*), aceitos em qualquer plano. Escada de diagnóstico, do mais simples ao mais fundo:
1. Abra `https://itthrive.com.br/` (raiz do domínio). Se o site principal também estiver com 500, o `.htaccess` da raiz `public_html/` foi sobrescrito por engano; restaure-o a partir do backup do cPanel.
2. Abra `.../nossocofre/public/teste.txt` (arquivo estático). Se der 500, o problema é `.htaccess` ou permissão, não PHP.
3. Permissões (Gerenciador de Arquivos → botão direito → *Change Permissions*): pastas `755`, arquivos `644`. **Nada com 777 ou 775** (pasta gravável por grupo/outros derruba o PHP com 500 no HostGator), inclusive `storage/`, que com `755` já é gravável pelo PHP porque ele roda com o seu usuário.
4. Renomeie `nossocofre/.htaccess` para `.htaccess.off` e teste `.../nossocofre/public/teste.txt` de novo; depois faça o mesmo com `nossocofre/public/.htaccess`. O arquivo cuja remoção fizer a página abrir é o culpado: me envie a linha correspondente de cPanel → **Métricas → Erros**.
5. Se `teste.txt` abre mas `.../nossocofre/public/diagnostico.php` dá 500, o problema é o handler de PHP da conta: em cPanel → *MultiPHP Manager* escolha PHP 8.2+ para o domínio e confira em *MultiPHP INI Editor* que `display_errors` está ligado temporariamente para ver a mensagem.
6. Erro 500 do **PHP** (página em português do próprio app) fica registrado em `storage/logs/app-AAAA-MM-DD.log` e `storage/logs/php-error.log`.
7. A subpasta pode ter qualquer nome (`cofre`, `nossocofre`…): nada é fixo no código, mas o `APP_URL` do `.env` precisa ser exatamente a URL pública (ex.: `https://itthrive.com.br/nossocofre`).

### 3.7 Se der "504 Gateway Time-out" (página do nginx)
O nginx do HostGator fica na frente do Apache; 504 significa que o Apache/PHP não respondeu a tempo. Para achar o ponto:
1. Abra `https://itthrive.com.br/cofre/public/diagnostico.php` (acesso direto, sem passar pelo roteador). O script imprime passo a passo: PHP, extensões, pastas graváveis, sessão, banco, carga do framework e a resposta interna da rota `/instalar`.
   - Se **também** der 504: o PHP da conta não está respondendo (troque a versão em cPanel → *MultiPHP Manager* / *Select PHP Version* para 8.2+ e verifique se o handler é php-fpm ou lsapi). Não é o código.
   - Se parar em uma linha: o travamento está naquele passo (o mais comum é o banco, `DB_HOST` errado).
   - Se tudo passar e só a URL amigável falhar: é a reescrita do `.htaccess`; me envie a saída e o log de cPanel → *Métricas → Erros*.
2. O `diagnostico.php` só funciona enquanto `APP_KEY` está vazio ou `APP_DEBUG=true`; apague-o depois.

### 3.8 Atualizando uma instalação existente (migrações)
Quando uma fase nova altera o banco, o código passa a esperar uma versão maior do esquema e a tela `/saude` avisa. Para atualizar sem terminal:
1. Envie os arquivos novos (zip) por cima dos antigos.
2. Abra `https://itthrive.com.br/cofre/sistema/migrar?token=SEU_CRON_TOKEN` (o mesmo `CRON_TOKEN` do `.env`) e clique em **Aplicar migrações**. Os arquivos ficam em `sql/migrations/NNN_nome.sql` e podem, alternativamente, ser importados no phpMyAdmin na ordem numérica.
3. Confira `/saude`.

Instalações novas não precisam disso: `sql/schema.sql` já está na versão atual.

### 3.9 E-mail (SMTP)
O sistema envia e-mails de confirmação de cadastro, recuperação de senha, convites e aviso de acesso de aparelho novo. Configure no `.env` a conta criada em cPanel → **Contas de e-mail**: `MAIL_HOST=mail.itthrive.com.br`, `MAIL_PORT=465`, `MAIL_ENCRYPTION=ssl`, `MAIL_USER=cofre@itthrive.com.br`, `MAIL_PASS=...`. O cliente SMTP é próprio (sem PHPMailer). Se o envio falhar, a mensagem fica em `email_outbox` e o cron tenta de novo a cada 5 minutos, até 5 vezes. Em desenvolvimento use `MAIL_DRIVER=log` (grava em `storage/logs/mail-AAAA-MM-DD.log`).

### 3.10 Regras de segurança da conta
- Senha: mínimo 10 caracteres, letras e números, verificada contra lista local de senhas vazadas comuns.
- E-mail confirmado obrigatoriamente antes do primeiro acesso (link de 24 h).
- Após 3 falhas de login no mesmo IP ou conta, aparece um desafio aritmético próprio; a partir de 5 falhas o bloqueio é progressivo (1, 5, 15 e 60 minutos).
- 2FA (TOTP) opcional para todos e **obrigatório** para responsável e administradores de lar familiar; 10 códigos de recuperação de uso único.
- "Lembrar-me": cookie com par selector/validator, validator trocado a cada uso, 30 dias; roubo do banco não dá acesso.
- Login de aparelho ou IP desconhecido gera e-mail de aviso; a tela Conta → Sessões ativas encerra as outras sessões e revoga os acessos lembrados.
- Troca ou redefinição de senha encerra as demais sessões.
- Tudo fica no log de auditoria, visível ao próprio usuário em Conta → Minha atividade.

### 3.11 LGPD: o que existe e como operar
- **Área do titular**: Conta → *Privacidade e seus dados* (`/conta/privacidade`). Cada direito do art. 18 é um botão: ver tudo, exportar ZIP (JSON + CSV, link de 24 h + e-mail), corrigir (perfil, e-mail com nova confirmação, CPF e renda opcionais), revogar consentimentos, "desligar todos os avisos", sair do lar (levando ou não os dados), anonimizar (imediato), excluir a conta e excluir o lar (carência de 7 dias com cancelamento).
- **Regras**: ações irreversíveis exigem senha e, se ativo, código 2FA. O responsável por um lar com outros membros precisa transferir a responsabilidade (Família → *Transferir*) ou excluir o lar antes de excluir a própria conta. Na exclusão da conta, lares em que a pessoa era a única são apagados por inteiro; nos compartilhados os lançamentos ficam com "Membro removido". Consentimentos e registros de segurança ficam sem IP/user-agent pelo prazo mínimo.
- **Retenção** (`settings`, prefixo `retention.`): tentativas de login 12 meses, auditoria 24 meses, lixeira 30 dias, exportações 24 h, sessões 14 dias, alertas 90 dias, backups 30 dias, carência de exclusão 7 dias. O cron aplica uma vez por hora (`RetentionService`) e executa as exclusões vencidas.
- **Controlador**: quem está em `ADMIN_EMAILS` (no `.env`) vê *Incidentes (controlador)* no menu da conta.

### 3.12 Procedimento em caso de incidente de segurança (art. 48 da LGPD)
1. **Conter**: troque a senha do cPanel, do banco (`DB_PASS`) e da conta de e-mail; gere novo `CRON_TOKEN`; em Conta → Sessões ativas, oriente os usuários a encerrar sessões. Se o `APP_KEY` puder ter vazado, considere rotacionar a chave (isso invalida campos criptografados: 2FA, observações, CPF; os usuários precisam reconfigurar o 2FA).
2. **Registrar**: menu → *Incidentes (controlador)* → preencha título, o que aconteceu, dados envolvidos, quando ocorreu e quando foi detectado. Guarde evidências (logs em `storage/logs`, log de auditoria, `email_outbox`).
3. **Avaliar o risco**: dados sensíveis expostos? quantos titulares? há dano provável? Se houver risco ou dano relevante, siga os passos 4 e 5 em prazo razoável (a ANPD orienta 3 dias úteis).
4. **Comunicar os titulares**: na tela do incidente, *Comunicar os afetados* (todos ou lista de e-mails), descrevendo medidas adotadas e recomendações. O modelo de e-mail já traz a natureza dos dados, o período, as medidas e o contato do encarregado.
5. **Comunicar a ANPD** pelo formulário oficial (gov.br/anpd) e registrar na tela (*Registrar comunicação à ANPD*).
6. **Encerrar** o incidente na tela e anotar as lições aprendidas no campo de anotações.

### 3.13 Cadastros financeiros (fase 4): como usar e o que fica no servidor
- **Contas e cartões** (`/contas`): tipos conta corrente, poupança/reserva, cartão de crédito (fechamento, vencimento e limite), dinheiro e investimento; dona ou conjunta. O saldo **atual** soma só o que está pago; o **projetado** inclui pendentes e agendados. Conta com lançamentos não é excluída, só **arquivada** (some do lançamento rápido, continua nos relatórios).
- **Categorias** (`/categorias`): o modelo padrão pt-BR (ids fixos do `seed.sql`) vale para todos os lares e pode ser **ocultado** por lar; cada lar cria as suas (com pai, ícone, cor e a marca "essencial"). Categorias com lançamentos são desativadas em vez de excluídas.
- **Lançamentos** (`/lancamentos`): despesa, receita e transferência (uma linha só, com conta de origem e destino); parcelamento em até 120× (a última parcela absorve o arredondamento; 31/01 + 1 mês = 28/02); situação pago/pendente/agendado com toque na lista; etiquetas; observações criptografadas; **privado** (os outros membros veem só o valor nos totais); modelos favoritos e "repetir último"; edição em lote (categoria, situação, responsável, privacidade, lixeira); lixeira com 30 dias; filtros por período, membro, conta, categoria (pai inclui filhas), tipo, situação, etiqueta e busca por texto.
- **Comprovantes**: JPG, PNG, WEBP ou PDF até 5 MB, gravados em `storage/uploads/{lar}/` (fora do `public`, servidos só por `/lancamentos/{id}/anexo` com sessão e checagem de lar/privacidade). Imagens são reprocessadas: orientação corrigida, redução para no máximo 1600 px e **remoção de EXIF** (localização, aparelho). Exige a extensão GD (padrão no HostGator); sem GD a imagem é gravada como veio.
- **Importação** (`/importar`): CSV (detecta delimitador, cabeçalho e mapeamento; aceita coluna única de valor com sinal, colunas separadas de débito/crédito, coluna D/C e "inverter sinal" para faturas) e OFX/QFX (`STMTTRN`, `FITID`). Antes de gravar, a pré-visualização marca **duplicados** (mesma data + valor + tipo + descrição já existente, ou mesmo FITID) e sugere categorias pelas regras aprendidas. Cada lote fica em *Importar → Lotes* com **Desfazer** (manda tudo para a lixeira). O arquivo enviado fica em `storage/cache/import-*` por 2 h e é apagado pelo cron.
- **Sugestão de categoria**: cada lançamento salvo com categoria "ensina" a regra (`category_rules`, texto normalizado da descrição sem números e sem palavras como PIX/TED/COMPRA). O formulário consulta `/lancamentos/sugerir` ao sair do campo descrição.
- **Permissões**: `viewer` só lê; `member` cria e edita os próprios (ou de todos, se em Família → *Membros podem editar lançamentos dos outros*); `admin`/`owner` editam tudo, exceto lançamentos privados de outros membros.
- **Limite de upload no PHP**: os formulários aceitam 5 MB (comprovante) e 2 MB (extrato). Se o cPanel estiver com `upload_max_filesize` menor (padrão 2M em alguns planos), ajuste em *Select PHP Version → Options* (ou `MultiPHP INI Editor`) para 8M/`post_max_size` 10M.

## 4. Decisões técnicas que valem registrar

- **Subpasta `/cofre`**: o `.htaccess` da raiz reescreve tudo para `public/` sem `RewriteBase`, e o `Request` remove a subpasta do caminho a partir de `APP_URL`. Mover para um domínio próprio exige só trocar `APP_URL`.
- **Multi-tenant por lar**: o `Model` base injeta `household_id = <lar da sessão>` em toda consulta e lança exceção se não houver lar. Um id de outro lar simplesmente "não existe" (404).
- **Datas**: `DATETIME` sempre em UTC no banco (a conexão PDO fixa `time_zone = '+00:00'`); a exibição converte para o fuso do usuário (`users.timezone`). `DATE` de lançamentos é data de calendário.
- **Senhas**: Argon2id quando o PHP do servidor suporta; caso contrário bcrypt custo 12. O login faz *rehash* automático quando o algoritmo disponível melhora. O seed usa bcrypt por ser verificável em qualquer PHP.
- **Criptografia em repouso** (AES-256-GCM com `APP_KEY`): segredo TOTP, CPF opcional e observações dos lançamentos. Descrição e valor ficam em claro porque busca, filtros e relatórios dependem deles.
- **CSP com nonce** para scripts e estilos: nenhum `<script>` ou `style=""` inline solto nas views. Cores dinâmicas usam `data-bg`, `data-color`, `data-width`, aplicadas pelo `app.js`.
- **Sessões**: cookie `HttpOnly`, `Secure`, `SameSite=Strict`, restrito ao caminho `/cofre/`; arquivos em `storage/sessions`; expiração por inatividade (configurável) e absoluta (12 h). Na fase 2 as sessões passam para o banco (tela "Sessões ativas").
- **Logs**: `storage/logs/app-AAAA-MM-DD.log` e `security-AAAA-MM-DD.log`, com retenção de `LOG_RETENTION_DAYS`. Senhas e tokens nunca entram no log.
- **TOTP em código próprio** (RFC 6238, ~100 linhas com testes contra os vetores oficiais) em vez de biblioteca vendorizada; o QR code é desenhado no navegador pela biblioteca `qrcode-generator` (jsDelivr, com hash SRI), sem enviar o segredo a serviço externo.
- **Sessões em banco** (tabela `sessions`) para a tela "Sessões ativas"; `SESSION_DRIVER=files` volta para arquivos se necessário.
- **Aparelho conhecido** = hash(IP + família de navegador/SO), não o user-agent inteiro, para não disparar aviso a cada atualização do navegador.
- **Convite aceito na confirmação do e-mail**: quem cria conta com o e-mail convidado entra no lar automaticamente ao confirmar, sem passo extra.
- **Anonimização preserva as FKs**: a linha do usuário continua existindo (nome "Membro removido", e-mail `removido-{id}@anonimizado.invalid`, sem senha) para que `transactions.created_by` e os totais por membro do lar não quebrem; é o que a lei chama de anonimização e o que a família espera ver.
- **Exportação síncrona**: o ZIP é gerado na hora (os volumes são pequenos) e guardado em `storage/exports` com token de 24 h; o cron apaga os vencidos.
- **Transferência em uma linha só** (`type = transfer`, `account_id` origem, `transfer_account_id` destino) em vez de duas linhas espelhadas: os saldos são calculados a partir dela e não há risco de "meia transferência"; `transfer_pair_id` fica reservado para integrações futuras.
- **Parcelas são lançamentos independentes** agrupados por `installment_group`: cada uma pode ser paga, editada ou excluída sozinha (como acontece na vida real com faturas), e os relatórios por mês ficam corretos sem cálculo especial.
- **Duplicidade na importação por hash** `sha256(data|valor|tipo|descrição normalizada)` gravado em `transactions.import_hash` (também nos lançamentos manuais), além do `FITID` do OFX guardado em `tags.fitid`.
- **Categorias globais compartilhadas** (`household_id NULL`) com ocultação por lar em `households.settings.hidden_categories`, em vez de copiar 120 categorias para cada lar: o modelo pode evoluir no seed sem migração de dados.
- **Formulário sem dependências**: seletor de categorias em "chips" (rádios estilizados) filtrável por texto, valor em pt-BR (`1.234,56`) aceito pelo `Validator` e formatado no `blur` pelo `app.js`; o lançamento rápido cabe em três toques (tipo já vem escolhido pelo botão do painel, valor, categoria, salvar).
- **Migrações versionadas** em `sql/migrations` com tela `/sistema/migrar` protegida pelo `CRON_TOKEN`, porque `schema.sql` (CREATE TABLE IF NOT EXISTS) não altera tabelas existentes.

## 5. Dados de exemplo (seed.sql)

- **Seção 1 — categorias globais** (ids 1–21 pais, 100+ filhas): modelo pt-BR com `is_essential`. Vale para produção.
- **Seção 2 — lar de teste "Família Spina"** (usuários `flavio@exemplo.test` e `priscila@exemplo.test`, senha `Cofre@2026teste`, e-mails já confirmados): contas, cartões, recorrências, PLR em março, assinaturas (com Amazon Prime duplicado de propósito) e o plano de ação de economia. Como o lar é familiar, o Flávio (responsável) e a Priscila (administradora) precisam ativar o 2FA no primeiro acesso. **Não use em produção**: importe só para testar e depois exclua o lar pela própria aplicação, ou edite o arquivo e remova a seção 2 antes de importar.

## 6. Desenvolvimento local

```
php tests/run.php                      # testes unitários e de integração (os de banco são pulados sem banco)
php -S 127.0.0.1:8080 tools/dev-server.php   # servidor local (emula o .htaccess)
bash tools/smoke-fase2.sh              # teste de ponta a ponta da fase 2 (banco limpo + MAIL_DRIVER=log)
bash tools/smoke-fase3.sh              # teste de ponta a ponta da fase 3 (depois do da fase 2; ADMIN_EMAILS=ana@exemplo.test)
bash tools/smoke-fase4.sh              # teste de ponta a ponta da fase 4 (banco limpo com seed; usa o lar "Família Spina")
```
Com `APP_URL=http://127.0.0.1:8080/cofre` no `.env` o app responde em `http://127.0.0.1:8080/cofre/`.

## 7. Roadmap das fases

1. ✅ Fundação
2. ✅ Contas e segurança: cadastro, confirmação de e-mail, login, 2FA, lembrar-me, rate limit, sessões, auditoria, onboarding, convites e papéis
3. ✅ LGPD: políticas versionadas, consentimentos, "Privacidade e seus dados", exportação, anonimização, exclusão com carência, incidentes, retenção
4. ✅ Cadastros financeiros (esta entrega): contas e cartões, categorias, lançamentos (rápido, parcelas, transferências, privado, lote, lixeira, modelos), comprovantes sem EXIF, importação CSV/OFX com duplicados e sugestão de categoria
5. Recorrências, radar de assinaturas, orçamento, metas, plano de ação
6. Dashboard, relatórios, exportações
7. Notificações: configuração, PWA/Web Push, sons e cores, scheduler, central, cron, backup
8. Testes, revisão final de segurança/LGPD, polimento mobile

Seções de SMTP, VAPID, restauração de backup e procedimento de incidente LGPD entram nas fases correspondentes.
