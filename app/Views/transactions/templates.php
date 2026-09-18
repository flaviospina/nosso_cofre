<?php
// app/Views/transactions/templates.php — modelos favoritos do usuário
/** @var array<int,array<string,mixed>> $templates */
/** @var array<int,array<string,mixed>> $categories */
/** @var array<int,string> $accounts */
$typeLabel = ['expense' => 'Despesa', 'income' => 'Receita', 'transfer' => 'Transferência'];
?>
<div class="nc-maxw-md mx-auto">
    <nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(route('transactions.index')) ?>">Lançamentos</a></li><li class="breadcrumb-item active" aria-current="page">Modelos favoritos</li></ol></nav>
    <h1 class="h3 mb-2">Modelos favoritos</h1>
    <p class="text-body-secondary">Para criar um modelo, abra um lançamento e use "Salvar como modelo". Eles aparecem como atalhos no lançamento rápido.</p>
    <?php if ($templates === []): ?>
        <div class="card nc-card"><div class="card-body text-center py-4 text-body-secondary">Nenhum modelo ainda.</div></div>
    <?php else: ?>
        <div class="card nc-card"><ul class="list-group list-group-flush">
            <?php foreach ($templates as $tpl): $cat = $tpl['category_id'] !== null ? ($categories[(int) $tpl['category_id']] ?? null) : null; ?>
                <li class="list-group-item d-flex align-items-center gap-2">
                    <span class="nc-cat-dot flex-shrink-0" data-bg="<?= e($cat['color'] ?? '#94a3b8') ?>"><i class="bi bi-<?= e($cat['icon'] ?? 'star') ?>" aria-hidden="true"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate"><?= e($tpl['name']) ?></div>
                        <div class="small text-body-secondary text-truncate"><?= e($typeLabel[$tpl['type']] ?? $tpl['type']) ?> · <?= e($tpl['description'] ?? '') ?><?= $tpl['amount'] !== null ? ' · ' . e(money($tpl['amount'])) : '' ?><?= $cat ? ' · ' . e($cat['full_name']) : '' ?><?= isset($accounts[(int) $tpl['account_id']]) ? ' · ' . e($accounts[(int) $tpl['account_id']]) : '' ?></div>
                    </div>
                    <a class="btn btn-sm btn-primary" href="<?= e(route('transactions.create', [], ['modelo' => $tpl['id']])) ?>">Usar</a>
                    <form method="post" action="<?= e(route('transactions.templates.delete', ['id' => $tpl['id']])) ?>" data-confirm="Excluir o modelo &quot;<?= e($tpl['name']) ?>&quot;?"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Excluir modelo <?= e($tpl['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
                </li>
            <?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
</div>
