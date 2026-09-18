<?php
// app/Views/onboarding/notifications.php — passo 3
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('onboarding-steps', ['step' => 3]) ?>
    <h1 class="h3 mb-1">Avisos</h1>
    <p class="text-body-secondary">Nada é enviado sem você escolher. Só os e-mails de segurança (confirmação, recuperação de senha e acesso de aparelho novo) são obrigatórios para o serviço funcionar.</p>
    <form method="post" action="<?= e(route('onboarding.notifications.store')) ?>" data-once novalidate>
        <?= csrf_field() ?>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="email_digest" name="email_digest" value="1">
            <label class="form-check-label" for="email_digest"><strong>Resumos e alertas por e-mail</strong><br><span class="text-body-secondary small">Contas a vencer, orçamento estourando, resumo semanal. Você escolhe o quê e quando na tela de notificações.</span></label>
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="push" name="push" value="1">
            <label class="form-check-label" for="push"><strong>Notificações no celular (push)</strong><br><span class="text-body-secondary small">Depois de instalar o app na tela inicial, você autoriza aparelho por aparelho. Cor e som de cada aviso são configuráveis.</span></label>
        </div>
        <p class="small text-body-secondary">Base legal: consentimento (art. 7º, I, da LGPD). Pode ser revogado a qualquer momento em Conta → Privacidade e seus dados.</p>
        <div class="d-flex justify-content-between mt-4">
            <a class="btn btn-link" href="<?= e(route('onboarding.setup')) ?>">Voltar</a>
            <button type="submit" class="btn btn-primary px-4">Concluir</button>
        </div>
    </form>
</div>
