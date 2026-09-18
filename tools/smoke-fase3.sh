#!/usr/bin/env bash
# tools/smoke-fase3.sh — teste de ponta a ponta da fase 3 (LGPD) contra o servidor local (MAIL_DRIVER=log).
# Pré-requisitos: banco limpo (schema + seed), .env com ADMIN_EMAILS=ana@exemplo.test, e tools/smoke-fase2.sh já executado
# (usa as contas ana@exemplo.test / NovaSenha@2026 [2FA] e bia@exemplo.test / Redefinida#2026 [membro do lar]).
set -u
BASE="${BASE:-http://127.0.0.1:8080/cofre}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; JAR="$TMP/jar"; JAR2="$TMP/jar2"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ✔ $1"; }
fail() { FAIL=$((FAIL+1)); echo "  ✘ $1"; [ -n "${2:-}" ] && echo "      $2"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1" "esperado '$2', obtido '$3'"; fi; }
contains() { if grep -q -- "$3" <<<"$2"; then ok "$1"; else fail "$1" "'$3' não encontrado"; fi; }
csrf() { curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="csrf-token" content="[^"]*' | sed 's/.*content="//'; }
post() { local jar="$1" path="$2"; shift 2; local token; token=$(csrf "$jar" "$path"); [ -z "$token" ] && token=$(csrf "$jar" "/entrar")
  curl -s -b "$jar" -c "$jar" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" -X POST "$BASE$path" --data-urlencode "_token=$token" "$@" > "$TMP/meta"
  CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); }
get() { curl -s -b "$1" -c "$1" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" "$BASE$2" > "$TMP/meta"; CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); }
maillink() { grep -o "$BASE/$1/[A-Za-z0-9_-]*" "$ROOT"/storage/logs/mail-*.log | tail -1 | sed "s#$BASE##"; }
totp() { php -r 'require "'"$ROOT"'/app/bootstrap.php"; echo App\Core\Totp::code($argv[1]);' "$1"; }
SECRET=$(php -r 'require "'"$ROOT"'/app/bootstrap.php"; $u = App\Core\Database::selectOne("SELECT totp_secret FROM users WHERE email = ?", ["ana@exemplo.test"]); echo App\Core\Crypto::decrypt($u["totp_secret"]);')

echo "== Login das duas contas"
sleep $(( 31 - $(date +%s) % 30 ))   # garante janela TOTP nova em relação ao último login da fase 2
post "$JAR" "/entrar" -d "email=ana@exemplo.test" -d "password=NovaSenha@2026"; post "$JAR" "/entrar/verificacao" -d "code=$(totp "$SECRET")"; check "Ana (responsável, 2FA) logada" "/painel" "$LOC"
post "$JAR2" "/entrar" -d "email=bia@exemplo.test" -d "password=Redefinida#2026"; check "Bia (membro) logada" "/painel" "$LOC"

echo "== Página de privacidade e consentimentos"
get "$JAR" "/conta/privacidade"; check "GET /conta/privacidade 200" "200" "$CODE"; contains "mostra controlador" "$(cat "$TMP/body")" "Encarregado"
contains "mostra versão aceita dos termos" "$(cat "$TMP/body")" "você aceitou a v1.0"
post "$JAR" "/conta/privacidade/consentimentos" -d "consent_push=1" -d "consent_digest_email=1" -d "consent_share_with_household=1"
get "$JAR" "/conta/privacidade"; contains "push ligado" "$(cat "$TMP/body")" 'name="consent_push" value="1" checked'
post "$JAR" "/conta/privacidade/desligar-avisos"; get "$JAR" "/conta/privacidade"
if grep -q 'name="consent_push" value="1" checked' "$TMP/body"; then fail "desligar todos os avisos"; else ok "desligar todos os avisos"; fi
get "$JAR" "/conta/privacidade/meus-dados"; check "meus dados 200" "200" "$CODE"; contains "meus dados lista consentimentos" "$(cat "$TMP/body")" "Consentimentos (histórico completo)"

echo "== Exportação"
post "$JAR" "/conta/privacidade/exportar"; contains "exportação redireciona para o link" "$LOC" "/conta/privacidade/exportacoes/"
TOKEN=$(echo "$LOC" | sed 's#.*/exportacoes/##; s#?.*##')
curl -s -b "$JAR" -c "$JAR" -o "$TMP/export.zip" -w "%{http_code} %{content_type}" "$BASE/conta/privacidade/exportacoes/$TOKEN" > "$TMP/meta"; contains "download é um zip" "$(cat "$TMP/meta")" "application/zip"
unzip -l "$TMP/export.zip" 2>/dev/null | grep -q "meus-dados.json" && ok "zip contém meus-dados.json" || fail "zip contém meus-dados.json"
curl -s -b "$JAR2" -o /dev/null -w "%{http_code}" "$BASE/conta/privacidade/exportacoes/$TOKEN" > "$TMP/meta"; check "outro usuário não baixa (404)" "404" "$(cat "$TMP/meta")"
[ -n "$(maillink 'conta/privacidade/exportacoes')" ] && ok "e-mail com link da exportação" || fail "e-mail com link da exportação"

echo "== Troca de e-mail"
post "$JAR2" "/conta/privacidade/email" -d "email=bia2@exemplo.test" -d "password=Redefinida#2026"; check "pedido de troca → privacidade" "/conta/privacidade" "$LOC"
LINK=$(maillink "confirmar-email"); get "$JAR2" "$LINK"; check "confirmação do novo e-mail" "/painel" "$LOC"
check "e-mail trocado no banco" "bia2@exemplo.test" "$(mysql -uroot -N nosso_cofre -e "SELECT email FROM users WHERE name='Bia Teste'")"
grep -q "Pedido de troca de e-mail" "$ROOT"/storage/logs/mail-*.log && ok "aviso enviado ao e-mail antigo" || fail "aviso enviado ao e-mail antigo"

echo "== Exclusão de conta: responsável travado, membro agenda e cancela"
sleep $(( 31 - $(date +%s) % 30 ))   # o código TOTP do login não vale de novo na mesma janela
post "$JAR" "/conta/privacidade/excluir-conta" -d "password=NovaSenha@2026" -d "code=$(totp "$SECRET")"; get "$JAR" "/conta/privacidade"
contains "responsável com membros é travado" "$(cat "$TMP/body")" "Transfira a responsabilidade"
post "$JAR2" "/conta/privacidade/excluir-conta" -d "password=errada"; get "$JAR2" "/conta/privacidade"; contains "senha errada é recusada" "$(cat "$TMP/body")" "Senha incorreta"
post "$JAR2" "/conta/privacidade/excluir-conta" -d "password=Redefinida#2026"; get "$JAR2" "/conta/privacidade"; contains "exclusão agendada" "$(cat "$TMP/body")" "Exclusão da conta agendada"
get "$JAR2" "/painel"; contains "faixa de aviso no painel" "$(cat "$TMP/body")" "exclusão da sua conta está agendada"
grep -q "Exclusão da sua conta agendada" "$ROOT"/storage/logs/mail-*.log && ok "e-mail de exclusão agendada" || fail "e-mail de exclusão agendada"
post "$JAR2" "/conta/privacidade/excluir-conta/cancelar"; get "$JAR2" "/conta/privacidade"
if grep -q "Exclusão da conta agendada" "$TMP/body"; then fail "cancelamento da exclusão"; else ok "cancelamento da exclusão"; fi

echo "== Exclusão do lar (responsável) e cancelamento"
post "$JAR" "/conta/privacidade/excluir-lar" -d "password=NovaSenha@2026" -d "code=$(sleep $(( 31 - $(date +%s) % 30 )); totp "$SECRET")"; get "$JAR" "/conta/privacidade"
contains "exclusão do lar agendada" "$(cat "$TMP/body")" "Exclusão do lar agendada"
grep -q 'Exclusão do lar "Família Teste" agendada' "$ROOT"/storage/logs/mail-*.log && ok "membros avisados por e-mail" || fail "membros avisados por e-mail"
post "$JAR" "/conta/privacidade/excluir-lar/cancelar"; get "$JAR" "/conta/privacidade"
if grep -q "Exclusão do lar agendada" "$TMP/body"; then fail "cancelamento da exclusão do lar"; else ok "cancelamento da exclusão do lar"; fi

echo "== Incidentes (área do controlador)"
get "$JAR2" "/admin/incidentes"; check "não-admin recebe 404" "404" "$CODE"
get "$JAR" "/admin/incidentes"; check "admin acessa" "200" "$CODE"
post "$JAR" "/admin/incidentes" -d "title=Acesso indevido ao backup" -d "description=Um backup criptografado foi copiado por conta de terceiros; a chave não foi exposta." -d "detected_at=2026-09-18T10:00"
contains "incidente criado" "$LOC" "/admin/incidentes/"
IID=$(basename "$LOC")
post "$JAR" "/admin/incidentes/$IID/comunicar" -d "scope=all" -d "measures=Chaves rotacionadas e senhas do cPanel trocadas." -d "recommendations=Troque sua senha."
get "$JAR" "/admin/incidentes/$IID"; contains "comunicado registrado" "$(cat "$TMP/body")" "Comunicado aos titulares"
N=$(grep -c "Comunicado de segurança" "$ROOT"/storage/logs/mail-*.log); [ "$N" -ge 2 ] && ok "e-mails de incidente enviados ($N)" || fail "e-mails de incidente enviados" "$N"

echo "== Sair do lar levando os dados"
post "$JAR2" "/conta/privacidade/sair-do-lar" -d "take_data=take"; check "saída cria lar individual → painel" "/painel" "$LOC"
get "$JAR2" "/painel"; contains "painel do novo lar individual" "$(cat "$TMP/body")" "Bia Teste"
get "$JAR" "/familia"; if grep -q "Bia Teste" "$TMP/body"; then fail "Bia saiu da família"; else ok "Bia saiu da família"; fi

echo "== Anonimização"
post "$JAR2" "/conta/privacidade/anonimizar" -d "password=Redefinida#2026"; check "anonimização desloga → home" "/" "$LOC"
check "usuária anonimizada no banco" "Membro removido" "$(mysql -uroot -N nosso_cofre -e "SELECT name FROM users WHERE email LIKE 'removido-%@anonimizado.invalid' LIMIT 1")"
post "$JAR2" "/entrar" -d "email=bia2@exemplo.test" -d "password=Redefinida#2026"; check "não entra mais" "/entrar" "$LOC"

echo "== Cron: retenção"
OUT=$(php "$ROOT/cron/run.php"); contains "cron roda retenção" "$OUT" "retenção:"

echo; echo "$PASS passaram, $FAIL falharam."; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
