<?php
// app/Views/accounts/form.php — nova conta / editar conta
/** @var array<string,mixed>|null $account */
/** @var array<int,array<string,mixed>> $members */
/** @var array<string,string> $types */
$editing = $account !== null;
$v = static fn(string $k, mixed $d = ''): mixed => old($k, $account[$k] ?? $d);
?>
<div class="nc-maxw-md mx-auto">
    <nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(route('accounts.index')) ?>">Contas e cartões</a></li><li class="breadcrumb-item active" aria-current="page"><?= $editing ? 'Editar' : 'Nova' ?></li></ol></nav>
    <h1 class="h3 mb-3"><?= $editing ? 'Editar conta' : 'Nova conta' ?></h1>
    <form method="post" action="<?= e($editing ? route('accounts.update', ['id' => $account['id']]) : route('accounts.store')) ?>" data-once novalidate class="card nc-card">
        <div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <span class="form-label d-block">Tipo</span>
                    <div class="row g-2">
                        <?php foreach ($types as $key => $label): ?>
                            <div class="col-6 col-md-4">
                                <label class="card nc-choice h-100 mb-0">
                                    <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                                        <input class="form-check-input mt-0 flex-shrink-0" type="radio" name="type" value="<?= e($key) ?>" <?= $v('type', 'checking') === $key ? 'checked' : '' ?>>
                                        <i class="bi bi-<?= e(\App\Models\Account::ICONS[$key]) ?> fs-5 text-body-secondary" aria-hidden="true"></i>
                                        <span class="small"><?= e($label) ?></span>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?= field_error('type') ?>
                </div>
                <div class="col-12 col-md-7">
                    <label for="name" class="form-label">Nome</label>
                    <input type="text" class="form-control<?= invalid_class('name') ?>" id="name" name="name" value="<?= e((string) $v('name')) ?>" required maxlength="80" placeholder="Ex.: Nubank, Itaú conjunta, Carteira" autofocus>
                    <?= field_error('name') ?>
                </div>
                <div class="col-12 col-md-5">
                    <label for="institution" class="form-label">Instituição <span class="text-body-secondary">(opcional)</span></label>
                    <input type="text" class="form-control<?= invalid_class('institution') ?>" id="institution" name="institution" value="<?= e((string) $v('institution')) ?>" maxlength="80">
                    <?= field_error('institution') ?>
                </div>
                <div class="col-12 col-md-6">
                    <label for="owner_user_id" class="form-label">De quem é</label>
                    <select class="form-select<?= invalid_class('owner_user_id') ?>" id="owner_user_id" name="owner_user_id">
                        <option value="">Conjunta (do lar)</option>
                        <?php foreach ($members as $m): ?>
                            <option value="<?= (int) $m['user_id'] ?>" <?= (int) $v('owner_user_id', 0) === (int) $m['user_id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('owner_user_id') ?>
                </div>
                <div class="col-12 col-md-6">
                    <label for="initial_balance" class="form-label">Saldo inicial</label>
                    <div class="input-group">
                        <span class="input-group-text">R$</span>
                        <input type="text" inputmode="decimal" class="form-control<?= invalid_class('initial_balance') ?>" id="initial_balance" name="initial_balance" value="<?= e(old('initial_balance', $editing ? money($account['initial_balance'], false) : '0,00')) ?>" data-money placeholder="0,00">
                    </div>
                    <div class="form-text">Saldo no dia em que você começa a usar o app (negativo para dívida: -150,00).</div>
                    <?= field_error('initial_balance') ?>
                </div>
                <div class="col-12" data-show-when="type=credit_card">
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <label for="closing_day" class="form-label">Dia de fechamento</label>
                            <input type="number" min="1" max="31" class="form-control<?= invalid_class('closing_day') ?>" id="closing_day" name="closing_day" value="<?= e((string) $v('closing_day')) ?>">
                            <?= field_error('closing_day') ?>
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="due_day" class="form-label">Dia de vencimento</label>
                            <input type="number" min="1" max="31" class="form-control<?= invalid_class('due_day') ?>" id="due_day" name="due_day" value="<?= e((string) $v('due_day')) ?>">
                            <?= field_error('due_day') ?>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="limit_amount" class="form-label">Limite</label>
                            <div class="input-group"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control<?= invalid_class('limit_amount') ?>" id="limit_amount" name="limit_amount" value="<?= e(old('limit_amount', $editing && $account['limit_amount'] !== null ? money($account['limit_amount'], false) : '')) ?>" data-money></div>
                            <?= field_error('limit_amount') ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <label for="color" class="form-label">Cor</label>
                    <input type="color" class="form-control form-control-color w-100<?= invalid_class('color') ?>" id="color" name="color" value="<?= e((string) $v('color', '#0f766e')) ?>">
                    <?= field_error('color') ?>
                </div>
            </div>
            <div class="d-flex justify-content-between mt-4">
                <a class="btn btn-outline-secondary" href="<?= e(route('accounts.index')) ?>">Cancelar</a>
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Salvar' : 'Criar conta' ?></button>
            </div>
        </div>
    </form>
</div>
