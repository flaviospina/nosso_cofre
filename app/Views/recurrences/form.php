<?php
// app/Views/recurrences/form.php — nova / editar recorrência
/** @var array<string,mixed>|null $rule */
/** @var array<string,mixed> $values */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array<int,array<string,mixed>> $categoriesExpense */
/** @var array<int,array<string,mixed>> $categoriesIncome */
/** @var array<int,array<string,mixed>> $members */
/** @var array<string,string> $frequencies */
/** @var array<string,string> $sources */
/** @var bool $isFamily */
$editing = $rule !== null;
$v = static fn(string $k, mixed $d = ''): mixed => old($k, $values[$k] ?? $d);
$kind = (string) $v('kind', 'expense');
$catSelected = (int) $v('category_id', 0);
$catOptions = static function (array $list, string $k) use ($catSelected): void {
    foreach ($list as $c): ?><option value="<?= (int) $c['id'] ?>" data-kind="<?= e($k) ?>" <?= $catSelected === (int) $c['id'] ? 'selected' : '' ?>><?= $c['depth'] === 1 ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach;
};
?>
<div class="nc-maxw-md mx-auto">
    <nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(route('recurrences.index')) ?>">Recorrências</a></li><li class="breadcrumb-item active" aria-current="page"><?= $editing ? 'Editar' : 'Nova' ?></li></ol></nav>
    <h1 class="h3 mb-3"><?= $editing ? 'Editar recorrência' : 'Nova recorrência' ?></h1>
    <form method="post" action="<?= e($editing ? route('recurrences.update', ['id' => $rule['id']]) : route('recurrences.store')) ?>" data-once novalidate class="card nc-card" data-recurrence-form>
        <div class="card-body">
            <?= csrf_field() ?>
            <div class="btn-group w-100 mb-3" role="group" aria-label="Tipo">
                <input type="radio" class="btn-check" name="kind" id="kind_expense" value="expense" <?= $kind === 'expense' ? 'checked' : '' ?> autocomplete="off"><label class="btn btn-outline-danger" for="kind_expense">Despesa</label>
                <input type="radio" class="btn-check" name="kind" id="kind_income" value="income" <?= $kind === 'income' ? 'checked' : '' ?> autocomplete="off"><label class="btn btn-outline-success" for="kind_income">Receita</label>
            </div>
            <div class="row g-3">
                <div class="col-12 col-sm-8">
                    <label for="description" class="form-label">Descrição</label>
                    <input type="text" class="form-control<?= invalid_class('description') ?>" id="description" name="description" value="<?= e((string) $v('description')) ?>" required maxlength="190" placeholder="Ex.: Aluguel, Netflix, Salário" autofocus>
                    <?= field_error('description') ?>
                </div>
                <div class="col-12 col-sm-4">
                    <label for="expected_amount" class="form-label">Valor esperado</label>
                    <div class="input-group"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control<?= invalid_class('expected_amount') ?>" id="expected_amount" name="expected_amount" value="<?= e(old('expected_amount', isset($values['expected_amount']) ? money($values['expected_amount'], false) : '')) ?>" required data-money></div>
                    <?= field_error('expected_amount') ?>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="expected_amount_source" class="form-label">Origem do valor</label>
                    <select class="form-select" id="expected_amount_source" name="expected_amount_source"><?php foreach ($sources as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) $v('expected_amount_source', 'fixed') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                    <div class="form-text">"Média" serve para contas que variam (água, energia): o agendado usa a média das 3 últimas pagas.</div>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="category_id" class="form-label">Categoria</label>
                    <select class="form-select<?= invalid_class('category_id') ?>" id="category_id" name="category_id" data-kind-filter>
                        <option value="">Sem categoria</option>
                        <?php $catOptions($categoriesExpense, 'expense'); $catOptions($categoriesIncome, 'income'); ?>
                    </select>
                    <?= field_error('category_id') ?>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="account_id" class="form-label">Conta / cartão</label>
                    <select class="form-select<?= invalid_class('account_id') ?>" id="account_id" name="account_id">
                        <option value="">Primeira conta ativa</option>
                        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $v('account_id', 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
                    </select>
                    <?= field_error('account_id') ?>
                </div>
                <?php if ($isFamily): ?>
                    <div class="col-12 col-sm-6">
                        <label for="responsible_user_id" class="form-label">Responsável</label>
                        <select class="form-select" id="responsible_user_id" name="responsible_user_id"><option value="">Todos (da casa)</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>" <?= (int) $v('responsible_user_id', 0) === (int) $m['user_id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="responsible_user_id" value="<?= (int) (auth_user()['id'] ?? 0) ?>">
                <?php endif; ?>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-6 col-sm-4">
                    <label for="frequency" class="form-label">Frequência</label>
                    <select class="form-select" id="frequency" name="frequency"><?php foreach ($frequencies as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) $v('frequency', 'monthly') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-6 col-sm-4" data-show-when="frequency=monthly">
                    <label for="day_of_month" class="form-label">Dia do mês</label>
                    <input type="number" min="1" max="31" class="form-control<?= invalid_class('day_of_month') ?>" id="day_of_month" name="day_of_month" value="<?= e((string) $v('day_of_month')) ?>" placeholder="ex.: 10">
                    <div class="form-text">31 vira o último dia em meses curtos.</div>
                </div>
                <div class="col-6 col-sm-4" data-show-when="frequency=monthly">
                    <label for="interval_count" class="form-label">A cada N meses</label>
                    <input type="number" min="1" max="24" class="form-control" id="interval_count" name="interval_count" value="<?= e((string) $v('interval_count', '1')) ?>">
                </div>
                <div class="col-6 col-sm-4" data-show-when="frequency=weekly">
                    <label for="day_of_week" class="form-label">Dia da semana</label>
                    <select class="form-select" id="day_of_week" name="day_of_week"><?php foreach ([1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'] as $d => $l): ?><option value="<?= $d ?>" <?= (int) $v('day_of_week', 1) === $d ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-6 col-sm-4" data-show-when="frequency=yearly">
                    <label for="month_of_year" class="form-label">Mês</label>
                    <select class="form-select" id="month_of_year" name="month_of_year"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= (int) $v('month_of_year', 1) === $m ? 'selected' : '' ?>><?= e(month_name($m)) ?></option><?php endfor; ?></select>
                </div>
                <div class="col-6 col-sm-4" data-show-when="frequency=yearly">
                    <label for="day_of_month_y" class="form-label">Dia</label>
                    <input type="number" min="1" max="31" class="form-control" id="day_of_month_y" name="day_of_month_yearly" value="<?= e((string) $v('day_of_month')) ?>" data-mirror="day_of_month">
                </div>
                <div class="col-6 col-sm-4" data-show-when="frequency=custom">
                    <label for="interval_days" class="form-label">A cada N dias</label>
                    <input type="number" min="1" max="365" class="form-control" id="interval_days" name="interval_days" value="<?= e((string) $v('interval_count', '30')) ?>" data-mirror="interval_count">
                </div>
                <div class="col-6 col-sm-4">
                    <label for="start_date" class="form-label">Começa em</label>
                    <input type="date" class="form-control<?= invalid_class('start_date') ?>" id="start_date" name="start_date" value="<?= e((string) $v('start_date')) ?>" required>
                    <?= field_error('start_date') ?>
                </div>
                <div class="col-6 col-sm-4">
                    <label for="end_date" class="form-label">Termina em <span class="text-body-secondary small">(opcional)</span></label>
                    <input type="date" class="form-control<?= invalid_class('end_date') ?>" id="end_date" name="end_date" value="<?= e((string) $v('end_date')) ?>">
                    <?= field_error('end_date') ?>
                </div>
                <div class="col-6 col-sm-4">
                    <label for="generate_days_ahead" class="form-label">Agendar com antecedência</label>
                    <div class="input-group"><input type="number" min="0" max="366" class="form-control" id="generate_days_ahead" name="generate_days_ahead" value="<?= e((string) $v('generate_days_ahead', '30')) ?>"><span class="input-group-text">dias</span></div>
                </div>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12 col-sm-6 form-check ms-2"><input class="form-check-input" type="checkbox" id="auto_debit" name="auto_debit" value="1" <?= $v('auto_debit') ? 'checked' : '' ?>><label class="form-check-label" for="auto_debit">Débito automático</label></div>
                <div class="col-12 col-sm-6 form-check ms-2"><input class="form-check-input" type="checkbox" id="is_subscription" name="is_subscription" value="1" <?= $v('is_subscription') ? 'checked' : '' ?>><label class="form-check-label" for="is_subscription">É assinatura (entra no radar)</label></div>
                <div class="col-12 col-sm-6 form-check ms-2"><input class="form-check-input" type="checkbox" id="is_major_event" name="is_major_event" value="1" <?= $v('is_major_event') ? 'checked' : '' ?>><label class="form-check-label" for="is_major_event">Evento previsto grande <span class="text-body-secondary small">(PLR, 13º, IPVA…)</span></label></div>
                <div class="col-12 col-sm-6">
                    <label for="notify_days_before" class="form-label">Avisar com antecedência <span class="text-body-secondary small">(dias; se o aviso estiver ligado)</span></label>
                    <input type="number" min="0" max="120" class="form-control" id="notify_days_before" name="notify_days_before" value="<?= e((string) $v('notify_days_before')) ?>" placeholder="ex.: 30">
                </div>
            </div>
            <div class="d-flex justify-content-between mt-4">
                <a class="btn btn-outline-secondary" href="<?= e(route('recurrences.index')) ?>">Cancelar</a>
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Salvar' : 'Criar recorrência' ?></button>
            </div>
            <?php if ($editing): ?><p class="small text-body-secondary mt-3 mb-0">Ao salvar, as ocorrências futuras ainda não confirmadas são refeitas com os novos parâmetros; as já pagas ficam como estão.</p><?php endif; ?>
        </div>
    </form>
</div>
