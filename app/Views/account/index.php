<?php
// app/Views/account/index.php — perfil
/** @var array<string,mixed> $user */
/** @var list<string> $timezones */
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('account-nav', ['active' => 'account.index']) ?>
    <h1 class="h3 mb-3">Minha conta</h1>
    <form method="post" action="<?= e(route('account.update')) ?>" data-once novalidate class="card nc-card">
        <div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12 col-md-8">
                    <label for="name" class="form-label">Nome</label>
                    <input type="text" class="form-control<?= invalid_class('name') ?>" id="name" name="name" value="<?= e(old('name', $user['name'])) ?>" required maxlength="120">
                    <?= field_error('name') ?>
                </div>
                <div class="col-12 col-md-4">
                    <label for="color" class="form-label">Minha cor</label>
                    <input type="color" class="form-control form-control-color w-100<?= invalid_class('color') ?>" id="color" name="color" value="<?= e(old('color', $user['color'])) ?>" title="Cor usada em gráficos e badges">
                    <?= field_error('color') ?>
                </div>
                <div class="col-12">
                    <label class="form-label">E-mail</label>
                    <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
                    <div class="form-text">A troca de e-mail (com nova confirmação) chega na área "Privacidade e seus dados".</div>
                </div>
                <div class="col-12 col-md-7">
                    <label for="timezone" class="form-label">Fuso horário</label>
                    <select class="form-select<?= invalid_class('timezone') ?>" id="timezone" name="timezone">
                        <?php foreach ($timezones as $tz): ?>
                            <option value="<?= e($tz) ?>" <?= old('timezone', $user['timezone']) === $tz ? 'selected' : '' ?>><?= e(str_replace(['America/', '_'], ['', ' '], $tz)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('timezone') ?>
                </div>
                <div class="col-12 col-md-5">
                    <label for="session_idle_minutes" class="form-label">Sair por inatividade após</label>
                    <select class="form-select" id="session_idle_minutes" name="session_idle_minutes">
                        <?php foreach ([15, 30, 60] as $m): ?>
                            <option value="<?= $m ?>" <?= (int) old('session_idle_minutes', $user['session_idle_minutes']) === $m ? 'selected' : '' ?>><?= $m ?> minutos</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </div>
    </form>
</div>
