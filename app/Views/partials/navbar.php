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
                <?php if ($user !== null && $hasHousehold): $unread = \App\Services\AlertService::unreadCount((int) $user['id']); ?>
                    <a class="btn btn-sm nc-btn-icon position-relative" href="<?= e(route('alerts.index')) ?>" aria-label="Avisos<?= $unread > 0 ? ', ' . $unread . ' não lido(s)' : '' ?>" title="Avisos" data-alerts-bell data-unread="<?= $unread ?>">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger <?= $unread > 0 ? '' : 'd-none' ?>" data-alerts-count><?= $unread ?></span>
                    </a>
                <?php endif; ?>
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
                            <li><a class="dropdown-item" href="<?= e(route('notifications.index')) ?>"><i class="bi bi-bell me-2" aria-hidden="true"></i>Notificações</a></li>
                            <li><a class="dropdown-item" href="<?= e(route('privacy.index')) ?>"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Privacidade e seus dados</a></li>
                            <?php if (\App\Core\Middleware\AdminMiddleware::isAdmin()): ?>
                                <li><a class="dropdown-item" href="<?= e(route('admin.incidents')) ?>"><i class="bi bi-shield-exclamation me-2" aria-hidden="true"></i>Incidentes (controlador)</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('admin.backups')) ?>"><i class="bi bi-database-down me-2" aria-hidden="true"></i>Backups (controlador)</a></li>
                            <?php endif; ?>
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
                        <li class="nav-item"><a class="nav-link <?= is_route('transactions.*') ? 'active' : '' ?>" href="<?= e(route('transactions.index')) ?>"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Lançamentos</a></li>
                        <li class="nav-item"><a class="nav-link <?= is_route('budgets.*') ? 'active' : '' ?>" href="<?= e(route('budgets.index')) ?>"><i class="bi bi-pie-chart me-1" aria-hidden="true"></i>Orçamento</a></li>
                        <li class="nav-item"><a class="nav-link <?= is_route('reports.*') ? 'active' : '' ?>" href="<?= e(route('reports.index')) ?>"><i class="bi bi-bar-chart-line me-1" aria-hidden="true"></i>Relatórios</a></li>
                        <li class="nav-item"><a class="nav-link <?= is_route('accounts.*') ? 'active' : '' ?>" href="<?= e(route('accounts.index')) ?>"><i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Contas</a></li>
                        <li class="nav-item"><a class="nav-link <?= is_route('family.*') ? 'active' : '' ?>" href="<?= e(route('family.index')) ?>"><i class="bi bi-people me-1" aria-hidden="true"></i><?= ($household['type'] ?? '') === 'family' ? 'Família' : 'Meu lar' ?></a></li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle <?= is_route('categories.*') || is_route('import.*') || is_route('recurrences.*') || is_route('subscriptions.*') || is_route('goals.*') || is_route('savings.*') || is_route('simulator.*') ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-grid me-1" aria-hidden="true"></i>Mais</a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="<?= e(route('recurrences.index')) ?>"><i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>Recorrências</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('subscriptions.index')) ?>"><i class="bi bi-broadcast me-2" aria-hidden="true"></i>Radar de assinaturas</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('goals.index')) ?>"><i class="bi bi-flag me-2" aria-hidden="true"></i>Metas</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('savings.index')) ?>"><i class="bi bi-check2-square me-2" aria-hidden="true"></i>Plano de ação</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('simulator.index')) ?>"><i class="bi bi-calculator me-2" aria-hidden="true"></i>Simulador "e se"</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('cashflow.index')) ?>"><i class="bi bi-graph-down me-2" aria-hidden="true"></i>Previsão de caixa</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= e(route('categories.index')) ?>"><i class="bi bi-tags me-2" aria-hidden="true"></i>Categorias</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('import.index')) ?>"><i class="bi bi-file-earmark-arrow-up me-2" aria-hidden="true"></i>Importar extrato</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('transactions.templates')) ?>"><i class="bi bi-star me-2" aria-hidden="true"></i>Modelos favoritos</a></li>
                                <li><a class="dropdown-item" href="<?= e(route('transactions.trash')) ?>"><i class="bi bi-trash me-2" aria-hidden="true"></i>Lixeira</a></li>
                            </ul>
                        </li>
                    <?php elseif ($user === null): ?>
                        <li class="nav-item"><a class="nav-link <?= is_route('auth.login') ? 'active' : '' ?>" href="<?= e(route('auth.login')) ?>">Entrar</a></li>
                        <li class="nav-item"><a class="nav-link <?= is_route('auth.register') ? 'active' : '' ?>" href="<?= e(route('auth.register')) ?>">Criar conta</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
</header>
