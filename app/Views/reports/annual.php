<?php
// app/Views/reports/annual.php — relatório anual
/** @var string $title */ /** @var string $subtitle */ /** @var array<string,mixed> $report */ /** @var array<string,mixed> $query */
/** @var array<int,array<string,mixed>> $members */ /** @var bool $isFamily */ /** @var int|null $memberId */ /** @var string $month */
$t = $report['totals'];
\App\Core\View::push('scripts', '<script src="' . e(asset('assets/vendor/chart.umd.js')) . '" nonce="' . e(nonce()) . '"></script><script type="application/json" id="dashboardData" nonce="' . e(nonce()) . '">' . json_encode(['annual' => $report['rows']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script><script src="' . e(asset('assets/js/dashboard.js')) . '" nonce="' . e(nonce()) . '"></script>');
?>
<?= \App\Core\View::partial('report-toolbar', ['title' => $title, 'subtitle' => $subtitle, 'query' => $query, 'routeName' => 'reports.annual']) ?>
<?= \App\Core\View::partial('report-filters', ['routeName' => 'reports.annual', 'query' => $query, 'members' => $members, 'isFamily' => $isFamily, 'period' => 'year', 'memberId' => $memberId, 'month' => $month]) ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Receitas no ano</div><div class="fs-5 fw-semibold nc-income"><?= e(money($t['income'])) ?></div><div class="small text-body-secondary">média <?= e(money($t['avg_income'])) ?>/mês</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Despesas no ano</div><div class="fs-5 fw-semibold nc-expense"><?= e(money($t['expense'])) ?></div><div class="small text-body-secondary">média <?= e(money($t['avg_expense'])) ?>/mês</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Sobra no ano</div><div class="fs-5 fw-semibold <?= $t['balance'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($t['balance'])) ?></div><div class="small text-body-secondary">poupança <?= $t['savings_rate'] === null ? '—' : (int) $t['savings_rate'] . '%' ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Melhor / pior mês</div><div class="small"><?= $report['best'] ? '<span class="nc-income">' . e($report['best']['label']) . ' ' . e(money($report['best']['balance'])) . '</span>' : '—' ?></div><div class="small"><?= $report['worst'] ? '<span class="nc-expense">' . e($report['worst']['label']) . ' ' . e(money($report['worst']['balance'])) . '</span>' : '—' ?></div></div></div></div>
</div>
<div class="card nc-card mb-3"><div class="card-body"><div class="nc-chart nc-chart-lg"><canvas id="chartAnnual" role="img" aria-label="Receitas e despesas por mês"></canvas></div></div></div>
<div class="card nc-card"><div class="card-body">
    <div class="table-responsive"><table class="table table-sm small nc-report-table mb-0">
        <thead><tr><th>Mês</th><th class="text-end">Receitas</th><th class="text-end">Despesas</th><th class="text-end">Saldo</th><th class="text-end">Poupança</th></tr></thead>
        <tbody><?php foreach ($report['rows'] as $r): ?><tr><td><a href="<?= e(route('reports.monthly', [], array_filter(['mes' => $r['month'], 'membro' => $memberId]))) ?>"><?= e($r['label']) ?></a></td><td class="text-end"><?= e(money($r['income'])) ?></td><td class="text-end"><?= e(money($r['expense'])) ?></td><td class="text-end <?= $r['balance'] < 0 ? 'nc-expense' : '' ?>"><?= e(money($r['balance'])) ?></td><td class="text-end"><?= $r['savings_rate'] === null ? '—' : (int) $r['savings_rate'] . '%' ?></td></tr><?php endforeach; ?></tbody>
        <tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= e(money($t['income'])) ?></td><td class="text-end"><?= e(money($t['expense'])) ?></td><td class="text-end"><?= e(money($t['balance'])) ?></td><td class="text-end"><?= $t['savings_rate'] === null ? '—' : (int) $t['savings_rate'] . '%' ?></td></tr></tfoot>
    </table></div>
</div></div>
