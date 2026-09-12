<?php
// app/Views/home/index.php — página inicial para visitantes
$hasLogin = route_exists('auth.login');
$hasRegister = route_exists('auth.register');
?>
<section class="nc-hero text-center py-4 py-md-5">
    <img src="<?= e(url('/assets/img/icon-192.png')) ?>" alt="" width="96" height="96" class="mb-3 rounded-4 shadow-sm">
    <h1 class="display-6 fw-bold mb-2"><?= e($appName) ?></h1>
    <p class="lead text-body-secondary mx-auto nc-maxw-sm">
        Controle financeiro para uma pessoa ou para a família inteira, feito para <strong>economizar</strong>:
        mostra onde o dinheiro vaza, avisa na hora certa e acompanha suas metas de corte de custo.
    </p>
    <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
        <?php if ($hasLogin): ?>
            <a class="btn btn-primary btn-lg px-4" href="<?= e(route('auth.login')) ?>"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Entrar</a>
        <?php endif; ?>
        <?php if ($hasRegister): ?>
            <a class="btn btn-outline-primary btn-lg px-4" href="<?= e(route('auth.register')) ?>"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Criar conta</a>
        <?php endif; ?>
        <?php if (!$hasLogin && !$hasRegister): ?>
            <span class="badge text-bg-secondary fs-6 fw-normal">Cadastro e login chegam na próxima fase</span>
        <?php endif; ?>
    </div>
</section>

<section class="row g-3 mt-2">
    <div class="col-12 col-md-4">
        <div class="card nc-card h-100">
            <div class="card-body">
                <div class="nc-feature-icon text-bg-success"><i class="bi bi-piggy-bank" aria-hidden="true"></i></div>
                <h2 class="h5 mt-3">Onde o dinheiro vaza</h2>
                <p class="mb-0 text-body-secondary">Essencial × supérfluo, radar de assinaturas, cobranças duplicadas e aumentos de preço detectados automaticamente.</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card nc-card h-100">
            <div class="card-body">
                <div class="nc-feature-icon text-bg-warning"><i class="bi bi-bell" aria-hidden="true"></i></div>
                <h2 class="h5 mt-3">Avisos do seu jeito</h2>
                <p class="mb-0 text-body-secondary">Você escolhe o quê, quando, por onde e com qual cor e som. Nada é enviado sem a sua permissão.</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card nc-card h-100">
            <div class="card-body">
                <div class="nc-feature-icon text-bg-primary"><i class="bi bi-people" aria-hidden="true"></i></div>
                <h2 class="h5 mt-3">Sozinho ou em família</h2>
                <p class="mb-0 text-body-secondary">Cada membro com sua cor, suas contas e seus lançamentos. Visão conjunta e individual, com privacidade quando você quiser.</p>
            </div>
        </div>
    </div>
</section>

<section class="mt-4 text-center text-body-secondary small">
    <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
    Seus dados ficam no seu servidor, criptografados onde importa, e nunca são vendidos ou compartilhados. Veja a
    <a href="<?= e(route_exists('legal.privacy') ? route('legal.privacy') : url('/privacidade')) ?>">Política de Privacidade</a>.
</section>
