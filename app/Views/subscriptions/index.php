<?php
// app/Views/subscriptions/index.php — radar de assinaturas
/** @var array{items:list<array<string,mixed>>,detected:list<array<string,mixed>>,monthly_total:float,alerts:int} $radar */
/** @var array<int,array<string,mixed>> $categories */
/** @var array<int,string> $accounts */
/** @var bool $canWrite */
/** @var string $today */
$flagClass = ['duplicate' => 'text-bg-danger', 'increase' => 'text-bg-warning', 'unused' => 'text-bg-secondary'];
$active = array_values(array_filter($radar['items'], static fn(array $r): bool => (int) $r['is_active'] === 1));
$cancelled = array_values(array_filter($radar['items'], static fn(array $r): bool => (int) $r['is_active'] !== 1));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Radar de assinaturas</h1>
    <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= e(route('recurrences.create')) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nova assinatura</a><?php endif; ?>
</div>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-4"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Total por mês</div><div class="fs-4 fw-semibold nc-expense"><?= e(money($radar['monthly_total'])) ?></div><div class="small text-body-secondary"><?= e(money($radar['monthly_total'] * 12)) ?> por ano</div></div></div></div>
    <div class="col-6 col-md-4"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Assinaturas ativas</div><div class="fs-4 fw-semibold"><?= count($active) ?></div><div class="small text-body-secondary"><?= count($radar['detected']) ?> detectada(s) sem cadastro</div></div></div></div>
    <div class="col-12 col-md-4"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Alertas</div><div class="fs-4 fw-semibold <?= $radar['alerts'] > 0 ? 'nc-late' : 'nc-income' ?>"><?= $radar['alerts'] ?></div><div class="small text-body-secondary">duplicidades e aumentos</div></div></div></div>
</div>
<p class="text-body-secondary small">Sinalizamos <span class="badge text-bg-danger">cobrança duplicada</span> (mesmo nome em mais de uma regra ou cartão), <span class="badge text-bg-warning">aumento de preço</span> (mais de 5 % acima do anterior) e <span class="badge text-bg-secondary">sem uso registrado</span> (60 dias sem você confirmar que usa). Toque em "Usei" quando usar o serviço.</p>

<?php if ($active === []): ?>
    <div class="card nc-card mb-3"><div class="card-body text-center py-4 text-body-secondary">Nenhuma assinatura cadastrada. Marque "É assinatura" nas recorrências ou adote as cobranças detectadas abaixo.</div></div>
<?php else: ?>
    <div class="card nc-card mb-3"><ul class="list-group list-group-flush">
        <?php foreach ($active as $r): $cat = $r['category_id'] !== null ? ($categories[(int) $r['category_id']] ?? null) : null; ?>
            <li class="list-group-item">
                <div class="d-flex align-items-center gap-2">
                    <span class="nc-cat-dot nc-cat-dot-sm flex-shrink-0" data-bg="<?= e($cat['color'] ?? '#94a3b8') ?>"><i class="bi bi-<?= e($cat['icon'] ?? 'broadcast') ?>" aria-hidden="true"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="text-truncate"><?= e($r['description']) ?> <?php foreach ($r['flags'] as $f): ?><span class="badge <?= e($flagClass[$f['kind']] ?? 'text-bg-secondary') ?>" title="<?= e($f['detail']) ?>"><?= e($f['label']) ?></span><?php endforeach; ?></div>
                        <div class="small text-body-secondary text-truncate"><?= e($cat['full_name'] ?? 'Sem categoria') ?><?= $r['account_id'] !== null && isset($accounts[(int) $r['account_id']]) ? ' · ' . e($accounts[(int) $r['account_id']]) : '' ?><?= $r['last_paid'] !== null ? ' · último pago ' . e(money($r['last_paid'])) : '' ?><?= !empty($r['last_usage_confirmed_at']) ? ' · uso confirmado ' . e(date_br($r['last_usage_confirmed_at'])) : '' ?></div>
                    </div>
                    <span class="fw-semibold text-nowrap nc-expense"><?= e(money($r['monthly_amount'])) ?><span class="small text-body-secondary fw-normal">/mês</span></span>
                </div>
                <?php if ($canWrite): ?>
                    <div class="d-flex flex-wrap gap-2 mt-2 ms-md-4">
                        <form method="post" action="<?= e(route('subscriptions.usage', ['id' => $r['id']])) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-hand-thumbs-up me-1" aria-hidden="true"></i>Usei</button></form>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(route('recurrences.edit', ['id' => $r['id']])) ?>">Editar</a>
                        <form method="post" action="<?= e(route('subscriptions.cancel', ['id' => $r['id']])) ?>" data-confirm="Marcar &quot;<?= e($r['description']) ?>&quot; como cancelada? As cobranças futuras agendadas serão removidas."><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Cancelei</button></form>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<?php if ($radar['detected'] !== []): ?>
    <h2 class="h5"><i class="bi bi-search me-1" aria-hidden="true"></i>Cobranças repetidas detectadas nos lançamentos</h2>
    <p class="small text-body-secondary">Mesma descrição em pelo menos 3 meses dos últimos 6, com valor estável. Se for uma assinatura, adote-a para acompanhar.</p>
    <div class="card nc-card mb-3"><ul class="list-group list-group-flush">
        <?php foreach ($radar['detected'] as $d): ?>
            <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                <div class="flex-grow-1 min-w-0">
                    <div class="text-truncate"><?= e($d['description']) ?> <?php foreach ($d['flags'] as $f): ?><span class="badge <?= e($flagClass[$f['kind']] ?? 'text-bg-secondary') ?>" title="<?= e($f['detail']) ?>"><?= e($f['label']) ?></span><?php endforeach; ?></div>
                    <div class="small text-body-secondary"><?= (int) $d['months'] ?> meses · última em <?= e(date_br($d['last_date'])) ?><?= $d['category_id'] !== null && isset($categories[$d['category_id']]) ? ' · ' . e($categories[$d['category_id']]['full_name']) : '' ?></div>
                </div>
                <span class="fw-semibold text-nowrap nc-expense"><?= e(money($d['monthly_amount'])) ?><span class="small text-body-secondary fw-normal">/mês</span></span>
                <?php if ($canWrite): ?>
                    <form method="post" action="<?= e(route('subscriptions.adopt')) ?>"><?= csrf_field() ?>
                        <input type="hidden" name="description" value="<?= e($d['description']) ?>"><input type="hidden" name="amount" value="<?= e(money($d['monthly_amount'], false)) ?>"><input type="hidden" name="category_id" value="<?= (int) ($d['category_id'] ?? 0) ?>"><input type="hidden" name="account_id" value="<?= (int) $d['account_id'] ?>"><input type="hidden" name="day_of_month" value="<?= (int) $d['day_of_month'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">É assinatura</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<?php if ($cancelled !== []): ?>
    <h2 class="h6 text-body-secondary mt-4">Canceladas</h2>
    <ul class="list-unstyled small text-body-secondary">
        <?php foreach ($cancelled as $r): ?><li><i class="bi bi-check2 me-1" aria-hidden="true"></i><?= e($r['description']) ?> · <?= e(money($r['monthly_amount'])) ?>/mês<?= $r['end_date'] ? ' · desde ' . e(date_br($r['end_date'])) : '' ?></li><?php endforeach; ?>
    </ul>
<?php endif; ?>
