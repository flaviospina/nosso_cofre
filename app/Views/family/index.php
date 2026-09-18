<?php
// app/Views/family/index.php — membros, papéis, convites e configurações do lar
/** @var array<string,mixed> $household */
/** @var array<int,array<string,mixed>> $members */
/** @var array<int,array<string,mixed>> $invitations */
/** @var bool $canManage */
/** @var bool $isOwner */
/** @var array<string,string> $roleLabels */
/** @var int $me */
$isFamily = $household['type'] === 'family';
?>
<div class="nc-maxw-md mx-auto">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0"><i class="bi bi-house-heart me-2" aria-hidden="true"></i><?= e($household['name']) ?></h1>
        <span class="badge text-bg-secondary"><?= $isFamily ? 'Lar familiar' : 'Conta individual' ?></span>
    </div>

    <?php if (!$isFamily): ?>
        <div class="card nc-card mb-4">
            <div class="card-body">
                <h2 class="h5">Usar em família</h2>
                <p class="text-body-secondary">Converta sua conta em um lar familiar para convidar outras pessoas. Nada do que você já registrou se perde. Como responsável, você precisará ativar a verificação em duas etapas.</p>
                <?php if ($isOwner): ?>
                    <form method="post" action="<?= e(route('family.convert')) ?>" data-once novalidate class="row g-2">
                        <?= csrf_field() ?>
                        <div class="col-12 col-sm-8">
                            <label for="name" class="form-label">Nome do lar</label>
                            <input type="text" class="form-control<?= invalid_class('name') ?>" id="name" name="name" value="<?= e(old('name', 'Família ' . (auth_user()['name'] ?? ''))) ?>" required maxlength="120">
                            <?= field_error('name') ?>
                        </div>
                        <div class="col-12 col-sm-4 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">Converter</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <h2 class="h5">Membros</h2>
    <div class="list-group nc-card mb-4">
        <?php foreach ($members as $m): ?>
            <div class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="nc-avatar" data-bg="<?= e($m['color']) ?>"><?= e(initials((string) $m['name'])) ?></span>
                    <div>
                        <div><?= e($m['name']) ?><?= (int) $m['user_id'] === $me ? ' <span class="small text-body-secondary">(você)</span>' : '' ?></div>
                        <div class="small text-body-secondary"><?= e($roleLabels[$m['role']] ?? $m['role']) ?> · desde <?= e(datetime_br($m['joined_at'], 'd/m/Y')) ?></div>
                    </div>
                </div>
                <?php if ($isFamily && $canManage && $m['role'] !== 'owner' && (int) $m['user_id'] !== $me && ($isOwner || $m['role'] !== 'admin')): ?>
                    <div class="d-flex gap-2">
                        <form method="post" action="<?= e(route('family.member.role', ['id' => $m['id']])) ?>" class="d-flex gap-1" data-once>
                            <?= csrf_field() ?>
                            <select name="role" class="form-select form-select-sm" aria-label="Papel de <?= e($m['name']) ?>">
                                <?php foreach (['admin', 'member', 'viewer'] as $role): ?>
                                    <?php if ($role === 'admin' && !$isOwner) continue; ?>
                                    <option value="<?= e($role) ?>" <?= $m['role'] === $role ? 'selected' : '' ?>><?= e($roleLabels[$role]) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Salvar</button>
                        </form>
                        <form method="post" action="<?= e(route('family.member.remove', ['id' => $m['id']])) ?>" data-once data-confirm="Remover <?= e($m['name']) ?> do lar? Os lançamentos dele continuam no lar.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Remover <?= e($m['name']) ?>"><i class="bi bi-person-x" aria-hidden="true"></i></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($isFamily && $canManage): ?>
        <h2 class="h5">Convidar membro</h2>
        <form method="post" action="<?= e(route('family.invite')) ?>" data-once novalidate class="card nc-card mb-4">
            <div class="card-body row g-2">
                <?= csrf_field() ?>
                <div class="col-12 col-sm-6">
                    <label for="invite_email" class="form-label">E-mail</label>
                    <input type="email" class="form-control<?= invalid_class('email') ?>" id="invite_email" name="email" value="<?= e(old('email')) ?>" required>
                    <?= field_error('email') ?>
                </div>
                <div class="col-8 col-sm-4">
                    <label for="invite_role" class="form-label">Papel</label>
                    <select class="form-select" id="invite_role" name="role">
                        <?php foreach (['member', 'viewer', 'admin'] as $role): ?>
                            <?php if ($role === 'admin' && !$isOwner) continue; ?>
                            <option value="<?= e($role) ?>" <?= old('role', 'member') === $role ? 'selected' : '' ?>><?= e($roleLabels[$role]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-4 col-sm-2 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">Enviar</button></div>
                <div class="col-12 form-text">
                    <strong>Administrador</strong>: tudo, menos excluir o lar. <strong>Membro</strong>: registra e vê lançamentos, edita só os próprios. <strong>Somente leitura</strong>: só consulta (ex.: filho adolescente, contador).
                </div>
            </div>
        </form>

        <?php if ($invitations !== []): ?>
            <h2 class="h5">Convites pendentes</h2>
            <div class="list-group nc-card mb-4">
                <?php foreach ($invitations as $inv): ?>
                    <div class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <div><?= e($inv['email']) ?> <span class="badge text-bg-light border"><?= e($roleLabels[$inv['role']] ?? $inv['role']) ?></span></div>
                            <div class="small text-body-secondary">convidado por <?= e($inv['invited_by_name']) ?> · expira em <?= e(datetime_br($inv['expires_at'])) ?></div>
                        </div>
                        <div class="d-flex gap-2">
                            <form method="post" action="<?= e(route('family.invite.resend', ['id' => $inv['id']])) ?>" data-once><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary">Reenviar</button></form>
                            <form method="post" action="<?= e(route('family.invite.revoke', ['id' => $inv['id']])) ?>" data-once><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Cancelar</button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2 class="h5">Configurações do lar</h2>
        <form method="post" action="<?= e(route('family.settings')) ?>" data-once novalidate class="card nc-card mb-4">
            <div class="card-body">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="household_name" class="form-label">Nome do lar</label>
                    <input type="text" class="form-control<?= invalid_class('name') ?>" id="household_name" name="name" value="<?= e(old('name', $household['name'])) ?>" required maxlength="120">
                    <?= field_error('name') ?>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="members_can_edit_others" name="members_can_edit_others" value="1" <?= !empty($household['settings']['members_can_edit_others']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="members_can_edit_others">Membros podem editar lançamentos dos outros</label>
                </div>
                <div class="d-flex justify-content-end"><button type="submit" class="btn btn-primary">Salvar</button></div>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($isFamily && $isOwner && count($members) > 1): ?>
        <details class="card nc-card mb-4">
            <summary class="card-body py-3 fw-semibold">Transferir a responsabilidade pelo lar</summary>
            <div class="card-body pt-0">
                <p class="small text-body-secondary">O novo responsável passa a gerenciar membros e a exclusão do lar; você vira administrador. Ele precisará ativar o 2FA.</p>
                <form method="post" action="<?= e(route('family.transfer')) ?>" data-once data-confirm="Transferir a responsabilidade do lar?" class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-12 col-sm-8"><select class="form-select" name="member_id" aria-label="Novo responsável">
                        <?php foreach ($members as $m): if ((int) $m['user_id'] === $me) continue; ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?> (<?= e($roleLabels[$m['role']] ?? $m['role']) ?>)</option><?php endforeach; ?>
                    </select></div>
                    <div class="col-12 col-sm-4"><button class="btn btn-outline-danger w-100">Transferir</button></div>
                </form>
            </div>
        </details>
    <?php endif; ?>
    <?php if ($isFamily && !$isOwner): ?>
        <p class="small text-body-secondary">Para sair deste lar (levando ou não seus lançamentos), use <a href="<?= e(route('privacy.index')) ?>">Privacidade e seus dados</a>.</p>
    <?php endif; ?>
</div>
