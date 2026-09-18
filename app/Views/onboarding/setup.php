<?php
// app/Views/onboarding/setup.php — passo 2
/** @var array<string,mixed>|null $household */
/** @var array<string,array{name:string,type:string}> $suggested */
$isFamily = ($household['type'] ?? '') === 'family';
$oldAccounts = (array) old('accounts', ['checking', 'credit_card', 'cash']);
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('onboarding-steps', ['step' => 2]) ?>
    <h1 class="h3 mb-1">Configuração inicial</h1>
    <p class="text-body-secondary">Tudo isso pode ser mudado depois. As categorias em português já vêm prontas.</p>
    <form method="post" action="<?= e(route('onboarding.setup.store')) ?>" data-once novalidate>
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="currency" class="form-label">Moeda</label>
                <select class="form-select" id="currency" name="currency">
                    <?php foreach (['BRL' => 'Real (R$)', 'USD' => 'Dólar (US$)', 'EUR' => 'Euro (€)'] as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= old('currency', $household['currency'] ?? 'BRL') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label for="fiscal_month_start_day" class="form-label">Dia em que seu mês financeiro começa</label>
                <input type="number" min="1" max="28" class="form-control<?= invalid_class('fiscal_month_start_day') ?>" id="fiscal_month_start_day" name="fiscal_month_start_day" value="<?= e(old('fiscal_month_start_day', $household['fiscal_month_start_day'] ?? 1)) ?>">
                <?= field_error('fiscal_month_start_day') ?>
                <div class="form-text">Use o dia do salário se preferir contar de pagamento a pagamento.</div>
            </div>
        </div>
        <fieldset class="mt-4">
            <legend class="h6">Contas e cartões iniciais</legend>
            <div class="row g-2">
                <?php foreach ($suggested as $key => $account): ?>
                    <div class="col-12 col-sm-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="accounts[]" id="acc_<?= e($key) ?>" value="<?= e($key) ?>" <?= in_array($key, $oldAccounts, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="acc_<?= e($key) ?>"><?= e($account['name']) ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-2">
                <label for="custom_accounts" class="form-label">Outras contas (separe por vírgula)</label>
                <input type="text" class="form-control" id="custom_accounts" name="custom_accounts" value="<?= e(old('custom_accounts')) ?>" placeholder="Ex.: Nubank, Cartão Itaú">
            </div>
        </fieldset>
        <div class="mt-4">
            <label for="income" class="form-label">Sua renda mensal estimada (opcional)</label>
            <input type="text" inputmode="decimal" class="form-control<?= invalid_class('income') ?>" id="income" name="income" value="<?= e(old('income')) ?>" placeholder="Ex.: 8.500,00">
            <?= field_error('income') ?>
            <div class="form-text">Serve só para a regra 50/30/20 e a taxa de poupança. Fica visível apenas dentro do seu lar<?= $isFamily ? ' (os outros membros informam a deles)' : '' ?>.</div>
        </div>
        <div class="d-flex justify-content-between mt-4">
            <a class="btn btn-link" href="<?= e(route('onboarding.notifications')) ?>">Pular</a>
            <button type="submit" class="btn btn-primary px-4">Continuar</button>
        </div>
    </form>
</div>
