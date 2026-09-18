<?php
// app/Views/account/password.php
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('account-nav', ['active' => 'account.password']) ?>
    <h1 class="h3 mb-3">Alterar senha</h1>
    <form method="post" action="<?= e(route('account.password.update')) ?>" data-once novalidate class="card nc-card">
        <div class="card-body">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="current_password" class="form-label">Senha atual</label>
                <input type="password" class="form-control<?= invalid_class('current_password') ?>" id="current_password" name="current_password" required autocomplete="current-password">
                <?= field_error('current_password') ?>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Nova senha</label>
                <input type="password" class="form-control<?= invalid_class('password') ?>" id="password" name="password" required autocomplete="new-password" minlength="10">
                <?= field_error('password') ?>
                <div class="form-text">Mínimo de 10 caracteres, com letras e números.</div>
            </div>
            <div class="mb-3">
                <label for="password_confirmation" class="form-label">Repita a nova senha</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>
            <p class="small text-body-secondary">Ao trocar a senha, as outras sessões e os acessos "lembrar-me" são encerrados.</p>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Alterar senha</button>
            </div>
        </div>
    </form>
</div>
