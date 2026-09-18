<?php
// app/Views/budgets/index.php — orçamento do mês: limites por categoria, projeção, essencial × supérfluo e 50/30/20
/** @var string $month */
/** @var string $monthLabel */
/** @var string $prevMonth */
/** @var string $nextMonth */
/** @var list<array<string,mixed>> $budgets */
/** @var array<string,mixed> $split */
/** @var array<int,array<string,mixed>> $categories */
/** @var array<int,float> $averages */
/** @var array<int,array<string,mixed>> $members */
/** @var bool $hasPrevious */
/** @var bool $canWrite */
/** @var bool $isFamily */
/** @var bool $isCurrent */
$mes = substr($month, 0, 7);
$totalLimit = array_sum(array_map(static fn(array $b): float => (float) $b['limit_amount'], $budgets));
$totalSpent = array_sum(array_map(static fn(array $b): float => (float) $b['spent'], $budgets));
$lightClass = ['green' => 'success', 'yellow' => 'warning', 'red' => 'danger', 'none' => 'secondary'];
$lightText = ['green' => 'Dentro da regra 50/30/20', 'yellow' => 'Perto da regra 50/30/20', 'red' => 'Fora da regra 50/30/20', 'none' => 'Sem receitas no mês'];
$statusBar = ['ok' => '', 'risk' => 'bg-info', 'warn' => 'bg-warning', 'over' => 'bg-danger'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Orçamento</h1>
    <?php if ($canWrite): ?>
        <div class="d-flex gap-2">
            <?php if ($hasPrevious): ?><form method="post" action="<?= e(route('budgets.copy')) ?>"><?= csrf_field() ?><input type="hidden" name="mes" value="<?= e($mes) ?>"><button type="submit" class="btn btn-outline-secondary"><i class="bi bi-files me-1" aria-hidden="true"></i>Copiar do mês anterior</button></form><?php endif; ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#budgetModal" data-new-budget><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Novo limite</button>
        </div>
    <?php endif; ?>
</div>
<div class="d-flex align-items-center justify-content-between gap-2 mb-3 nc-period">
    <a class="btn btn-sm nc-btn-icon" href="<?= e(route('budgets.index', [], ['mes' => $prevMonth])) ?>" aria-label="Mês anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
    <span class="fw-semibold fs-5"><?= e($monthLabel) ?></span>
    <a class="btn btn-sm nc-btn-icon" href="<?= e(route('budgets.index', [], ['mes' => $nextMonth])) ?>" aria-label="Próximo mês"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-7">
        <div class="card nc-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-baseline mb-2">
                <h2 class="h5 mb-0"><i class="bi bi-pie-chart me-1" aria-hidden="true"></i>Limites por categoria</h2>
                <?php if ($budgets !== []): ?><span class="small text-body-secondary"><?= e(money($totalSpent)) ?> de <?= e(money($totalLimit)) ?></span><?php endif; ?>
            </div>
            <?php if ($budgets === []): ?>
                <p class="text-body-secondary mb-0">Nenhum limite neste mês. <?php if ($canWrite): ?>Crie o primeiro (sugestão: comece pelas categorias que mais pesam, como mercado, padaria e delivery).<?php endif; ?></p>
            <?php else: ?>
                <?php foreach ($budgets as $b): $cat = $b['category']; ?>
                    <div class="py-2 border-top">
                        <div class="d-flex align-items-center gap-2">
                            <span class="nc-cat-dot nc-cat-dot-sm flex-shrink-0" data-bg="<?= e($cat['color'] ?? '#94a3b8') ?>"><i class="bi bi-<?= e($cat['icon'] ?? 'tag') ?>" aria-hidden="true"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="text-truncate"><?= e($b['category_name']) ?><?= $b['user_name'] ? ' <span class="small text-body-secondary">(' . e($b['user_name']) . ')</span>' : '' ?></span>
                                    <span class="text-nowrap small"><strong class="<?= $b['status'] === 'over' ? 'nc-late' : ($b['status'] === 'warn' ? 'nc-due' : '') ?>"><?= e(money($b['spent'])) ?></strong> / <?= e(money($b['limit_amount'])) ?></span>
                                </div>
                                <div class="progress my-1" role="progressbar" aria-label="<?= e($b['category_name']) ?>" aria-valuenow="<?= min(100, (int) $b['pct']) ?>" aria-valuemin="0" aria-valuemax="100" data-height="10px">
                                    <div class="progress-bar <?= e($statusBar[$b['status']]) ?>" data-width="<?= min(100, (int) $b['pct']) ?>%"></div>
                                </div>
                                <div class="small text-body-secondary">
                                    <?= (int) $b['pct'] ?>% usado
                                    <?php if ($b['status'] === 'over'): ?> · <span class="nc-late">estourou em <?= e(money(-$b['remaining'])) ?></span>
                                    <?php elseif ($b['burst_day'] !== null): ?> · <span class="nc-due">neste ritmo você estoura no dia <?= (int) $b['burst_day'] ?></span>
                                    <?php elseif ($isCurrent && $b['daily_allow'] !== null): ?> · sobra <?= e(money($b['remaining'])) ?> (<?= e(money($b['daily_allow'])) ?> por dia)
                                    <?php else: ?> · sobra <?= e(money($b['remaining'])) ?><?php endif; ?>
                                    <?php if ($isCurrent && $b['projected'] > 0): ?> · projeção <?= e(money($b['projected'])) ?><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canWrite): ?>
                                <button type="button" class="btn btn-sm nc-btn-icon" data-bs-toggle="modal" data-bs-target="#budgetModal" data-edit-budget="<?= e(json_encode(['id' => $b['id'], 'name' => $b['category_name'], 'limit' => money($b['limit_amount'], false), 'warn' => $b['thresholds'][0]])) ?>" aria-label="Editar limite de <?= e($b['category_name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                <form method="post" action="<?= e(route('budgets.destroy', ['id' => $b['id']])) ?>" data-confirm="Remover este limite?"><?= csrf_field() ?><button type="submit" class="btn btn-sm nc-btn-icon text-danger" aria-label="Remover limite de <?= e($b['category_name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card nc-card h-100"><div class="card-body">
            <h2 class="h5"><i class="bi bi-diagram-2 me-1" aria-hidden="true"></i>Essencial × supérfluo</h2>
            <?php if ($split['expense'] <= 0): ?>
                <p class="text-body-secondary mb-0">Sem despesas registradas neste mês.</p>
            <?php else: ?>
                <div class="progress mb-2" role="progressbar" aria-label="Essencial e supérfluo" aria-valuenow="<?= 100 - (int) $split['superfluous_share'] ?>" aria-valuemin="0" aria-valuemax="100" data-height="14px">
                    <div class="progress-bar bg-success" data-width="<?= 100 - (int) $split['superfluous_share'] ?>%">essencial</div>
                    <div class="progress-bar bg-warning text-dark" data-width="<?= (int) $split['superfluous_share'] ?>%">supérfluo</div>
                </div>
                <div class="d-flex justify-content-between small"><span>Essencial <strong><?= e(money($split['essential'])) ?></strong></span><span>Supérfluo <strong class="nc-due"><?= e(money($split['superfluous'])) ?></strong> (<?= (int) $split['superfluous_share'] ?>%)</span></div>
            <?php endif; ?>
            <hr>
            <h3 class="h6">Regra 50/30/20 <span class="badge text-bg-<?= e($lightClass[$split['light']]) ?>"><?= e($lightText[$split['light']]) ?></span></h3>
            <?php if ($split['income'] > 0): ?>
                <table class="table table-sm small mb-1">
                    <thead><tr><th></th><th class="text-end">Você</th><th class="text-end">Regra</th></tr></thead>
                    <tbody>
                        <tr><td>Necessidades (essencial)</td><td class="text-end <?= $split['needs_pct'] > 50 ? 'nc-late' : 'nc-income' ?>"><?= (int) $split['needs_pct'] ?>%</td><td class="text-end text-body-secondary">≤ 50%</td></tr>
                        <tr><td>Desejos (supérfluo)</td><td class="text-end <?= $split['wants_pct'] > 30 ? 'nc-late' : 'nc-income' ?>"><?= (int) $split['wants_pct'] ?>%</td><td class="text-end text-body-secondary">≤ 30%</td></tr>
                        <tr><td>Poupança (sobra)</td><td class="text-end <?= $split['savings_pct'] < 20 ? 'nc-late' : 'nc-income' ?>"><?= (int) $split['savings_pct'] ?>%</td><td class="text-end text-body-secondary">≥ 20%</td></tr>
                    </tbody>
                </table>
                <p class="small text-body-secondary mb-0">Sobre a receita do mês de <?= e(money($split['income'])) ?>. Categorias marcadas como "essencial" contam como necessidade.</p>
            <?php else: ?>
                <p class="small text-body-secondary mb-0">Registre as receitas do mês para ver a regra 50/30/20.</p>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<?php if ($canWrite): ?>
<div class="modal fade" id="budgetModal" tabindex="-1" aria-labelledby="budgetModalLabel" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" method="post" action="<?= e(route('budgets.store')) ?>" data-once novalidate data-budget-form data-store-url="<?= e(route('budgets.store')) ?>" data-update-template="<?= e(route('budgets.update', ['id' => 0])) ?>">
        <?= csrf_field() ?><input type="hidden" name="mes" value="<?= e($mes) ?>">
        <div class="modal-header"><h2 class="modal-title h5" id="budgetModalLabel" data-modal-title>Novo limite</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-12" data-only-new>
                    <label for="b_category" class="form-label">Categoria</label>
                    <select class="form-select<?= invalid_class('category_id') ?>" id="b_category" name="category_id" required data-budget-category>
                        <option value="">Escolha…</option>
                        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" data-avg="<?= e(isset($averages[(int) $c['id']]) ? money($averages[(int) $c['id']], false) : '') ?>" <?= (int) old('category_id', 0) === (int) $c['id'] ? 'selected' : '' ?>><?= $c['depth'] === 1 ? '— ' : '' ?><?= e($c['name']) ?><?= isset($averages[(int) $c['id']]) ? ' (média ' . e(money($averages[(int) $c['id']])) . ')' : '' ?></option><?php endforeach; ?>
                    </select>
                    <?= field_error('category_id') ?>
                </div>
                <?php if ($isFamily): ?>
                    <div class="col-12" data-only-new>
                        <label for="b_user" class="form-label">Vale para</label>
                        <select class="form-select" id="b_user" name="user_id"><option value="">O lar inteiro</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select>
                    </div>
                <?php endif; ?>
                <div class="col-7">
                    <label for="b_limit" class="form-label">Limite mensal</label>
                    <div class="input-group"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control<?= invalid_class('limit_amount') ?>" id="b_limit" name="limit_amount" value="<?= e((string) old('limit_amount')) ?>" required data-money></div>
                    <div class="form-text"><button type="button" class="btn btn-link btn-sm p-0" data-use-average hidden>Usar a média dos 3 meses</button></div>
                    <?= field_error('limit_amount') ?>
                </div>
                <div class="col-5">
                    <label for="b_warn" class="form-label">Avisar em</label>
                    <div class="input-group"><input type="number" min="1" max="100" class="form-control" id="b_warn" name="warn_at" value="<?= e((string) old('warn_at', '80')) ?>"><span class="input-group-text">%</span></div>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div>
    </form></div>
</div>
<?php if (has_error('category_id') || has_error('limit_amount')): ?>
<?php \App\Core\View::push('scripts', '<script nonce="' . e(nonce()) . '">document.addEventListener("DOMContentLoaded",function(){var m=document.getElementById("budgetModal");if(m&&window.bootstrap){new bootstrap.Modal(m).show();}});</script>'); ?>
<?php endif; ?>
<?php endif; ?>
