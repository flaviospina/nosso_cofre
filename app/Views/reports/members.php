<?php
// app/Views/reports/members.php — relatório por membro
/** @var string $title */ /** @var string $subtitle */ /** @var array<string,mixed> $report */ /** @var array<string,mixed> $query */
/** @var array<int,array<string,mixed>> $members */ /** @var bool $isFamily */ /** @var string $month */
?>
<?= \App\Core\View::partial('report-toolbar', ['title' => $title, 'subtitle' => $subtitle, 'query' => $query, 'routeName' => 'reports.members']) ?>
<?= \App\Core\View::partial('report-filters', ['routeName' => 'reports.members', 'query' => $query, 'members' => $members, 'isFamily' => false, 'period' => 'month', 'memberId' => null, 'month' => $month]) ?>
<?php if ($report['rows'] === []): ?><div class="card nc-card"><div class="card-body text-body-secondary">Sem lançamentos no mês.</div></div><?php else: ?>
<div class="row g-3">
    <?php foreach ($report['rows'] as $r): ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card nc-card h-100 nc-account-card" data-border="<?= e($r['color']) ?>"><div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2"><span class="nc-avatar" data-bg="<?= e($r['color']) ?>"><?= $r['user_id'] === null ? '<i class="bi bi-people" aria-hidden="true"></i>' : e(initials($r['name'])) ?></span><div><div class="fw-semibold"><?= e($r['name']) ?></div><div class="small text-body-secondary"><?= (int) $r['share'] ?>% das despesas</div></div></div>
            <div class="d-flex justify-content-between small"><span>Receitas</span><span class="nc-income"><?= e(money($r['income'])) ?></span></div>
            <div class="d-flex justify-content-between small"><span>Despesas</span><span class="nc-expense"><?= e(money($r['expense'])) ?></span></div>
            <div class="d-flex justify-content-between small fw-semibold border-top mt-1 pt-1"><span>Saldo</span><span class="<?= $r['balance'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($r['balance'])) ?></span></div>
            <?php if ($r['top'] !== []): ?><div class="small text-body-secondary mt-2">Maiores categorias: <?= e(implode(' · ', array_map(static fn(array $t): string => $t['name'] . ' ' . money($t['amount']), $r['top']))) ?></div><?php endif; ?>
        </div></div></div>
    <?php endforeach; ?>
</div>
<p class="small text-body-secondary mt-3">Total do lar: <?= e(money($report['totals']['income'])) ?> em receitas e <?= e(money($report['totals']['expense'])) ?> em despesas. Lançamentos "Todos (da casa)" são os sem responsável definido.</p>
<?php endif; ?>
