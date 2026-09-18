<?php
// app/Views/auth/verify-notice.php
/** @var string $email */
ob_start(); ?>
<p>Enviamos um link de confirmação<?= $email !== '' ? ' para <strong>' . e($email) . '</strong>' : '' ?>. Abra o e-mail e clique em <em>Confirmar e-mail</em>. O link vale por 24 horas.</p>
<p class="small text-body-secondary">Não chegou? Confira a pasta de spam. Se preferir, peça um novo link:</p>
<form method="post" action="<?= e(route('verification.resend')) ?>" data-once novalidate class="d-flex gap-2">
    <?= csrf_field() ?>
    <input type="email" class="form-control<?= invalid_class('email') ?>" name="email" value="<?= e(old('email', $email)) ?>" placeholder="seu@email.com" required aria-label="E-mail">
    <button type="submit" class="btn btn-outline-primary text-nowrap">Reenviar</button>
</form>
<?= field_error('email') ?>
<p class="text-center mt-3 mb-0 small"><a href="<?= e(route('auth.login')) ?>">Já confirmei, quero entrar</a></p>
<?php $body = ob_get_clean();
echo \App\Core\View::partial('auth-card', ['heading' => 'Confirme seu e-mail', 'lead' => null, 'body' => $body]);
