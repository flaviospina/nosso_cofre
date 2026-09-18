<?php
// app/Views/account/privacy.php — "Privacidade e seus dados"
/** @var array<string,mixed> $user */
/** @var array<string,mixed>|null $household */
/** @var array<string,bool> $state */
/** @var array<string,array{label:string,help:string,basis:string,revocable:bool}> $meta */
/** @var array<string,mixed>|null $termsAccepted */
/** @var array<string,mixed>|null $privacyAccepted */
/** @var string $termsVersion */
/** @var string $privacyVersion */
/** @var array<string,mixed>|null $pendingAccount */
/** @var array<string,mixed>|null $pendingHousehold */
/** @var int $graceDays */
/** @var bool $isOwner */
/** @var bool $isFamily */
/** @var bool $hasTwoFactor */
/** @var array<int,array<string,mixed>> $exports */
$confirmFields = static function (string $prefix) use ($hasTwoFactor): string {
    $html = '<div class="row g-2 mt-1"><div class="col-12 col-sm-6"><label class="form-label" for="' . $prefix . '_password">Sua senha</label><input type="password" class="form-control" id="' . $prefix . '_password" name="password" required autocomplete="current-password"></div>';
    if ($hasTwoFactor) {
        $html .= '<div class="col-12 col-sm-6"><label class="form-label" for="' . $prefix . '_code">Código do aplicativo (2FA)</label><input type="text" inputmode="numeric" class="form-control" id="' . $prefix . '_code" name="code" maxlength="6" required></div>';
    }
    return $html . '</div>';
};
$confirmError = error_text('confirm');
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('account-nav', ['active' => 'privacy.index']) ?>
    <h1 class="h3 mb-1">Privacidade e seus dados</h1>
    <p class="text-body-secondary">Seus direitos como titular (art. 18 da LGPD), cada um como um botão que funciona. Nada aqui depende de pedir a alguém.</p>
    <?php if ($confirmError !== ''): ?><div class="alert alert-danger"><?= e($confirmError) ?></div><?php endif; ?>

    <?php if ($pendingAccount !== null): ?>
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i><strong>Exclusão da conta agendada</strong> para <?= e(datetime_br($pendingAccount['scheduled_for'])) ?>. Até lá tudo continua funcionando.</div>
            <form method="post" action="<?= e(route('privacy.delete.cancel')) ?>" data-once><?= csrf_field() ?><button class="btn btn-sm btn-outline-dark">Cancelar exclusão</button></form>
        </div>
    <?php endif; ?>
    <?php if ($pendingHousehold !== null): ?>
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><i class="bi bi-house-x me-1" aria-hidden="true"></i><strong>Exclusão do lar agendada</strong> por <?= e($pendingHousehold['requested_by_name']) ?> para <?= e(datetime_br($pendingHousehold['scheduled_for'])) ?>.</div>
            <?php if ($isOwner): ?><form method="post" action="<?= e(route('privacy.household.cancel')) ?>" data-once><?= csrf_field() ?><button class="btn btn-sm btn-outline-dark">Cancelar exclusão do lar</button></form><?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="card nc-card mb-3">
        <div class="card-body">
            <h2 class="h5"><i class="bi bi-eye me-1" aria-hidden="true"></i>Transparência</h2>
            <dl class="row mb-2 small">
                <dt class="col-sm-4">Controlador</dt><dd class="col-sm-8"><?= e(config('legal.controller_name')) ?> · <a href="mailto:<?= e(config('legal.controller_email')) ?>"><?= e(config('legal.controller_email')) ?></a></dd>
                <dt class="col-sm-4">Encarregado (DPO)</dt><dd class="col-sm-8"><?= e(config('legal.dpo_name')) ?> · <a href="mailto:<?= e(config('legal.dpo_email')) ?>"><?= e(config('legal.dpo_email')) ?></a></dd>
                <dt class="col-sm-4">Termos de Uso</dt><dd class="col-sm-8">vigente v<?= e($termsVersion) ?>; você aceitou a v<?= e($termsAccepted['document_version'] ?? '?') ?> em <?= e(datetime_br($termsAccepted['created_at'] ?? null)) ?> · <a href="<?= e(route('legal.terms')) ?>">ler</a></dd>
                <dt class="col-sm-4">Política de Privacidade</dt><dd class="col-sm-8">vigente v<?= e($privacyVersion) ?>; você aceitou a v<?= e($privacyAccepted['document_version'] ?? '?') ?> em <?= e(datetime_br($privacyAccepted['created_at'] ?? null)) ?> · <a href="<?= e(route('legal.privacy')) ?>">ler</a></dd>
            </dl>
            <p class="small text-body-secondary mb-0">Nenhum dado é vendido ou compartilhado com terceiros além da hospedagem e do serviço de e-mail. Sem rastreadores.</p>
        </div>
    </section>

    <section class="card nc-card mb-3">
        <div class="card-body">
            <h2 class="h5"><i class="bi bi-toggles me-1" aria-hidden="true"></i>Consentimentos</h2>
            <form method="post" action="<?= e(route('privacy.consents')) ?>" data-once>
                <?= csrf_field() ?>
                <?php foreach ($meta as $kind => $m): ?>
                    <?php if ($kind === 'share_with_household' && !$isFamily) continue; ?>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="consent_<?= e($kind) ?>" name="consent_<?= e($kind) ?>" value="1" <?= $state[$kind] ? 'checked' : '' ?> <?= $m['revocable'] ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="consent_<?= e($kind) ?>"><strong><?= e($m['label']) ?></strong> <span class="badge text-bg-light border fw-normal"><?= e($m['basis']) ?></span><br><span class="small text-body-secondary"><?= e($m['help']) ?><?= $m['revocable'] ? '' : ' Não pode ser desligado enquanto a conta existir.' ?></span></label>
                    </div>
                <?php endforeach; ?>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">Salvar consentimentos</button>
                    <button type="submit" formaction="<?= e(route('privacy.revoke_all')) ?>" class="btn btn-outline-secondary btn-sm">Desligar todos os avisos</button>
                </div>
            </form>
        </div>
    </section>

    <section class="card nc-card mb-3">
        <div class="card-body">
            <h2 class="h5"><i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>Acesso e portabilidade</h2>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary btn-sm" href="<?= e(route('privacy.my_data')) ?>"><i class="bi bi-search me-1" aria-hidden="true"></i>Ver tudo que guardamos sobre mim</a>
                <form method="post" action="<?= e(route('privacy.export')) ?>" data-once><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1" aria-hidden="true"></i>Exportar meus dados (ZIP com JSON + CSV)</button></form>
            </div>
            <?php if ($exports !== []): ?>
                <p class="small text-body-secondary mt-2 mb-0">Exportações recentes: <?php foreach ($exports as $ex): ?><span class="badge text-bg-light border fw-normal"><?= e(datetime_br($ex['created_at'])) ?> · válida até <?= e(datetime_br($ex['expires_at'], 'd/m H:i')) ?></span> <?php endforeach; ?> (o link de download está no e-mail).</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="card nc-card mb-3">
        <div class="card-body">
            <h2 class="h5"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Correção</h2>
            <p class="small text-body-secondary">Nome, cor, fuso e tempo de sessão ficam em <a href="<?= e(route('account.index')) ?>">Minha conta</a>. Aqui: e-mail e dados opcionais.</p>
            <form method="post" action="<?= e(route('privacy.email')) ?>" data-once novalidate class="row g-2 mb-3">
                <?= csrf_field() ?>
                <div class="col-12 col-sm-6"><label class="form-label" for="new_email">Novo e-mail</label><input type="email" class="form-control<?= invalid_class('email') ?>" id="new_email" name="email" value="<?= e(old('email')) ?>" required><?= field_error('email') ?></div>
                <div class="col-12 col-sm-4"><label class="form-label" for="email_password">Senha</label><input type="password" class="form-control<?= invalid_class('password') ?>" id="email_password" name="password" required autocomplete="current-password"><?= field_error('password') ?></div>
                <div class="col-12 col-sm-2 d-flex align-items-end"><button class="btn btn-outline-primary w-100">Trocar</button></div>
                <div class="col-12 form-text">O novo endereço recebe um link de confirmação; o atual recebe um aviso.</div>
            </form>
            <form method="post" action="<?= e(route('privacy.optional')) ?>" data-once novalidate class="row g-2">
                <?= csrf_field() ?>
                <div class="col-12 col-sm-5"><label class="form-label" for="document">CPF (opcional)</label><input type="text" inputmode="numeric" class="form-control<?= invalid_class('document') ?>" id="document" name="document" value="<?= e(old('document', $user['document'] ?? '')) ?>" placeholder="000.000.000-00"><?= field_error('document') ?><div class="form-text">Só para identificar você em pedidos formais. Guardado criptografado.</div></div>
                <div class="col-12 col-sm-5"><label class="form-label" for="income">Renda mensal estimada (opcional)</label><input type="text" inputmode="decimal" class="form-control<?= invalid_class('income') ?>" id="income" name="income" value="<?= e(old('income', isset(\App\Core\Auth::member()['estimated_income']) && \App\Core\Auth::member()['estimated_income'] !== null ? number_format((float) \App\Core\Auth::member()['estimated_income'], 2, ',', '.') : '')) ?>"><?= field_error('income') ?><div class="form-text">Usada só na regra 50/30/20 e na taxa de poupança.</div></div>
                <div class="col-12 col-sm-2 d-flex align-items-end"><button class="btn btn-outline-primary w-100">Salvar</button></div>
            </form>
        </div>
    </section>

    <?php if ($household !== null && !$isOwner): ?>
        <section class="card nc-card mb-3">
            <div class="card-body">
                <h2 class="h5"><i class="bi bi-box-arrow-left me-1" aria-hidden="true"></i>Sair do lar "<?= e($household['name']) ?>"</h2>
                <form method="post" action="<?= e(route('privacy.leave')) ?>" data-once data-confirm="Sair do lar agora?">
                    <?= csrf_field() ?>
                    <div class="form-check"><input class="form-check-input" type="radio" name="take_data" id="take_keep" value="keep" checked><label class="form-check-label" for="take_keep"><strong>Deixar meus lançamentos no lar</strong> (ficam nos totais, sem o meu nome)</label></div>
                    <div class="form-check mb-2"><input class="form-check-input" type="radio" name="take_data" id="take_take" value="take"><label class="form-check-label" for="take_take"><strong>Levar meus dados</strong>: cria um lar individual para mim com minhas contas, os lançamentos delas, minhas metas, orçamentos e recorrências. Lançamentos meus em contas conjuntas ficam no lar, sem o meu nome.</label></div>
                    <button class="btn btn-outline-danger btn-sm">Sair do lar</button>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <section class="card nc-card border-danger mb-3">
        <div class="card-body">
            <h2 class="h5 text-danger"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Anonimização e exclusão</h2>
            <p class="small text-body-secondary">Ações irreversíveis. Exigem sua senha<?= $hasTwoFactor ? ' e o código do 2FA' : '' ?>. O que a lei obriga a manter (registros de consentimento e de segurança) fica sem nome, e-mail, IP ou navegador, pelo prazo mínimo.</p>
            <details class="mb-3">
                <summary class="fw-semibold">Anonimizar minha identidade (imediato)</summary>
                <form method="post" action="<?= e(route('privacy.anonymize')) ?>" data-once data-confirm="Anonimizar agora? Não dá para desfazer." class="mt-2">
                    <?= csrf_field() ?>
                    <p class="small">Seu nome vira "Membro removido"; e-mail, senha, 2FA, CPF, IPs, sessões e aparelhos são apagados. Os lançamentos continuam nos totais do lar. Você não conseguirá mais entrar.</p>
                    <?= $confirmFields('anon') ?>
                    <button class="btn btn-outline-danger btn-sm mt-2">Anonimizar</button>
                </form>
            </details>
            <details class="mb-3">
                <summary class="fw-semibold">Excluir minha conta (carência de <?= (int) $graceDays ?> dias)</summary>
                <form method="post" action="<?= e(route('privacy.delete')) ?>" data-once data-confirm="Agendar a exclusão da conta?" class="mt-2">
                    <?= csrf_field() ?>
                    <p class="small">Lares em que você é a única pessoa são apagados por inteiro. Em lares compartilhados, os lançamentos ficam com "Membro removido". Você pode cancelar durante a carência; depois a exclusão é definitiva.<?= $isOwner && $isFamily ? ' Como responsável por um lar com outros membros, transfira a responsabilidade (em Família) ou exclua o lar antes.' : '' ?></p>
                    <?= $confirmFields('del') ?>
                    <button class="btn btn-danger btn-sm mt-2">Agendar exclusão da conta</button>
                </form>
            </details>
            <?php if ($isOwner && $household !== null): ?>
                <details>
                    <summary class="fw-semibold">Excluir o lar "<?= e($household['name']) ?>" (carência de <?= (int) $graceDays ?> dias)</summary>
                    <form method="post" action="<?= e(route('privacy.household.delete')) ?>" data-once data-confirm="Agendar a exclusão do lar inteiro?" class="mt-2">
                        <?= csrf_field() ?>
                        <p class="small">Todos os membros recebem um e-mail agora e outro quando a exclusão acontecer. Contas, lançamentos, metas e anexos do lar são apagados. As contas pessoais dos membros continuam existindo.</p>
                        <?= $confirmFields('hh') ?>
                        <button class="btn btn-danger btn-sm mt-2">Agendar exclusão do lar</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </section>
</div>
