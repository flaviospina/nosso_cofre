#!/usr/bin/env bash
# tools/smoke-fase6.sh — teste de ponta a ponta da fase 6 (painel com indicadores e relatórios com CSV/PDF).
# Pré-requisitos: banco limpo (sql/schema.sql + sql/seed.sql), servidor em $BASE e .env com MAIL_DRIVER=log.
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
get() { curl -s -b "$1" -c "$1" -o "$TMP/body" -w "%{http_code}|%{redirect_url}|%{content_type}" "$BASE$2" > "$TMP/meta"; CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); CTYPE=$(cut -d'|' -f3 "$TMP/meta"); }
totp() { php -r 'require "'"$ROOT"'/app/bootstrap.php"; echo App\Core\Totp::code($argv[1]);' "$1"; }
sql() { mysql -uroot -N --default-character-set=utf8mb4 nosso_cofre -e "$1"; }
TODAY=$(date +%Y-%m-%d); MES=$(date +%Y-%m); ANO=$(date +%Y); PREV=$(date -d "$(date +%Y-%m-01) -1 month" +%Y-%m); D5=$(date +%Y-%m-05)
LATE=$(date -d "$TODAY -3 days" +%Y-%m-%d); [ "$(date -d "$LATE" +%m)" != "$(date +%m)" ] && LATE=$(date +%Y-%m-01)

echo "== Login e 2FA"
post "$JAR" "/entrar" -d "email=flavio@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR" "/conta/2fa"; SECRET=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR" "/conta/2fa/ativar" -d "code=$(totp "$SECRET")"; get "$JAR" "/painel"; check "Flávio logado" "200" "$CODE"
post "$JAR2" "/entrar" -d "email=priscila@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR2" "/conta/2fa"; SECRET2=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR2" "/conta/2fa/ativar" -d "code=$(totp "$SECRET2")"; get "$JAR2" "/painel"; check "Priscila logada" "200" "$CODE"

echo "== Dados de exemplo para os indicadores"
post "$JAR" "/lancamentos/novo" -d "type=income" -d "amount=9.000,00" -d "date=$D5" -d "description=Salário" -d "account_id=1" -d "category_id=19" -d "responsible_user_id=1"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=3.200,00" -d "date=$D5" -d "description=Aluguel" -d "account_id=2" -d "category_id=100" -d "responsible_user_id=2"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=450,00" -d "date=$D5" -d "description=Padaria" -d "account_id=5" -d "category_id=111" -d "responsible_user_id=1"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=300,00" -d "date=$D5" -d "description=Cinema" -d "account_id=3" -d "category_id=181" -d "responsible_user_id=1"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=120,00" -d "date=$LATE" -d "description=Conta atrasada" -d "account_id=1" -d "category_id=104" -d "status=pending"
post "$JAR" "/lancamentos/novo" -d "type=transfer" -d "amount=2.000,00" -d "date=$D5" -d "description=Reserva" -d "account_id=1" -d "transfer_account_id=6"
post "$JAR" "/lancamentos/novo" -d "type=income" -d "amount=8.500,00" -d "date=$PREV-05" -d "description=Salário" -d "account_id=1" -d "category_id=19"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=3.200,00" -d "date=$PREV-05" -d "description=Aluguel" -d "account_id=2" -d "category_id=100"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=200,00" -d "date=$PREV-08" -d "description=Padaria" -d "account_id=5" -d "category_id=111"
check "lançamentos criados" "9" "$(sql "SELECT COUNT(*) FROM transactions WHERE household_id=1 AND recurring_id IS NULL")"

echo "== Painel: 11 indicadores"
get "$JAR" "/painel"; check "GET /painel 200" "200" "$CODE"; B="$(cat "$TMP/body")"
contains "1) saldo do mês" "$B" "Saldo do mês"; contains "1) projetado" "$B" "Projetado até o fim do mês"
contains "2) receitas × despesas 12 meses" "$B" 'id="chartSeries"'; contains "3) por categoria" "$B" 'id="chartCategories"'; contains "3) por membro (lar familiar)" "$B" 'id="chartMembers"'
contains "4) contas a vencer com atrasada" "$B" "atrasada(s)"; contains "4) botão marcar pago" "$B" 'como pago"'
contains "5) orçamentos em risco" "$B" "Orçamentos mais perto de estourar"; contains "5) padaria estourou (450 de 400)" "$B" "estoura"
contains "6) taxa de poupança" "$B" "Taxa de poupança"; contains "6) idade do dinheiro" "$B" "idade do dinheiro"
contains "7) supérfluo" "$B" "Gasto supérfluo do mês"; contains "8) metas e plano" "$B" "Metas e plano de ação"; contains "8) reserva do seed" "$B" "Reserva de emergência"
contains "9) radar" "$B" "Radar de assinaturas"; contains "10) eventos previstos" "$B" "Próximos eventos previstos"
contains "11) score" "$B" "Saúde financeira"; contains "11) explicação do cálculo" "$B" "Taxa de poupança:"
contains "dados dos gráficos embutidos com nonce" "$B" 'id="dashboardData"'; contains "Chart.js vendorizado" "$B" "assets/vendor/chart.umd.js"
contains "tabela alternativa ao gráfico" "$B" "Ver como tabela"
SCORE=$(grep -o 'aria-label="Score [0-9]* de 100"' <<<"$B" | grep -o '[0-9]*' | head -1); [ -n "$SCORE" ] && [ "$SCORE" -ge 0 ] && [ "$SCORE" -le 100 ] && ok "score entre 0 e 100 ($SCORE)" || fail "score" "$SCORE"
get "$JAR" "/assets/vendor/chart.umd.js"; check "Chart.js servido" "200" "$CODE"; contains "content-type JS" "$CTYPE" "javascript"
get "$JAR" "/painel?mes=$PREV"; check "mês anterior abre" "200" "$CODE"; contains "mês anterior sem a conta atrasada de hoje" "$(cat "$TMP/body")" "Saldo do mês"
get "$JAR" "/painel?membro=2"; contains "filtro por membro aplicado" "$(cat "$TMP/body")" 'value="2" selected'; contains "só despesas da Priscila" "$(cat "$TMP/body")" "3.200,00"
get "$JAR" "/painel?mes=2020-13"; check "mês inválido cai no atual" "200" "$CODE"

echo "== Relatórios"
get "$JAR" "/relatorios"; check "GET /relatorios 200" "200" "$CODE"; contains "hub lista os relatórios" "$(cat "$TMP/body")" "Conta ou cartão (fatura)"
get "$JAR" "/relatorios/mensal?mes=$MES"; check "mensal 200" "200" "$CODE"; B="$(cat "$TMP/body")"
contains "mensal: categoria pai" "$B" "Moradia"; contains "mensal: comparativo" "$B" "Mesmo mês, ano passado"; contains "mensal: ranking" "$B" "Onde o dinheiro mais cresceu"; contains "mensal: padaria cresceu 200 → 450" "$B" "200,00 → R[$]"
get "$JAR" "/relatorios/mensal?mes=$MES&formato=csv"; contains "CSV mensal: tipo" "$CTYPE" "text/csv"; check "CSV com BOM" "1" "$(head -c 3 "$TMP/body" | od -An -tx1 | tr -d ' \n' | grep -c efbbbf)"; contains "CSV cabeçalho" "$(cat "$TMP/body")" "Categoria;Este mês"
get "$JAR" "/relatorios/mensal?mes=$MES&formato=pdf"; contains "PDF mensal: tipo" "$CTYPE" "application/pdf"; check "PDF começa com %PDF" "%PDF-1.4" "$(head -c 8 "$TMP/body")"; contains "PDF com título acentuado (WinAnsi)" "$(cat "$TMP/body")" "$(printf '(Relat\xf3rio mensal)')"
get "$JAR" "/relatorios/anual?ano=$ANO"; check "anual 200" "200" "$CODE"; contains "anual: melhor/pior mês" "$(cat "$TMP/body")" "Melhor / pior mês"; contains "anual: gráfico" "$(cat "$TMP/body")" 'id="chartAnnual"'
get "$JAR" "/relatorios/anual?ano=$ANO&formato=csv"; contains "CSV anual" "$(cat "$TMP/body")" "Total $ANO"
get "$JAR" "/relatorios/membros?mes=$MES"; check "por membro 200" "200" "$CODE"; contains "por membro: Priscila" "$(cat "$TMP/body")" "Priscila"; contains "por membro: parte nas despesas" "$(cat "$TMP/body")" "% das despesas"
get "$JAR" "/relatorios/membros?mes=$MES&formato=pdf"; contains "PDF por membro" "$CTYPE" "application/pdf"
get "$JAR" "/relatorios/categoria?categoria=2&mes=$MES"; check "por categoria 200" "200" "$CODE"; contains "categoria: evolução" "$(cat "$TMP/body")" 'id="chartEvolution"'; contains "categoria: lançamentos do mês" "$(cat "$TMP/body")" "Padaria"
get "$JAR" "/relatorios/categoria?categoria=2&mes=$MES&formato=csv"; contains "CSV categoria" "$(cat "$TMP/body")" "Total do período"
get "$JAR" "/relatorios/conta?conta=1&mes=$MES"; check "extrato 200" "200" "$CODE"; contains "extrato: saldo inicial e final" "$(cat "$TMP/body")" "Saldo final"; contains "extrato: transferência" "$(cat "$TMP/body")" "Reserva"
get "$JAR" "/relatorios/conta?conta=3&mes=$MES"; contains "fatura do cartão" "$(cat "$TMP/body")" "Total da fatura"; contains "fatura: período de fechamento" "$(cat "$TMP/body")" "Período"
get "$JAR" "/relatorios/conta?conta=3&mes=$MES&formato=csv"; contains "CSV fatura" "$(cat "$TMP/body")" "Total da fatura"
get "$JAR" "/relatorios/conta?conta=999&mes=$MES"; check "conta inexistente → 404" "404" "$CODE"
get "$JAR2" "/relatorios/mensal?mes=$MES"; check "outro membro acessa relatórios" "200" "$CODE"
N=$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='report.exported'"); [ "$N" -ge 6 ] && ok "exportações auditadas ($N)" || fail "exportações auditadas" "$N"

echo "== Logs"
N=$(cat "$ROOT"/storage/logs/app-*.log 2>/dev/null | grep -c "ERROR\|CRITICAL"); check "sem erros no log" "0" "$N"

echo; echo "$PASS passaram, $FAIL falharam."; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
