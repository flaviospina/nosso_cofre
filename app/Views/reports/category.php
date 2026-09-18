<?php
// app/Views/reports/category.php — evolução de uma categoria em 12 meses + lançamentos do mês
/** @var string $title */ /** @var string $subtitle */ /** @var array<string,mixed> $report */ /** @var array<string,mixed> $query */
/** @var array<int,array<string,mixed>> $members */ /** @var bool $isFamily */ /** @var string $month */
/** @var array<int,array<string,mixed>> $categories */ /** @var int $categoryId */
\App\Core\View::push('scripts', '<script src="' . e(asset('assets/vendor/chart.umd.js')) . '" nonce="' . e(nonce()) . '"></script><script type="application/json" id="dashboardData" nonce="' . e(nonce()) . '">' . json_encode(['evolution' => $report['rows'], 'evolution_label' => $report['category']['full_name'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script><script src="' . e(asset('assets/js/dashboard.js')) . '" nonce="' . e(nonce()) . '"></script>');
?>
<?= \App\Core\View::partial('report-toolbar', ['title' => $title, 'subtitle' => $subtitle, 'query' => $query, 'routeName' => 'reports.category']) ?>
<form method="get" action="<?= e(route('reports.category')) ?>" class="d-flex flex-wrap align-items-end gap-2 mb-3 nc-no-print">
    <div><label for="f_cat" class="form-label small mb-0">Categoria</label><select class="form-select form-select-sm" id="f_cat" name="categoria"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= $c['depth'] === 1 ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div><label for="f_mes" class="form-label small mb-0">Mês final</label><input type="month" class="form-control form-control-sm" id="f_mes" name="mes" value="<?= e($month) ?>"></div>
    <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
</form>
<div class="row g-2 mb-3">
    <div class="col-4"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Total em 12 meses</div><div class="fs-5 fw-semibold"><?= e(money($report['total'])) ?></div></div></div></div>
    <div class="col-4"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Média mensal</div><div class="fs-5 fw-semibold"><?= e(money($report['average'])) ?></div></div></div></div>
    <div class="col-4"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Maior mês</div><div class="fs-5 fw-semibold nc-expense"><?= e(money($report['max'])) ?></div></div></div></div>
</div>
<div class="card nc-card mb-3"><div class="card-body"><div class="nc-chart"><canvas id="chartEvolution" role="img" aria-label="Evolução mensal da categoria"></canvas></div>
    <details class="small mt-2"><summary class="text-body-secondary">Ver como tabela</summary><table class="table table-sm mt-1"><tbody><?php foreach ($report['rows'] as $r): ?><tr><td><?= e($r['label']) ?></td><td class="text-end"><?= e(money($r['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></details>
</div></div>
<div class="card nc-card"><div class="card-body">
    <h2 class="h6">Lançamentos em <?= e($report['label']) ?></h2>
    <?php if ($report['transactions'] === []): ?><p class="text-body-secondary small mb-0">Nenhum lançamento nesta categoria no mês.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm small nc-report-table mb-0"><thead><tr><th>Data</th><th>Descrição</th><th>Subcategoria</th><th>Conta</th><th>Responsável</th><th class="text-end">Valor</th></tr></thead><tbody>
        <?php foreach ($report['transactions'] as $t): ?><tr><td><?= e(date_br($t['date'])) ?></td><td><?= e($t['description']) ?></td><td><?= e($t['category_name'] ?? '') ?></td><td><?= e($t['account_name']) ?></td><td><?= e($t['responsible_name'] ?? 'Todos') ?></td><td class="text-end <?= $t['type'] === 'income' ? 'nc-income' : 'nc-expense' ?>"><?= e(money($t['amount'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div></div>
