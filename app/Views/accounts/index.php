<?php
// app/Views/accounts/index.php — contas e cartões do lar, com saldo atual e projetado
/** @var array<int,array<string,mixed>> $accounts */
/** @var array{cash:float,cards:float,net:float} $totals */
/** @var bool $canWrite */
/** @var array<int,string> $members */
$active = array_values(array_filter($accounts, static fn(array $a): bool => (int) $a['is_active'] === 1));
$archived = array_values(array_filter($accounts, static fn(array $a): bool => (int) $a['is_active'] !== 1));
$card = static function (array $a) use ($canWrite, $members): void { ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card nc-card h-100 nc-account-card" data-border="<?= e($a['color'] ?: '#94a3b8') ?>">
            <div class="card-body d-flex flex-column gap-2">
                <div class="d-flex align-items-start gap-2">
                    <span class="nc-feature-icon fs-5 text-white" data-bg="<?= e($a['color'] ?: '#64748b') ?>"><i class="bi bi-<?= e($a['icon'] ?: 'bank') ?>" aria-hidden="true"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate"><?= e($a['name']) ?></div>
                        <div class="small text-body-secondary"><?= e($a['type_label']) ?><?= $a['institution'] ? ' · ' . e($a['institution']) : '' ?></div>
                        <div class="small text-body-secondary"><i class="bi bi-person me-1" aria-hidden="true"></i><?= $a['owner_user_id'] === null ? 'Conjunta' : e($members[(int) $a['owner_user_id']] ?? 'Membro') ?></div>
                    </div>
                    <?php if ($canWrite): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm nc-btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Ações da conta <?= e($a['name']) ?>"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= e(route('accounts.edit', ['id' => $a['id']])) ?>"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Editar</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('transactions.index', [], ['conta' => $a['id']])) ?>"><i class="bi bi-list-ul me-2" aria-hidden="true"></i>Ver lançamentos</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" action="<?= e(route('accounts.toggle', ['id' => $a['id']])) ?>"><?= csrf_field() ?>
                                        <button type="submit" class="dropdown-item"><i class="bi bi-archive me-2" aria-hidden="true"></i><?= (int) $a['is_active'] === 1 ? 'Arquivar' : 'Reativar' ?></button>
                                    </form>
                                </li>
                                <li>
                                    <form method="post" action="<?= e(route('accounts.destroy', ['id' => $a['id']])) ?>" data-confirm="Excluir a conta &quot;<?= e($a['name']) ?>&quot;? Só é possível quando não há lançamentos."><?= csrf_field() ?>
                                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2" aria-hidden="true"></i>Excluir</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mt-auto">
                    <?php if ($a['type'] === 'credit_card'): ?>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="small text-body-secondary">Fatura em aberto</span>
                            <span class="fs-5 fw-semibold <?= (float) $a['projected'] < 0 ? 'nc-expense' : '' ?>"><?= e(money(abs((float) $a['projected']))) ?></span>
                        </div>
                        <?php if ($a['limit_amount'] !== null && (float) $a['limit_amount'] > 0): $pct = min(100, (int) round(abs((float) $a['projected']) / (float) $a['limit_amount'] * 100)); ?>
                            <div class="progress mt-1" role="progressbar" aria-label="Uso do limite" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" data-height="6px">
                                <div class="progress-bar <?= $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : '') ?>" data-width="<?= $pct ?>%"></div>
                            </div>
                            <div class="small text-body-secondary mt-1"><?= $pct ?>% do limite de <?= e(money($a['limit_amount'])) ?><?= $a['due_day'] ? ' · vence dia ' . (int) $a['due_day'] : '' ?><?= $a['closing_day'] ? ' · fecha dia ' . (int) $a['closing_day'] : '' ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="small text-body-secondary">Saldo atual</span>
                            <span class="fs-5 fw-semibold <?= (float) $a['balance'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($a['balance'])) ?></span>
                        </div>
                        <?php if (abs((float) $a['projected'] - (float) $a['balance']) >= 0.01): ?>
                            <div class="small text-body-secondary d-flex justify-content-between"><span>Projetado (com pendentes)</span><span><?= e(money($a['projected'])) ?></span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php }; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Contas e cartões</h1>
    <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= e(route('accounts.create')) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nova conta</a><?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-4"><div class="card nc-card h-100"><div class="card-body py-3"><div class="small text-body-secondary">Em contas e dinheiro</div><div class="fs-4 fw-semibold <?= $totals['cash'] < 0 ? 'nc-expense' : '' ?>"><?= e(money($totals['cash'])) ?></div></div></div></div>
    <div class="col-6 col-lg-4"><div class="card nc-card h-100"><div class="card-body py-3"><div class="small text-body-secondary">Faturas de cartão</div><div class="fs-4 fw-semibold <?= $totals['cards'] > 0 ? 'nc-expense' : '' ?>"><?= e(money($totals['cards'])) ?></div></div></div></div>
    <div class="col-12 col-lg-4"><div class="card nc-card h-100"><div class="card-body py-3"><div class="small text-body-secondary">Patrimônio líquido (contas − faturas)</div><div class="fs-4 fw-semibold <?= $totals['net'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($totals['net'])) ?></div></div></div></div>
</div>

<?php if ($active === []): ?>
    <div class="card nc-card"><div class="card-body text-center py-5">
        <i class="bi bi-wallet2 fs-1 text-body-secondary" aria-hidden="true"></i>
        <p class="mt-2 mb-3">Nenhuma conta ativa. Cadastre sua conta corrente, o cartão e o dinheiro em espécie para começar a lançar.</p>
        <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= e(route('accounts.create')) ?>">Criar a primeira conta</a><?php endif; ?>
    </div></div>
<?php else: ?>
    <div class="row g-3"><?php foreach ($active as $a) { $card($a); } ?></div>
<?php endif; ?>

<?php if ($archived !== []): ?>
    <h2 class="h5 mt-4 text-body-secondary">Arquivadas</h2>
    <div class="row g-3 opacity-75"><?php foreach ($archived as $a) { $card($a); } ?></div>
<?php endif; ?>
