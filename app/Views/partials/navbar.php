<?php
// app/Views/partials/navbar.php
/** @var array<string,mixed>|null $user */
/** @var array<string,mixed>|null $household */
$hasHousehold = $household !== null;
?>
<header class="nc-header">
    <nav class="navbar navbar-expand-md nc-navbar" aria-label="Navegação principal">
        <div class="container-fluid px-3">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($user !== null && $hasHousehold ? route('dashboard') : route('home')) ?>">
                <img src="<?= e(url('/assets/img/favicon.svg')) ?>" alt="" width="28" height="28">
                <span class="fw-semibold"><?= e($appName) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2 order-md-last">
                <button type="button" class="btn btn-sm nc-btn-icon" id="themeToggle" aria-label="Alternar tema claro/escuro" title="Tema">
                    <i class="bi bi-moon-stars" aria-hidden="true"></i>
                </button>
                <?php if ($user !== null): ?>
                    <div class="dropdown">
                        <button class="btn p-0 border-0 bg-transparent" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu da conta">
                            <span class="nc-avatar" data-bg="<?= e($user['color'] ?? '#0d6efd') ?>"><?= e(initials((string) $user['name'])) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small text-body-secondary"><?= e($user['name']) ?></span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= e(route('account.index')) ?>"><i class="bi bi-person me-2" aria-hidden="true"></i>Minha conta</a></li>
                            <li><a class="dropdown-item" href="<?= e(route('account.two_factor')) ?>"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Verificação em duas etapas</a></li>
                            <li><a class="dropdown-item" href="<?= e(route('account.sessions')) ?>"><i class="bi bi-phone me-2" aria-hidden="true"></i>Sessões ativas</a></li>
                            <li><a class="dropdown-item" href="<?= e(route('account.activity')) ?>"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Minha atividade</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(route('auth.logout')) ?>"><?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sair</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navPrincipal" aria-controls="navPrincipal" aria-expanded="false" aria-label="Abrir menu">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>
            <div class="collapse navbar-collapse" id="navPrincipal">
                <ul class="navbar-nav me-auto mb-2 mb-md-0">
                    <?php if ($user !== null && $hasHousehold): ?>
                        <li class="nav-item"><a class="nav-link <?= is_route('dashboard') ? 'active' : '' ?>" href="<?= e(route('dashboard')) ?>"><i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Início</a></li>
                        <li class="nav-item"><a class="nav-link <?= is_route('family.*') ? 'active' : '' ?>" href="<?= e(route('family.index')) ?>"><i class="bi bi-people me-1" aria-hidden="true"></i><?= ($household['type'] ?? '') === 'family' ? 'Família' : 'Meu lar' ?></a></li>
                    <?php elseif ($user === null): ?>
                        <li class="nav-item"><a class="nav-link <?= is_route('auth.login') ? 'active' : '' ?>" href="<?= e(route('auth.login')) ?>">Entrar</a></li>
                        <li class="nav-item"><a class="nav-link <?= is_route('auth.register') ? 'active' : '' ?>" href="<?= e(route('auth.register')) ?>">Criar conta</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
</header>
