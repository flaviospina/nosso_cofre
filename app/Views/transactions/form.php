<?php
// app/Views/transactions/form.php — lançamento rápido (≤ 3 toques) e edição completa
/** @var array<string,mixed>|null $tx */
/** @var array<string,mixed> $values */
/** @var string $today */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array<int,array<string,mixed>> $categoriesExpense */
/** @var array<int,array<string,mixed>> $categoriesIncome */
/** @var array<int,array<string,mixed>> $members */
/** @var array<int,array<string,mixed>> $templates */
/** @var int|null $defaultResponsible */
/** @var int|null $defaultAccount */
/** @var bool $isFamily */
$editing = $tx !== null;
$v = static fn(string $k, mixed $d = ''): mixed => old($k, $values[$k] ?? $d);
$type = (string) $v('type', 'expense');
$amount = old('amount', isset($values['amount']) && $values['amount'] !== null ? money($values['amount'], false) : '');
$tags = old('tags', is_array($values['tags'] ?? null) ? implode(', ', array_filter($values['tags'], static fn($k): bool => $k !== 'fitid', ARRAY_FILTER_USE_KEY)) : '');
$catSelected = (int) $v('category_id', 0);
$chips = static function (array $list, string $kind) use ($catSelected): void {
    foreach ($list as $c): ?>
        <label class="nc-chip <?= $c['depth'] === 1 ? 'nc-chip-child' : '' ?>" data-chip-text="<?= e(mb_strtolower(($c['parent_name'] ?? '') . ' ' . $c['name'])) ?>">
            <input type="radio" name="category_id" value="<?= (int) $c['id'] ?>" <?= $catSelected === (int) $c['id'] ? 'checked' : '' ?> data-kind="<?= e($kind) ?>">
            <span class="nc-chip-body"><span class="nc-cat-dot nc-cat-dot-sm" data-bg="<?= e($c['color'] ?: '#94a3b8') ?>"><i class="bi bi-<?= e($c['icon'] ?: 'tag') ?>" aria-hidden="true"></i></span><?= e($c['name']) ?></span>
        </label>
    <?php endforeach;
};
?>
<div class="nc-maxw-md mx-auto">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><?= $editing ? 'Editar lançamento' : 'Novo lançamento' ?></h1>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(route('transactions.index')) ?>">Voltar</a>
    </div>

    <?php if (!$editing && $templates !== []): ?>
        <div class="mb-3 nc-pills overflow-auto pb-1">
            <span class="small text-body-secondary me-1"><i class="bi bi-star" aria-hidden="true"></i> Modelos:</span>
            <?php foreach ($templates as $tpl): ?><a class="btn btn-sm btn-outline-secondary rounded-pill me-1" href="<?= e(route('transactions.create', [], ['modelo' => $tpl['id']])) ?>"><?= e($tpl['name']) ?></a><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e($editing ? route('transactions.update', ['id' => $tx['id']]) : route('transactions.store')) ?>" enctype="multipart/form-data" data-once novalidate class="card nc-card" id="txForm" data-tx-form data-suggest-url="<?= e(route('transactions.suggest')) ?>">
        <div class="card-body">
            <?= csrf_field() ?>
            <div class="btn-group w-100 mb-3" role="group" aria-label="Tipo do lançamento">
                <input type="radio" class="btn-check" name="type" id="type_expense" value="expense" <?= $type === 'expense' ? 'checked' : '' ?> autocomplete="off"><label class="btn btn-outline-danger" for="type_expense"><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>Despesa</label>
                <input type="radio" class="btn-check" name="type" id="type_income" value="income" <?= $type === 'income' ? 'checked' : '' ?> autocomplete="off"><label class="btn btn-outline-success" for="type_income"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Receita</label>
                <input type="radio" class="btn-check" name="type" id="type_transfer" value="transfer" <?= $type === 'transfer' ? 'checked' : '' ?> autocomplete="off"><label class="btn btn-outline-secondary" for="type_transfer"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Transferência</label>
            </div>
            <?= field_error('type') ?>

            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <label for="amount" class="form-label">Valor</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text">R$</span>
                        <input type="text" inputmode="decimal" class="form-control fw-semibold<?= invalid_class('amount') ?>" id="amount" name="amount" value="<?= e((string) $amount) ?>" placeholder="0,00" required data-money autofocus>
                    </div>
                    <?= field_error('amount') ?>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="date" class="form-label">Data</label>
                    <input type="date" class="form-control<?= invalid_class('date') ?>" id="date" name="date" value="<?= e((string) $v('date', $today)) ?>" required>
                    <div class="mt-1 d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill py-0" data-set-date="0">Hoje</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill py-0" data-set-date="-1">Ontem</button>
                    </div>
                    <?= field_error('date') ?>
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Descrição</label>
                    <input type="text" class="form-control<?= invalid_class('description') ?>" id="description" name="description" value="<?= e((string) $v('description')) ?>" required maxlength="190" placeholder="Ex.: Mercado, Aluguel, Salário" autocomplete="off" data-suggest-category>
                    <?= field_error('description') ?>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="account_id" class="form-label"><span data-label-when="transfer" data-label-text="Conta de origem">Conta / cartão</span></label>
                    <select class="form-select<?= invalid_class('account_id') ?>" id="account_id" name="account_id" required>
                        <?php if ($accounts === []): ?><option value="">Cadastre uma conta primeiro</option><?php endif; ?>
                        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) old('account_id', $defaultAccount ?? ($accounts[0]['id'] ?? 0)) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?><?= (int) $a['is_active'] !== 1 ? ' (arquivada)' : '' ?></option><?php endforeach; ?>
                    </select>
                    <?= field_error('account_id') ?>
                </div>
                <div class="col-12 col-sm-6" data-show-when="type=transfer">
                    <label for="transfer_account_id" class="form-label">Conta de destino</label>
                    <select class="form-select<?= invalid_class('transfer_account_id') ?>" id="transfer_account_id" name="transfer_account_id">
                        <option value="">Escolha…</option>
                        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $v('transfer_account_id', 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
                    </select>
                    <?= field_error('transfer_account_id') ?>
                </div>
                <?php if ($isFamily): ?>
                    <div class="col-12 col-sm-6">
                        <label for="responsible_user_id" class="form-label">Responsável</label>
                        <select class="form-select<?= invalid_class('responsible_user_id') ?>" id="responsible_user_id" name="responsible_user_id">
                            <option value="">Todos (da casa)</option>
                            <?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>" <?= (int) old('responsible_user_id', $defaultResponsible ?? 0) === (int) $m['user_id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
                        </select>
                        <?= field_error('responsible_user_id') ?>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="responsible_user_id" value="<?= (int) ($defaultResponsible ?? 0) ?>">
                <?php endif; ?>
                <div class="col-12" data-show-when="type!=transfer">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="form-label mb-0">Categoria <span class="small text-body-secondary" data-suggest-hint hidden>(sugerida)</span></span>
                        <input type="search" class="form-control form-control-sm w-auto" placeholder="filtrar…" aria-label="Filtrar categorias" data-chip-filter>
                    </div>
                    <div class="nc-chips" data-chips="expense"><?php $chips($categoriesExpense, 'expense'); ?></div>
                    <div class="nc-chips" data-chips="income"><?php $chips($categoriesIncome, 'income'); ?></div>
                    <?= field_error('category_id') ?>
                </div>
                <div class="col-12 col-sm-6">
                    <span class="form-label d-block">Situação</span>
                    <div class="btn-group w-100" role="group" aria-label="Situação">
                        <?php foreach (['paid' => 'Pago', 'pending' => 'Pendente', 'scheduled' => 'Agendado'] as $k => $l): ?>
                            <input type="radio" class="btn-check" name="status" id="status_<?= e($k) ?>" value="<?= e($k) ?>" <?= (string) $v('status', 'paid') === $k ? 'checked' : '' ?> autocomplete="off"><label class="btn btn-outline-secondary" for="status_<?= e($k) ?>"><?= e($l) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$editing): ?>
                    <div class="col-12 col-sm-6" data-show-when="type=expense">
                        <label for="installments" class="form-label">Parcelas</label>
                        <div class="input-group">
                            <input type="number" min="1" max="120" class="form-control" id="installments" name="installments" value="<?= e((string) old('installments', '1')) ?>" data-installments>
                            <span class="input-group-text small" data-installments-preview>à vista</span>
                        </div>
                    </div>
                <?php elseif ($tx['installment_total'] !== null): ?>
                    <div class="col-12 col-sm-6"><span class="form-label d-block">Parcela</span><span class="form-control-plaintext"><?= (int) $tx['installment_no'] ?> de <?= (int) $tx['installment_total'] ?> (cada parcela é editada separadamente)</span></div>
                <?php endif; ?>
            </div>

            <button class="btn btn-link px-0 mt-3 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#maisOpcoes" aria-expanded="<?= ($v('notes') || $tags || $v('is_private') || $v('auto_debit') || has_error('attachment') || ($editing && $tx['attachment_path'])) ? 'true' : 'false' ?>" aria-controls="maisOpcoes"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Mais opções (observações, etiquetas, comprovante, privado)</button>
            <div class="collapse <?= ($v('notes') || $tags || $v('is_private') || $v('auto_debit') || has_error('attachment') || ($editing && $tx['attachment_path'])) ? 'show' : '' ?>" id="maisOpcoes">
                <div class="row g-3 mt-0">
                    <div class="col-12">
                        <label for="notes" class="form-label">Observações <span class="text-body-secondary small">(criptografadas)</span></label>
                        <textarea class="form-control<?= invalid_class('notes') ?>" id="notes" name="notes" rows="2" maxlength="2000"><?= e((string) $v('notes')) ?></textarea>
                        <?= field_error('notes') ?>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="tags" class="form-label">Etiquetas <span class="text-body-secondary small">(separadas por vírgula)</span></label>
                        <input type="text" class="form-control<?= invalid_class('tags') ?>" id="tags" name="tags" value="<?= e((string) $tags) ?>" placeholder="viagem, reforma">
                        <?= field_error('tags') ?>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="attachment" class="form-label">Comprovante <span class="text-body-secondary small">(foto ou PDF até 5 MB)</span></label>
                        <input type="file" class="form-control<?= invalid_class('attachment') ?>" id="attachment" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment">
                        <div class="form-text">Fotos são reduzidas e têm os dados de localização (EXIF) removidos.</div>
                        <?= field_error('attachment') ?>
                        <?php if ($editing && $tx['attachment_path']): ?>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="remove_attachment" name="remove_attachment" value="1">
                                <label class="form-check-label" for="remove_attachment">Remover o comprovante atual (<a href="<?= e(route('transactions.attachment', ['id' => $tx['id']])) ?>"><?= e($tx['attachment_name'] ?: 'ver') ?></a>)</label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-sm-6 form-check ms-2">
                        <input class="form-check-input" type="checkbox" id="auto_debit" name="auto_debit" value="1" <?= $v('auto_debit') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="auto_debit">Débito automático</label>
                    </div>
                    <?php if ($isFamily): ?>
                        <div class="col-12 col-sm-6 form-check ms-2">
                            <input class="form-check-input" type="checkbox" id="is_private" name="is_private" value="1" <?= $v('is_private') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_private">Privado <span class="text-body-secondary small">(os outros veem só o valor nos totais)</span></label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                <?php if (!$editing): ?><button type="submit" class="btn btn-outline-primary" name="save_and_new" value="1">Salvar e novo</button><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-lg px-4"><?= $editing ? 'Salvar' : 'Salvar' ?></button>
            </div>
        </div>
    </form>

    <?php if ($editing): ?>
        <div class="d-flex flex-wrap justify-content-between gap-2 mt-3">
            <form method="post" action="<?= e(route('transactions.template', ['id' => $tx['id']])) ?>" class="d-flex gap-2"><?= csrf_field() ?>
                <input type="text" class="form-control form-control-sm" name="name" placeholder="Nome do modelo" maxlength="80" required aria-label="Nome do modelo">
                <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-star me-1" aria-hidden="true"></i>Salvar como modelo</button>
            </form>
            <form method="post" action="<?= e(route('transactions.delete', ['id' => $tx['id']])) ?>" data-confirm="Enviar este lançamento para a lixeira?"><?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Lixeira</button>
            </form>
        </div>
    <?php endif; ?>
</div>
