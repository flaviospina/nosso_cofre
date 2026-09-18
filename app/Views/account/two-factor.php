<?php
// app/Views/account/two-factor.php
/** @var bool $enabled */
/** @var bool $required */
/** @var array{secret:string,uri:string,formatted:string}|null $setup */
/** @var list<string>|null $freshCodes */
/** @var int $remaining */
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('account-nav', ['active' => 'account.two_factor']) ?>
    <h1 class="h3 mb-3">Verificação em duas etapas (2FA)</h1>

    <?php if ($freshCodes !== null): ?>
        <div class="card nc-card border-warning mb-3">
            <div class="card-body">
                <h2 class="h5"><i class="bi bi-life-preserver me-1" aria-hidden="true"></i>Códigos de recuperação</h2>
                <p class="small">Guarde em lugar seguro (gerenciador de senhas ou papel). Cada código entra <strong>uma única vez</strong> no lugar do aplicativo, se você perder o celular. <strong>Eles não serão mostrados de novo.</strong></p>
                <pre class="nc-codes" id="recoveryCodes"><?= e(implode("\n", $freshCodes)) ?></pre>
                <button type="button" class="btn btn-outline-primary btn-sm" data-copy-target="#recoveryCodes"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copiar</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$enabled && $setup !== null): ?>
        <?php if ($required): ?>
            <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Como responsável ou administrador de um lar familiar, o 2FA é obrigatório para você.</div>
        <?php endif; ?>
        <div class="card nc-card">
            <div class="card-body">
                <ol class="mb-3">
                    <li>Instale um aplicativo autenticador (Google Authenticator, Microsoft Authenticator, Authy, 1Password…).</li>
                    <li>Leia o QR code abaixo ou digite a chave manualmente.</li>
                    <li>Digite o código de 6 dígitos que o aplicativo mostrar.</li>
                </ol>
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-sm-5 text-center">
                        <div id="qrcode" class="nc-qr d-inline-block bg-white p-2 rounded" data-qr="<?= e($setup['uri']) ?>" aria-label="QR code para o aplicativo autenticador"></div>
                    </div>
                    <div class="col-12 col-sm-7">
                        <div class="small text-body-secondary">Chave para digitação manual</div>
                        <code class="d-block fs-6 mb-2 user-select-all"><?= e($setup['formatted']) ?></code>
                        <form method="post" action="<?= e(route('account.two_factor.enable')) ?>" data-once novalidate>
                            <?= csrf_field() ?>
                            <label for="code" class="form-label">Código do aplicativo</label>
                            <div class="input-group">
                                <input type="text" inputmode="numeric" autocomplete="one-time-code" class="form-control<?= invalid_class('code') ?>" id="code" name="code" maxlength="6" required>
                                <button type="submit" class="btn btn-primary">Ativar</button>
                            </div>
                            <?= field_error('code') ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js" integrity="sha384-8FWZA6BGMXhsfO+BLtrJK0We6gg5o1JyO8xQm6peWDEUs17ACA5ziE/NIAkl9z2k" crossorigin="anonymous" nonce="<?= e(nonce()) ?>"></script>
        <script nonce="<?= e(nonce()) ?>">
            (function () {
                var el = document.getElementById('qrcode');
                if (!el || typeof qrcode !== 'function') { return; }
                try {
                    var qr = qrcode(0, 'M');
                    qr.addData(el.getAttribute('data-qr'));
                    qr.make();
                    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
                    var svg = el.querySelector('svg'); if (svg) { svg.setAttribute('width', '200'); svg.setAttribute('height', '200'); }
                } catch (e) { el.textContent = 'Use a chave manual ao lado.'; }
            })();
        </script>
    <?php else: ?>
        <div class="card nc-card mb-3">
            <div class="card-body">
                <p class="mb-2"><i class="bi bi-shield-check text-success me-1" aria-hidden="true"></i><strong>2FA ativo.</strong> Códigos de recuperação restantes: <?= (int) $remaining ?>.</p>
                <form method="post" action="<?= e(route('account.two_factor.recovery')) ?>" data-once novalidate class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-12 col-sm-6">
                        <label for="code_regen" class="form-label">Gerar novos códigos de recuperação (digite o código do aplicativo)</label>
                        <input type="text" inputmode="numeric" class="form-control<?= invalid_class('code') ?>" id="code_regen" name="code" maxlength="6" required>
                        <?= field_error('code') ?>
                    </div>
                    <div class="col-12 col-sm-auto"><button type="submit" class="btn btn-outline-primary">Gerar novos códigos</button></div>
                </form>
            </div>
        </div>
        <?php if (!$required && !(\App\Core\Auth::isFamily() && \App\Core\Auth::canManage())): ?>
            <details class="card nc-card">
                <summary class="card-body py-3 fw-semibold">Desativar o 2FA</summary>
                <div class="card-body pt-0">
                    <form method="post" action="<?= e(route('account.two_factor.disable')) ?>" data-once novalidate class="row g-2">
                        <?= csrf_field() ?>
                        <div class="col-12 col-sm-5">
                            <label for="password" class="form-label">Sua senha</label>
                            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                        </div>
                        <div class="col-12 col-sm-4">
                            <label for="code_disable" class="form-label">Código do aplicativo</label>
                            <input type="text" inputmode="numeric" class="form-control" id="code_disable" name="code" maxlength="6" required>
                        </div>
                        <div class="col-12 col-sm-3 d-flex align-items-end"><button type="submit" class="btn btn-outline-danger w-100">Desativar</button></div>
                    </form>
                </div>
            </details>
        <?php else: ?>
            <p class="small text-body-secondary">Responsável e administradores de lar familiar não podem desativar o 2FA.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
