<?php
// app/Views/partials/account-nav.php — abas da área da conta
/** @var string $active */
$items = [
    'account.index'      => ['Perfil', 'person'],
    'account.password'   => ['Senha', 'key'],
    'account.two_factor' => ['2FA', 'shield-lock'],
    'account.sessions'   => ['Sessões', 'phone'],
    'account.activity'   => ['Atividade', 'clock-history'],
    'notifications.index'=> ['Notificações', 'bell'],
    'privacy.index'      => ['Privacidade', 'shield-check'],
];
?>
<ul class="nav nav-pills flex-nowrap overflow-auto mb-3 nc-pills">
    <?php foreach ($items as $name => [$label, $icon]): ?>
        <li class="nav-item"><a class="nav-link<?= $active === $name ? ' active' : '' ?>" href="<?= e(route($name)) ?>"><i class="bi bi-<?= e($icon) ?> me-1" aria-hidden="true"></i><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>
