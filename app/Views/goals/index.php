<?php
// app/Views/goals/index.php — metas com progresso, sugestão de aporte e histórico de aportes
/** @var list<array<string,mixed>> $goals */
/** @var array<int,list<array<string,mixed>>> $contributions */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array<int,array<string,mixed>> $members */
/** @var bool $canWrite */
/** @var bool $isFamily */
/** @var string $today */
$open = array_values(array_filter($goals, static fn(array $g): bool => $g['status'] === 'active'));
$closed = array_values(array_filter($goals, static fn(array $g): bool => $g['status'] !== 'active'));
$accountNames = array_column($accounts, 'name', 'id');
$memberNames = array_column($members, 'name', 'user_id');
$card = static function (array $g) use ($contributions, $canWrite, $accountNames, $memberNames, $today): void { ?>
    <div class="col-12 col-md-6">
        <div class="card nc-card h-100 nc-account-card" data-border="<?= e($g['color'] ?: '#0f766e') ?>"><div class="card-body">
            <div class="d-flex align-items-start gap-2">
                <span class="nc-feature-icon fs-5 text-white" data-bg="<?= e($g['color'] ?: '#0f766e') ?>"><i class="bi bi-<?= e($g['icon'] ?: 'flag') ?>" aria-hidden="true"></i></span>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold"><?= e($g['name']) ?> <?php if ($g['status'] === 'done'): ?><span class="badge text-bg-primary">batida 🎉</span><?php elseif ($g['status'] === 'archived'): ?><span class="badge text-bg-secondary">arquivada</span><?php elseif ($g['overdue']): ?><span class="badge text-bg-danger">prazo vencido</span><?php endif; ?></div>
                    <div class="small text-body-secondary"><?= $g['deadline'] ? 'até ' . e(date_br($g['deadline'])) : 'sem prazo' ?><?= $g['linked_account_id'] !== null && isset($accountNames[(int) $g['linked_account_id']]) ? ' · saldo de ' . e($accountNames[(int) $g['linked_account_id']]) : '' ?><?= $g['user_id'] !== null && isset($memberNames[(int) $g['user_id']]) ? ' · ' . e($memberNames[(int) $g['user_id']]) : '' ?></div>
                </div>
                <?php if ($canWrite): ?>
                    <div class="dropdown">
                        <button class="btn btn-sm nc-btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Ações da meta <?= e($g['name']) ?>"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#goalModal" data-edit-goal="<?= e(json_encode(['id' => $g['id'], 'name' => $g['name'], 'target' => money($g['target_amount'], false), 'deadline' => $g['deadline'], 'account' => $g['linked_account_id'], 'user' => $g['user_id'], 'color' => $g['color'] ?: '#0f766e', 'icon' => $g['icon'] ?: 'flag'])) ?>"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Editar</button></li>
                            <li><form method="post" action="<?= e(route('goals.toggle', ['id' => $g['id']])) ?>"><?= csrf_field() ?><button type="submit" class="dropdown-item"><i class="bi bi-archive me-2" aria-hidden="true"></i><?= $g['status'] === 'archived' ? 'Reativar' : 'Arquivar' ?></button></form></li>
                            <li><form method="post" action="<?= e(route('goals.destroy', ['id' => $g['id']])) ?>" data-confirm="Excluir a meta &quot;<?= e($g['name']) ?>&quot;?"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2" aria-hidden="true"></i>Excluir</button></form></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-3">
                <div class="d-flex justify-content-between align-items-baseline"><span class="fs-5 fw-semibold nc-income"><?= e(money($g['saved'])) ?></span><span class="small text-body-secondary">de <?= e(money($g['target_amount'])) ?> · <?= (int) $g['pct'] ?>%</span></div>
                <div class="progress my-1" role="progressbar" aria-label="Progresso de <?= e($g['name']) ?>" aria-valuenow="<?= (int) $g['pct'] ?>" aria-valuemin="0" aria-valuemax="100" data-height="12px"><div class="progress-bar <?= (int) $g['pct'] >= 100 ? 'bg-primary' : 'bg-success' ?>" data-width="<?= (int) $g['pct'] ?>%"></div></div>
                <?php if ($g['status'] === 'active' && $g['gap'] > 0): ?>
                    <div class="small text-body-secondary">Faltam <?= e(money($g['gap'])) ?><?= $g['months_left'] !== null ? ' em ' . (int) $g['months_left'] . ' mês(es)' : '' ?> · sugestão: <strong><?= e(money($g['suggested_monthly'])) ?>/mês</strong></div>
                <?php endif; ?>
            </div>
            <?php if ($canWrite && $g['status'] === 'active'): ?>
                <form method="post" action="<?= e(route('goals.contribute', ['id' => $g['id']])) ?>" class="d-flex flex-wrap gap-1 mt-2"><?= csrf_field() ?>
                    <div class="input-group input-group-sm w-auto flex-grow-1"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control" name="amount" placeholder="<?= e(money($g['suggested_monthly'], false)) ?>" data-money aria-label="Valor do aporte" required></div>
                    <input type="date" class="form-control form-control-sm w-auto" name="date" value="<?= e($today) ?>" aria-label="Data">
                    <input type="text" class="form-control form-control-sm w-auto" name="note" placeholder="observação" maxlength="190" aria-label="Observação">
                    <button type="submit" class="btn btn-sm btn-primary">Guardei</button>
                    <button type="submit" class="btn btn-sm btn-outline-secondary" name="withdraw" value="1">Retirei</button>
                </form>
            <?php endif; ?>
            <?php if (($contributions[(int) $g['id']] ?? []) !== []): ?>
                <details class="mt-2 small"><summary class="text-body-secondary">Últimos aportes</summary>
                    <ul class="list-unstyled mb-0 mt-1">
                        <?php foreach ($contributions[(int) $g['id']] as $c): ?><li class="d-flex justify-content-between"><span><?= e(date_br($c['date'])) ?> · <?= e($c['user_name'] ?? '') ?><?= $c['note'] ? ' · ' . e($c['note']) : '' ?></span><span class="<?= (float) $c['amount'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($c['amount'])) ?></span></li><?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div></div>
    </div>
<?php }; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Metas</h1>
    <?php if ($canWrite): ?><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#goalModal" data-new-goal><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nova meta</button><?php endif; ?>
</div>
<p class="text-body-secondary">Uma meta pode acompanhar o saldo de uma conta (reserva, poupança) ou receber aportes registrados aqui. A sugestão de aporte mensal divide o que falta pelos meses até o prazo.</p>
<?php if ($open === []): ?>
    <div class="card nc-card mb-3"><div class="card-body text-center py-5"><i class="bi bi-flag fs-1 text-body-secondary" aria-hidden="true"></i><p class="mt-2 mb-0">Nenhuma meta ativa. Que tal começar pela reserva de emergência (3 a 6 meses de gastos essenciais)?</p></div></div>
<?php else: ?>
    <div class="row g-3 mb-3"><?php foreach ($open as $g) { $card($g); } ?></div>
<?php endif; ?>
<?php if ($closed !== []): ?>
    <h2 class="h5 text-body-secondary">Concluídas e arquivadas</h2>
    <div class="row g-3 opacity-75"><?php foreach ($closed as $g) { $card($g); } ?></div>
<?php endif; ?>

<?php if ($canWrite): ?>
<div class="modal fade" id="goalModal" tabindex="-1" aria-labelledby="goalModalLabel" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" method="post" action="<?= e(route('goals.store')) ?>" data-once novalidate data-goal-form data-store-url="<?= e(route('goals.store')) ?>" data-update-template="<?= e(route('goals.update', ['id' => 0])) ?>">
        <?= csrf_field() ?>
        <div class="modal-header"><h2 class="modal-title h5" id="goalModalLabel" data-modal-title>Nova meta</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label for="g_name" class="form-label">Nome</label><input type="text" class="form-control<?= invalid_class('name') ?>" id="g_name" name="name" value="<?= e((string) old('name')) ?>" required maxlength="120" placeholder="Ex.: Reserva de emergência, Viagem, Quitar cartão"><?= field_error('name') ?></div>
            <div class="col-6"><label for="g_target" class="form-label">Valor da meta</label><div class="input-group"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control<?= invalid_class('target_amount') ?>" id="g_target" name="target_amount" value="<?= e((string) old('target_amount')) ?>" required data-money></div><?= field_error('target_amount') ?></div>
            <div class="col-6" data-only-new><label for="g_saved" class="form-label">Já guardado</label><div class="input-group"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control" id="g_saved" name="saved_amount" value="<?= e((string) old('saved_amount', '0,00')) ?>" data-money></div></div>
            <div class="col-6"><label for="g_deadline" class="form-label">Prazo</label><input type="date" class="form-control<?= invalid_class('deadline') ?>" id="g_deadline" name="deadline" value="<?= e((string) old('deadline')) ?>"><?= field_error('deadline') ?></div>
            <div class="col-6"><label for="g_account" class="form-label">Acompanhar saldo de</label><select class="form-select" id="g_account" name="linked_account_id"><option value="">— só aportes —</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
            <?php if ($isFamily): ?><div class="col-6"><label for="g_user" class="form-label">Meta de</label><select class="form-select" id="g_user" name="user_id"><option value="">Do lar</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="col-3"><label for="g_color" class="form-label">Cor</label><input type="color" class="form-control form-control-color w-100" id="g_color" name="color" value="<?= e((string) old('color', '#0f766e')) ?>"></div>
            <div class="col-3"><label for="g_icon" class="form-label">Ícone</label><input type="text" class="form-control" id="g_icon" name="icon" value="<?= e((string) old('icon', 'flag')) ?>" maxlength="40"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div>
    </form></div>
</div>
<?php if (has_error('name') || has_error('target_amount')): ?>
<?php \App\Core\View::push('scripts', '<script nonce="' . e(nonce()) . '">document.addEventListener("DOMContentLoaded",function(){var m=document.getElementById("goalModal");if(m&&window.bootstrap){new bootstrap.Modal(m).show();}});</script>'); ?>
<?php endif; ?>
<?php endif; ?>
