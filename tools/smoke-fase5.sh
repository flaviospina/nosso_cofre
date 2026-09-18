#!/usr/bin/env bash
# tools/smoke-fase5.sh — teste de ponta a ponta da fase 5 (recorrências, radar, orçamento, metas, plano, simulador).
# Pré-requisitos: banco limpo (sql/schema.sql + sql/seed.sql), servidor em $BASE e .env com MAIL_DRIVER=log.
# Usa o lar do seed ("Família Spina") e ativa o 2FA dos dois membros no começo.
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
get() { curl -s -b "$1" -c "$1" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" "$BASE$2" > "$TMP/meta"; CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); }
totp() { php -r 'require "'"$ROOT"'/app/bootstrap.php"; echo App\Core\Totp::code($argv[1]);' "$1"; }
sql() { mysql -uroot -N --default-character-set=utf8mb4 nosso_cofre -e "$1"; }
TODAY=$(date +%Y-%m-%d); MES=$(date +%Y-%m); NEXTMES=$(date -d "$(date +%Y-%m-01) +1 month" +%Y-%m)
M1=$(date -d "$(date +%Y-%m-01) -1 month" +%Y-%m-12); M2=$(date -d "$(date +%Y-%m-01) -2 month" +%Y-%m-12); M3=$(date -d "$(date +%Y-%m-01) -3 month" +%Y-%m-12)

echo "== Login e 2FA"
post "$JAR" "/entrar" -d "email=flavio@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR" "/conta/2fa"; SECRET=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR" "/conta/2fa/ativar" -d "code=$(totp "$SECRET")"; get "$JAR" "/painel"; check "Flávio logado" "200" "$CODE"
post "$JAR2" "/entrar" -d "email=priscila@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR2" "/conta/2fa"; SECRET2=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR2" "/conta/2fa/ativar" -d "code=$(totp "$SECRET2")"; get "$JAR2" "/painel"; check "Priscila logada" "200" "$CODE"
check "esquema na versão 3" "3" "$(sql "SELECT value FROM settings WHERE \`key\`='schema.version'")"

echo "== Recorrências: geração, confirmação, edição, pausa"
get "$JAR" "/recorrencias"; check "GET /recorrencias 200" "200" "$CODE"; contains "regras do seed listadas" "$(cat "$TMP/body")" "Aluguel"
N=$(sql "SELECT COUNT(*) FROM transactions WHERE household_id=1 AND recurring_id IS NOT NULL AND status='scheduled'"); [ "$N" -ge 20 ] && ok "ocorrências agendadas geradas ao abrir ($N)" || fail "ocorrências agendadas geradas" "$N"
check "cada (regra, data) só uma vez" "0" "$(sql "SELECT COUNT(*) FROM (SELECT recurring_id, date, COUNT(*) c FROM transactions WHERE household_id=1 AND recurring_id IS NOT NULL GROUP BY recurring_id, date HAVING c > 1) x")"
post "$JAR" "/recorrencias/gerar"; get "$JAR" "/recorrencias"; contains "gerar de novo é idempotente" "$(cat "$TMP/body")" "Nada novo para gerar"
contains "lista 'a confirmar' mostra ocorrência" "$(cat "$TMP/body")" "Paguei"
ALUG=$(sql "SELECT id FROM transactions WHERE household_id=1 AND recurring_id=7 AND status='scheduled' ORDER BY date LIMIT 1")
post "$JAR" "/recorrencias/ocorrencias/$ALUG/confirmar" -d "date=$TODAY"; check "confirmar ocorrência → paga" "paid|$TODAY" "$(sql "SELECT CONCAT(status,'|',paid_at) FROM transactions WHERE id=$ALUG")"
PRIME=$(sql "SELECT id FROM transactions WHERE household_id=1 AND recurring_id=16 AND status='scheduled' ORDER BY date LIMIT 1")
post "$JAR" "/recorrencias/ocorrencias/$PRIME/pular"; check "pular ocorrência → lixeira" "1" "$(sql "SELECT deleted_at IS NOT NULL FROM transactions WHERE id=$PRIME")"
post "$JAR" "/recorrencias/nova" -d "description=Errada" -d "kind=expense" -d "expected_amount=10,00" -d "expected_amount_source=fixed" -d "frequency=monthly" -d "day_of_month=5" -d "start_date=2026-09-01" -d "end_date=2026-08-01"
get "$JAR" "/recorrencias/nova"; contains "fim antes do início é recusado" "$(cat "$TMP/body")" "depois do início"
post "$JAR" "/recorrencias/nova" -d "description=13º salário" -d "kind=income" -d "category_id=19" -d "account_id=2" -d "responsible_user_id=2" -d "expected_amount=8.000,00" -d "expected_amount_source=fixed" -d "frequency=yearly" -d "month_of_year=12" -d "day_of_month=5" -d "start_date=2026-01-01" -d "generate_days_ahead=120" -d "is_major_event=1" -d "notify_days_before=30"
check "recorrência anual criada → lista" "/recorrencias" "$LOC"
R13=$(sql "SELECT id FROM recurring_rules WHERE household_id=1 AND description='13º salário'"); [ -n "$R13" ] && ok "regra anual no banco" || fail "regra anual no banco"
check "ocorrência anual agendada e cursor avança um ano" "$(date +%Y)-12-05|$(( $(date +%Y) + 1 ))-12-05" "$(sql "SELECT CONCAT((SELECT MIN(date) FROM transactions WHERE recurring_id=$R13),'|',next_run_date) FROM recurring_rules WHERE id=$R13")"
get "$JAR" "/recorrencias"; contains "evento previsto listado" "$(cat "$TMP/body")" "Próximos eventos previstos"; contains "sugestão de destino: dívida mais cara" "$(cat "$TMP/body")" "Quitar ou amortizar"; contains "sugestão: reserva" "$(cat "$TMP/body")" "Reforçar a reserva"
post "$JAR" "/recorrencias/4/editar" -d "description=Internet" -d "kind=expense" -d "category_id=106" -d "account_id=1" -d "responsible_user_id=1" -d "expected_amount=139,90" -d "expected_amount_source=fixed" -d "frequency=monthly" -d "day_of_month=12" -d "interval_count=1" -d "start_date=2026-01-01" -d "generate_days_ahead=30" -d "is_subscription=1"
check "editar regra refaz as ocorrências futuras" "139.90" "$(sql "SELECT DISTINCT amount FROM transactions WHERE household_id=1 AND recurring_id=4 AND status='scheduled' AND date >= '$TODAY'")"
post "$JAR" "/recorrencias/20/pausar"; check "pausar remove agendadas futuras" "0|0" "$(sql "SELECT CONCAT(is_active,'|',(SELECT COUNT(*) FROM transactions WHERE recurring_id=20 AND status='scheduled' AND deleted_at IS NULL AND date >= '$TODAY')) FROM recurring_rules WHERE id=20")"
post "$JAR" "/recorrencias/20/pausar"; check "retomar gera de novo" "1" "$(sql "SELECT is_active FROM recurring_rules WHERE id=20")"
post "$JAR" "/recorrencias/7/debito-automatico"; check "débito automático marcado" "1" "$(sql "SELECT auto_debit FROM recurring_rules WHERE id=7")"
get "$JAR" "/recorrencias"; contains "indicador de débito automático por membro" "$(cat "$TMP/body")" "Priscila"
post "$JAR" "/familia/membros/2/papel" -d "role=viewer"; post "$JAR2" "/recorrencias/gerar"; check "viewer não gera (403)" "403" "$CODE"; post "$JAR" "/familia/membros/2/papel" -d "role=admin"
get "$JAR" "/lancamentos?de=$MES-01&ate=$MES-31"; contains "lançamentos mostram ícone de recorrente" "$(cat "$TMP/body")" 'aria-label="Recorrente"'

echo "== Radar de assinaturas"
get "$JAR" "/assinaturas"; check "GET /assinaturas 200" "200" "$CODE"
contains "Amazon Prime duplicado sinalizado" "$(cat "$TMP/body")" "Cobrança duplicada"; contains "sem uso registrado" "$(cat "$TMP/body")" "Sem uso registrado"
post "$JAR" "/assinaturas/16/usei"; check "usei → data de uso" "$TODAY" "$(sql "SELECT last_usage_confirmed_at FROM recurring_rules WHERE id=16")"
post "$JAR" "/assinaturas/17/cancelar"; check "cancelei → inativa com fim hoje" "0|$TODAY" "$(sql "SELECT CONCAT(is_active,'|',end_date) FROM recurring_rules WHERE id=17")"
get "$JAR" "/assinaturas"; contains "cancelada listada" "$(cat "$TMP/body")" "Canceladas"; lacks "duplicidade some após cancelar" "$(cat "$TMP/body")" "Cobrança duplicada"
for D in "$M3" "$M2" "$M1"; do post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=39,90" -d "date=$D" -d "description=DISNEY PLUS" -d "account_id=3" -d "category_id=170"; done
get "$JAR" "/assinaturas"; contains "cobrança repetida detectada" "$(cat "$TMP/body")" "DISNEY PLUS"
post "$JAR" "/assinaturas/detectada" -d "description=DISNEY PLUS" -d "amount=39,90" -d "category_id=170" -d "account_id=3" -d "day_of_month=12"; contains "adotar → edição da regra" "$LOC" "/recorrencias/"
check "assinatura adotada" "1|1" "$(sql "SELECT CONCAT(is_subscription,'|',is_active) FROM recurring_rules WHERE household_id=1 AND description='DISNEY PLUS'")"
get "$JAR" "/assinaturas"; lacks "detectada some depois de adotada" "$(cat "$TMP/body")" "É assinatura"

echo "== Orçamento"
get "$JAR" "/orcamento"; check "GET /orcamento 200" "200" "$CODE"; contains "orçamentos do seed" "$(cat "$TMP/body")" "Padaria"; contains "regra 50/30/20" "$(cat "$TMP/body")" "Regra 50/30/20"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=300,00" -d "date=$TODAY" -d "description=Padaria do bairro" -d "account_id=5" -d "category_id=111"
post "$JAR" "/lancamentos/novo" -d "type=income" -d "amount=5.000,00" -d "date=$TODAY" -d "description=Salário" -d "account_id=1" -d "category_id=19"
get "$JAR" "/orcamento"; contains "padaria em 75% do limite" "$(cat "$TMP/body")" "75% usado"; contains "tabela 50/30/20 com receita" "$(cat "$TMP/body")" "Necessidades (essencial)"
post "$JAR" "/orcamento" -d "category_id=9" -d "limit_amount=500,00" -d "warn_at=70" -d "mes=$MES"; check "limite criado → mês" "/orcamento?mes=$MES" "$LOC"
LZ=$(sql "SELECT id FROM budgets WHERE household_id=1 AND category_id=9 AND period_month='$MES-01'"); check "limiares gravados" "[70,100]" "$(sql "SELECT alert_thresholds FROM budgets WHERE id=$LZ")"
post "$JAR" "/orcamento" -d "category_id=9" -d "limit_amount=600,00" -d "mes=$MES"; check "mesma categoria/mês atualiza em vez de duplicar" "1|600.00" "$(sql "SELECT CONCAT(COUNT(*),'|',MAX(limit_amount)) FROM budgets WHERE household_id=1 AND category_id=9 AND period_month='$MES-01'")"
post "$JAR" "/orcamento/$LZ/editar" -d "limit_amount=550,00" -d "warn_at=90"; check "editar limite" "550.00" "$(sql "SELECT limit_amount FROM budgets WHERE id=$LZ")"
post "$JAR" "/orcamento" -d "category_id=19" -d "limit_amount=100,00" -d "mes=$MES"; check "categoria de receita é recusada" "422" "$CODE"
post "$JAR" "/orcamento/copiar" -d "mes=$NEXTMES"; N=$(sql "SELECT COUNT(*) FROM budgets WHERE household_id=1 AND period_month='$NEXTMES-01'"); [ "$N" -ge 6 ] && ok "copiar do mês anterior ($N)" || fail "copiar do mês anterior" "$N"
get "$JAR" "/orcamento?mes=$NEXTMES"; check "mês seguinte abre" "200" "$CODE"
post "$JAR" "/orcamento/$LZ/excluir"; check "remover limite" "0" "$(sql "SELECT COUNT(*) FROM budgets WHERE id=$LZ")"
get "$JAR2" "/orcamento"; check "outro membro vê o orçamento" "200" "$CODE"

echo "== Metas"
get "$JAR" "/metas"; check "GET /metas 200" "200" "$CODE"; contains "meta do seed" "$(cat "$TMP/body")" "Reserva de emergência"
post "$JAR" "/metas/1/aporte" -d "amount=1.000,00" -d "date=$TODAY" -d "note=Primeiro aporte"; check "aporte soma" "1000.00" "$(sql "SELECT saved_amount FROM goals WHERE id=1")"
get "$JAR" "/metas"; contains "progresso exibido" "$(cat "$TMP/body")" "1.000,00"; contains "sugestão de aporte mensal" "$(cat "$TMP/body")" "sugestão:"
post "$JAR" "/metas" -d "name=Viagem" -d "target_amount=500,00" -d "saved_amount=0,00" -d "deadline=2027-06-30" -d "color=#1d4ed8" -d "icon=airplane"; VG=$(sql "SELECT id FROM goals WHERE household_id=1 AND name='Viagem'"); [ -n "$VG" ] && ok "meta criada" || fail "meta criada"
post "$JAR" "/metas/$VG/aporte" -d "amount=500,00" -d "date=$TODAY"; check "meta batida ao atingir o alvo" "done" "$(sql "SELECT status FROM goals WHERE id=$VG")"
get "$JAR" "/metas"; contains "selo de meta batida" "$(cat "$TMP/body")" "batida"
check "auditoria da meta batida" "1" "$(sql "SELECT COUNT(*)>0 FROM audit_logs WHERE action='goal.achieved' AND entity_id=$VG")"
post "$JAR" "/metas/$VG/editar" -d "name=Viagem ao Sul" -d "target_amount=700,00"; check "meta editada" "Viagem ao Sul|700.00" "$(sql "SELECT CONCAT(name,'|',target_amount) FROM goals WHERE id=$VG")"
post "$JAR" "/metas/$VG/arquivar"; check "meta arquivada" "archived" "$(sql "SELECT status FROM goals WHERE id=$VG")"
post "$JAR" "/metas/$VG/excluir"; check "meta excluída (soft)" "1" "$(sql "SELECT deleted_at IS NOT NULL FROM goals WHERE id=$VG")"
post "$JAR" "/metas" -d "name=Zero" -d "target_amount=0,00"; check "meta com valor zero é recusada" "422" "$CODE"

echo "== Plano de ação"
get "$JAR" "/plano"; check "GET /plano 200" "200" "$CODE"; contains "ações do seed" "$(cat "$TMP/body")" "Pet só ração"; contains "7 ações" "$(cat "$TMP/body")" "de 7</span>"
post "$JAR" "/plano/4/situacao" -d "status=doing"; check "iniciar registra base e data" "$TODAY|1" "$(sql "SELECT CONCAT(started_at,'|',baseline_amount IS NOT NULL) FROM savings_actions WHERE id=4")"
get "$JAR" "/plano"; contains "realizado aparece na ação iniciada" "$(cat "$TMP/body")" "realizado"
post "$JAR" "/plano/4/situacao" -d "status=done"; check "concluir grava economia medida" "$TODAY|1" "$(sql "SELECT CONCAT(done_at,'|',measured_saving_month IS NOT NULL) FROM savings_actions WHERE id=4")"
post "$JAR" "/plano" -d "title=Trocar plano do celular" -d "responsible_user_id=1" -d "category_id=8" -d "estimated_saving_month=40,00"; NA=$(sql "SELECT id FROM savings_actions WHERE household_id=1 AND title='Trocar plano do celular'"); [ -n "$NA" ] && ok "ação criada" || fail "ação criada"
post "$JAR" "/plano/$NA/editar" -d "title=Trocar plano do celular (pré-pago)" -d "estimated_saving_month=55,00"; check "ação editada" "55.00" "$(sql "SELECT estimated_saving_month FROM savings_actions WHERE id=$NA")"
post "$JAR" "/plano/$NA/excluir"; check "ação removida" "1" "$(sql "SELECT deleted_at IS NOT NULL FROM savings_actions WHERE id=$NA")"

echo "== Simulador"
get "$JAR" "/simulador"; check "GET /simulador 200" "200" "$CODE"; contains "ponto de partida" "$(cat "$TMP/body")" "Ponto de partida"
get "$JAR" "/simulador?corte%5B2%5D=100,00&cancelar%5B%5D=19"; contains "cancelamento simulado" "$(cat "$TMP/body")" "Cancelar Netflix"; contains "economia mensal" "$(cat "$TMP/body")" "144,90"; contains "horizontes 6/12" "$(cat "$TMP/body")" "12 meses"

echo "== Cron e logs"
OUT=$(php "$ROOT/cron/run.php"); contains "cron gera recorrências" "$OUT" "recorrências:"
N=$(cat "$ROOT"/storage/logs/app-*.log 2>/dev/null | grep -c "ERROR\|CRITICAL"); check "sem erros no log" "0" "$N"

echo; echo "$PASS passaram, $FAIL falharam."; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
