<?php
// app/Views/auth/two-factor.php
/** @var string $name */
ob_start(); ?>
<form method="post" action="<?= e(route('auth.two_factor.verify')) ?>" data-once novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="code" class="form-label">Código do aplicativo autenticador</label>
        <input type="text" inputmode="numeric" autocomplete="one-time-code" class="form-control form-control-lg text-center<?= invalid_class('code') ?>" id="code" name="code" maxlength="20" required autofocus>
        <?= field_error('code') ?>
        <div class="form-text">Abra o Google Authenticator, Authy ou similar e digite os 6 dígitos. Sem acesso ao aplicativo? Use um código de recuperação (formato xxxxx-xxxxx).</div>
    </div>
    <button type="submit" class="btn btn-primary w-100">Confirmar</button>
</form>
<p class="text-center mt-3 mb-0 small"><a href="<?= e(route('auth.login')) ?>">Voltar</a></p>
<?php $body = ob_get_clean();
echo \App\Core\View::partial('auth-card', ['heading' => 'Olá, ' . $name, 'lead' => 'Segunda etapa da verificação.', 'body' => $body]);
