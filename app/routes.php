<?php
// app/routes.php
// Todas as rotas da aplicação. Cada fase acrescenta seu bloco aqui.
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AdminIncidentController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\EmailVerificationController;
use App\Controllers\FamilyController;
use App\Controllers\FinancialAccountController;
use App\Controllers\HomeController;
use App\Controllers\ImportController;
use App\Controllers\RecurrenceController;
use App\Controllers\SubscriptionController;
use App\Controllers\BudgetController;
use App\Controllers\GoalController;
use App\Controllers\SavingsActionController;
use App\Controllers\SimulatorController;
use App\Controllers\InvitationController;
use App\Controllers\LegalController;
use App\Controllers\OnboardingController;
use App\Controllers\PasswordResetController;
use App\Controllers\PrivacyController;
use App\Controllers\SystemController;
use App\Controllers\TransactionController;
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

        // Fase 3: Privacidade e seus dados (LGPD)
        $r->get('/privacidade', [PrivacyController::class, 'index'], 'privacy.index');
        $r->post('/privacidade/consentimentos', [PrivacyController::class, 'updateConsents'], 'privacy.consents');
        $r->post('/privacidade/desligar-avisos', [PrivacyController::class, 'revokeAllNotifications'], 'privacy.revoke_all');
        $r->get('/privacidade/meus-dados', [PrivacyController::class, 'myData'], 'privacy.my_data');
        $r->post('/privacidade/exportar', [PrivacyController::class, 'export'], 'privacy.export');
        $r->get('/privacidade/exportacoes/{token}', [PrivacyController::class, 'download'], 'privacy.export.download');
        $r->post('/privacidade/email', [PrivacyController::class, 'requestEmailChange'], 'privacy.email');
        $r->post('/privacidade/opcionais', [PrivacyController::class, 'updateOptional'], 'privacy.optional');
        $r->post('/privacidade/anonimizar', [PrivacyController::class, 'anonymize'], 'privacy.anonymize');
        $r->post('/privacidade/excluir-conta', [PrivacyController::class, 'requestDeletion'], 'privacy.delete');
        $r->post('/privacidade/excluir-conta/cancelar', [PrivacyController::class, 'cancelDeletion'], 'privacy.delete.cancel');
        $r->post('/privacidade/excluir-lar', [PrivacyController::class, 'requestHouseholdDeletion'], 'privacy.household.delete');
        $r->post('/privacidade/excluir-lar/cancelar', [PrivacyController::class, 'cancelHouseholdDeletion'], 'privacy.household.cancel');
        $r->post('/privacidade/sair-do-lar', [PrivacyController::class, 'leaveHousehold'], 'privacy.leave');
    });

    // Área do controlador (ADMIN_EMAILS)
    $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/incidentes', [AdminIncidentController::class, 'index'], 'admin.incidents');
        $r->post('/incidentes', [AdminIncidentController::class, 'store'], 'admin.incidents.store');
        $r->get('/incidentes/{id:\d+}', [AdminIncidentController::class, 'show'], 'admin.incidents.show');
        $r->post('/incidentes/{id:\d+}/comunicar', [AdminIncidentController::class, 'notify'], 'admin.incidents.notify');
        $r->post('/incidentes/{id:\d+}/encerrar', [AdminIncidentController::class, 'close'], 'admin.incidents.close');
        $r->post('/incidentes/{id:\d+}/anpd', [AdminIncidentController::class, 'anpd'], 'admin.incidents.anpd');
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
        $r->post('/familia/transferir', [FamilyController::class, 'transfer'], 'family.transfer');

        // Fase 4: cadastros financeiros
        $r->get('/contas', [FinancialAccountController::class, 'index'], 'accounts.index');
        $r->get('/contas/nova', [FinancialAccountController::class, 'create'], 'accounts.create');
        $r->post('/contas/nova', [FinancialAccountController::class, 'store'], 'accounts.store');
        $r->get('/contas/{id:\d+}/editar', [FinancialAccountController::class, 'edit'], 'accounts.edit');
        $r->post('/contas/{id:\d+}/editar', [FinancialAccountController::class, 'update'], 'accounts.update');
        $r->post('/contas/{id:\d+}/arquivar', [FinancialAccountController::class, 'toggle'], 'accounts.toggle');
        $r->post('/contas/{id:\d+}/excluir', [FinancialAccountController::class, 'destroy'], 'accounts.destroy');

        $r->get('/categorias', [CategoryController::class, 'index'], 'categories.index');
        $r->post('/categorias', [CategoryController::class, 'store'], 'categories.store');
        $r->post('/categorias/{id:\d+}/editar', [CategoryController::class, 'update'], 'categories.update');
        $r->post('/categorias/{id:\d+}/excluir', [CategoryController::class, 'destroy'], 'categories.destroy');
        $r->post('/categorias/{id:\d+}/ocultar', [CategoryController::class, 'toggleHidden'], 'categories.hide');

        $r->get('/lancamentos', [TransactionController::class, 'index'], 'transactions.index');
        $r->get('/lancamentos/novo', [TransactionController::class, 'create'], 'transactions.create');
        $r->post('/lancamentos/novo', [TransactionController::class, 'store'], 'transactions.store');
        $r->get('/lancamentos/lixeira', [TransactionController::class, 'trashIndex'], 'transactions.trash');
        $r->get('/lancamentos/modelos', [TransactionController::class, 'templates'], 'transactions.templates');
        $r->post('/lancamentos/modelos/{id:\d+}/excluir', [TransactionController::class, 'deleteTemplate'], 'transactions.templates.delete');
        $r->get('/lancamentos/sugerir', [TransactionController::class, 'suggest'], 'transactions.suggest');
        $r->post('/lancamentos/lote', [TransactionController::class, 'bulk'], 'transactions.bulk');
        $r->get('/lancamentos/{id:\d+}/editar', [TransactionController::class, 'edit'], 'transactions.edit');
        $r->post('/lancamentos/{id:\d+}/editar', [TransactionController::class, 'update'], 'transactions.update');
        $r->post('/lancamentos/{id:\d+}/status', [TransactionController::class, 'status'], 'transactions.status');
        $r->post('/lancamentos/{id:\d+}/excluir', [TransactionController::class, 'trash'], 'transactions.delete');
        $r->post('/lancamentos/{id:\d+}/restaurar', [TransactionController::class, 'restore'], 'transactions.restore');
        $r->post('/lancamentos/{id:\d+}/destruir', [TransactionController::class, 'destroy'], 'transactions.destroy');
        $r->post('/lancamentos/{id:\d+}/modelo', [TransactionController::class, 'saveTemplate'], 'transactions.template');
        $r->get('/lancamentos/{id:\d+}/anexo', [TransactionController::class, 'attachment'], 'transactions.attachment');

        $r->get('/importar', [ImportController::class, 'index'], 'import.index');
        $r->post('/importar', [ImportController::class, 'upload'], 'import.upload');
        $r->get('/importar/{key}', [ImportController::class, 'preview'], 'import.preview');
        $r->post('/importar/{key}/mapear', [ImportController::class, 'preview'], 'import.map');
        $r->post('/importar/{key}/confirmar', [ImportController::class, 'confirm'], 'import.confirm');
        $r->post('/importar/lotes/{id:\d+}/desfazer', [ImportController::class, 'undo'], 'import.undo');

        // Fase 5: recorrências, radar de assinaturas, orçamento, metas, plano de ação, simulador
        $r->get('/recorrencias', [RecurrenceController::class, 'index'], 'recurrences.index');
        $r->get('/recorrencias/nova', [RecurrenceController::class, 'create'], 'recurrences.create');
        $r->post('/recorrencias/nova', [RecurrenceController::class, 'store'], 'recurrences.store');
        $r->post('/recorrencias/gerar', [RecurrenceController::class, 'generate'], 'recurrences.generate');
        $r->post('/recorrencias/ocorrencias/{id:\d+}/confirmar', [RecurrenceController::class, 'confirm'], 'recurrences.confirm');
        $r->post('/recorrencias/ocorrencias/{id:\d+}/pular', [RecurrenceController::class, 'skip'], 'recurrences.skip');
        $r->get('/recorrencias/{id:\d+}/editar', [RecurrenceController::class, 'edit'], 'recurrences.edit');
        $r->post('/recorrencias/{id:\d+}/editar', [RecurrenceController::class, 'update'], 'recurrences.update');
        $r->post('/recorrencias/{id:\d+}/pausar', [RecurrenceController::class, 'toggle'], 'recurrences.toggle');
        $r->post('/recorrencias/{id:\d+}/debito-automatico', [RecurrenceController::class, 'autoDebit'], 'recurrences.auto_debit');
        $r->post('/recorrencias/{id:\d+}/excluir', [RecurrenceController::class, 'destroy'], 'recurrences.destroy');

        $r->get('/assinaturas', [SubscriptionController::class, 'index'], 'subscriptions.index');
        $r->post('/assinaturas/detectada', [SubscriptionController::class, 'adopt'], 'subscriptions.adopt');
        $r->post('/assinaturas/{id:\d+}/usei', [SubscriptionController::class, 'usage'], 'subscriptions.usage');
        $r->post('/assinaturas/{id:\d+}/cancelar', [SubscriptionController::class, 'cancel'], 'subscriptions.cancel');

        $r->get('/orcamento', [BudgetController::class, 'index'], 'budgets.index');
        $r->post('/orcamento', [BudgetController::class, 'store'], 'budgets.store');
        $r->post('/orcamento/copiar', [BudgetController::class, 'copy'], 'budgets.copy');
        $r->post('/orcamento/{id:\d+}/editar', [BudgetController::class, 'update'], 'budgets.update');
        $r->post('/orcamento/{id:\d+}/excluir', [BudgetController::class, 'destroy'], 'budgets.destroy');

        $r->get('/metas', [GoalController::class, 'index'], 'goals.index');
        $r->post('/metas', [GoalController::class, 'store'], 'goals.store');
        $r->post('/metas/{id:\d+}/editar', [GoalController::class, 'update'], 'goals.update');
        $r->post('/metas/{id:\d+}/aporte', [GoalController::class, 'contribute'], 'goals.contribute');
        $r->post('/metas/{id:\d+}/arquivar', [GoalController::class, 'toggle'], 'goals.toggle');
        $r->post('/metas/{id:\d+}/excluir', [GoalController::class, 'destroy'], 'goals.destroy');

        $r->get('/plano', [SavingsActionController::class, 'index'], 'savings.index');
        $r->post('/plano', [SavingsActionController::class, 'store'], 'savings.store');
        $r->post('/plano/{id:\d+}/editar', [SavingsActionController::class, 'update'], 'savings.update');
        $r->post('/plano/{id:\d+}/situacao', [SavingsActionController::class, 'status'], 'savings.status');
        $r->post('/plano/{id:\d+}/excluir', [SavingsActionController::class, 'destroy'], 'savings.destroy');

        $r->get('/simulador', [SimulatorController::class, 'index'], 'simulator.index');
    });
});
