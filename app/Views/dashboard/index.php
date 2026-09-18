<?php
// app/Views/dashboard/index.php — painel inicial (saldos, mês atual, a pagar, últimos lançamentos; indicadores completos na fase 6)
/** @var array<string,mixed> $household */
/** @var array<int,array<string,mixed>> $members */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array{cash:float,cards:float,net:float} $totals */
/** @var array<string,mixed> $month */
/** @var string $monthLabel */
/** @var array<int,array<string,mixed>> $upcoming */
/** @var array<int,array<string,mixed>> $latest */
/** @var array<int,array<string,mixed>> $categories */
/** @var string $today */
/** @var string $stage */
/** @var bool $canWrite */
$isFamily = $household['type'] === 'family';
$balance = (float) $month['income'] - (float) $month['expense'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Olá, <?= e(auth_user()['name'] ?? '') ?></h1>
        <span class="text-body-secondary small"><?= e($household['name']) ?> · <?= e($monthLabel) ?></span>
    </div>
    <?php if ($canWrite): ?>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-danger" href="<?= e(route('transactions.create', [], ['tipo' => 'expense'])) ?>"><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>Gasto</a>
            <a class="btn btn-outline-success" href="<?= e(route('transactions.create', [], ['tipo' => 'income'])) ?>"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Receita</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($stage !== 'done'): ?>
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><i class="bi bi-magic me-1" aria-hidden="true"></i>Falta pouco: conclua a configuração inicial (contas, moeda e avisos).</div>
        <a class="btn btn-sm btn-primary" href="<?= e(route($stage === 'notifications' ? 'onboarding.notifications' : 'onboarding.setup')) ?>">Continuar</a>
    </div>
<?php endif; ?>

<div class="row g-2 g-md-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card nc-card nc-stat h-100"><div class="card-body py-3"><div class="small text-body-secondary">Receitas do mês</div><div class="fs-4 fw-semibold nc-income"><?= e(money($month['income'])) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card nc-card nc-stat h-100"><div class="card-body py-3"><div class="small text-body-secondary">Despesas do mês</div><div class="fs-4 fw-semibold nc-expense"><?= e(money($month['expense'])) ?></div><?php if ((float) $month['expense_open'] > 0): ?><div class="small text-body-secondary">a pagar <?= e(money($month['expense_open'])) ?></div><?php endif; ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card nc-card nc-stat h-100"><div class="card-body py-3"><div class="small text-body-secondary">Sobra do mês</div><div class="fs-4 fw-semibold <?= $balance < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($balance)) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card nc-card nc-stat h-100"><div class="card-body py-3"><div class="small text-body-secondary">Em contas − faturas</div><div class="fs-4 fw-semibold <?= $totals['net'] < 0 ? 'nc-expense' : '' ?>"><?= e(money($totals['net'])) ?></div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0"><i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Contas e cartões</h2>
                    <a class="small" href="<?= e(route('accounts.index')) ?>">Gerenciar</a>
                </div>
                <?php if ($accounts === []): ?>
                    <p class="text-body-secondary mb-0">Nenhuma conta ainda. <a href="<?= e(route('accounts.create')) ?>">Cadastre a primeira</a> para começar a lançar.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($accounts as $a): $isCard = $a['type'] === 'credit_card'; $value = $isCard ? abs((float) $a['projected']) : (float) $a['balance']; ?>
                            <li class="d-flex align-items-center gap-2 py-1">
                                <span class="nc-cat-dot nc-cat-dot-sm" data-bg="<?= e($a['color'] ?: '#64748b') ?>"><i class="bi bi-<?= e($a['icon'] ?: 'bank') ?>" aria-hidden="true"></i></span>
                                <span class="text-truncate"><?= e($a['name']) ?></span>
                                <span class="small text-body-secondary d-none d-sm-inline"><?= e($a['type_label']) ?></span>
                                <span class="ms-auto fw-semibold <?= $isCard ? ($value > 0 ? 'nc-expense' : '') : ($value < 0 ? 'nc-expense' : '') ?>"><?= $isCard ? 'fatura ' : '' ?><?= e(money($value)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100">
            <div class="card-body">
                <h2 class="h5"><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>A pagar nos próximos 7 dias</h2>
                <?php if ($upcoming === []): ?>
                    <p class="text-body-secondary mb-0">Nada pendente até <?= e(date_br((new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d'))) ?>. 🎉</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($upcoming as $t): $late = $t['date'] < $today; ?>
                            <li class="d-flex align-items-center gap-2 py-1">
                                <span class="small text-nowrap <?= $late ? 'nc-late fw-semibold' : 'nc-due' ?>"><i class="bi bi-<?= $late ? 'exclamation-circle-fill' : 'clock' ?>" aria-hidden="true"></i> <?= e(date_br($t['date'])) ?></span>
                                <span class="text-truncate"><?= e($t['description']) ?></span>
                                <span class="ms-auto fw-semibold nc-expense"><?= e(money($t['amount'])) ?></span>
                                <?php if ($canWrite && empty($t['masked'])): ?>
                                    <form method="post" action="<?= e(route('transactions.status', ['id' => $t['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="paid"><button type="submit" class="btn btn-sm btn-outline-success py-0" title="Marcar como pago" aria-label="Marcar <?= e($t['description']) ?> como pago"><i class="bi bi-check-lg" aria-hidden="true"></i></button></form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card nc-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Últimos lançamentos</h2>
                    <a class="small" href="<?= e(route('transactions.index')) ?>">Ver todos</a>
                </div>
                <?php if ($latest === []): ?>
                    <p class="text-body-secondary mb-0">Nenhum lançamento ainda. Use os botões <strong>Gasto</strong> e <strong>Receita</strong> acima, ou <a href="<?= e(route('import.index')) ?>">importe um extrato</a>.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($latest as $t): $cat = $t['category_id'] !== null ? ($categories[(int) $t['category_id']] ?? null) : null; ?>
                            <li class="d-flex align-items-center gap-2 py-1">
                                <span class="nc-cat-dot nc-cat-dot-sm" data-bg="<?= e($t['type'] === 'transfer' ? '#64748b' : ($cat['color'] ?? '#94a3b8')) ?>"><i class="bi bi-<?= e($t['type'] === 'transfer' ? 'arrow-left-right' : ($cat['icon'] ?? 'tag')) ?>" aria-hidden="true"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="text-truncate"><?= e($t['description']) ?></div>
                                    <div class="small text-body-secondary text-truncate"><?= e(date_br($t['date'])) ?> · <?= e($cat['full_name'] ?? ($t['type'] === 'transfer' ? 'Transferência' : 'Sem categoria')) ?> · <?= e($t['account_name']) ?></div>
                                </div>
                                <span class="fw-semibold text-nowrap <?= $t['type'] === 'income' ? 'nc-income' : ($t['type'] === 'expense' ? 'nc-expense' : 'text-body-secondary') ?>"><?= $t['type'] === 'income' ? '+' : ($t['type'] === 'expense' ? '−' : '') ?><?= e(money($t['amount'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card nc-card h-100">
            <div class="card-body">
                <h2 class="h5"><i class="bi bi-people me-1" aria-hidden="true"></i><?= $isFamily ? 'Membros do lar' : 'Sua conta' ?></h2>
                <ul class="list-unstyled mb-2">
                    <?php foreach ($members as $m): ?>
                        <li class="d-flex align-items-center gap-2 py-1"><span class="nc-avatar" data-bg="<?= e($m['color']) ?>"><?= e(initials((string) $m['name'])) ?></span><?= e($m['name']) ?><span class="small text-body-secondary ms-auto"><?= e(role_label((string) $m['role'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(route('family.index')) ?>"><?= $isFamily ? 'Gerenciar família' : 'Usar em família' ?></a>
                <p class="small text-body-secondary mt-3 mb-0">Recorrências, orçamento, metas, radar de assinaturas e os indicadores completos chegam nas próximas fases.</p>
            </div>
        </div>
    </div>
</div>
