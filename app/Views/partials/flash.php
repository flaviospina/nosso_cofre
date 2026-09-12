<?php
// app/Views/partials/flash.php — mensagens de uma única exibição
$flashes = \App\Core\Session::pullFlashes();
$icons = ['success' => 'check-circle', 'info' => 'info-circle', 'warning' => 'exclamation-triangle', 'danger' => 'x-octagon'];
?>
<?php foreach ($flashes as $flash): ?>
    <?php $type = in_array($flash['type'], ['success', 'info', 'warning', 'danger'], true) ? $flash['type'] : 'info'; ?>
    <div class="alert alert-<?= e($type) ?> alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-<?= e($icons[$type]) ?>" aria-hidden="true"></i>
        <div><?= e($flash['message']) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
    </div>
<?php endforeach; ?>
