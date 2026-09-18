<?php
// app/Views/dashboard/index.php — painel inicial (indicadores financeiros entram na fase 6)
/** @var array<string,mixed> $household */
/** @var array<int,array<string,mixed>> $members */
/** @var array<int,array<string,mixed>> $accounts */
/** @var string $stage */
$isFamily = $household['type'] === 'family';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Olá, <?= e(auth_user()['name'] ?? '') ?></h1>
    <span class="text-body-secondary"><?= e($household['name']) ?></span>
</div>

<?php if ($stage !== 'done'): ?>
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><i class="bi bi-magic me-1" aria-hidden="true"></i>Falta pouco: conclua a configuração inicial (contas, moeda e avisos).</div>
        <a class="btn btn-sm btn-primary" href="<?= e(route($stage === 'notifications' ? 'onboarding.notifications' : 'onboarding.setup')) ?>">Continuar</a>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100">
            <div class="card-body">
                <h2 class="h5"><i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Contas e cartões</h2>
                <?php if ($accounts === []): ?>
                    <p class="text-body-secondary mb-0">Nenhuma conta ainda. O cadastro completo chega na fase de lançamentos.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($accounts as $a): ?>
                            <li class="d-flex align-items-center gap-2 py-1"><i class="bi bi-<?= e($a['icon'] ?: 'bank') ?> text-body-secondary" aria-hidden="true"></i><?= e($a['name']) ?><span class="small text-body-secondary ms-auto"><?= e(\App\Models\Account::TYPES[$a['type']] ?? $a['type']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100">
            <div class="card-body">
                <h2 class="h5"><i class="bi bi-people me-1" aria-hidden="true"></i><?= $isFamily ? 'Membros do lar' : 'Sua conta' ?></h2>
                <ul class="list-unstyled mb-2">
                    <?php foreach ($members as $m): ?>
                        <li class="d-flex align-items-center gap-2 py-1"><span class="nc-avatar" data-bg="<?= e($m['color']) ?>"><?= e(initials((string) $m['name'])) ?></span><?= e($m['name']) ?><span class="small text-body-secondary ms-auto"><?= e(role_label((string) $m['role'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(route('family.index')) ?>"><?= $isFamily ? 'Gerenciar família' : 'Usar em família' ?></a>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card nc-card">
            <div class="card-body">
                <h2 class="h5"><i class="bi bi-signpost-2 me-1" aria-hidden="true"></i>Próximas etapas do Nosso Cofre</h2>
                <p class="text-body-secondary mb-0">Lançamentos, recorrências, orçamento, radar de assinaturas e o painel com indicadores chegam nas próximas fases. Enquanto isso, deixe sua conta em ordem em <a href="<?= e(route('account.index')) ?>">Minha conta</a>.</p>
            </div>
        </div>
    </div>
</div>
