<?php
// app/Views/transactions/index.php — lista de lançamentos com filtros, totais, seleção em lote e ações rápidas
/** @var array<int,array<string,mixed>> $transactions */
/** @var array<string,mixed> $filters */
/** @var int $total */
/** @var int $pages */
/** @var array<string,mixed> $sums */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array<int,array<string,mixed>> $categories */
/** @var array<int,array<string,mixed>> $members */
/** @var bool $canWrite */
/** @var bool $isFamily */
/** @var int|null $me */
$from = new DateTimeImmutable($filters['from']);
$to = new DateTimeImmutable($filters['to']);
$isMonth = $from->format('d') === '01' && $to->format('Y-m-d') === $from->modify('last day of this month')->format('Y-m-d');
$q = static function (array $over) use ($filters): array {
    $base = ['de' => $filters['from'], 'ate' => $filters['to'], 'membro' => $filters['member'], 'conta' => $filters['account'] ?: '', 'categoria' => $filters['category'] ?: '', 'tipo' => $filters['type'], 'status' => $filters['status'], 'tag' => $filters['tag'], 'busca' => $filters['search']];
    return array_filter(array_merge($base, $over), static fn($v): bool => $v !== '' && $v !== null);
};
$statusLabel = ['paid' => 'Pago', 'pending' => 'Pendente', 'scheduled' => 'Agendado'];
$statusIcon = ['paid' => 'check-circle-fill nc-income', 'pending' => 'circle nc-due', 'scheduled' => 'clock nc-goal'];
$balance = (float) $sums['income'] - (float) $sums['expense'];
$hasFilters = $filters['member'] !== '' || $filters['account'] || $filters['category'] || $filters['type'] !== '' || $filters['status'] !== '' || $filters['tag'] !== '' || $filters['search'] !== '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Lançamentos</h1>
    <div class="d-flex gap-2">
        <div class="dropdown">
            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots" aria-hidden="true"></i><span class="visually-hidden">Mais opções</span></button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= e(route('transactions.templates')) ?>"><i class="bi bi-star me-2" aria-hidden="true"></i>Modelos favoritos</a></li>
                <li><a class="dropdown-item" href="<?= e(route('import.index')) ?>"><i class="bi bi-file-earmark-arrow-up me-2" aria-hidden="true"></i>Importar extrato</a></li>
                <li><a class="dropdown-item" href="<?= e(route('transactions.trash')) ?>"><i class="bi bi-trash me-2" aria-hidden="true"></i>Lixeira</a></li>
            </ul>
        </div>
        <?php if ($canWrite): ?>
            <a class="btn btn-outline-primary d-none d-sm-inline-flex" href="<?= e(route('transactions.create', [], ['repetir' => 'ultimo'])) ?>" title="Repetir o último lançamento"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Repetir último</a>
            <a class="btn btn-primary" href="<?= e(route('transactions.create')) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Novo</a>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex align-items-center justify-content-between gap-2 mb-3 nc-period">
    <a class="btn btn-sm nc-btn-icon" href="<?= e(route('transactions.index', [], $q(['de' => $from->modify('-1 month')->modify('first day of this month')->format('Y-m-d'), 'ate' => $from->modify('-1 month')->modify('last day of this month')->format('Y-m-d')]))) ?>" aria-label="Mês anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
    <button type="button" class="btn btn-link text-decoration-none fw-semibold text-body" data-bs-toggle="collapse" data-bs-target="#filtros" aria-expanded="<?= $hasFilters ? 'true' : 'false' ?>" aria-controls="filtros">
        <?= $isMonth ? e(month_name((int) $from->format('n')) . ' de ' . $from->format('Y')) : e(date_br($filters['from']) . ' a ' . date_br($filters['to'])) ?>
        <i class="bi bi-funnel<?= $hasFilters ? '-fill text-primary' : '' ?> ms-1" aria-hidden="true"></i>
    </button>
    <a class="btn btn-sm nc-btn-icon" href="<?= e(route('transactions.index', [], $q(['de' => $from->modify('+1 month')->modify('first day of this month')->format('Y-m-d'), 'ate' => $from->modify('+1 month')->modify('last day of this month')->format('Y-m-d')]))) ?>" aria-label="Próximo mês"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
</div>

<div class="collapse <?= $hasFilters ? 'show' : '' ?> mb-3" id="filtros">
    <form method="get" action="<?= e(route('transactions.index')) ?>" class="card nc-card"><div class="card-body">
        <div class="row g-2">
            <div class="col-6 col-md-2"><label for="f_de" class="form-label small mb-0">De</label><input type="date" class="form-control form-control-sm" id="f_de" name="de" value="<?= e($filters['from']) ?>"></div>
            <div class="col-6 col-md-2"><label for="f_ate" class="form-label small mb-0">Até</label><input type="date" class="form-control form-control-sm" id="f_ate" name="ate" value="<?= e($filters['to']) ?>"></div>
            <div class="col-12 col-md-4"><label for="f_busca" class="form-label small mb-0">Buscar na descrição</label><input type="search" class="form-control form-control-sm" id="f_busca" name="busca" value="<?= e($filters['search']) ?>" placeholder="Ex.: mercado"></div>
            <div class="col-6 col-md-2"><label for="f_tipo" class="form-label small mb-0">Tipo</label><select class="form-select form-select-sm" id="f_tipo" name="tipo"><option value="">Todos</option><option value="expense" <?= $filters['type'] === 'expense' ? 'selected' : '' ?>>Despesas</option><option value="income" <?= $filters['type'] === 'income' ? 'selected' : '' ?>>Receitas</option><option value="transfer" <?= $filters['type'] === 'transfer' ? 'selected' : '' ?>>Transferências</option></select></div>
            <div class="col-6 col-md-2"><label for="f_status" class="form-label small mb-0">Situação</label><select class="form-select form-select-sm" id="f_status" name="status"><option value="">Todas</option><?php foreach ($statusLabel as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label for="f_conta" class="form-label small mb-0">Conta</label><select class="form-select form-select-sm" id="f_conta" name="conta"><option value="">Todas</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $filters['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label for="f_categoria" class="form-label small mb-0">Categoria</label><select class="form-select form-select-sm" id="f_categoria" name="categoria"><option value="">Todas</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $filters['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option><?php endforeach; ?></select></div>
            <?php if ($isFamily): ?>
                <div class="col-6 col-md-3"><label for="f_membro" class="form-label small mb-0">Responsável</label><select class="form-select form-select-sm" id="f_membro" name="membro"><option value="">Qualquer um</option><option value="todos" <?= $filters['member'] === 'todos' ? 'selected' : '' ?>>Todos (da casa)</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>" <?= $filters['member'] === (string) $m['user_id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="col-6 col-md-3"><label for="f_tag" class="form-label small mb-0">Etiqueta</label><input type="text" class="form-control form-control-sm" id="f_tag" name="tag" value="<?= e($filters['tag']) ?>" placeholder="viagem"></div>
        </div>
        <div class="d-flex justify-content-end gap-2 mt-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(route('transactions.index')) ?>">Limpar</a>
            <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
        </div>
    </div></form>
</div>

<div class="row g-2 mb-3 text-center">
    <div class="col-4"><div class="card nc-card"><div class="card-body py-2"><div class="small text-body-secondary">Receitas</div><div class="fw-semibold nc-income"><?= e(money($sums['income'])) ?></div><?php if ((float) $sums['income'] - (float) $sums['income_paid'] > 0.009): ?><div class="small text-body-secondary">a receber <?= e(money((float) $sums['income'] - (float) $sums['income_paid'])) ?></div><?php endif; ?></div></div></div>
    <div class="col-4"><div class="card nc-card"><div class="card-body py-2"><div class="small text-body-secondary">Despesas</div><div class="fw-semibold nc-expense"><?= e(money($sums['expense'])) ?></div><?php if ((float) $sums['expense'] - (float) $sums['expense_paid'] > 0.009): ?><div class="small text-body-secondary">a pagar <?= e(money((float) $sums['expense'] - (float) $sums['expense_paid'])) ?></div><?php endif; ?></div></div></div>
    <div class="col-4"><div class="card nc-card"><div class="card-body py-2"><div class="small text-body-secondary">Saldo do período</div><div class="fw-semibold <?= $balance < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($balance)) ?></div></div></div></div>
</div>

<?php if ($transactions === []): ?>
    <div class="card nc-card"><div class="card-body text-center py-5">
        <i class="bi bi-receipt fs-1 text-body-secondary" aria-hidden="true"></i>
        <p class="mt-2 mb-3">Nenhum lançamento neste período<?= $hasFilters ? ' com esses filtros' : '' ?>.</p>
        <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= e(route('transactions.create')) ?>">Registrar o primeiro</a><?php endif; ?>
    </div></div>
<?php else: ?>
    <?php if ($canWrite): ?>
    <form method="post" action="<?= e(route('transactions.bulk')) ?>" id="bulkForm" class="nc-bulk-bar card nc-card shadow d-none" data-bulk-bar>
        <?= csrf_field() ?>
        <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold"><span data-bulk-count>0</span> selecionado(s)</span>
            <select class="form-select form-select-sm w-auto" name="action" aria-label="Ação em lote" data-bulk-action>
                <option value="status">Mudar situação</option>
                <option value="category">Mudar categoria</option>
                <?php if ($isFamily): ?><option value="responsible">Mudar responsável</option><?php endif; ?>
                <option value="private">Tornar privados</option>
                <option value="public">Tornar visíveis</option>
                <option value="trash">Enviar para a lixeira</option>
            </select>
            <select class="form-select form-select-sm w-auto" name="value" aria-label="Novo valor" data-bulk-value="status"><?php foreach ($statusLabel as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
            <select class="form-select form-select-sm w-auto d-none" aria-label="Nova categoria" data-bulk-value="category" disabled><option value="">Sem categoria</option><?php foreach ($categories as $c): ?><?php if ((int) $c['is_active'] === 1 && !$c['hidden']): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['full_name']) ?></option><?php endif; ?><?php endforeach; ?></select>
            <?php if ($isFamily): ?><select class="form-select form-select-sm w-auto d-none" aria-label="Novo responsável" data-bulk-value="responsible" disabled><option value="">Todos (da casa)</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
            <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-clear>Cancelar</button>
        </div>
    </form>
    <?php endif; ?>
    <div class="card nc-card">
        <ul class="list-group list-group-flush nc-tx-list">
            <?php $lastDate = null; foreach ($transactions as $t): $cat = $t['category_id'] !== null ? ($categories[(int) $t['category_id']] ?? null) : null; $masked = !empty($t['masked']); $mine = (int) $t['created_by'] === (int) $me; $isTransfer = $t['type'] === 'transfer'; ?>
                <?php if ($t['date'] !== $lastDate): $lastDate = $t['date']; ?>
                    <li class="list-group-item bg-body-tertiary py-1 small text-body-secondary fw-semibold"><?= e(date_br($t['date'])) ?></li>
                <?php endif; ?>
                <li class="list-group-item d-flex align-items-center gap-2 nc-tx <?= $t['status'] !== 'paid' ? 'nc-tx-pending' : '' ?>">
                    <?php if ($canWrite && !$masked): ?><input class="form-check-input mt-0 flex-shrink-0" type="checkbox" name="ids[]" value="<?= (int) $t['id'] ?>" form="bulkForm" aria-label="Selecionar <?= e($t['description']) ?>" data-bulk-item><?php endif; ?>
                    <span class="nc-cat-dot flex-shrink-0" data-bg="<?= e($isTransfer ? '#64748b' : ($cat['color'] ?? '#94a3b8')) ?>" title="<?= e($isTransfer ? 'Transferência' : ($cat['full_name'] ?? 'Sem categoria')) ?>"><i class="bi bi-<?= e($isTransfer ? 'arrow-left-right' : ($cat['icon'] ?? 'tag')) ?>" aria-hidden="true"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-1 min-w-0">
                            <?php if ($masked || $mine || \App\Services\TransactionPolicy::canEdit($t)): ?>
                                <a class="text-body text-decoration-none text-truncate <?= $masked ? 'fst-italic text-body-secondary' : '' ?>" href="<?= e($masked ? '#' : route('transactions.edit', ['id' => $t['id']])) ?>"><?= e($t['description']) ?></a>
                            <?php else: ?>
                                <span class="text-truncate"><?= e($t['description']) ?></span>
                            <?php endif; ?>
                            <?php if ((int) $t['is_private'] === 1 && !$masked): ?><i class="bi bi-lock-fill small text-body-secondary" title="Privado (só você vê a descrição)" aria-label="Privado"></i><?php endif; ?>
                            <?php if (!empty($t['attachment_path'])): ?><a class="text-body-secondary" href="<?= e(route('transactions.attachment', ['id' => $t['id']])) ?>" title="Comprovante" aria-label="Comprovante"><i class="bi bi-paperclip" aria-hidden="true"></i></a><?php endif; ?>
                            <?php if ((int) $t['auto_debit'] === 1): ?><span class="badge text-bg-light border small" title="Débito automático">auto</span><?php endif; ?>
                        </div>
                        <div class="small text-body-secondary text-truncate">
                            <?= e($isTransfer ? $t['account_name'] . ' → ' . ($t['transfer_account_name'] ?? '?') : ($cat['full_name'] ?? 'Sem categoria') . ' · ' . $t['account_name']) ?>
                            <?php if (is_array($t['tags'] ?? null) && !$masked): foreach ($t['tags'] as $k => $tag): if ($k === 'fitid') { continue; } ?> <a class="badge text-bg-light border text-decoration-none" href="<?= e(route('transactions.index', [], $q(['tag' => $tag]))) ?>">#<?= e((string) $tag) ?></a><?php endforeach; endif; ?>
                        </div>
                    </div>
                    <?php if ($isFamily): ?>
                        <?php if ($t['responsible_user_id'] !== null): ?><span class="nc-avatar nc-avatar-sm flex-shrink-0" data-bg="<?= e($t['responsible_color'] ?? '#6c757d') ?>" title="<?= e($t['responsible_name'] ?? '') ?>"><?= e(initials((string) ($t['responsible_name'] ?? ''))) ?></span><?php else: ?><span class="nc-avatar nc-avatar-sm flex-shrink-0 bg-secondary-subtle text-body" title="Todos (da casa)"><i class="bi bi-people" aria-hidden="true"></i></span><?php endif; ?>
                    <?php endif; ?>
                    <div class="text-end flex-shrink-0">
                        <div class="fw-semibold <?= $isTransfer ? 'text-body-secondary' : ($t['type'] === 'income' ? 'nc-income' : 'nc-expense') ?>"><?= $isTransfer ? '' : ($t['type'] === 'income' ? '+' : '−') ?><?= e(money($t['amount'])) ?></div>
                        <?php if ($canWrite && !$masked): ?>
                            <form method="post" action="<?= e(route('transactions.status', ['id' => $t['id']])) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $t['status'] === 'paid' ? 'pending' : 'paid' ?>">
                                <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none small" title="<?= $t['status'] === 'paid' ? 'Marcar como pendente' : 'Marcar como pago' ?>"><i class="bi bi-<?= e($statusIcon[$t['status']]) ?>" aria-hidden="true"></i> <?= e($statusLabel[$t['status']]) ?></button>
                            </form>
                        <?php else: ?>
                            <span class="small"><i class="bi bi-<?= e($statusIcon[$t['status']]) ?>" aria-hidden="true"></i> <?= e($statusLabel[$t['status']]) ?></span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if ($pages > 1): ?>
        <nav aria-label="Páginas" class="mt-3"><ul class="pagination justify-content-center">
            <?php for ($p = 1; $p <= $pages; $p++): ?><li class="page-item <?= $p === (int) $filters['page'] ? 'active' : '' ?>"><a class="page-link" href="<?= e(route('transactions.index', [], $q(['pagina' => $p]))) ?>"><?= $p ?></a></li><?php endfor; ?>
        </ul></nav>
    <?php endif; ?>
    <p class="small text-body-secondary mt-2"><?= $total ?> lançamento(s). Toque na situação para marcar como pago/pendente; toque na descrição para editar.</p>
<?php endif; ?>

<?php if ($canWrite): ?><a class="nc-fab btn btn-primary rounded-circle shadow d-sm-none" href="<?= e(route('transactions.create')) ?>" aria-label="Novo lançamento"><i class="bi bi-plus-lg fs-4" aria-hidden="true"></i></a><?php endif; ?>
