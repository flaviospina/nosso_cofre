<?php
// app/Views/layouts/base.php — layout principal (mobile-first, tema claro/escuro, CSP com nonce)
/** @var string $content */
// Variáveis compartilhadas podem faltar se o erro acontecer antes do boot completo (ex.: banco fora do ar)
$appName = $appName ?? (string) config('app.name', 'Nosso Cofre');
$appVersion = $appVersion ?? (string) config('app.version', '');
$title = isset($title) ? $title . ' · ' . $appName : $appName;
$user = auth_user();
$household = \App\Core\Auth::household();
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(url('/')) ?>">
    <meta name="theme-color" content="#0f766e" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b1220" media="(prefers-color-scheme: dark)">
    <meta name="application-name" content="<?= e($appName) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?></title>
    <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/img/favicon.svg')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('/assets/img/favicon-32.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/img/apple-touch-icon.png')) ?>">
    <script nonce="<?= e(nonce()) ?>">
        // Aplica o tema salvo antes da primeira pintura para não piscar
        (function () {
            try {
                var t = localStorage.getItem('nc-theme');
                if (t !== 'light' && t !== 'dark') { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
                document.documentElement.setAttribute('data-bs-theme', t);
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <?= \App\Core\View::stack('head') ?>
</head>
<body class="nc-body">
<a class="visually-hidden-focusable" href="#conteudo">Ir para o conteúdo</a>

<?= \App\Core\View::partial('navbar', ['user' => $user, 'household' => $household]) ?>

<main id="conteudo" class="nc-main container-fluid px-3 py-3">
    <?php if ($user !== null && ($user['status'] ?? '') === 'pending_deletion' && !is_route('privacy.*')): ?>
        <div class="alert alert-warning small"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>A exclusão da sua conta está agendada. <a href="<?= e(route('privacy.index')) ?>">Cancelar ou ver detalhes</a>.</div>
    <?php endif; ?>
    <?= \App\Core\View::partial('flash') ?>
    <?= $content ?>
</main>

<?= \App\Core\View::partial('footer') ?>

<div class="nc-toast-area" id="ncToastArea" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" nonce="<?= e(nonce()) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" nonce="<?= e(nonce()) ?>"></script>
<?= \App\Core\View::stack('scripts') ?>
</body>
</html>
