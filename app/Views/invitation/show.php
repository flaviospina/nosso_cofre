<?php
// app/Views/invitation/show.php
/** @var array<string,mixed> $invitation */
/** @var string $token */
/** @var array<string,mixed>|null $user */
/** @var bool $emailMatches */
ob_start(); ?>
<p><strong><?= e($invitation['invited_by_name']) ?></strong> convidou <strong><?= e($invitation['email']) ?></strong> para participar do lar
    <strong><?= e($invitation['household_name']) ?></strong> como <?= e(role_label((string) $invitation['role'])) ?>.</p>
<?php if ($user === null): ?>
    <p>Para aceitar, crie sua conta com esse e-mail ou entre, se já tiver uma.</p>
    <div class="d-grid gap-2">
        <a class="btn btn-primary" href="<?= e(route('auth.register')) ?>">Criar conta com <?= e($invitation['email']) ?></a>
        <a class="btn btn-outline-primary" href="<?= e(route('auth.login')) ?>">Já tenho conta</a>
    </div>
    <p class="small text-body-secondary mt-3 mb-0">Ao confirmar o e-mail da conta nova, o convite é aceito automaticamente.</p>
<?php elseif (!$emailMatches): ?>
    <div class="alert alert-warning">Você está conectado como <strong><?= e($user['email']) ?></strong>, mas o convite é para <strong><?= e($invitation['email']) ?></strong>. Saia e entre com a conta certa.</div>
    <form method="post" action="<?= e(route('auth.logout')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary w-100">Sair</button></form>
<?php else: ?>
    <form method="post" action="<?= e(route('invitation.accept', ['token' => $token])) ?>" data-once>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary w-100">Aceitar convite</button>
    </form>
<?php endif; ?>
<?php $body = ob_get_clean();
echo \App\Core\View::partial('auth-card', ['heading' => 'Você foi convidado', 'lead' => null, 'body' => $body]);
