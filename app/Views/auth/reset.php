<?php
// app/Views/auth/reset.php
/** @var string $token */
/** @var string $email */
ob_start(); ?>
<form method="post" action="<?= e(route('password.update')) ?>" data-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="mb-3">
        <label class="form-label">Conta</label>
        <input type="text" class="form-control" value="<?= e($email) ?>" disabled>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Nova senha</label>
        <input type="password" class="form-control<?= invalid_class('password') ?>" id="password" name="password" required autocomplete="new-password" minlength="10" autofocus>
        <?= field_error('password') ?>
        <div class="form-text">Mínimo de 10 caracteres, com letras e números.</div>
    </div>
    <div class="mb-3">
        <label for="password_confirmation" class="form-label">Repita a nova senha</label>
        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn-primary w-100">Salvar nova senha</button>
</form>
<?php $body = ob_get_clean();
echo \App\Core\View::partial('auth-card', ['heading' => 'Nova senha', 'lead' => 'Ao salvar, todas as sessões antigas são encerradas.', 'body' => $body]);
