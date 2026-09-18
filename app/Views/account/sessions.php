<?php
// app/Views/account/sessions.php
/** @var array<int,array<string,mixed>> $sessions */
/** @var string $current */
/** @var array<int,array<string,mixed>> $tokens */
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('account-nav', ['active' => 'account.sessions']) ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Sessões ativas</h1>
        <form method="post" action="<?= e(route('account.sessions.revoke')) ?>" data-once class="d-flex flex-wrap gap-2 align-items-start">
            <?= csrf_field() ?>
            <div>
                <label for="revoke_password" class="visually-hidden">Sua senha</label>
                <input type="password" class="form-control form-control-sm<?= invalid_class('password') ?>" id="revoke_password" name="password" placeholder="Sua senha" autocomplete="current-password" required>
                <?= field_error('password') ?>
            </div>
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Encerrar todas as outras</button>
        </form>
    </div>
    <div class="list-group nc-card mb-4">
        <?php foreach ($sessions as $s): ?>
            <div class="list-group-item d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div><i class="bi bi-<?= str_contains((string) $s['device_label'], 'Android') || str_contains((string) $s['device_label'], 'iOS') ? 'phone' : 'laptop' ?> me-1" aria-hidden="true"></i><?= e($s['device_label'] ?: 'Dispositivo') ?>
                        <?php if ($s['id'] === $current): ?><span class="badge text-bg-success ms-1">esta sessão</span><?php endif; ?></div>
                    <div class="small text-body-secondary">IP <?= e($s['ip']) ?> · ativa <?= e(time_ago($s['last_activity'])) ?> · iniciada em <?= e(datetime_br($s['created_at'])) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <h2 class="h5">Acessos "lembrar-me"</h2>
    <?php if ($tokens === []): ?>
        <p class="text-body-secondary">Nenhum aparelho lembrado.</p>
    <?php else: ?>
        <div class="list-group nc-card">
            <?php foreach ($tokens as $t): ?>
                <div class="list-group-item">
                    <div><?= e($t['device_label'] ?: 'Dispositivo') ?> · IP <?= e($t['ip']) ?></div>
                    <div class="small text-body-secondary">criado em <?= e(datetime_br($t['created_at'])) ?><?= $t['last_used_at'] ? ' · último uso ' . e(time_ago($t['last_used_at'])) : '' ?> · expira em <?= e(datetime_br($t['expires_at'], 'd/m/Y')) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="small text-body-secondary mt-3">"Encerrar todas as outras" derruba as sessões em outros aparelhos e revoga todos os acessos lembrados. Esta sessão continua.</p>
</div>
