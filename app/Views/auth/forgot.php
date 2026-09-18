<?php
// app/Views/auth/forgot.php
ob_start(); ?>
<form method="post" action="<?= e(route('password.email')) ?>" data-once novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="email" class="form-label">E-mail da conta</label>
        <input type="email" class="form-control<?= invalid_class('email') ?>" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email" autofocus>
        <?= field_error('email') ?>
    </div>
    <button type="submit" class="btn btn-primary w-100">Enviar link de redefinição</button>
</form>
<p class="text-center mt-3 mb-0 small"><a href="<?= e(route('auth.login')) ?>">Voltar para entrar</a></p>
<?php $body = ob_get_clean();
echo \App\Core\View::partial('auth-card', ['heading' => 'Recuperar senha', 'lead' => 'Enviamos um link válido por 1 hora. Por segurança, a resposta é a mesma exista ou não a conta.', 'body' => $body]);
