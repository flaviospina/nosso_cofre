<?php
// app/Views/auth/register.php
/** @var string $termsVersion */
/** @var string $privacyVersion */
/** @var string $invitedEmail */
ob_start(); ?>
<form method="post" action="<?= e(route('auth.register.store')) ?>" data-once novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="name" class="form-label">Seu nome</label>
        <input type="text" class="form-control<?= invalid_class('name') ?>" id="name" name="name" value="<?= e(old('name')) ?>" required autocomplete="name" maxlength="120" autofocus>
        <?= field_error('name') ?>
    </div>
    <div class="mb-3">
        <label for="email" class="form-label">E-mail</label>
        <input type="email" class="form-control<?= invalid_class('email') ?>" id="email" name="email" value="<?= e(old('email', $invitedEmail)) ?>" required autocomplete="email" maxlength="190">
        <?= field_error('email') ?>
        <div class="form-text">Você receberá um link para confirmar o e-mail antes do primeiro acesso.</div>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Senha</label>
        <input type="password" class="form-control<?= invalid_class('password') ?>" id="password" name="password" required autocomplete="new-password" minlength="10">
        <?= field_error('password') ?>
        <div class="form-text">Mínimo de 10 caracteres, com letras e números. Senhas comuns são recusadas.</div>
    </div>
    <div class="mb-3">
        <label for="password_confirmation" class="form-label">Repita a senha</label>
        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
    </div>
    <div class="form-check mb-2">
        <input class="form-check-input<?= invalid_class('adult') ?>" type="checkbox" id="adult" name="adult" value="1" <?= old('adult') ? 'checked' : '' ?>>
        <label class="form-check-label" for="adult">Declaro que tenho 18 anos ou mais.</label>
        <?= field_error('adult') ?>
    </div>
    <div class="form-check mb-3">
        <input class="form-check-input<?= invalid_class('terms') ?>" type="checkbox" id="terms" name="terms" value="1" <?= old('terms') ? 'checked' : '' ?>>
        <label class="form-check-label" for="terms">
            Li e aceito os <a href="<?= e(route('legal.terms')) ?>" target="_blank" rel="noopener">Termos de Uso</a> (v<?= e($termsVersion) ?>)
            e a <a href="<?= e(route('legal.privacy')) ?>" target="_blank" rel="noopener">Política de Privacidade</a> (v<?= e($privacyVersion) ?>).
        </label>
        <?= field_error('terms') ?>
    </div>
    <p class="small text-body-secondary">Guardamos a data, a hora, o IP e a versão dos documentos aceitos, como exige a LGPD. Nenhum dado é vendido ou compartilhado.</p>
    <button type="submit" class="btn btn-primary w-100">Criar conta</button>
</form>
<p class="text-center mt-3 mb-0">Já tem conta? <a href="<?= e(route('auth.login')) ?>">Entrar</a></p>
<?php $body = ob_get_clean();
echo \App\Core\View::partial('auth-card', ['heading' => 'Criar conta', 'lead' => 'Leva um minuto. Depois você escolhe se vai usar sozinho ou em família.', 'body' => $body]);
