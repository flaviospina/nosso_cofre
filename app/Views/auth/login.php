<?php
// app/Views/auth/login.php
/** @var array{question:string,token:string}|null $captcha */
ob_start(); ?>
<form method="post" action="<?= e(route('auth.login.attempt')) ?>" data-once novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="email" class="form-label">E-mail</label>
        <input type="email" class="form-control<?= invalid_class('email') ?>" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="username" autofocus>
        <?= field_error('email') ?>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Senha</label>
        <input type="password" class="form-control<?= invalid_class('password') ?>" id="password" name="password" required autocomplete="current-password">
        <?= field_error('password') ?>
    </div>
    <?php if ($captcha !== null): ?>
        <div class="mb-3">
            <label for="captcha_answer" class="form-label">Verificação: <?= e($captcha['question']) ?></label>
            <input type="text" inputmode="numeric" class="form-control" id="captcha_answer" name="captcha_answer" required autocomplete="off">
            <input type="hidden" name="captcha_token" value="<?= e($captcha['token']) ?>">
            <div class="form-text">Houve várias tentativas recentes; responda a conta para continuar.</div>
        </div>
    <?php endif; ?>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
        <label class="form-check-label" for="remember">Lembrar-me neste aparelho por 30 dias</label>
    </div>
    <button type="submit" class="btn btn-primary w-100">Entrar</button>
</form>
<div class="d-flex justify-content-between mt-3 small">
    <a href="<?= e(route('password.request')) ?>">Esqueci minha senha</a>
    <a href="<?= e(route('auth.register')) ?>">Criar conta</a>
</div>
<?php $body = ob_get_clean();
echo \App\Core\View::partial('auth-card', ['heading' => 'Entrar', 'lead' => null, 'body' => $body]);
