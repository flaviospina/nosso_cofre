<?php
// app/Views/transactions/trash.php — lixeira (restaurar ou excluir de vez)
/** @var array<int,array<string,mixed>> $transactions */
/** @var array<int,array<string,mixed>> $categories */
/** @var int $days */
?>
<div class="nc-maxw-md mx-auto">
    <nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(route('transactions.index')) ?>">Lançamentos</a></li><li class="breadcrumb-item active" aria-current="page">Lixeira</li></ol></nav>
    <h1 class="h3 mb-2">Lixeira</h1>
    <p class="text-body-secondary">Lançamentos ficam aqui por <?= $days ?> dias e depois são apagados de vez pela rotina automática.</p>
    <?php if ($transactions === []): ?>
        <div class="card nc-card"><div class="card-body text-center py-4 text-body-secondary">A lixeira está vazia.</div></div>
    <?php else: ?>
        <div class="card nc-card"><ul class="list-group list-group-flush">
            <?php foreach ($transactions as $t): $cat = $t['category_id'] !== null ? ($categories[(int) $t['category_id']] ?? null) : null; ?>
                <li class="list-group-item d-flex align-items-center gap-2">
                    <div class="flex-grow-1 min-w-0">
                        <div class="text-truncate"><?= e($t['description']) ?></div>
                        <div class="small text-body-secondary"><?= e(date_br($t['date'])) ?> · <?= e($cat['full_name'] ?? ($t['type'] === 'transfer' ? 'Transferência' : 'Sem categoria')) ?> · <?= e($t['account_name']) ?> · excluído <?= e(time_ago($t['deleted_at'])) ?></div>
                    </div>
                    <span class="fw-semibold <?= $t['type'] === 'income' ? 'nc-income' : ($t['type'] === 'expense' ? 'nc-expense' : '') ?>"><?= e(money($t['amount'])) ?></span>
                    <?php if (empty($t['masked'])): ?>
                        <form method="post" action="<?= e(route('transactions.restore', ['id' => $t['id']])) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-primary" title="Restaurar" aria-label="Restaurar <?= e($t['description']) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button></form>
                        <form method="post" action="<?= e(route('transactions.destroy', ['id' => $t['id']])) ?>" data-confirm="Excluir definitivamente? Não dá para desfazer."><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir de vez" aria-label="Excluir de vez <?= e($t['description']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button></form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
</div>
