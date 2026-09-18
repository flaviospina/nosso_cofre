<?php
// app/Views/reports/account.php — extrato de conta / fatura de cartão
/** @var string $title */ /** @var string $subtitle */ /** @var array<string,mixed> $report */ /** @var array<string,mixed> $query */
/** @var array<int,array<string,mixed>> $members */ /** @var bool $isFamily */ /** @var string $month */
/** @var array<int,array<string,mixed>> $accounts */ /** @var int $accountId */
$a = $report['account'];
?>
<?= \App\Core\View::partial('report-toolbar', ['title' => $title, 'subtitle' => $subtitle, 'query' => $query, 'routeName' => 'reports.account']) ?>
<form method="get" action="<?= e(route('reports.account')) ?>" class="d-flex flex-wrap align-items-end gap-2 mb-3 nc-no-print">
    <div><label for="f_conta" class="form-label small mb-0">Conta / cartão</label><select class="form-select form-select-sm" id="f_conta" name="conta"><?php foreach ($accounts as $ac): ?><option value="<?= (int) $ac['id'] ?>" <?= $accountId === (int) $ac['id'] ? 'selected' : '' ?>><?= e($ac['name']) ?></option><?php endforeach; ?></select></div>
    <div><label for="f_mes" class="form-label small mb-0">Mês</label><input type="month" class="form-control form-control-sm" id="f_mes" name="mes" value="<?= e($month) ?>"></div>
    <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
</form>
<div class="row g-2 mb-3">
    <?php if ($report['is_card']): ?>
        <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Período</div><div class="fw-semibold"><?= e(date_br($report['from'])) ?> a <?= e(date_br($report['to'])) ?></div><?php if ($report['due']): ?><div class="small text-body-secondary">vence <?= e(date_br($report['due'])) ?></div><?php endif; ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Compras no período</div><div class="fs-5 fw-semibold nc-expense"><?= e(money($report['charges'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Pagamentos / estornos</div><div class="fs-5 fw-semibold nc-income"><?= e(money($report['payments'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Total da fatura</div><div class="fs-5 fw-semibold"><?= e(money($report['charges'] - $report['payments'])) ?></div><?php if ($a['limit_amount'] !== null && (float) $a['limit_amount'] > 0): ?><div class="small text-body-secondary">limite <?= e(money($a['limit_amount'])) ?></div><?php endif; ?></div></div></div>
    <?php else: ?>
        <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Saldo inicial</div><div class="fs-5 fw-semibold"><?= e(money($report['opening'])) ?></div><div class="small text-body-secondary">em <?= e(date_br($report['from'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Entradas</div><div class="fs-5 fw-semibold nc-income"><?= e(money($report['payments'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Saídas</div><div class="fs-5 fw-semibold nc-expense"><?= e(money($report['charges'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Saldo final</div><div class="fs-5 fw-semibold <?= $report['closing'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($report['closing'])) ?></div><div class="small text-body-secondary">em <?= e(date_br($report['to'])) ?></div></div></div></div>
    <?php endif; ?>
</div>
<div class="card nc-card"><div class="card-body">
    <?php if ($report['rows'] === []): ?><p class="text-body-secondary small mb-0">Nenhum lançamento no período.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm small nc-report-table mb-0">
        <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Responsável</th><th class="text-end">Valor</th><th class="text-end"><?= $report['is_card'] ? 'Acumulado' : 'Saldo' ?></th></tr></thead>
        <tbody><?php foreach ($report['rows'] as $r): ?><tr><td><?= e(date_br($r['date'])) ?></td><td><?= e($r['description']) ?><?= $r['type'] === 'transfer' ? ' <span class="text-body-secondary">(' . ((int) $r['transfer_account_id'] === $accountId ? 'de ' . e($r['account_name'] ?? '') : 'para ' . e($r['transfer_account_name'] ?? '')) . ')</span>' : '' ?></td><td><?= e($r['category_name'] ?? ($r['type'] === 'transfer' ? 'Transferência' : '')) ?></td><td><?= e($r['responsible_name'] ?? 'Todos') ?></td><td class="text-end <?= $r['delta'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= $r['delta'] >= 0 ? '+' : '−' ?><?= e(money(abs($r['delta']))) ?></td><td class="text-end"><?= e(money($r['running'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div></div>
