<?php
// app/Views/cashflow/index.php — previsão de caixa dia a dia (plano A, item 1)
/** @var array<string,mixed> $forecast */ /** @var int $days */ /** @var string $today */
$f = $forecast;
$withMovement = array_values(array_filter($f['days'], static fn(array $d): bool => $d['in'] > 0 || $d['out'] > 0));
\App\Core\View::push('scripts', '<script src="' . e(asset('assets/vendor/chart.umd.js')) . '" nonce="' . e(nonce()) . '"></script><script type="application/json" id="dashboardData" nonce="' . e(nonce()) . '">' . json_encode(['cashflow' => array_map(static fn(array $d): array => ['date' => substr($d['date'], 8, 2) . '/' . substr($d['date'], 5, 2), 'balance' => $d['balance']], $f['days'])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script><script src="' . e(asset('assets/js/dashboard.js')) . '" nonce="' . e(nonce()) . '"></script>');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h3 mb-0">Previsão de caixa</h1>
    <form method="get" action="<?= e(route('cashflow.index')) ?>" class="d-flex gap-2 align-items-center"><label for="dias" class="small text-body-secondary">Horizonte</label><select class="form-select form-select-sm w-auto" id="dias" name="dias" data-autosubmit><?php foreach ([30, 60, 90, 120] as $d): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $d ?> dias</option><?php endforeach; ?></select></form>
</div>
<p class="text-body-secondary">Parte do que está em conta corrente e dinheiro hoje e aplica, dia a dia, o que já está previsto: pendentes, agendados, recorrências, parcelas e faturas de cartão no vencimento. Não é adivinhação: é o que acontece se nada mudar.</p>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card nc-card h-100"><div class="card-body py-3"><div class="small text-body-secondary">Hoje em conta</div><div class="fs-4 fw-semibold <?= $f['start'] < 0 ? 'nc-expense' : '' ?>"><?= e(money($f['start'])) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card h-100"><div class="card-body py-3"><div class="small text-body-secondary">Ponto mais baixo</div><div class="fs-4 fw-semibold <?= $f['lowest']['balance'] < 0 ? 'nc-late' : 'nc-income' ?>"><?= e(money($f['lowest']['balance'])) ?></div><div class="small text-body-secondary">em <?= e(date_br($f['lowest']['date'])) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card h-100 <?= $f['first_negative'] ? 'border-danger' : '' ?>"><div class="card-body py-3"><div class="small text-body-secondary">Fica negativo?</div><div class="fs-5 fw-semibold <?= $f['first_negative'] ? 'nc-late' : 'nc-income' ?>"><?= $f['first_negative'] ? 'Sim, em ' . e(date_br($f['first_negative'])) : 'Não nos ' . $days . ' dias' ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card h-100"><div class="card-body py-3"><div class="small text-body-secondary">Sobra segura hoje</div><div class="fs-4 fw-semibold nc-goal"><?= e(money($f['safe_surplus'])) ?></div><div class="small text-body-secondary"><?= $f['next_income'] ? 'dá para guardar sem faltar até ' . e(date_br($f['next_income'])) : 'sem receita prevista no horizonte' ?></div></div></div></div>
</div>
<?php if ($f['first_negative']): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><strong>Mês apertado à vista.</strong> Olhe os dias marcados abaixo: adiar uma conta não essencial, antecipar uma receita ou cortar um gasto do <a href="<?= e(route('simulator.index')) ?>" class="alert-link">simulador</a> resolve a curva antes de acontecer.</div>
<?php endif; ?>
<div class="card nc-card mb-3"><div class="card-body"><div class="nc-chart"><canvas id="chartCashflow" role="img" aria-label="Saldo previsto dia a dia"></canvas></div></div></div>
<div class="card nc-card"><div class="card-body">
    <h2 class="h6">Dias com movimento</h2>
    <?php if ($withMovement === []): ?><p class="text-body-secondary small mb-0">Nada previsto no período. Cadastre recorrências para a previsão fazer sentido.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm small nc-report-table mb-0">
        <thead><tr><th>Data</th><th>O que acontece</th><th class="text-end">Entra</th><th class="text-end">Sai</th><th class="text-end">Saldo ao fim do dia</th></tr></thead>
        <tbody><?php foreach ($withMovement as $d): ?><tr class="<?= $d['balance'] < 0 ? 'table-danger' : '' ?>"><td class="text-nowrap"><?= e(date_br($d['date'])) ?><?= $d['date'] === $today ? ' <span class="badge text-bg-secondary">hoje</span>' : '' ?></td><td class="text-wrap"><?= e(implode(' · ', array_slice($d['items'], 0, 6))) ?><?= count($d['items']) > 6 ? ' · +' . (count($d['items']) - 6) : '' ?></td><td class="text-end nc-income"><?= $d['in'] > 0 ? e(money($d['in'])) : '' ?></td><td class="text-end nc-expense"><?= $d['out'] > 0 ? e(money($d['out'])) : '' ?></td><td class="text-end fw-semibold <?= $d['balance'] < 0 ? 'nc-late' : '' ?>"><?= e(money($d['balance'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div></div>
