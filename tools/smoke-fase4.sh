#!/usr/bin/env bash
# tools/smoke-fase4.sh — teste de ponta a ponta da fase 4 (cadastros financeiros) contra o servidor local.
# Pré-requisitos: banco limpo (sql/schema.sql + sql/seed.sql), servidor em $BASE e .env com MAIL_DRIVER=log.
# Usa o lar de teste do seed ("Família Spina": flavio@exemplo.test responsável, priscila@exemplo.test admin; senha Cofre@2026teste)
# e ativa o 2FA dos dois no começo (obrigatório para responsável/admin de lar familiar).
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
post() { local jar="$1" path="$2"; shift 2; local token; token=$(csrf "$jar" "$path"); [ -z "$token" ] && token=$(csrf "$jar" "/painel")
  curl -s -b "$jar" -c "$jar" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" -X POST "$BASE$path" --data-urlencode "_token=$token" "$@" > "$TMP/meta"
  CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); dbg; }
postf() { local jar="$1" path="$2"; shift 2; local token; token=$(csrf "$jar" "/painel")   # multipart (-F)
  curl -s -b "$jar" -c "$jar" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" -X POST "$BASE$path" -F "_token=$token" "$@" > "$TMP/meta"
  CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); }
dbg() { [ -z "${DEBUG:-}" ] && return 0; echo "    → $CODE $LOC"; case "$LOC" in */novo*|*/editar*|*/nova*) curl -s -b "$JAR" "$BASE$LOC" | grep -o 'invalid-feedback d-block">[^<]*' | head -3 | sed 's/^/      /';; esac; }
get() { curl -s -b "$1" -c "$1" -o "$TMP/body" -w "%{http_code}|%{redirect_url}" "$BASE$2" > "$TMP/meta"; CODE=$(cut -d'|' -f1 "$TMP/meta"); LOC=$(cut -d'|' -f2 "$TMP/meta" | sed "s#$BASE##"); }
totp() { php -r 'require "'"$ROOT"'/app/bootstrap.php"; echo App\Core\Totp::code($argv[1]);' "$1"; }
sql() { mysql -uroot -N --default-character-set=utf8mb4 nosso_cofre -e "$1"; }
TODAY=$(date +%Y-%m-%d); MONTH_FROM=$(date +%Y-%m-01); MONTH_TO=$(date -d "$MONTH_FROM +1 month -1 day" +%Y-%m-%d)
PERIOD="de=$MONTH_FROM&ate=$MONTH_TO"

echo "== Login e ativação do 2FA (responsável e admin do lar familiar)"
post "$JAR" "/entrar" -d "email=flavio@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR" "/painel"; check "Flávio é levado ao 2FA" "/conta/2fa" "$LOC"
get "$JAR" "/conta/2fa"; SECRET=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR" "/conta/2fa/ativar" -d "code=$(totp "$SECRET")"; get "$JAR" "/painel"; check "Flávio no painel após 2FA" "200" "$CODE"
post "$JAR2" "/entrar" -d "email=priscila@exemplo.test" -d "password=Cofre@2026teste"; get "$JAR2" "/conta/2fa"; SECRET2=$(grep -o 'user-select-all">[A-Z2-7 ]*' "$TMP/body" | sed 's/.*">//' | tr -d ' ')
post "$JAR2" "/conta/2fa/ativar" -d "code=$(totp "$SECRET2")"; get "$JAR2" "/painel"; check "Priscila no painel após 2FA" "200" "$CODE"
contains "painel abre com o score" "$(cat "$TMP/body")" "Saúde financeira"

echo "== Contas e cartões"
get "$JAR" "/contas"; check "GET /contas 200" "200" "$CODE"; contains "lista contas do seed" "$(cat "$TMP/body")" "Reserva de emergência"
post "$JAR" "/contas/nova" -d "name=C" -d "type=checking"; check "nome curto volta ao formulário" "/contas/nova" "$LOC"
post "$JAR" "/contas/nova" -d "name=Cartão Teste" -d "type=credit_card" -d "closing_day=5" -d "due_day=12" -d "limit_amount=2.000,00" -d "initial_balance=0,00" -d "color=#ff8800"
check "cartão criado → /contas" "/contas" "$LOC"
CARD=$(sql "SELECT id FROM accounts WHERE household_id=1 AND name='Cartão Teste'"); [ -n "$CARD" ] && ok "cartão no banco (id $CARD)" || fail "cartão no banco"
check "limite gravado" "2000.00" "$(sql "SELECT limit_amount FROM accounts WHERE id=$CARD")"
post "$JAR" "/contas/$CARD/editar" -d "name=Cartão Teste 2" -d "type=credit_card" -d "closing_day=6" -d "due_day=13" -d "limit_amount=2500,00" -d "initial_balance=0,00"
check "conta editada" "Cartão Teste 2|6" "$(sql "SELECT CONCAT(name,'|',closing_day) FROM accounts WHERE id=$CARD")"
post "$JAR" "/contas/$CARD/arquivar"; get "$JAR" "/contas"; contains "conta arquivada aparece na seção Arquivadas" "$(cat "$TMP/body")" "Arquivadas"
post "$JAR" "/contas/$CARD/arquivar"; check "reativada" "1" "$(sql "SELECT is_active FROM accounts WHERE id=$CARD")"
post "$JAR" "/contas/nova" -d "name=Temporária" -d "type=cash"; TMPACC=$(sql "SELECT id FROM accounts WHERE household_id=1 AND name='Temporária'")
post "$JAR" "/contas/$TMPACC/excluir"; check "conta sem lançamentos é excluída (soft delete)" "1" "$(sql "SELECT deleted_at IS NOT NULL FROM accounts WHERE id=$TMPACC")"
get "$JAR2" "/contas/99999/editar"; check "conta de outro lar/inexistente → 404" "404" "$CODE"

echo "== Categorias"
get "$JAR" "/categorias"; check "GET /categorias 200" "200" "$CODE"; contains "modelo padrão listado" "$(cat "$TMP/body")" "Alimentação"
post "$JAR" "/categorias" -d "name=Ração premium" -d "kind=expense" -d "parent_id=7" -d "icon=heart" -d "color=#aa00aa" -d "is_essential=1"
OWNCAT=$(sql "SELECT id FROM categories WHERE household_id=1 AND name='Ração premium'"); [ -n "$OWNCAT" ] && ok "categoria do lar criada (id $OWNCAT, filha de Pets)" || fail "categoria do lar criada"
post "$JAR" "/categorias" -d "name=Errada" -d "kind=income" -d "parent_id=7"; check "pai de tipo diferente é recusado" "422" "$CODE"
post "$JAR" "/categorias/$OWNCAT/editar" -d "name=Ração e petiscos" -d "icon=heart" -d "color=#aa00aa" -d "is_active=1"
check "categoria própria editada" "Ração e petiscos" "$(sql "SELECT name FROM categories WHERE id=$OWNCAT")"
post "$JAR" "/categorias/110/editar" -d "name=Hack" -d "is_active=1"; check "categoria global não pode ser editada (403)" "403" "$CODE"
post "$JAR" "/categorias/9/ocultar"; get "$JAR" "/categorias"; contains "Lazer ocultada neste lar" "$(cat "$TMP/body")" 'aria-label="Mostrar Lazer"'
get "$JAR" "/lancamentos/novo"; lacks "categoria oculta some do lançamento rápido" "$(cat "$TMP/body")" ">Viagens<"
post "$JAR" "/categorias/9/ocultar"; check "Lazer visível de novo" "" "$(sql "SELECT JSON_EXTRACT(settings,'$.hidden_categories[0]') FROM households WHERE id=1" | grep -v NULL)"
get "$JAR2" "/categorias"; contains "outro membro vê a categoria do lar" "$(cat "$TMP/body")" "Ração e petiscos"

echo "== Lançamentos: rápido, validação, parcelas, transferência"
get "$JAR" "/lancamentos"; check "GET /lancamentos 200" "200" "$CODE"; contains "lista mostra as ocorrências agendadas das recorrências do seed" "$(cat "$TMP/body")" "Agendado"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=0" -d "date=$TODAY" -d "description=Zero" -d "account_id=1"; get "$JAR" "/lancamentos/novo"; contains "valor zero é recusado" "$(cat "$TMP/body")" "maior que zero"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=10,00" -d "date=$TODAY" -d "description=Cat errada" -d "account_id=1" -d "category_id=19"; get "$JAR" "/lancamentos/novo"; contains "categoria de receita em despesa é recusada" "$(cat "$TMP/body")" "Categoria inválida para este tipo"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=123,45" -d "date=$TODAY" -d "description=Mercado Bom Preco" -d "account_id=1" -d "category_id=110" -d "responsible_user_id=1" -d "status=paid" -d "tags=semana, casa" -d "notes=Compra do mês"
contains "despesa criada → lista do mês" "$LOC" "/lancamentos?de="
TX1=$(sql "SELECT id FROM transactions WHERE household_id=1 AND description='Mercado Bom Preco'"); check "valor pt-BR convertido" "123.45" "$(sql "SELECT amount FROM transactions WHERE id=$TX1")"
check "etiquetas em JSON" '["semana","casa"]' "$(sql "SELECT tags FROM transactions WHERE id=$TX1")"
check "observação criptografada (não fica em texto puro)" "0" "$(sql "SELECT notes LIKE '%Compra do mês%' FROM transactions WHERE id=$TX1")"
check "hash de importação preenchido" "64" "$(sql "SELECT CHAR_LENGTH(import_hash) FROM transactions WHERE id=$TX1")"
check "regra de categoria aprendida" "110" "$(sql "SELECT category_id FROM category_rules WHERE household_id=1 AND pattern='mercado bom preco'")"
get "$JAR" "/lancamentos/sugerir?descricao=MERCADO%20BOM%20PRECO%2012%2F09"; contains "sugestão via JSON" "$(cat "$TMP/body")" '"category_id":110'
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=100,00" -d "date=2026-09-15" -d "description=Notebook" -d "account_id=$CARD" -d "category_id=8" -d "installments=3"
check "3 parcelas criadas" "3" "$(sql "SELECT COUNT(*) FROM transactions WHERE household_id=1 AND description LIKE 'Notebook (%/3)'")"
check "última parcela absorve arredondamento" "33.33,33.33,33.34" "$(sql "SELECT GROUP_CONCAT(amount ORDER BY installment_no) FROM transactions WHERE household_id=1 AND installment_total=3")"
check "parcelas mês a mês, 2ª e 3ª pendentes" "2026-09-15:paid,2026-10-15:pending,2026-11-15:pending" "$(sql "SELECT GROUP_CONCAT(CONCAT(date,':',status) ORDER BY installment_no) FROM transactions WHERE household_id=1 AND installment_total=3")"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=100,00" -d "date=2026-01-31" -d "description=Fim de mês" -d "account_id=1" -d "installments=2"
check "parcela em 31/01 cai em 28/02 (não pula para março)" "2026-02-28" "$(sql "SELECT date FROM transactions WHERE household_id=1 AND description='Fim de mês (2/2)'")"
post "$JAR" "/lancamentos/novo" -d "type=transfer" -d "amount=50,00" -d "date=$TODAY" -d "description=Guardar" -d "account_id=1" -d "transfer_account_id=1"; get "$JAR" "/lancamentos/novo"; contains "transferência para a mesma conta é recusada" "$(cat "$TMP/body")" "diferente da origem"
post "$JAR" "/lancamentos/novo" -d "type=transfer" -d "amount=50,00" -d "date=$TODAY" -d "description=Guardar" -d "account_id=1" -d "transfer_account_id=6" -d "category_id=110"
check "transferência gravada sem categoria" "6|" "$(sql "SELECT CONCAT(transfer_account_id,'|',IFNULL(category_id,'')) FROM transactions WHERE household_id=1 AND type='transfer'")"
post "$JAR" "/lancamentos/novo" -d "type=income" -d "amount=5.000,00" -d "date=$TODAY" -d "description=Salário" -d "account_id=1" -d "category_id=19" -d "responsible_user_id=1"
post "$JAR" "/lancamentos/novo" -d "type=expense" -d "amount=80,00" -d "date=$TODAY" -d "description=Conta de luz" -d "account_id=1" -d "category_id=104" -d "status=pending" -d "save_and_new=1"
check "salvar e novo volta ao formulário" "/lancamentos/novo?tipo=expense" "$LOC"
get "$JAR" "/contas"; contains "saldo da conta reflete lançamentos pagos" "$(cat "$TMP/body")" "4.776,55"
get "$JAR" "/painel"; contains "painel: a vencer nos próximos dias" "$(cat "$TMP/body")" "A vencer nos próximos 7 dias"; contains "painel: receita do mês" "$(cat "$TMP/body")" "5.000,00 receitas"

echo "== Lista, filtros e busca"
get "$JAR" "/lancamentos?$PERIOD"; contains "lista mostra a despesa" "$(cat "$TMP/body")" "Mercado Bom Preco"; contains "totais do período (receitas)" "$(cat "$TMP/body")" "5.000,00"
get "$JAR" "/lancamentos?$PERIOD&tipo=income"; contains "filtro por tipo mantém receita" "$(cat "$TMP/body")" "Salário"; lacks "filtro por tipo esconde despesa" "$(cat "$TMP/body")" "Mercado Bom Preco"
get "$JAR" "/lancamentos?de=2026-09-01&ate=2026-12-31&busca=notebook"; contains "busca por texto" "$(cat "$TMP/body")" "Notebook (1/3)"
get "$JAR" "/lancamentos?$PERIOD&tag=semana"; contains "filtro por etiqueta" "$(cat "$TMP/body")" "Mercado Bom Preco"; lacks "etiqueta filtra os demais" "$(cat "$TMP/body")" ">Salário<"
get "$JAR" "/lancamentos?$PERIOD&status=pending"; contains "filtro por situação" "$(cat "$TMP/body")" "Conta de luz"; lacks "pendente esconde pago" "$(cat "$TMP/body")" ">Salário<"
get "$JAR" "/lancamentos?$PERIOD&conta=6"; contains "filtro por conta inclui destino da transferência" "$(cat "$TMP/body")" "Guardar"
get "$JAR" "/lancamentos?$PERIOD&categoria=2"; contains "filtro por categoria pai inclui filhas" "$(cat "$TMP/body")" "Mercado Bom Preco"

echo "== Edição, situação, privacidade entre membros"
post "$JAR" "/lancamentos/$TX1/editar" -d "type=expense" -d "amount=130,00" -d "date=$TODAY" -d "description=Mercado Bom Preco Editado" -d "account_id=1" -d "category_id=111" -d "status=paid"
check "edição gravada" "130.00|111" "$(sql "SELECT CONCAT(amount,'|',category_id) FROM transactions WHERE id=$TX1")"
check "auditoria da edição" "1" "$(sql "SELECT COUNT(*)>0 FROM audit_logs WHERE action='transaction.update' AND entity_id=$TX1")"
post "$JAR" "/lancamentos/$TX1/status" -d "status=pending"; check "marcar pendente limpa paid_at" "pending|" "$(sql "SELECT CONCAT(status,'|',IFNULL(paid_at,'')) FROM transactions WHERE id=$TX1")"
post "$JAR" "/lancamentos/$TX1/status" -d "status=paid"; check "marcar pago preenche paid_at" "paid|$TODAY" "$(sql "SELECT CONCAT(status,'|',paid_at) FROM transactions WHERE id=$TX1")"
post "$JAR2" "/lancamentos/novo" -d "type=expense" -d "amount=200,00" -d "date=$TODAY" -d "description=Presente surpresa" -d "account_id=2" -d "category_id=9" -d "is_private=1" -d "responsible_user_id=2"
PRIV=$(sql "SELECT id FROM transactions WHERE household_id=1 AND description='Presente surpresa'")
get "$JAR" "/lancamentos?$PERIOD"; contains "outro membro vê 'Lançamento privado'" "$(cat "$TMP/body")" "Lançamento privado"; lacks "descrição privada escondida" "$(cat "$TMP/body")" "Presente surpresa"
contains "valor privado entra nos totais (despesas)" "$(cat "$TMP/body")" "$(php -r 'echo number_format((float) $argv[1], 2, ",", ".");' "$(sql "SELECT SUM(amount) FROM transactions WHERE household_id=1 AND type='expense' AND deleted_at IS NULL AND date BETWEEN '$MONTH_FROM' AND '$MONTH_TO'")")"
get "$JAR2" "/lancamentos?$PERIOD"; contains "quem criou vê a descrição" "$(cat "$TMP/body")" "Presente surpresa"
get "$JAR" "/lancamentos/$PRIV/editar"; check "outro membro não edita o privado (403)" "403" "$CODE"
get "$JAR" "/lancamentos?$PERIOD&busca=Presente"; lacks "busca não vaza descrição privada" "$(cat "$TMP/body")" "Presente surpresa"
post "$JAR" "/familia/membros/2/papel" -d "role=viewer"; get "$JAR2" "/lancamentos/novo"; check "papel 'viewer' não cria lançamento (403)" "403" "$CODE"
post "$JAR2" "/lancamentos/$PRIV/status" -d "status=pending"; check "viewer não muda situação (403)" "403" "$CODE"
post "$JAR" "/familia/membros/2/papel" -d "role=member"; get "$JAR2" "/lancamentos/novo"; check "de volta a membro, cria de novo" "200" "$CODE"
get "$JAR2" "/lancamentos/$TX1/editar"; check "membro não edita lançamento alheio por padrão (403)" "403" "$CODE"
post "$JAR" "/familia/configuracoes" -d "name=Família Spina" -d "members_can_edit_others=1"; get "$JAR2" "/lancamentos/$TX1/editar"; check "com a opção ligada, membro edita" "200" "$CODE"

echo "== Anexo (comprovante) com reprocessamento da imagem"
php -r '$im = imagecreatetruecolor(2400, 1800); imagefill($im, 0, 0, 0xEEEEEE); imagepng($im, $argv[1]);' "$TMP/recibo.png"
postf "$JAR" "/lancamentos/$TX1/editar" -F "type=expense" -F "amount=130,00" -F "date=$TODAY" -F "description=Mercado Bom Preco Editado" -F "account_id=1" -F "category_id=111" -F "status=paid" -F "attachment=@$TMP/recibo.png;type=image/png"
ATT=$(sql "SELECT attachment_path FROM transactions WHERE id=$TX1"); [ -n "$ATT" ] && ok "anexo gravado ($ATT)" || fail "anexo gravado"
[ -f "$ROOT/storage/uploads/$ATT" ] && ok "arquivo em storage/uploads (fora do public)" || fail "arquivo em storage/uploads"
W=$(php -r 'echo getimagesize($argv[1])[0];' "$ROOT/storage/uploads/$ATT" 2>/dev/null); [ "$W" -le 1600 ] && ok "imagem reduzida para ≤ 1600 px ($W)" || fail "imagem reduzida" "$W"
curl -s -b "$JAR" -o /dev/null -w "%{http_code} %{content_type}" "$BASE/lancamentos/$TX1/anexo" > "$TMP/meta"; contains "download do anexo pelo dono" "$(cat "$TMP/meta")" "200 image/"
postf "$JAR" "/lancamentos/novo" -F "type=expense" -F "amount=1,00" -F "date=$TODAY" -F "description=Anexo ruim" -F "account_id=1" -F "attachment=@$ROOT/tools/smoke-fase4.sh;type=application/octet-stream"
get "$JAR" "/lancamentos/novo"; contains "arquivo não permitido é recusado" "$(cat "$TMP/body")" "Formato não aceito"
post "$JAR" "/lancamentos/$TX1/editar" -d "type=expense" -d "amount=130,00" -d "date=$TODAY" -d "description=Mercado Bom Preco Editado" -d "account_id=1" -d "category_id=111" -d "status=paid" -d "remove_attachment=1"
[ ! -f "$ROOT/storage/uploads/$ATT" ] && ok "remover anexo apaga o arquivo" || fail "remover anexo apaga o arquivo"

echo "== Modelos, repetir último, lote, lixeira"
post "$JAR" "/lancamentos/$TX1/modelo" -d "name=Mercado semanal"; get "$JAR" "/lancamentos/modelos"; contains "modelo salvo" "$(cat "$TMP/body")" "Mercado semanal"
TPL=$(sql "SELECT id FROM transaction_templates WHERE household_id=1 AND name='Mercado semanal'")
get "$JAR" "/lancamentos/novo?modelo=$TPL"; contains "formulário pré-preenchido pelo modelo" "$(cat "$TMP/body")" 'value="Mercado Bom Preco Editado"'
get "$JAR" "/lancamentos/novo?repetir=ultimo"; contains "repetir último pré-preenche" "$(cat "$TMP/body")" 'value="Conta de luz"'
get "$JAR2" "/lancamentos/novo"; lacks "modelo é por usuário" "$(cat "$TMP/body")" "Mercado semanal"
post "$JAR" "/lancamentos/modelos/$TPL/excluir"; check "modelo excluído" "0" "$(sql "SELECT COUNT(*) FROM transaction_templates WHERE id=$TPL AND deleted_at IS NULL")"
P1=$(sql "SELECT id FROM transactions WHERE household_id=1 AND description='Notebook (1/3)'"); P2=$(sql "SELECT id FROM transactions WHERE household_id=1 AND description='Notebook (2/3)'")
post "$JAR" "/lancamentos/lote" -d "action=category" -d "ids[]=$P1" -d "ids[]=$P2" -d "value=$OWNCAT"; check "lote: categoria" "2" "$(sql "SELECT COUNT(*) FROM transactions WHERE id IN ($P1,$P2) AND category_id=$OWNCAT")"
post "$JAR" "/lancamentos/lote" -d "action=status" -d "ids[]=$P2" -d "value=paid"; check "lote: situação" "paid" "$(sql "SELECT status FROM transactions WHERE id=$P2")"
post "$JAR" "/lancamentos/lote" -d "action=trash" -d "ids[]=$P1" -d "ids[]=$P2"; check "lote: lixeira" "2" "$(sql "SELECT COUNT(*) FROM transactions WHERE id IN ($P1,$P2) AND deleted_at IS NOT NULL")"
get "$JAR" "/lancamentos/lixeira"; contains "lixeira lista" "$(cat "$TMP/body")" "Notebook (1/3)"
get "$JAR" "/lancamentos?de=2026-09-01&ate=2026-12-31"; lacks "lixeira some da lista" "$(cat "$TMP/body")" "Notebook (1/3)"
post "$JAR" "/lancamentos/$P1/restaurar"; check "restaurar" "0" "$(sql "SELECT deleted_at IS NOT NULL FROM transactions WHERE id=$P1")"
post "$JAR" "/lancamentos/$P2/destruir"; check "excluir de vez" "0" "$(sql "SELECT COUNT(*) FROM transactions WHERE id=$P2")"
post "$JAR" "/contas/$CARD/excluir"; get "$JAR" "/contas"; contains "conta com lançamentos não é excluída" "$(cat "$TMP/body")" "Arquive-a em vez de excluir"

echo "== Importação CSV"
printf 'Data;Histórico;Valor\n%s;MERCADO BOM PRECO EDITADO;-130,00\n02/09/2026;MERCADO BOM PRECO LOJA 2;-88,10\n03/09/2026;PIX RECEBIDO JOAO;250,00\n' "$(date +%d/%m/%Y)" > "$TMP/extrato.csv"
postf "$JAR" "/importar" -F "file=@$TMP/extrato.csv;type=text/csv" -F "account_id=1"; contains "upload → mapeamento" "$LOC" "/importar/u1-"
KEY=$(basename "$LOC"); get "$JAR" "/importar/$KEY"; check "página de mapeamento" "200" "$CODE"; contains "colunas detectadas" "$(cat "$TMP/body")" "Histórico"
post "$JAR" "/importar/$KEY/mapear" -d "date=0" -d "description=1" -d "amount=2" -d "has_header=1" -d "date_format=d/m/Y"
contains "pré-visualização" "$(cat "$TMP/body")" "Confira as linhas"; contains "duplicado detectado" "$(cat "$TMP/body")" ">duplicado<"
contains "categoria sugerida pela regra aprendida" "$(cat "$TMP/body")" "sugerida"
post "$JAR" "/importar/$KEY/confirmar" -d "date=0" -d "description=1" -d "amount=2" -d "has_header=1" -d "date_format=d/m/Y" -d "account_id=1" -d "status=paid" -d "rows[1]=1" -d "rows[2]=1" -d "category[1]=110" -d "category[2]=19"
contains "confirmação → lançamentos" "$LOC" "/lancamentos?de=2026-09-02"
BATCH=$(sql "SELECT id FROM import_batches WHERE household_id=1 ORDER BY id DESC LIMIT 1"); check "lote registrado (2 importados de 3)" "2|3|1|done" "$(sql "SELECT CONCAT(rows_imported,'|',rows_total,'|',rows_duplicated,'|',status) FROM import_batches WHERE id=$BATCH")"
check "lançamentos ligados ao lote" "2" "$(sql "SELECT COUNT(*) FROM transactions WHERE import_batch_id=$BATCH")"
check "arquivo temporário descartado" "0" "$(ls "$ROOT"/storage/cache/import-$KEY.* 2>/dev/null | wc -l)"
get "$JAR" "/importar/$KEY"; check "chave usada expira" "/importar" "$LOC"
post "$JAR" "/importar/lotes/$BATCH/desfazer"; check "desfazer manda para a lixeira" "2|undone" "$(sql "SELECT CONCAT((SELECT COUNT(*) FROM transactions WHERE import_batch_id=$BATCH AND deleted_at IS NOT NULL),'|',status) FROM import_batches WHERE id=$BATCH")"

echo "== Importação OFX"
cat > "$TMP/extrato.ofx" <<'OFX'
OFXHEADER:100
DATA:OFXSGML
<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKACCTFROM><ACCTID>9999-1</ACCTID></BANKACCTFROM><BANKTRANLIST>
<STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260903120000[-3:BRT]</DTPOSTED><TRNAMT>-89.90</TRNAMT><FITID>F001</FITID><MEMO>NETFLIX.COM</MEMO></STMTTRN>
<STMTTRN><TRNTYPE>CREDIT</TRNTYPE><DTPOSTED>20260905</DTPOSTED><TRNAMT>1500.00</TRNAMT><FITID>F002</FITID><NAME>SALARIO EMPRESA</NAME></STMTTRN>
</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
OFX
postf "$JAR" "/importar" -F "file=@$TMP/extrato.ofx;type=application/octet-stream" -F "account_id=1"; KEY=$(basename "$LOC")
get "$JAR" "/importar/$KEY"; contains "OFX vai direto à pré-visualização" "$(cat "$TMP/body")" "NETFLIX.COM"; lacks "OFX não tem duplicados na 1ª vez" "$(cat "$TMP/body")" ">duplicado<"
post "$JAR" "/importar/$KEY/confirmar" -d "account_id=1" -d "status=paid" -d "rows[0]=1" -d "rows[1]=1" -d "category[0]=8"
check "OFX importado com FITID nas etiquetas" "F001" "$(sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(tags,'$.fitid')) FROM transactions WHERE household_id=1 AND description='NETFLIX.COM'")"
postf "$JAR" "/importar" -F "file=@$TMP/extrato.ofx;type=application/octet-stream"; KEY=$(basename "$LOC"); get "$JAR" "/importar/$KEY"
check "reenviar o mesmo OFX marca tudo como duplicado" "2" "$(grep -o '>duplicado<' "$TMP/body" | wc -l)"
get "$JAR2" "/importar/$KEY"; check "chave de importação é por usuário" "/importar" "$LOC"
head -c 2500000 /dev/zero | tr '\0' 'a' > "$TMP/grande.csv"; postf "$JAR" "/importar" -F "file=@$TMP/grande.csv;type=text/csv"; get "$JAR" "/importar"; contains "arquivo > 2 MB é recusado" "$(cat "$TMP/body")" "no máximo 2 MB"
get "$JAR" "/importar"; contains "histórico de lotes" "$(cat "$TMP/body")" "extrato.ofx"

echo "== Cron e logs"
OUT=$(php "$ROOT/cron/run.php"); contains "cron limpa importações temporárias" "$OUT" "importacoes_temporarias"
N=$(cat "$ROOT"/storage/logs/app-*.log 2>/dev/null | grep -c "ERROR\|CRITICAL"); check "sem erros no log da aplicação" "0" "$N"

echo; echo "$PASS passaram, $FAIL falharam."; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
