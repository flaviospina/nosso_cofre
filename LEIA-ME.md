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
├── vendor/                 ← bibliotecas PHP vendorizadas (Web Push, fase 7); as de navegador ficam em public/assets/vendor
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

### 3.5 Cron (obrigatório a partir da fase 7)
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
- Após 3 falhas de login no mesmo IP ou na mesma conta, aparece um desafio aritmético próprio (cada desafio vale uma resposta); a partir de 5 falhas **do mesmo IP** o bloqueio é progressivo (1, 5, 15 e 60 minutos). Falhas vindas de outros IPs contra a sua conta só exigem o desafio (bloqueio por conta apenas a partir de 20), para que ninguém tranque a sua conta de propósito.
- O cadastro nunca revela se um e-mail já tem conta: a resposta é igual e o dono do endereço recebe um aviso por e-mail.
- 2FA (TOTP) opcional para todos e **obrigatório** para responsável e administradores de lar familiar; 10 códigos de recuperação de uso único.
- "Lembrar-me": cookie com par selector/validator, validator trocado a cada uso, 30 dias; roubo do banco não dá acesso.
- Login de aparelho ou IP desconhecido gera e-mail de aviso; a tela Conta → Sessões ativas encerra as outras sessões (pede a senha) e revoga os acessos lembrados.
- Links por e-mail (confirmação, redefinição, troca de e-mail) são de uso único e um pedido novo invalida o anterior; o corpo dos e-mails some da fila (`email_outbox`) assim que enviado.
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

### 3.14 Recorrências, radar, orçamento, metas e plano (fase 5)
- **Recorrências** (`/recorrencias`): semanal, mensal (dia do mês, a cada N meses), anual (mês + dia) e "a cada N dias". Cada regra vira lançamentos **agendados** com a antecedência configurada (padrão 30 dias). A geração é idempotente e roda ao abrir a tela, no botão *Gerar agora* e a cada execução do cron (`RecurrenceService::generateAll`). Ocorrências vencidas ou dos próximos 7 dias aparecem em *A confirmar*: **Paguei/Recebi** (marca paga na data de hoje), *Ajustar valor* (abre o lançamento) ou *Pular* (lixeira). Valor "média dos últimos 3 meses" usa as 3 últimas ocorrências pagas da própria regra. Editar ou pausar uma regra refaz as ocorrências futuras ainda não confirmadas; as pagas ficam no histórico.
- **Eventos previstos** (regra com "evento previsto grande", ex.: PLR em março): aparecem no topo com a data e a **sugestão de destino** (dívida mais cara → reserva de emergência → demais metas). O aviso antecipado (`notify_days_before`) é disparado pela fase 7. Ao confirmar, o campo *Valor real* é obrigatório.
- **Débito automático**: botão-raio em cada regra de despesa e indicador "% em débito automático" por responsável.
- **Radar de assinaturas** (`/assinaturas`): regras marcadas como assinatura + cobranças repetidas detectadas nos lançamentos (mesma descrição normalizada em ≥ 3 dos últimos 6 meses, valor variando até 10 %). Sinaliza **duplicidade** (mesmo nome em mais de uma regra ativa), **aumento de preço** (> 5 % sobre o anterior/esperado) e **sem uso registrado** (60 dias sem tocar em *Usei*). *Cancelei* encerra a regra e remove as cobranças futuras; *É assinatura* adota uma cobrança detectada como regra.
- **Orçamento** (`/orcamento?mes=AAAA-MM`): limite por categoria (a categoria pai soma as filhas) e opcionalmente por membro; gasto = pagos + pendentes do mês (agendados não contam); percentual, sobra por dia, **projeção** pelo ritmo diário e "neste ritmo você estoura no dia X"; avisos em X % (padrão 80) e 100 %. *Copiar do mês anterior* e sugestão pela média dos 3 meses. Ao lado, **essencial × supérfluo** (categorias `is_essential`) e a **regra 50/30/20** com semáforo (verde dentro; amarelo até 60/40/10; vermelho fora).
- **Metas** (`/metas`): valor, prazo, opcionalmente ligada a uma conta (usa o saldo dela) ou alimentada por **aportes** (`goal_contributions`, negativo = retirada). Sugestão de aporte = faltante ÷ meses até o prazo. Ao atingir o alvo a meta vira "batida" (auditoria `goal.achieved`; a notificação chega na fase 7).
- **Plano de ação** (`/plano`): ações com responsável, categoria de medição e economia estimada. *Iniciar* congela a linha de base (média dos 3 meses anteriores na categoria); *realizada* = base − gasto do mês atual. *Concluir* grava a economia medida.
- **Simulador "e se"** (`/simulador`): cortes por categoria (limitados à média atual) e cancelamento de assinaturas → sobra mensal nova, economia acumulada em 6/12 meses, meses de reserva cobertos (reserva = saldo de poupança + investimentos ÷ gastos essenciais) e impacto na primeira meta ativa.
- **Migração**: instalações anteriores à fase 5 precisam da `sql/migrations/003_fase5_aportes_de_meta.sql` (tabela `goal_contributions`), aplicada por `/sistema/migrar?token=CRON_TOKEN` ou pelo phpMyAdmin.

### 3.15 Painel e relatórios (fase 6)
- **Painel** (`/painel?mes=AAAA-MM&membro=id`): os 11 indicadores do §6.4 — saldo do mês (pagos + pendentes) e **projetado** (inclui agendados), receitas × despesas em 12 meses, despesas por categoria e por membro, contas a vencer em 7 dias (atrasada / hoje / em breve), os 5 orçamentos mais perto de estourar, **taxa de poupança** e **idade do dinheiro** (saldo líquido em conta corrente/dinheiro/poupança ÷ gasto diário médio dos últimos 90 dias), gasto supérfluo, metas e plano, radar de assinaturas, próximos eventos previstos e o **score de saúde financeira**. Filtro por mês e, em lar familiar, por membro.
- **Score (0–100)**, com a explicação de cada parcela na tela: taxa de poupança (25 pontos aos 20 %), orçamentos sem estouro (20; sem limites definidos vale 10), contas em dia (15, −5 por atrasada), reserva de emergência (20 com 6 meses de gastos essenciais cobertos), gasto supérfluo (10 até 30 % das despesas, zero a partir de 60 %) e assinaturas sem alerta (10, −5 por alerta). Níveis: Ótima ≥ 80, Boa ≥ 60, Atenção ≥ 40, Crítica abaixo.
- **Gráficos**: Chart.js 4.4.4 **vendorizado** em `public/assets/vendor/chart.umd.js` (sem CDN; a licença MIT está ao lado). Os dados vão embutidos na página em `<script type="application/json">` com nonce, respeitando a CSP. Cores: azul para receitas e laranja para despesas (par validado para daltonismo em tema claro e escuro); barras de categoria em um só matiz; barras de membro na cor do próprio membro. Todo gráfico tem legenda ou rótulo de eixo e uma tabela alternativa ("Ver como tabela").
- **Relatórios** (`/relatorios`): mensal por categoria (pai › filha, % do total, comparação com o mês anterior e com o mesmo mês do ano passado, ranking "onde o dinheiro mais cresceu"), anual (12 meses, melhor e pior mês, taxa de poupança), por membro, por categoria (evolução em 12 meses + lançamentos do mês) e por conta/cartão (extrato com saldo inicial e final; para cartões, a **fatura** pelo dia de fechamento, com vencimento). Todos aceitam `?formato=csv` (UTF-8 com BOM e `;`, abre direto no Excel pt-BR) e `?formato=pdf`.
- **PDF sem biblioteca**: `app/Core/Pdf.php` gera PDF 1.4 com Helvetica/WinAnsi (acentos ok), títulos, parágrafos e tabelas com cabeçalho repetido a cada página. Não faz imagens nem fontes embutidas: é o "PDF simples" do §6.5. Cada exportação fica no log de auditoria (`report.exported`).

### 3.16 Notificações, Web Push, cron e backup (fase 7)
- **Tela** Conta → *Notificações* (`/conta/notificacoes`). Nada é enviado por padrão além dos e-mails de segurança. Cada tipo de aviso (dinheiro entrando/saindo, conta a vencer com os dias escolhidos, conta atrasada, orçamento em X %, assinatura duplicada/mais cara, evento previsto, meta batida, mês apertado, lançamentos de outros membros, resumo periódico, segurança) tem liga/desliga, canal (só no app, push, e-mail, ambos), cor e som. Também: modo imediato/agrupado/só resumo, limite de pushes por dia, valor mínimo, horário silencioso por dia da semana, dias sem avisos, volume e vibração, "ver como fica" (toast + som na hora, sem enviar) e a lista de aparelhos com "testar aqui" e "remover". Os canais espelham os consentimentos (`digest_email`, `push`) da área de Privacidade; "desligar todos os avisos" continua lá.
- **Central de avisos** (`/avisos`, sino na barra): histórico, filtro por tipo, lido/não lido, "silenciar este tipo por 7 dias". Com o app aberto, avisos novos viram toast com cor e som (sondagem a cada 60 s). Nas notificações de conta a vencer/atrasada, a ação "Marcar como pago" usa um link assinado válido por 7 dias e exige a sessão do próprio usuário.
- **Web Push sem biblioteca externa**: `app/Core/WebPush.php` implementa VAPID (JWT ES256) e a cifra `aes128gcm` (RFC 8291) só com OpenSSL e curl, então não há dependência do composer nem de gmp/bcmath. **Ativar**: abra `/sistema/vapid?token=SEU_CRON_TOKEN`, copie as três linhas para o `.env`, confira em `/saude`. O push exige HTTPS e, no iPhone, o app instalado na tela inicial (iOS 16.4+). Assinatura que falha 3 vezes seguidas é desativada e o usuário é avisado por e-mail e na Central.
- **Sons** (`public/assets/sounds/*.wav`): sintetizados por `tools/gerar-sons.php` (sem direitos de terceiros). O som toca dentro do app; o push usa vibração e a cor no ícone.
- **Cron a cada 5 minutos** (cPanel → Cron Jobs): `curl -s "https://itthrive.com.br/nossocofre/sistema/cron?token=SEU_CRON_TOKEN" > /dev/null` ou `php /home1/itthri79/nossocofre_app/cron/run.php`. Cada execução: gera recorrências, roda o `NotificationScheduler` (detecção com deduplicação + entrega respeitando janelas, agrupamento e limite diário), reenvia e-mails pendentes, faz o backup diário e a retenção uma vez por hora. Adiados por horário silencioso ficam em `alerts.scheduled_for`.
- **Previsão de caixa** (`/previsao`): saldo líquido de hoje + pendentes, agendados, recorrências ainda não geradas e faturas de cartão no vencimento, dia a dia por até 120 dias; mostra o primeiro dia negativo, o ponto mais baixo e a **sobra segura** (quanto dá para guardar sem faltar até a próxima receita). O aviso "mês apertado" usa esse cálculo.
- **Backup**: com `BACKUP_KEY` no `.env`, o cron gera um `storage/backups/nosso-cofre-AAAA-MM-DD-HHMM.sql.gz.enc` por dia (SQL completo em PHP puro, gzip, AES-256-GCM) e mantém `BACKUP_RETENTION_DAYS`. Quem está em `ADMIN_EMAILS` vê *Backups (controlador)* para gerar agora e baixar. **Restaurar**: (a) com terminal, `php tools/restaurar-backup.php arquivo.sql.gz.enc` (pede confirmação); (b) sem terminal, no seu computador com PHP instalado: `php tools/restaurar-backup.php arquivo.sql.gz.enc --somente-sql > restauracao.sql` (o `.env` local precisa da mesma `BACKUP_KEY`) e importe o `.sql` pelo phpMyAdmin. Guarde a `BACKUP_KEY` fora do servidor: sem ela o backup é ilegível.

### 3.17 Revisão final de segurança e LGPD (fase 8): checklist item por item

Três revisões independentes (isolamento entre lares e membros; autenticação, sessão e LGPD; saída HTML, CSP, uploads e JavaScript) foram feitas sobre o código completo. Tudo o que foi apontado está corrigido e coberto por teste, exceto os riscos aceitos listados no fim.

| Item | Como é atendido | Onde |
|---|---|---|
| Isolamento entre lares | Todo `Model` injeta `household_id` da sessão; ids de outro lar dão 404 | `app/Core/Model.php` |
| Lançamento privado / "compartilhar com o lar" | Filtro SQL único (`TransactionPolicy::visibleSql`) aplicado a busca, filtros por categoria e etiqueta, relatórios por categoria, radar de assinaturas, previsão de caixa, avisos do agendador e exportação LGPD; nos agregados (painel e relatório mensal) o que é de outro membro aparece como "Lançamentos privados", sem revelar categoria; quem desliga o consentimento *compartilhar com o lar* tem tudo tratado como privado pelos demais | `app/Services/TransactionPolicy.php`, testes `SecurityHardeningTest`, `tools/smoke-fase8.sh` |
| Edição só de quem pode | `canEdit` também nas ocorrências de recorrência (confirmar/pular), desfazer importação só por quem importou ou responsável, responsável de lançamento/importação/lote tem de ser membro do lar, modelo favorito só do próprio usuário | `RecurrenceController`, `ImportController`, `TransactionController` |
| CSRF | Token por sessão em todo POST (campo oculto ou cabeçalho `X-CSRF-Token`), `SameSite=Strict` | `app/Core/Csrf.php` |
| XSS | `e()` (`ENT_QUOTES`) em toda saída; JSON embutido com `JSON_HEX_TAG`; JS sem `innerHTML` com dado do servidor; Markdown legal escapa antes de marcar | `app/helpers.php`, views |
| CSP e cabeçalhos | `script-src 'self' 'nonce-…'` (sem host externo; os scripts do CDN levam nonce + SRI), `style-src` com nonce, `frame-ancestors 'none'`, `object-src 'none'`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS com https, `X-Powered-By` removido | `app/Core/Response.php` |
| SQL injection | PDO com placeholders em 100 % das consultas; listas de ids montadas só de inteiros | `app/Core/Database.php` |
| Uploads | MIME por `finfo`, extensão pelo MIME, nome aleatório, gravação fora de `public/`, imagens recodificadas (sem EXIF/GPS), 5 MB; PDF é **baixado** (não abre no visualizador, pois pode conter JavaScript) | `AttachmentService`, `TransactionController::attachment` |
| CSV / planilhas | Célula que começa com `=`, `+`, `-`, `@`, TAB ou CR recebe apóstrofo (números negativos continuam números) nos relatórios e na exportação LGPD | `ExportService::csvSafe` |
| Senhas | Argon2id (ou bcrypt 12), lista de senhas vazadas, hash falso no mesmo algoritmo quando o e-mail não existe (tempo constante) | `app/Core/Auth.php` |
| Rate limit e CAPTCHA | Progressivo por IP; por conta só CAPTCHA até 20 falhas; CAPTCHA de uso único, assinado com HMAC e com validade | `RateLimiter`, `Captcha` |
| 2FA | TOTP próprio (RFC 6238), anti-replay atômico (`UPDATE … WHERE totp_last_counter < ?`), códigos de recuperação com HMAC da chave do app (não quebráveis offline) | `TwoFactorService` |
| Sessões | Banco, `HttpOnly`/`Secure`/`SameSite=Strict`, inatividade e absoluta; o polling de avisos não conta como atividade; encerrar outras sessões pede a senha; troca de senha derruba as demais | `app/Core/Session.php`, `AccountController` |
| Tokens por e-mail | Aleatórios (32 bytes), guardados por hash, uso único, pedido novo invalida o anterior, usuário precisa estar ativo | `AuthService` |
| E-mails | Cabeçalhos sem CR/LF, corpo escapado, parte texto; corpo apagado do `email_outbox` após envio; pendentes/falhos expiram em 7 dias | `Mailer`, `RetentionService` |
| Erros e informação | Mensagem genérica sem `APP_DEBUG`; erro de banco nunca vai para a tela (só para o log); `/saude` mostra detalhes só a admin, com `CRON_TOKEN` ou em debug; `index.php` não imprime caminho do servidor | `ErrorHandler`, `SystemController` |
| PWA | Página offline em cache sem nome, avisos ou token do usuário; service worker só navega para a própria origem; nada financeiro em cache | `public/sw.js`, `layouts/base.php` |
| Criptografia em repouso | AES-256-GCM: segredo TOTP, CPF, observações; backup cifrado com `BACKUP_KEY` | `app/Core/Crypto.php`, `BackupService` |
| LGPD art. 18 | Acesso, exportação (só lares ativos, só o que o titular pode ver), correção, revogação de consentimentos, portabilidade, anonimização (limpa convites, tentativas de login e fila de e-mail), exclusão com carência | `PrivacyService`, `ExportService` |
| Retenção e incidentes | Prazos em `settings` aplicados pelo cron; registro de incidente com comunicação a titulares e ANPD | `RetentionService`, `IncidentService` |

**Riscos aceitos (documentados)**
- Sair do lar e cancelar a exclusão da conta não pedem a senha de novo (ações reversíveis ou na direção segura); todas as irreversíveis pedem senha e, se ativo, 2FA.
- O link "marcar como pago" do e-mail/push é GET assinado, válido por 48 h, idempotente e só executa com a sessão do próprio usuário.
- Códigos de recuperação gerados antes da fase 8 continuam aceitos pelo hash antigo até serem regenerados.
- Comprovantes não são cifrados (ver seção 4).

## 4. Decisões técnicas que valem registrar

- **Subpasta `/cofre`**: o `.htaccess` da raiz reescreve tudo para `public/` sem `RewriteBase`, e o `Request` remove a subpasta do caminho a partir de `APP_URL`. Mover para um domínio próprio exige só trocar `APP_URL`.
- **Multi-tenant por lar**: o `Model` base injeta `household_id = <lar da sessão>` em toda consulta e lança exceção se não houver lar. Um id de outro lar simplesmente "não existe" (404).
- **Datas**: `DATETIME` sempre em UTC no banco (a conexão PDO fixa `time_zone = '+00:00'`); a exibição converte para o fuso do usuário (`users.timezone`). `DATE` de lançamentos é data de calendário.
- **Senhas**: Argon2id quando o PHP do servidor suporta; caso contrário bcrypt custo 12. O login faz *rehash* automático quando o algoritmo disponível melhora. O seed usa bcrypt por ser verificável em qualquer PHP.
- **Criptografia em repouso** (AES-256-GCM com `APP_KEY`): segredo TOTP, CPF opcional e observações dos lançamentos. Descrição e valor ficam em claro porque busca, filtros e relatórios dependem deles. **Comprovantes não são cifrados**: ficam fora da raiz pública, com nome aleatório e servidos só por controller autenticado; cifrar cada imagem exigiria decifrar e recodificar a cada visualização no PHP compartilhado (memória e tempo), e o backup do banco não os inclui — o próprio cPanel faz o backup da pasta.
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
- **Ocorrências geradas são lançamentos comuns** (`status = scheduled`, `recurring_id`), não uma tabela à parte: entram na lista, nos filtros, nos saldos projetados e na lixeira sem código especial; a idempotência é garantida pela chave lógica (regra, data).
- **Cursor `next_run_date` recalculado pela própria regra** a cada geração (não confia no valor gravado), o que torna edições de dia/frequência seguras.
- **Radar sem tabela própria**: assinaturas são regras com `is_subscription`; a detecção nos lançamentos é calculada na hora (últimos 6 meses) porque o volume por lar é pequeno e evita sincronizar duas fontes.
- **Orçamento mede pagos + pendentes**: o pendente já é compromisso do mês; o agendado (gerado por recorrência) não entra para não "estourar" orçamentos antes do gasto acontecer.
- **Chart.js vendorizado em vez de CDN**: o painel é a tela mais usada e não pode depender de um terceiro fora do ar; o arquivo tem 200 KB e é servido com cache pelo Apache. Os demais assets (Bootstrap, ícones) continuam no jsDelivr com SRI.
- **Idade do dinheiro simplificada**: em vez do cálculo FIFO do YNAB (que exige histórico completo por real), usamos saldo líquido ÷ gasto diário médio de 90 dias — o mesmo significado prático ("quantos dias você aguenta sem receita") com dados que o app já tem.
- **Relatórios calculados na hora**, sem tabelas de agregados: o volume de um lar é pequeno (milhares de linhas por ano) e os índices por lar + data resolvem; evita jobs de recomputação no cron do cPanel.
- **Web Push em código próprio** (~250 linhas) em vez de `minishlink/web-push`: a biblioteca puxa uma dúzia de pacotes do composer e exige gmp/bcmath, que o HostGator nem sempre tem; o PHP 8 traz `openssl_pkey_derive` e `hash_hkdf`, que bastam para VAPID e RFC 8291, e o teste unitário decifra o payload como o navegador faria.
- **Avisos como linhas em `alerts`** com `dedupe_key`: a detecção pode rodar quantas vezes for (cron a cada 5 min) sem repetir; a entrega é uma etapa separada, o que permite adiar por horário silencioso, agrupar e limitar por dia sem perder nada.
- **Backup em PHP puro**: `mysqldump` e `exec()` costumam estar bloqueados no compartilhado; o dump por `SELECT` em blocos de 500 linhas cabe no limite de memória do PHP para o volume de um lar.
- **Migrações versionadas** em `sql/migrations` com tela `/sistema/migrar` protegida pelo `CRON_TOKEN`, porque `schema.sql` (CREATE TABLE IF NOT EXISTS) não altera tabelas existentes.
- **Privado entra no total, não na categoria**: o lançamento privado de outro membro (ou de quem não compartilha) continua somando no saldo do lar, mas nos gráficos por categoria vira a fatia "Lançamentos privados" e some de busca, filtros e relatório por categoria. É o único jeito de manter os totais da casa corretos sem revelar o que foi comprado.
- **`script-src` sem host do CDN**: com nonce, um `<script src="https://cdn…">` com o nonce já é permitido; liberar o host inteiro deixaria qualquer pacote do npm (AngularJS etc.) disponível para contornar a CSP em caso de regressão de XSS.

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
bash tools/smoke-fase5.sh              # teste de ponta a ponta da fase 5 (banco limpo com seed)
bash tools/smoke-fase6.sh              # teste de ponta a ponta da fase 6 (banco limpo com seed)
bash tools/smoke-fase7.sh              # teste de ponta a ponta da fase 7 (banco limpo; .env com BACKUP_KEY, VAPID_* e CRON_TOKEN)
bash tools/smoke-fase8.sh              # segurança e LGPD de ponta a ponta (banco limpo com seed; CRON_TOKEN; altera APP_DEBUG só durante o teste)
```
Com `APP_URL=http://127.0.0.1:8080/cofre` no `.env` o app responde em `http://127.0.0.1:8080/cofre/`.

## 7. Roadmap das fases

1. ✅ Fundação
2. ✅ Contas e segurança: cadastro, confirmação de e-mail, login, 2FA, lembrar-me, rate limit, sessões, auditoria, onboarding, convites e papéis
3. ✅ LGPD: políticas versionadas, consentimentos, "Privacidade e seus dados", exportação, anonimização, exclusão com carência, incidentes, retenção
4. ✅ Cadastros financeiros: contas e cartões, categorias, lançamentos (rápido, parcelas, transferências, privado, lote, lixeira, modelos), comprovantes sem EXIF, importação CSV/OFX com duplicados e sugestão de categoria
5. ✅ Recorrências, radar de assinaturas, orçamento (projeção, essencial × supérfluo, 50/30/20), metas com aportes, plano de ação (estimado × realizado) e simulador "e se"
6. ✅ Painel com os 11 indicadores e gráficos, relatórios (mensal, anual, membro, categoria, conta/fatura) com CSV e PDF
7. ✅ Notificações 100 % configuráveis, Web Push próprio, sons e cores, agendador, central de avisos, previsão de caixa, cron e backup criptografado
8. ✅ Testes (81 unitários/integração + 7 roteiros de ponta a ponta), revisão final de segurança e LGPD item por item (seção 3.17), polimento mobile e tema escuro (esta entrega)
9. Carteira de investimentos (cadastro, rentabilidade, alocação, liquidez, IR/FGC, "melhor momento de aplicar" por regras)
10. Diferenciais: divisão justa entre o casal, ritual da conversa financeira, compra com tempo de espera, preço em horas de trabalho, custo do hábito, inflação pessoal, cartão inteligente, tempo até a independência

Seções de SMTP, VAPID, restauração de backup e procedimento de incidente LGPD entram nas fases correspondentes.
