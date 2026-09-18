<?php
// app/Views/reports/monthly.php — relatório mensal por categoria com comparativos
/** @var string $title */ /** @var string $subtitle */ /** @var array<string,mixed> $report */ /** @var array<string,mixed> $query */
/** @var array<int,array<string,mixed>> $members */ /** @var bool $isFamily */ /** @var int|null $memberId */ /** @var string $month */
$t = $report['totals'];
$delta = static fn(float $d): string => $d == 0 ? '<span class="text-body-secondary">=</span>' : '<span class="' . ($d > 0 ? 'nc-expense' : 'nc-income') . '">' . ($d > 0 ? '+' : '−') . e(money(abs($d))) . '</span>';
?>
<?= \App\Core\View::partial('report-toolbar', ['title' => $title, 'subtitle' => $subtitle, 'query' => $query, 'routeName' => 'reports.monthly']) ?>
<?= \App\Core\View::partial('report-filters', ['routeName' => 'reports.monthly', 'query' => $query, 'members' => $members, 'isFamily' => $isFamily, 'period' => 'month', 'memberId' => $memberId, 'month' => $month]) ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Receitas</div><div class="fs-5 fw-semibold nc-income"><?= e(money($t['income'])) ?></div><div class="small text-body-secondary">antes: <?= e(money($t['previous_income'])) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Despesas</div><div class="fs-5 fw-semibold nc-expense"><?= e(money($t['expense'])) ?></div><div class="small text-body-secondary">antes: <?= e(money($t['previous_expense'])) ?> <?= $delta($t['expense'] - $t['previous_expense']) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Saldo</div><div class="fs-5 fw-semibold <?= $t['balance'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($t['balance'])) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Mesmo mês, ano passado</div><div class="fs-5 fw-semibold"><?= e(money($t['last_year_expense'])) ?></div><div class="small text-body-secondary">em despesas <?= $delta($t['expense'] - $t['last_year_expense']) ?></div></div></div></div>
</div>
<?php if ($report['ranking'] !== []): ?>
    <div class="card nc-card mb-3"><div class="card-body">
        <h2 class="h6"><i class="bi bi-graph-up-arrow me-1 nc-expense" aria-hidden="true"></i>Onde o dinheiro mais cresceu (vs. mês anterior)</h2>
        <ol class="mb-0 small"><?php foreach ($report['ranking'] as $r): ?><li><strong><?= e($r['name']) ?></strong>: <?= e(money($r['previous'])) ?> → <?= e(money($r['amount'])) ?> (<?= $delta($r['delta']) ?>)</li><?php endforeach; ?></ol>
    </div></div>
<?php endif; ?>
<div class="card nc-card mb-3"><div class="card-body">
    <h2 class="h6">Despesas por categoria</h2>
    <?php if ($report['expenses'] === []): ?><p class="text-body-secondary small mb-0">Sem despesas no mês.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm small nc-report-table mb-0">
        <thead><tr><th>Categoria</th><th class="text-end">Este mês</th><th class="text-end">%</th><th class="text-end">Mês anterior</th><th class="text-end">Variação</th><th class="text-end">Ano passado</th></tr></thead>
        <tbody>
            <?php foreach ($report['expenses'] as $e): ?>
                <tr class="fw-semibold"><td><span class="nc-cat-dot nc-cat-dot-sm me-1" data-bg="<?= e($e['color']) ?>"></span><?= e($e['name']) ?></td><td class="text-end"><?= e(money($e['amount'])) ?></td><td class="text-end"><?= (int) $e['pct'] ?>%</td><td class="text-end"><?= e(money($e['previous'])) ?></td><td class="text-end"><?= $delta($e['delta']) ?></td><td class="text-end"><?= e(money($e['last_year'])) ?></td></tr>
                <?php foreach ($e['children'] as $ch): ?><tr class="nc-child text-body-secondary"><td><?= e($ch['name']) ?></td><td class="text-end"><?= e(money($ch['amount'])) ?></td><td></td><td class="text-end"><?= e(money($ch['previous'])) ?></td><td class="text-end"><?= $delta($ch['delta']) ?></td><td></td></tr><?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= e(money($t['expense'])) ?></td><td class="text-end">100%</td><td class="text-end"><?= e(money($t['previous_expense'])) ?></td><td class="text-end"><?= $delta($t['expense'] - $t['previous_expense']) ?></td><td class="text-end"><?= e(money($t['last_year_expense'])) ?></td></tr></tfoot>
    </table></div>
    <?php endif; ?>
</div></div>
<?php if ($report['incomes'] !== []): ?>
<div class="card nc-card"><div class="card-body">
    <h2 class="h6">Receitas por categoria</h2>
    <div class="table-responsive"><table class="table table-sm small nc-report-table mb-0"><thead><tr><th>Categoria</th><th class="text-end">Este mês</th><th class="text-end">Mês anterior</th></tr></thead><tbody>
        <?php foreach ($report['incomes'] as $i): ?><tr><td><?= e($i['name']) ?></td><td class="text-end"><?= e(money($i['amount'])) ?></td><td class="text-end"><?= e(money($i['previous'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div></div>
<?php endif; ?>
