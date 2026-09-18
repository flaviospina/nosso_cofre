<?php
// app/Views/onboarding/start.php — passo 1
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('onboarding-steps', ['step' => 1]) ?>
    <h1 class="h3 mb-3">Como você vai usar o Nosso Cofre?</h1>
    <form method="post" action="<?= e(route('onboarding.store')) ?>" data-once novalidate>
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="card nc-card h-100 nc-choice">
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="type_individual" value="individual" <?= old('type', 'individual') === 'individual' ? 'checked' : '' ?>>
                            <span class="form-check-label h5 ms-1">Individual</span>
                        </div>
                        <p class="text-body-secondary mb-0 mt-2">Só você. Sem a ideia de "responsável": tudo é seu. Dá para converter em familiar depois, sem perder nada.</p>
                    </div>
                </label>
            </div>
            <div class="col-12 col-md-6">
                <label class="card nc-card h-100 nc-choice">
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="type_family" value="family" <?= old('type') === 'family' ? 'checked' : '' ?>>
                            <span class="form-check-label h5 ms-1">Familiar</span>
                        </div>
                        <p class="text-body-secondary mb-0 mt-2">Casal ou família: cada membro com sua cor e seus lançamentos, visão conjunta e individual. Você será o responsável.</p>
                    </div>
                </label>
            </div>
        </div>
        <div id="familyFields" class="mt-3" data-show-when="type=family">
            <div class="mb-3">
                <label for="name" class="form-label">Nome do lar</label>
                <input type="text" class="form-control<?= invalid_class('name') ?>" id="name" name="name" value="<?= e(old('name')) ?>" placeholder="Ex.: Família Spina" maxlength="120">
                <?= field_error('name') ?>
            </div>
            <div class="mb-3">
                <label for="emails" class="form-label">Convidar membros por e-mail (opcional)</label>
                <textarea class="form-control<?= invalid_class('emails') ?>" id="emails" name="emails" rows="2" placeholder="um e-mail por linha ou separados por vírgula"><?= e(old('emails')) ?></textarea>
                <?= field_error('emails') ?>
                <div class="form-text">Cada convidado recebe um link válido por 7 dias e entra como membro. Você pode mudar o papel depois.</div>
            </div>
            <div class="alert alert-info small mb-0">Em lares familiares, o responsável e os administradores precisam ativar a verificação em duas etapas (2FA). Vamos pedir isso ao final.</div>
        </div>
        <div class="d-flex justify-content-end mt-4">
            <button type="submit" class="btn btn-primary px-4">Continuar</button>
        </div>
    </form>
</div>
