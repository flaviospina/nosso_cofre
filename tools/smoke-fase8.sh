#!/usr/bin/env bash
# tools/smoke-fase8.sh — teste de ponta a ponta da fase 8 (revisão de segurança e LGPD): isolamento entre membros,
# "compartilhar com o lar", CSV sem fórmulas, cabeçalhos, CAPTCHA por conta, sessões, tokens de uso único.
# Pré-requisitos: banco limpo (schema + seed), servidor em $BASE, .env com MAIL_DRIVER=log e CRON_TOKEN.
set -u
BASE="${BASE:-http://127.0.0.1:8080/cofre}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; JAR="$TMP/jar"; JAR2="$TMP/jar2"; JAR3="$TMP/jar3"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ✔ $1"; }
fail() { FAIL=$((FAIL+1)); echo "  ✘ $1"; [ -n "${2:-}" ] && echo "      $2"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1" "esperado '$2', obtido '$3'"; fi; }
contains() { if grep -q -- "$3" <<<"$2"; then ok "$1"; else fail "$1" "'$3' não encontrado"; fi; }
lacks() { if grep -q -- "$3" <<<"$2"; then fail "$1" "'$3' apareceu"; else ok "$1"; fi; }
csrf() { curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="csrf-token" content="[^"]*' | sed 's/.*content="//'; }
dbg() { [ -z "${DEBUG:-}" ] && return 0; echo "    → $CODE $LOC"; }
post() { local jar="$1" path="$2"; shift 2; local token; token=$(csrf "$jar" "$path"); [ -z "$token" ] && token=$(csrf "$jar" "/painel")
  curl -s -b "$jar" -c "$jar" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" -X POST "$BASE$path" --data-urlencode "_token=$token" "$@" > "$TMP/meta"
  CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); dbg; }
postf() { local jar="$1" path="$2"; shift 2; local token; token=$(csrf "$jar" "/painel")
  curl -s -b "$jar" -c "$jar" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" -X POST "$BASE$path" -F "_token=$token" "$@" > "$TMP/meta"
  CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); dbg; }
get() { curl -s -b "$1" -c "$1" -o "$TMP/body" -w "%{http_code}|%{redirect_url}|%{content_type}" "$BASE$2" > "$TMP/meta"; CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); CTYPE=$(cut -d'|' -f3 "$TMP/meta"); }
totp() { php -r 'require "'"$ROOT"'/app/bootstrap.php"; echo App\Core\Totp::code($argv[1]);' "$1"; }
sql() { mysql -uroot -N --default-character-set=utf8mb4 nosso_cofre -e "$1"; }
maillink() { grep -o "$BASE/$1/[A-Za-z0-9_-]*" "$ROOT"/storage/logs/mail-*.log | tail -1 | sed "s#$BASE##"; }
TODAY=$(date +%Y-%m-%d); MES=$(date +%Y-%m)
CRON_TOKEN=$(grep "^CRON_TOKEN=" "$ROOT/.env" | cut -d= -f2)

echo "== Cabeçalhos de segurança"
curl -s -D "$TMP/h" -o /dev/null "$BASE/entrar"; H="$(cat "$TMP/h")"
contains "CSP presente" "$H" "Content-Security-Policy:"
lacks "script-src sem host do CDN (só self + nonce)" "$(grep -i "content-security-policy" "$TMP/h" | sed 's/;.*style-src.*//')" "cdn.jsdelivr.net"
contains "style-src ainda permite o CDN (Bootstrap)" "$(grep -i "content-security-policy" "$TMP/h")" "style-src 'self' 'nonce-[A-Za-z0-9_-]*' https://cdn.jsdelivr.net"
lacks "X-Powered-By removido" "$H" "X-Powered-By"
contains "X-Frame-Options DENY" "$H" "X-Frame-Options: DENY"
contains "nosniff" "$H" "X-Content-Type-Options: nosniff"

echo "== /saude e /offline sem vazar configuração ou dados"
# Sem APP_DEBUG (como em produção): detalhes só com o token ou para admin
DEBUG_LINE=$(grep "^APP_DEBUG=" "$ROOT/.env"); sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' "$ROOT/.env"; trap 'sed -i "s/^APP_DEBUG=.*/$DEBUG_LINE/" "$ROOT/.env"' EXIT
get "$TMP/anon" "/saude"; lacks "anônimo não vê detalhes técnicos" "$(cat "$TMP/body")" "APP_URL="
get "$TMP/anon" "/saude?token=$CRON_TOKEN"; contains "com CRON_TOKEN vê os detalhes" "$(cat "$TMP/body")" "valor esperado é APP_URL="
sed -i "s/^APP_DEBUG=.*/$DEBUG_LINE/" "$ROOT/.env"

echo "== Login (Flávio responsável, Priscila membro)"
post "$JAR" "/entrar" -d "email=flavio@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR" "/conta/2fa"; SECRET=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR" "/conta/2fa/ativar" -d "code=$(totp "$SECRET")"; get "$JAR" "/painel"; check "Flávio logado" "200" "$CODE"
post "$JAR2" "/entrar" -d "email=priscila@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR2" "/conta/2fa"; SECRET2=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR2" "/conta/2fa/ativar" -d "code=$(totp "$SECRET2")"; get "$JAR2" "/painel"; check "Priscila logada" "200" "$CODE"
get "$JAR" "/offline"; B="$(cat "$TMP/body")"; check "offline 200" "200" "$CODE"
lacks "offline não traz o nome do usuário" "$B" "Flávio"; lacks "offline sem sino de avisos" "$B" "data-alerts-bell"; lacks "offline sem token CSRF" "$B" 'name="csrf-token"'

echo "== Isolamento: lançamento privado e 'compartilhar com o lar'"
post "$JAR2" "/lancamentos/novo" -d "type=expense" -d "amount=50,00" -d "date=$TODAY" -d "description=Presente surpresa" -d "account_id=1" -d "category_id=110" -d "responsible_user_id=2" -d "status=paid" -d "is_private=1" -d "tags=segredo"
post "$JAR2" "/lancamentos/novo" -d "type=expense" -d "amount=30,00" -d "date=$TODAY" -d "description=Salao da Pri" -d "account_id=1" -d "category_id=110" -d "responsible_user_id=2" -d "status=paid"
PRIV=$(sql "SELECT id FROM transactions WHERE description='Presente surpresa'"); PUB=$(sql "SELECT id FROM transactions WHERE description='Salao da Pri'")
[ -n "$PRIV" ] && [ -n "$PUB" ] && ok "lançamentos criados ($PRIV privado, $PUB comum)" || fail "lançamentos criados"
get "$JAR" "/lancamentos?busca=Presente"; lacks "busca do responsável não acha o privado da Priscila" "$(cat "$TMP/body")" "Presente surpresa"
get "$JAR" "/lancamentos?tag=segredo"; lacks "filtro por etiqueta não revela o privado" "$(cat "$TMP/body")" "Presente surpresa"
get "$JAR" "/lancamentos?busca=Salao"; contains "lançamento comum da Priscila aparece para o responsável" "$(cat "$TMP/body")" "Salao da Pri"
get "$JAR" "/lancamentos"; contains "privado aparece mascarado na lista" "$(cat "$TMP/body")" "Lançamento privado"
get "$JAR" "/painel"; contains "painel agrupa o privado em 'Lançamentos privados'" "$(cat "$TMP/body")" "Lançamentos privados"
get "$JAR" "/relatorios/mensal?mes=$MES&formato=csv"; contains "CSV mensal agrupa privados sem revelar categoria" "$(cat "$TMP/body")" "Lançamentos privados"
get "$JAR" "/relatorios/categoria?categoria=110&mes=$MES"; lacks "relatório da categoria não lista o privado de outro" "$(cat "$TMP/body")" "Presente surpresa"
post "$JAR" "/lancamentos/$PRIV/editar" -d "type=expense" -d "amount=1,00" -d "date=$TODAY" -d "description=Hackeado" -d "account_id=1" -d "status=paid"; check "responsável não edita o privado de outro (403)" "403" "$CODE"
# Priscila desliga "compartilhar com o lar": tudo dela vira privado para os outros
post "$JAR2" "/conta/privacidade/consentimentos" -d "consent_push=0"; check "consentimentos salvos" "/conta/privacidade" "$LOC"
check "share_with_household revogado" "0" "$(sql "SELECT granted FROM consents WHERE user_id=2 AND kind='share_with_household' ORDER BY id DESC LIMIT 1")"
get "$JAR" "/lancamentos?busca=Salao"; lacks "sem compartilhar: busca não acha o lançamento comum dela" "$(cat "$TMP/body")" "Salao da Pri"
get "$JAR" "/lancamentos"; contains "sem compartilhar: lista mascara" "$(cat "$TMP/body")" "Lançamento privado"
get "$JAR2" "/lancamentos?busca=Salao"; contains "a própria Priscila continua vendo tudo" "$(cat "$TMP/body")" "Salao da Pri"
post "$JAR2" "/conta/privacidade/consentimentos" -d "consent_share_with_household=1"
get "$JAR" "/lancamentos?busca=Salao"; contains "compartilhar de novo: volta a aparecer" "$(cat "$TMP/body")" "Salao da Pri"

echo "== Validações de pertencimento (responsável, modelo, lote)"
post "$JAR" "/lancamentos/lote" -d "action=responsible" -d "value=999" -d "ids[]=$PUB"; check "lote com responsável de fora do lar → 422" "422" "$CODE"
post "$JAR2" "/lancamentos/$PUB/modelo" -d "name=Modelo da Pri"; TPL=$(sql "SELECT id FROM transaction_templates WHERE name='Modelo da Pri'")
get "$JAR" "/lancamentos/novo?modelo=$TPL"; lacks "modelo de outro usuário não pré-preenche" "$(cat "$TMP/body")" 'value="Salao da Pri"'
get "$JAR2" "/lancamentos/novo?modelo=$TPL"; contains "modelo do próprio usuário pré-preenche" "$(cat "$TMP/body")" 'value="Salao da Pri"'
post "$JAR" "/importar/lotes/999/desfazer"; check "desfazer lote inexistente → 404" "404" "$CODE"

echo "== CSV sem injeção de fórmula"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=5,00" -d "date=$TODAY" -d "description==HYPERLINK(\"http://x\")" -d "account_id=1" -d "category_id=110" -d "status=paid"
get "$JAR" "/relatorios/conta?conta=1&mes=$MES&formato=csv"; contains "célula que começa com = ganha apóstrofo" "$(cat "$TMP/body")" "'=HYPERLINK"
contains "número negativo continua número" "$(cat "$TMP/body")" ";-30,00;"; lacks "sem apóstrofo em número" "$(cat "$TMP/body")" "'-"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=7,00" -d "date=$TODAY" --data-urlencode "description=-2+3|cmd" -d "account_id=1" -d "category_id=110" -d "status=paid"
get "$JAR" "/relatorios/conta?conta=1&mes=$MES&formato=csv"; contains "texto que começa com - ganha apóstrofo" "$(cat "$TMP/body")" "'-2+3|cmd"
post "$JAR" "/conta/privacidade/exportar"; sleep 1; EXP=$(sql "SELECT file_path FROM data_exports WHERE user_id=1 ORDER BY id DESC LIMIT 1")
if [ -n "$EXP" ] && [ -f "$ROOT/storage/exports/$EXP" ]; then unzip -p "$ROOT/storage/exports/$EXP" lancamentos.csv > "$TMP/exp.csv" 2>/dev/null; contains "exportação LGPD também neutraliza fórmulas" "$(cat "$TMP/exp.csv")" "'=HYPERLINK"; else fail "exportação LGPD gerada" "$EXP"; fi

echo "== Comprovante em PDF é baixado, imagem abre na tela"
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n160\n%%%%EOF\n' > "$TMP/nota.pdf"
postf "$JAR" "/lancamentos/$PUB/editar" -F "type=expense" -F "amount=30,00" -F "date=$TODAY" -F "description=Salao da Pri" -F "account_id=1" -F "category_id=110" -F "status=paid" -F "attachment=@$TMP/nota.pdf;type=application/pdf"
curl -s -b "$JAR" -D "$TMP/h" -o /dev/null "$BASE/lancamentos/$PUB/anexo"; contains "PDF: Content-Disposition attachment" "$(cat "$TMP/h")" "Content-Disposition: attachment"

echo "== CAPTCHA por conta sem trancar o dono; CAPTCHA de uso único"
for i in 1 2 3 4; do sql "INSERT INTO login_attempts (kind, ip, email, succeeded, user_agent, created_at) VALUES ('login','198.51.100.$i','priscila@exemplo.test',0,'atacante',UTC_TIMESTAMP())"; done
post "$JAR3" "/entrar" -d "email=priscila@exemplo.test" -d "password=errada"; check "falha → volta ao login" "/entrar" "$LOC"
get "$JAR3" "/entrar"; B="$(cat "$TMP/body")"; contains "CAPTCHA aparece (falhas de outros IPs contra a conta)" "$B" "Quanto é"; lacks "mas a conta NÃO está bloqueada" "$B" "Muitas tentativas"
Q=$(grep -o 'Quanto é [0-9]* [+-] [0-9]*' <<<"$B" | head -1); A=$(( $(echo "$Q" | sed 's/Quanto é //') )); TOK=$(grep -o 'name="captcha_token" value="[^"]*' <<<"$B" | sed 's/.*value="//')
post "$JAR3" "/entrar" -d "email=priscila@exemplo.test" -d "password=Cofre@2026teste" -d "captcha_answer=$A" --data-urlencode "captcha_token=$TOK"; check "dona entra com senha + CAPTCHA (segue para o 2FA)" "/entrar/verificacao" "$LOC"
post "$JAR3" "/sair"; sql "DELETE FROM login_attempts WHERE email='priscila@exemplo.test' AND succeeded=0"
for i in 1 2 3; do post "$JAR3" "/entrar" -d "email=priscila@exemplo.test" -d "password=errada$i"; done
get "$JAR3" "/entrar"; B="$(cat "$TMP/body")"; contains "CAPTCHA após 3 falhas do mesmo IP" "$B" "Quanto é"
Q=$(grep -o 'Quanto é [0-9]* [+-] [0-9]*' <<<"$B" | head -1); A=$(( $(echo "$Q" | sed 's/Quanto é //') )); TOK=$(grep -o 'name="captcha_token" value="[^"]*' <<<"$B" | sed 's/.*value="//')
post "$JAR3" "/entrar" -d "email=priscila@exemplo.test" -d "password=errada4" -d "captcha_answer=$A" --data-urlencode "captcha_token=$TOK"; check "CAPTCHA certo + senha errada → volta" "/entrar" "$LOC"
post "$JAR3" "/entrar" -d "email=priscila@exemplo.test" -d "password=Cofre@2026teste" -d "captcha_answer=$A" --data-urlencode "captcha_token=$TOK"; check "mesmo desafio reutilizado não vale" "/entrar" "$LOC"
get "$JAR3" "/entrar"; contains "mensagem pede nova verificação" "$(cat "$TMP/body")" "Resolva a conta"
sql "DELETE FROM login_attempts WHERE email='priscila@exemplo.test' AND succeeded=0"

echo "== Sessões e tokens de uso único"
post "$JAR" "/conta/sessoes/encerrar"; get "$JAR" "/conta/sessoes"; contains "encerrar outras sessões exige senha" "$(cat "$TMP/body")" "obrigatório"
post "$JAR" "/conta/sessoes/encerrar" -d "password=Cofre@2026teste"; get "$JAR" "/conta/sessoes"; contains "com a senha, encerra" "$(cat "$TMP/body")" "Outras sessões encerradas"
post "$TMP/r" "/esqueci-senha" -d "email=priscila@exemplo.test"; R1=$(maillink "redefinir-senha")
post "$TMP/r" "/esqueci-senha" -d "email=priscila@exemplo.test"; R2=$(maillink "redefinir-senha")
get "$TMP/r" "$R1"; check "pedido novo invalida o link anterior" "/esqueci-senha" "$LOC"
get "$TMP/r" "$R2"; check "link mais recente vale" "200" "$CODE"
check "corpo dos e-mails enviados não fica guardado" "0" "$(sql "SELECT COUNT(*) FROM email_outbox WHERE status='sent' AND body_html<>''")"
post "$JAR" "/avisos/novos" >/dev/null 2>&1; get "$JAR" "/avisos/novos"; check "polling de avisos responde" "200" "$CODE"

echo; echo "$PASS passaram, $FAIL falharam."; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
