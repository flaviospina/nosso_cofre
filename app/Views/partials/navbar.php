<?php
// app/Views/partials/navbar.php
/** @var array<string,mixed>|null $user */
/** @var array<string,mixed>|null $household */
?>
<header class="nc-header">
    <nav class="navbar navbar-expand-md nc-navbar" aria-label="Navegação principal">
        <div class="container-fluid px-3">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(route('home')) ?>">
                <img src="<?= e(url('/assets/img/favicon.svg')) ?>" alt="" width="28" height="28">
                <span class="fw-semibold"><?= e($appName) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2 order-md-last">
                <button type="button" class="btn btn-sm nc-btn-icon" id="themeToggle" aria-label="Alternar tema claro/escuro" title="Tema">
                    <i class="bi bi-moon-stars" aria-hidden="true"></i>
                </button>
                <?php if ($user !== null): ?>
                    <span class="nc-avatar" data-bg="<?= e($user['color'] ?? '#0d6efd') ?>" title="<?= e($user['name']) ?>"><?= e(initials((string) $user['name'])) ?></span>
                <?php endif; ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navPrincipal" aria-controls="navPrincipal" aria-expanded="false" aria-label="Abrir menu">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>
            <div class="collapse navbar-collapse" id="navPrincipal">
                <ul class="navbar-nav me-auto mb-2 mb-md-0">
                    <?php if ($user !== null): ?>
                        <?php if ($household !== null): ?>
                            <li class="nav-item"><span class="nav-link text-body-secondary"><i class="bi bi-house-heart me-1" aria-hidden="true"></i><?= e($household['name']) ?></span></li>
                        <?php endif; ?>
                        <?php if (route_exists('dashboard')): ?>
                            <li class="nav-item"><a class="nav-link <?= is_route('dashboard') ? 'active' : '' ?>" href="<?= e(route('dashboard')) ?>">Painel</a></li>
                        <?php endif; ?>
                        <?php if (route_exists('auth.logout')): ?>
                            <li class="nav-item">
                                <form method="post" action="<?= e(route('auth.logout')) ?>" class="d-inline"><?= csrf_field() ?>
                                    <button type="submit" class="nav-link btn btn-link">Sair</button>
                                </form>
                            </li>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (route_exists('auth.login')): ?>
                            <li class="nav-item"><a class="nav-link <?= is_route('auth.login') ? 'active' : '' ?>" href="<?= e(route('auth.login')) ?>">Entrar</a></li>
                        <?php endif; ?>
                        <?php if (route_exists('auth.register')): ?>
                            <li class="nav-item"><a class="nav-link <?= is_route('auth.register') ? 'active' : '' ?>" href="<?= e(route('auth.register')) ?>">Criar conta</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
</header>
