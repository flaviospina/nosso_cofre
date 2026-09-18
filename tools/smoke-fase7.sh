#!/usr/bin/env bash
# tools/smoke-fase7.sh — teste de ponta a ponta da fase 7 (notificações, push, central de avisos, previsão de caixa, cron, backup).
# Pré-requisitos: banco limpo (schema + seed), servidor em $BASE, .env com MAIL_DRIVER=log, BACKUP_KEY, VAPID_* e CRON_TOKEN.
set -u
BASE="${BASE:-http://127.0.0.1:8080/cofre}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; JAR="$TMP/jar"; JAR2="$TMP/jar2"
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
postjson() { local jar="$1" path="$2" json="$3"; local token; token=$(csrf "$jar" "/painel")
  curl -s -b "$jar" -c "$jar" -o "$TMP/body" -w "%{http_code}" -X POST "$BASE$path" -H "Content-Type: application/json" -H "Accept: application/json" -H "X-CSRF-Token: $token" -H "X-Requested-With: XMLHttpRequest" --data "$json" > "$TMP/meta"; CODE=$(cat "$TMP/meta"); }
get() { curl -s -b "$1" -c "$1" -o "$TMP/body" -w "%{http_code}|%{redirect_url}|%{content_type}" "$BASE$2" > "$TMP/meta"; CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); CTYPE=$(cut -d'|' -f3 "$TMP/meta"); }
totp() { php -r 'require "'"$ROOT"'/app/bootstrap.php"; echo App\Core\Totp::code($argv[1]);' "$1"; }
sql() { mysql -uroot -N --default-character-set=utf8mb4 nosso_cofre -e "$1"; }
TODAY=$(date +%Y-%m-%d); IN3=$(date -d "+3 days" +%Y-%m-%d); AGO5=$(date -d "-5 days" +%Y-%m-%d); TOMORROW=$(date -d "+1 day" +%Y-%m-%d)
CRON_TOKEN=$(grep "^CRON_TOKEN=" "$ROOT/.env" | cut -d= -f2)

echo "== Login e 2FA"
post "$JAR" "/entrar" -d "email=flavio@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR" "/conta/2fa"; SECRET=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR" "/conta/2fa/ativar" -d "code=$(totp "$SECRET")"; get "$JAR" "/painel"; check "Flávio logado" "200" "$CODE"; contains "sino de avisos na barra" "$(cat "$TMP/body")" "data-alerts-bell"
post "$JAR2" "/entrar" -d "email=priscila@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR2" "/conta/2fa"; SECRET2=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR2" "/conta/2fa/ativar" -d "code=$(totp "$SECRET2")"; get "$JAR2" "/painel"; check "Priscila logada" "200" "$CODE"

echo "== Preferências de aviso"
get "$JAR" "/conta/notificacoes"; check "GET /conta/notificacoes 200" "200" "$CODE"; B="$(cat "$TMP/body")"
contains "tipos de aviso" "$B" "Tipos de aviso"; contains "aparelhos" "$B" "Aparelhos (push)"; contains "pré-visualização" "$B" "Ver como fica"; contains "horário silencioso" "$B" "Horário silencioso"; contains "base legal" "$B" "consentimento"
post "$JAR" "/conta/notificacoes" -d "consent_email=1" -d "consent_push=1" -d "mode=immediate" -d "daily_limit=10" -d "min_amount=0,00" \
  -d "types[due][enabled]=1" -d "types[due][channel]=both" -d "types[due][days][]=3" -d "types[due][days][]=0" -d "types[due][color]=#ca8a04" -d "types[due][sound]=alerta" \
  -d "types[overdue][enabled]=1" -d "types[overdue][channel]=email" -d "types[budget][enabled]=1" -d "types[budget][channel]=app" -d "types[budget][thresholds][]=80" -d "types[budget][thresholds][]=100" \
  -d "types[income][enabled]=1" -d "types[income][channel]=app" -d "types[cashflow][enabled]=1" -d "types[cashflow][channel]=email" -d "types[subscription][enabled]=1" -d "types[subscription][channel]=app" \
  -d "types[digest][enabled]=1" -d "types[digest][frequency]=daily" -d "types[digest][time]=00:00" -d "types[digest][channel]=email" -d "types[digest][contents][]=balance" -d "types[digest][contents][]=due" \
  -d "types[member_activity][mode]=all" -d "types[member_activity][channel]=app" -d "vibrate=1" -d "volume=0.5"
check "salvar → volta à tela" "/conta/notificacoes" "$LOC"
check "consentimento de push registrado" "1" "$(sql "SELECT granted FROM consents WHERE user_id=1 AND kind='push' ORDER BY id DESC LIMIT 1")"
check "preferência gravada (due both, dias 3 e 0)" "both|[3, 0]" "$(sql "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(settings,'$.types.due.channel')),'|',JSON_EXTRACT(settings,'$.types.due.days')) FROM notification_settings WHERE user_id=1")"
check "canais sincronizados com os consentimentos" "true|true" "$(sql "SELECT CONCAT(JSON_EXTRACT(settings,'$.channels.email'),'|',JSON_EXTRACT(settings,'$.channels.push')) FROM notification_settings WHERE user_id=1")"
get "$JAR" "/conta/notificacoes"; contains "formulário reflete o salvo" "$(cat "$TMP/body")" 'name="types\[due\]\[enabled\]" value="1" checked'

echo "== Push: chave VAPID e aparelho"
get "$JAR" "/conta/notificacoes/vapid"; contains "chave pública VAPID" "$(cat "$TMP/body")" '"key":"'
P256=$(php -r '$k = openssl_pkey_new(["curve_name"=>"prime256v1","private_key_type"=>OPENSSL_KEYTYPE_EC]); $d = openssl_pkey_get_details($k); echo rtrim(strtr(base64_encode("\x04".str_pad($d["ec"]["x"],32,"\0",STR_PAD_LEFT).str_pad($d["ec"]["y"],32,"\0",STR_PAD_LEFT)),"+/","-_"),"=");')
AUTH=$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(16)),"+/","-_"),"=");')
postjson "$JAR" "/conta/notificacoes/assinar" "{\"subscription\":{\"endpoint\":\"https://push.exemplo.invalid/send/abc123\",\"keys\":{\"p256dh\":\"$P256\",\"auth\":\"$AUTH\"}},\"label\":\"Celular de teste\"}"
contains "assinatura registrada (JSON)" "$(cat "$TMP/body")" '"ok":true'
DEV=$(sql "SELECT id FROM push_subscriptions WHERE user_id=1"); [ -n "$DEV" ] && ok "aparelho no banco (id $DEV)" || fail "aparelho no banco"
get "$JAR" "/conta/notificacoes"; contains "aparelho listado" "$(cat "$TMP/body")" "Celular de teste"
post "$JAR" "/conta/notificacoes/aparelhos/$DEV/testar" -d "type=due"; get "$JAR" "/conta/notificacoes"; contains "teste em endpoint falso falha com aviso" "$(cat "$TMP/body")" "Não foi possível enviar"
check "falha contada (1 de 3)" "1|" "$(sql "SELECT CONCAT(failures,'|',IFNULL(disabled_at,'')) FROM push_subscriptions WHERE id=$DEV")"
postjson "$JAR2" "/conta/notificacoes/assinar" "{\"subscription\":{\"endpoint\":\"https://push.exemplo.invalid/send/xyz\",\"keys\":{\"p256dh\":\"$P256\",\"auth\":\"$AUTH\"}}}"; contains "sem consentimento de push → recusa" "$(cat "$TMP/body")" "consentimento"

echo "== Eventos: contas, orçamento, receita → cron gera os avisos"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=120,00" -d "date=$IN3" -d "description=Água" -d "account_id=1" -d "category_id=103" -d "status=pending"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=80,00" -d "date=$AGO5" -d "description=Luz atrasada" -d "account_id=1" -d "category_id=104" -d "status=pending"
post "$JAR" "/lancamentos/novo" -d "type=income" -d "amount=3.000,00" -d "date=$TODAY" -d "description=Salário" -d "account_id=1" -d "category_id=19"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=350,00" -d "date=$TODAY" -d "description=Padaria" -d "account_id=5" -d "category_id=111"
post "$JAR2" "/lancamentos/novo" -d "type=expense" -d "amount=45,00" -d "date=$TODAY" -d "description=Farmácia" -d "account_id=2" -d "category_id=140" -d "responsible_user_id=2"
OUT=$(php "$ROOT/cron/run.php"); contains "cron roda o agendador" "$OUT" "avisos:"; contains "cron gera backup diário" "$OUT" "backup:"
N=$(sql "SELECT COUNT(*) FROM alerts WHERE user_id=1"); [ "$N" -ge 5 ] && ok "avisos criados para o Flávio ($N)" || fail "avisos criados" "$N"
for T in due overdue budget income member_activity digest; do check "tipo '$T' detectado" "1" "$(sql "SELECT COUNT(*)>0 FROM alerts WHERE user_id=1 AND type='$T'")"; done
check "conta atrasada por e-mail: entregue" "email|1" "$(sql "SELECT CONCAT(channel,'|',sent_at IS NOT NULL) FROM alerts WHERE user_id=1 AND type='overdue' LIMIT 1")"
grep -q "Conta atrasada: Luz atrasada" "$ROOT"/storage/logs/mail-*.log && ok "e-mail de conta atrasada no log de e-mail" || fail "e-mail de conta atrasada"
grep -q "Resumo de" "$ROOT"/storage/logs/mail-*.log && ok "resumo diário por e-mail" || fail "resumo diário por e-mail"
check "orçamento em 80 % só no app (channel none)" "none" "$(sql "SELECT channel FROM alerts WHERE user_id=1 AND type='budget' LIMIT 1")"
check "push contado como tentativa (endpoint falso → falha)" "1" "$(sql "SELECT COUNT(*)>0 FROM alerts WHERE user_id=1 AND type='due' AND channel='both' AND sent_at IS NOT NULL")"
OUT=$(php "$ROOT/cron/run.php"); contains "segunda execução não duplica" "$OUT" "avisos: 0 criado(s)"
check "Priscila (nada ligado) não recebe avisos" "0" "$(sql "SELECT COUNT(*) FROM alerts WHERE user_id=2 AND type<>'security'")"

echo "== Central de avisos"
get "$JAR" "/avisos"; check "GET /avisos 200" "200" "$CODE"; contains "lista o aviso" "$(cat "$TMP/body")" "Luz atrasada"; contains "contador de novos" "$(cat "$TMP/body")" "novo(s)"
get "$JAR" "/avisos?tipo=overdue"; contains "filtro por tipo" "$(cat "$TMP/body")" 'Silenciar "Conta atrasada"'
AID=$(sql "SELECT id FROM alerts WHERE user_id=1 AND type='overdue' LIMIT 1")
post "$JAR" "/avisos/$AID/lida"; check "marcar como lido" "1" "$(sql "SELECT read_at IS NOT NULL FROM alerts WHERE id=$AID")"
get "$JAR" "/avisos/novos?depois=0"; contains "sondagem JSON" "$(cat "$TMP/body")" '"unread":'
post "$JAR" "/avisos/todas-lidas"; check "todos lidos" "0" "$(sql "SELECT COUNT(*) FROM alerts WHERE user_id=1 AND read_at IS NULL")"
post "$JAR" "/avisos/silenciar/due"; check "silenciado por 7 dias" "1" "$(sql "SELECT JSON_EXTRACT(settings,'$.muted_until.due') IS NOT NULL FROM notification_settings WHERE user_id=1")"
get "$JAR" "/avisos"; contains "silenciados listados" "$(cat "$TMP/body")" "Silenciados por 7 dias"
post "$JAR" "/avisos/reativar/due"; check "reativado" "1" "$(sql "SELECT JSON_EXTRACT(settings,'$.muted_until.due') IS NULL FROM notification_settings WHERE user_id=1")"
LUZ=$(sql "SELECT id FROM transactions WHERE household_id=1 AND description='Luz atrasada'")
TOKEN=$(php -r 'require "'"$ROOT"'/app/bootstrap.php"; echo App\Services\AlertService::actionToken(1, (int) $argv[1]);' "$LUZ")
get "$JAR" "/avisos/acao/$TOKEN"; contains "ação assinada → edição do lançamento" "$LOC" "/lancamentos/$LUZ/editar"; check "marcado como pago pela notificação" "paid" "$(sql "SELECT status FROM transactions WHERE id=$LUZ")"
curl -s -o /dev/null -w "%{http_code}" "$BASE/avisos/acao/${TOKEN}x" > "$TMP/meta"; check "token adulterado → 410" "410" "$(cat "$TMP/meta")"

echo "== Horário silencioso adia a entrega"
post "$JAR" "/conta/notificacoes" -d "consent_email=1" -d "consent_push=1" -d "mode=immediate" -d "quiet_enabled=1" -d "quiet_start=00:00" -d "quiet_end=23:59" -d "quiet_days[]=1" -d "quiet_days[]=2" -d "quiet_days[]=3" -d "quiet_days[]=4" -d "quiet_days[]=5" -d "quiet_days[]=6" -d "quiet_days[]=7" -d "types[due][enabled]=1" -d "types[due][channel]=email" -d "types[due][days][]=1"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=60,00" -d "date=$TOMORROW" -d "description=Gás" -d "account_id=1" -d "category_id=105" -d "status=pending"
php "$ROOT/cron/run.php" > /dev/null
check "aviso criado mas adiado (scheduled_for, sem sent_at)" "1|1" "$(sql "SELECT CONCAT(scheduled_for IS NOT NULL,'|',sent_at IS NULL) FROM alerts WHERE user_id=1 AND dedupe_key LIKE 'due:tx:%:d1' LIMIT 1")"
get "$JAR" "/avisos"; contains "central mostra 'aguardando envio'" "$(cat "$TMP/body")" "aguardando envio"

echo "== Previsão de caixa"
get "$JAR" "/previsao"; check "GET /previsao 200" "200" "$CODE"; contains "sobra segura" "$(cat "$TMP/body")" "Sobra segura hoje"; contains "gráfico" "$(cat "$TMP/body")" 'id="chartCashflow"'; contains "dias com movimento (recorrências do seed)" "$(cat "$TMP/body")" "Aluguel"
get "$JAR" "/previsao?dias=30"; check "horizonte 30 dias" "200" "$CODE"

echo "== Segurança: aparelho novo gera aviso"
sleep $(( 31 - $(date +%s) % 30 ))
curl -s -c "$TMP/jar3" -b "$TMP/jar3" -o /dev/null "$BASE/entrar"; T=$(csrf "$TMP/jar3" "/entrar")
curl -s -b "$TMP/jar3" -c "$TMP/jar3" -o /dev/null -A "Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0" -X POST "$BASE/entrar" --data-urlencode "_token=$T" -d "email=priscila@exemplo.test" -d "password=Cofre@2026teste"
T=$(csrf "$TMP/jar3" "/entrar/verificacao"); curl -s -b "$TMP/jar3" -c "$TMP/jar3" -o /dev/null -A "Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0" -X POST "$BASE/entrar/verificacao" --data-urlencode "_token=$T" -d "code=$(totp "$SECRET2")"
check "aviso de segurança na Central da Priscila" "1" "$(sql "SELECT COUNT(*)>0 FROM alerts WHERE user_id=2 AND type='security'")"

echo "== Backup e VAPID"
N=$(ls "$ROOT"/storage/backups/nosso-cofre-*.sql.gz.enc 2>/dev/null | wc -l); [ "$N" -ge 1 ] && ok "arquivo de backup criptografado gerado" || fail "arquivo de backup" "$N"
F=$(ls "$ROOT"/storage/backups/nosso-cofre-*.sql.gz.enc | head -1); php "$ROOT/tools/restaurar-backup.php" "$F" --somente-sql 2>/dev/null | grep -q "CREATE TABLE \`users\`" && ok "restaurar-backup.php --somente-sql decifra o SQL" || fail "restaurar-backup.php"
get "$JAR" "/admin/backups"; check "não-admin não vê backups (404)" "404" "$CODE"
curl -s -o "$TMP/body" -w "%{http_code}" "$BASE/sistema/vapid?token=$CRON_TOKEN" > "$TMP/meta"; check "tela VAPID com token" "200" "$(cat "$TMP/meta")"; contains "chaves geradas" "$(cat "$TMP/body")" "VAPID_PUBLIC_KEY="
curl -s -o /dev/null -w "%{http_code}" "$BASE/sistema/vapid" > "$TMP/meta"; [ "$(cat "$TMP/meta")" != "200" ] && ok "sem token é negado ($(cat "$TMP/meta"))" || fail "sem token é negado"
get "$JAR" "/saude"; contains "saúde: item VAPID" "$(cat "$TMP/body")" "Chaves VAPID configuradas"
get "$JAR" "/assets/sounds/alerta.wav"; check "som servido" "200" "$CODE"

echo "== Logs"
N=$(cat "$ROOT"/storage/logs/app-*.log 2>/dev/null | grep -c "ERROR\|CRITICAL"); check "sem erros no log" "0" "$N"

echo; echo "$PASS passaram, $FAIL falharam."; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
