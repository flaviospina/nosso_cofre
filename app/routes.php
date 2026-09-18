<?php
// app/routes.php
// Todas as rotas da aplicação. Cada fase acrescenta seu bloco aqui.
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EmailVerificationController;
use App\Controllers\FamilyController;
use App\Controllers\HomeController;
use App\Controllers\InvitationController;
use App\Controllers\LegalController;
use App\Controllers\OnboardingController;
use App\Controllers\PasswordResetController;
use App\Controllers\SystemController;
use App\Controllers\TwoFactorController;
use App\Core\Router;

/** @var Router $router */
$router = \App\Core\App::router();

// --- Fase 1: fundação ---
$router->get('/', [HomeController::class, 'index'], 'home');
$router->get('/offline', [SystemController::class, 'offline'], 'system.offline');
$router->get('/saude', [SystemController::class, 'health'], 'system.health');
$router->get('/instalar', [SystemController::class, 'install'], 'system.install');
$router->get('/cron/run', [SystemController::class, 'cron'], 'system.cron')->middleware('cron');
$router->match(['GET', 'POST'], '/sistema/migrar', [SystemController::class, 'migrate'], 'system.migrate')->middleware('cron');

// --- Documentos legais (públicos) ---
$router->get('/termos', [LegalController::class, 'terms'], 'legal.terms');
$router->get('/privacidade', [LegalController::class, 'privacy'], 'legal.privacy');

// --- Fase 2: contas e segurança ---
$router->group(['middleware' => ['guest']], static function (Router $r): void {
    $r->get('/cadastro', [AuthController::class, 'registerForm'], 'auth.register');
    $r->post('/cadastro', [AuthController::class, 'register'], 'auth.register.store');
    $r->get('/entrar', [AuthController::class, 'loginForm'], 'auth.login');
    $r->post('/entrar', [AuthController::class, 'login'], 'auth.login.attempt');
    $r->get('/entrar/verificacao', [TwoFactorController::class, 'challenge'], 'auth.two_factor');
    $r->post('/entrar/verificacao', [TwoFactorController::class, 'verify'], 'auth.two_factor.verify');
    $r->get('/esqueci-senha', [PasswordResetController::class, 'requestForm'], 'password.request');
    $r->post('/esqueci-senha', [PasswordResetController::class, 'sendLink'], 'password.email');
    $r->get('/redefinir-senha/{token}', [PasswordResetController::class, 'resetForm'], 'password.reset');
    $r->post('/redefinir-senha', [PasswordResetController::class, 'update'], 'password.update');
});
$router->get('/confirmar-email', [EmailVerificationController::class, 'notice'], 'verification.notice');
$router->post('/confirmar-email/reenviar', [EmailVerificationController::class, 'resend'], 'verification.resend');
$router->get('/confirmar-email/{token}', [EmailVerificationController::class, 'verify'], 'verification.verify');
$router->get('/convite/{token}', [InvitationController::class, 'show'], 'invitation.show');
$router->post('/convite/{token}/aceitar', [InvitationController::class, 'accept'], 'invitation.accept');

$router->group(['middleware' => ['auth']], static function (Router $r): void {
    $r->post('/sair', [AuthController::class, 'logout'], 'auth.logout');

    // Onboarding (sem exigir 2FA ainda)
    $r->get('/inicio', [OnboardingController::class, 'start'], 'onboarding.start');
    $r->post('/inicio', [OnboardingController::class, 'store'], 'onboarding.store');
    $r->group(['middleware' => ['household']], static function (Router $r): void {
        $r->get('/inicio/configuracao', [OnboardingController::class, 'setup'], 'onboarding.setup');
        $r->post('/inicio/configuracao', [OnboardingController::class, 'storeSetup'], 'onboarding.setup.store');
        $r->get('/inicio/avisos', [OnboardingController::class, 'notifications'], 'onboarding.notifications');
        $r->post('/inicio/avisos', [OnboardingController::class, 'storeNotifications'], 'onboarding.notifications.store');
    });

    // Conta (o 2FA é configurado aqui, então não exige 2FA)
    $r->group(['prefix' => '/conta'], static function (Router $r): void {
        $r->get('', [AccountController::class, 'index'], 'account.index');
        $r->post('', [AccountController::class, 'update'], 'account.update');
        $r->get('/senha', [AccountController::class, 'passwordForm'], 'account.password');
        $r->post('/senha', [AccountController::class, 'updatePassword'], 'account.password.update');
        $r->get('/2fa', [AccountController::class, 'twoFactor'], 'account.two_factor');
        $r->post('/2fa/ativar', [AccountController::class, 'enableTwoFactor'], 'account.two_factor.enable');
        $r->post('/2fa/desativar', [AccountController::class, 'disableTwoFactor'], 'account.two_factor.disable');
        $r->post('/2fa/codigos', [AccountController::class, 'regenerateRecoveryCodes'], 'account.two_factor.recovery');
        $r->get('/sessoes', [AccountController::class, 'sessions'], 'account.sessions');
        $r->post('/sessoes/encerrar', [AccountController::class, 'revokeOtherSessions'], 'account.sessions.revoke');
        $r->get('/atividade', [AccountController::class, 'activity'], 'account.activity');
    });

    // Área do lar (exige lar ativo e, para owner/admin de família, 2FA)
    $r->group(['middleware' => ['household', '2fa']], static function (Router $r): void {
        $r->get('/painel', [DashboardController::class, 'index'], 'dashboard');
        $r->get('/familia', [FamilyController::class, 'index'], 'family.index');
        $r->post('/familia/converter', [FamilyController::class, 'convert'], 'family.convert');
        $r->post('/familia/configuracoes', [FamilyController::class, 'updateSettings'], 'family.settings');
        $r->post('/familia/convidar', [FamilyController::class, 'invite'], 'family.invite');
        $r->post('/familia/convites/{id:\d+}/reenviar', [FamilyController::class, 'resendInvitation'], 'family.invite.resend');
        $r->post('/familia/convites/{id:\d+}/cancelar', [FamilyController::class, 'revokeInvitation'], 'family.invite.revoke');
        $r->post('/familia/membros/{id:\d+}/papel', [FamilyController::class, 'changeRole'], 'family.member.role');
        $r->post('/familia/membros/{id:\d+}/remover', [FamilyController::class, 'removeMember'], 'family.member.remove');
        $r->post('/familia/sair', [FamilyController::class, 'leave'], 'family.leave');
    });
});
