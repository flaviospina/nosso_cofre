#!/usr/bin/env bash
# tools/smoke-fase2.sh — teste de ponta a ponta da fase 2 contra o servidor local (MAIL_DRIVER=log).
# Uso: php -S 127.0.0.1:8080 tools/dev-server.php &   e depois   bash tools/smoke-fase2.sh
# Pré-requisito: banco limpo (sql/schema.sql + sql/seed.sql) e .env apontando para ele.
set -u
BASE="${BASE:-http://127.0.0.1:8080/cofre}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
JAR="$TMP/jar"; JAR2="$TMP/jar2"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ✔ $1"; }
fail() { FAIL=$((FAIL+1)); echo "  ✘ $1"; [ -n "${2:-}" ] && echo "      $2"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1" "esperado '$2', obtido '$3'"; fi; }
contains() { if grep -q -- "$3" <<<"$2"; then ok "$1"; else fail "$1" "'$3' não encontrado"; fi; }
csrf() { curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="csrf-token" content="[^"]*' | sed 's/.*content="//'; }
post() { # post JAR path [dados curl...] → CODE, LOC e corpo em $TMP/body
  local jar="$1" path="$2"; shift 2
  local token; token=$(csrf "$jar" "$path")
  [ -z "$token" ] && token=$(csrf "$jar" "/entrar")
  curl -s -b "$jar" -c "$jar" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" -X POST "$BASE$path" --data-urlencode "_token=$token" "$@" > "$TMP/meta"
  CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); }
get() { curl -s -b "$1" -c "$1" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" "$BASE$2" > "$TMP/meta"; CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); }
sql() { mysql -uroot -N --default-character-set=utf8mb4 nosso_cofre -e "$1"; }
maillink() { grep -o "$BASE/$1/[A-Za-z0-9_-]*" "$ROOT"/storage/logs/mail-*.log | tail -1 | sed "s#$BASE##"; }
totp() { php -r 'require "'"$ROOT"'/app/bootstrap.php"; echo App\Core\Totp::code($argv[1]);' "$1"; }

echo "== Cadastro"
get "$JAR" "/cadastro"; check "GET /cadastro 200" "200" "$CODE"
post "$JAR" "/cadastro" -d "name=Ana Teste" -d "email=ana@exemplo.test" -d "password=Segura@2026x" -d "password_confirmation=Segura@2026x" -d "terms=1" -d "adult=1"
check "POST /cadastro redireciona para confirmação" "/confirmar-email" "$LOC"
post "$JAR" "/cadastro" -d "name=Ana Teste" -d "email=ana@exemplo.test" -d "password=Segura@2026x" -d "password_confirmation=Segura@2026x" -d "terms=1" -d "adult=1"
check "e-mail duplicado: mesma resposta (sem revelar que existe)" "/confirmar-email" "$LOC"
check "e-mail duplicado não cria segunda conta" "1" "$(sql "SELECT COUNT(*) FROM users WHERE email='ana@exemplo.test'")"
post "$JAR" "/cadastro" -d "name=X" -d "email=x@exemplo.test" -d "password=password12345" -d "password_confirmation=password12345" -d "terms=1" -d "adult=1"
get "$JAR" "/cadastro"; contains "senha comum é recusada" "$(cat "$TMP/body")" "muito comum"

echo "== Login antes de confirmar"
post "$JAR" "/entrar" -d "email=ana@exemplo.test" -d "password=Segura@2026x"
check "login sem confirmação volta para o aviso" "/confirmar-email" "$LOC"

echo "== Confirmação de e-mail"
LINK=$(maillink "confirmar-email"); [ -n "$LINK" ] && ok "link de confirmação no e-mail" || fail "link de confirmação no e-mail"
get "$JAR" "$LINK"; check "confirmar → /entrar" "/entrar" "$LOC"
get "$JAR" "$LINK"; check "link reutilizado é recusado" "/confirmar-email" "$LOC"

echo "== Rate limit e CAPTCHA"
for i in 1 2 3; do post "$JAR" "/entrar" -d "email=ana@exemplo.test" -d "password=errada$i"; done
get "$JAR" "/entrar"; contains "CAPTCHA aparece após 3 falhas" "$(cat "$TMP/body")" "Quanto é"
Q=$(grep -o 'Quanto é [0-9]* [+-] [0-9]*' "$TMP/body" | head -1); A=$(( $(echo "$Q" | sed 's/Quanto é //') ))
TOK=$(grep -o 'name="captcha_token" value="[^"]*' "$TMP/body" | sed 's/.*value="//')
post "$JAR" "/entrar" -d "email=ana@exemplo.test" -d "password=Segura@2026x" -d "captcha_answer=999" --data-urlencode "captcha_token=$TOK"
check "CAPTCHA errado não entra" "/entrar" "$LOC"
get "$JAR" "/entrar"; Q=$(grep -o 'Quanto é [0-9]* [+-] [0-9]*' "$TMP/body" | head -1); A=$(( $(echo "$Q" | sed 's/Quanto é //') )); TOK=$(grep -o 'name="captcha_token" value="[^"]*' "$TMP/body" | sed 's/.*value="//')
post "$JAR" "/entrar" -d "email=ana@exemplo.test" -d "password=Segura@2026x" -d "captcha_answer=$A" --data-urlencode "captcha_token=$TOK" -d "remember=1"
check "login correto com CAPTCHA → onboarding" "/inicio" "$LOC"
grep -q "nc_remember" "$JAR" && ok "cookie lembrar-me emitido" || fail "cookie lembrar-me emitido"

echo "== Onboarding familiar"
post "$JAR" "/inicio" -d "type=family" -d "name=Família Teste" -d "emails=bia@exemplo.test"
check "passo 1 → configuração" "/inicio/configuracao" "$LOC"
INVITE=$(maillink "convite"); [ -n "$INVITE" ] && ok "convite enviado por e-mail" || fail "convite enviado por e-mail"
post "$JAR" "/inicio/configuracao" -d "currency=BRL" -d "fiscal_month_start_day=5" -d "accounts[]=checking" -d "accounts[]=credit_card" -d "custom_accounts=Nubank" -d "income=8.500,00"
check "passo 2 → avisos" "/inicio/avisos" "$LOC"
post "$JAR" "/inicio/avisos" -d "email_digest=1"
check "passo 3 → painel" "/painel" "$LOC"
get "$JAR" "/painel"; check "painel exige 2FA do responsável" "/conta/2fa" "$LOC"

echo "== Ativação do 2FA"
get "$JAR" "/conta/2fa"; check "GET /conta/2fa 200" "200" "$CODE"
SECRET=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
[ ${#SECRET} -eq 32 ] && ok "segredo TOTP exibido" || fail "segredo TOTP exibido" "$SECRET"
post "$JAR" "/conta/2fa/ativar" -d "code=000000"; get "$JAR" "/conta/2fa"; contains "código errado é recusado" "$(cat "$TMP/body")" "Código inválido"
post "$JAR" "/conta/2fa/ativar" -d "code=$(totp "$SECRET")"; get "$JAR" "/conta/2fa"
contains "2FA ativado com códigos de recuperação" "$(cat "$TMP/body")" "Códigos de recuperação"
RECOVERY=$(grep -A12 'id="recoveryCodes"' "$TMP/body" | grep -o '[a-z0-9]\{5\}-[a-z0-9]\{5\}' | head -1)
get "$JAR" "/painel"; check "painel abre após 2FA" "200" "$CODE"; contains "painel mostra o lar" "$(cat "$TMP/body")" "Família Teste"
get "$JAR" "/familia"; contains "família lista o convite pendente" "$(cat "$TMP/body")" "bia@exemplo.test"

echo "== Logout e login com 2FA"
post "$JAR" "/sair"; check "logout → home" "/" "$LOC"
get "$JAR" "/painel"; check "painel exige login" "/entrar" "$LOC"
post "$JAR" "/entrar" -d "email=ana@exemplo.test" -d "password=Segura@2026x"
check "senha certa → verificação" "/entrar/verificacao" "$LOC"
post "$JAR" "/entrar/verificacao" -d "code=$(totp "$SECRET")"
check "código TOTP → painel" "/painel" "$LOC"
post "$JAR" "/sair"
post "$JAR" "/entrar" -d "email=ana@exemplo.test" -d "password=Segura@2026x"
post "$JAR" "/entrar/verificacao" -d "code=$RECOVERY"
check "código de recuperação → painel" "/painel" "$LOC"
post "$JAR" "/sair"
post "$JAR" "/entrar" -d "email=ana@exemplo.test" -d "password=Segura@2026x"
post "$JAR" "/entrar/verificacao" -d "code=$RECOVERY"
check "código de recuperação não vale duas vezes" "/entrar/verificacao" "$LOC"
# Anti-reuso: o código da janela atual já foi consumido no login anterior; espera a próxima janela de 30 s
sleep $(( 31 - $(date +%s) % 30 ))
post "$JAR" "/entrar/verificacao" -d "code=$(totp "$SECRET")"
check "código TOTP da janela seguinte entra" "/painel" "$LOC"

echo "== Segundo usuário pelo convite"
get "$JAR2" "$INVITE"; check "página do convite 200" "200" "$CODE"; contains "convite mostra o lar" "$(cat "$TMP/body")" "Família Teste"
post "$JAR2" "/cadastro" -d "name=Bia Teste" -d "email=bia@exemplo.test" -d "password=OutraSenha#77" -d "password_confirmation=OutraSenha#77" -d "terms=1" -d "adult=1"
LINK2=$(maillink "confirmar-email"); get "$JAR2" "$LINK2"
post "$JAR2" "/entrar" -d "email=bia@exemplo.test" -d "password=OutraSenha#77"
check "convidada entra direto no painel (convite aceito na confirmação)" "/painel" "$LOC"
get "$JAR2" "/painel"; contains "convidada vê o lar" "$(cat "$TMP/body")" "Família Teste"
get "$JAR2" "/familia"; contains "convidada é membro" "$(cat "$TMP/body")" "Bia Teste"
post "$JAR2" "/familia/convidar" -d "email=z@exemplo.test" -d "role=member"; check "membro não pode convidar (403)" "403" "$CODE"

echo "== Papéis (responsável)"
get "$JAR" "/familia"; MID=$(grep -o 'familia/membros/[0-9]*/papel' "$TMP/body" | head -1 | grep -o '[0-9]*')
post "$JAR" "/familia/membros/$MID/papel" -d "role=viewer"; get "$JAR" "/familia"; contains "papel alterado para somente leitura" "$(cat "$TMP/body")" 'value="viewer" selected'

echo "== Conta: perfil, sessões, atividade, senha"
post "$JAR" "/conta" -d "name=Ana Teste Silva" -d "color=#198754" -d "timezone=America/Sao_Paulo" -d "session_idle_minutes=60"
get "$JAR" "/conta"; contains "perfil salvo" "$(cat "$TMP/body")" 'value="Ana Teste Silva"'
get "$JAR" "/conta/sessoes"; contains "sessão atual listada" "$(cat "$TMP/body")" "esta sessão"
get "$JAR" "/conta/atividade"; contains "atividade registra login" "$(cat "$TMP/body")" "Entrou no sistema"
post "$JAR" "/conta/senha" -d "current_password=Segura@2026x" -d "password=NovaSenha@2026" -d "password_confirmation=NovaSenha@2026"
check "troca de senha ok" "/conta" "$LOC"

echo "== Recuperação de senha"
post "$JAR2" "/sair"
post "$JAR2" "/esqueci-senha" -d "email=bia@exemplo.test"; check "pedido → /entrar" "/entrar" "$LOC"
RESET=$(maillink "redefinir-senha"); [ -n "$RESET" ] && ok "link de redefinição no e-mail" || fail "link de redefinição no e-mail"
TOKEN=$(basename "$RESET")
post "$JAR2" "/redefinir-senha" --data-urlencode "token=$TOKEN" -d "password=Redefinida#2026" -d "password_confirmation=Redefinida#2026"
check "senha redefinida → /entrar" "/entrar" "$LOC"
post "$JAR2" "/entrar" -d "email=bia@exemplo.test" -d "password=Redefinida#2026"; check "login com a senha nova" "/painel" "$LOC"

echo "== Documentos legais e migração"
get "$JAR" "/privacidade"; contains "política renderiza com o controlador" "$(cat "$TMP/body")" "Spina"
get "$JAR" "/sistema/migrar"; check "migração sem token → 403" "403" "$CODE"

echo; echo "$PASS passaram, $FAIL falharam."; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
