<?php
// app/Controllers/OnboardingController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Models\Consent;
use App\Models\Household;
use App\Services\AuditService;
use App\Services\HouseholdService;
use App\Services\InvitationService;

/** Onboarding em 3 passos: tipo de uso, configuração rápida, avisos. */
final class OnboardingController extends Controller
{
    /** Passo 1: individual ou familiar. */
    public function start(): Response
    {
        if (Auth::householdId() !== null) {
            return $this->redirectRoute('onboarding.setup');
        }
        return $this->view('onboarding/start', ['title' => 'Como você vai usar?', 'step' => 1]);
    }

    public function store(): Response
    {
        if (Auth::householdId() !== null) {
            return $this->redirectRoute('onboarding.setup');
        }
        $data = $this->validate([
            'type'   => 'required|in:individual,family',
            'name'   => 'nullable|max:120',
            'emails' => 'nullable|max:2000',
        ], ['type' => 'tipo de uso', 'name' => 'nome do lar', 'emails' => 'e-mails'], 'onboarding.start');

        $user = Auth::user() ?? [];
        $name = $data['type'] === 'family'
            ? (trim((string) ($data['name'] ?? '')) !== '' ? trim((string) $data['name']) : 'Família ' . $user['name'])
            : (string) $user['name'];
        $householdId = HouseholdService::create((int) $user['id'], (string) $data['type'], $name);
        Auth::refresh();
        Auth::switchHousehold($householdId);

        $sent = 0;
        if ($data['type'] === 'family' && !empty($data['emails'])) {
            foreach (preg_split('/[\s,;]+/', (string) $data['emails']) ?: [] as $email) {
                $email = trim($email);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && InvitationService::invite($householdId, $email, 'member', (int) $user['id']) === null) {
                    $sent++;
                }
            }
        }
        $this->flash('success', $data['type'] === 'family'
            ? 'Lar "' . $name . '" criado.' . ($sent > 0 ? " {$sent} convite(s) enviado(s)." : '')
            : 'Conta individual pronta.');
        return $this->redirectRoute('onboarding.setup');
    }

    /** Passo 2: assistente rápido. */
    public function setup(): Response
    {
        $household = Auth::household();
        return $this->view('onboarding/setup', [
            'title'     => 'Configuração inicial',
            'step'      => 2,
            'household' => $household,
            'suggested' => HouseholdService::SUGGESTED_ACCOUNTS,
        ]);
    }

    public function storeSetup(): Response
    {
        $householdId = (int) Auth::householdId();
        $data = $this->validate([
            'currency'               => 'required|in:BRL,USD,EUR',
            'fiscal_month_start_day' => 'required|integer|between:1,28',
            'accounts'               => 'nullable|array',
            'custom_accounts'        => 'nullable|max:500',
            'income'                 => 'nullable|money',
        ], ['currency' => 'moeda', 'fiscal_month_start_day' => 'dia de início do mês', 'income' => 'renda mensal'], 'onboarding.setup');

        $accounts = array_values(array_filter(array_map('strval', (array) ($data['accounts'] ?? [])), static fn(string $a): bool => isset(HouseholdService::SUGGESTED_ACCOUNTS[$a])));
        $custom = array_values(array_filter(array_map('trim', explode(',', (string) ($data['custom_accounts'] ?? '')))));
        HouseholdService::applyQuickSetup($householdId, (int) Auth::id(), [
            'currency'               => (string) $data['currency'],
            'fiscal_month_start_day' => (int) $data['fiscal_month_start_day'],
            'accounts'               => $accounts,
            'custom_accounts'        => $custom,
            'income'                 => $data['income'] !== null ? (string) $data['income'] : null,
        ]);
        self::setOnboardingStage($householdId, 'notifications');
        return $this->redirectRoute('onboarding.notifications');
    }

    /** Passo 3: avisos (nada ligado por padrão). */
    public function notifications(): Response
    {
        return $this->view('onboarding/notifications', ['title' => 'Avisos', 'step' => 3]);
    }

    public function storeNotifications(): Response
    {
        $userId = (int) Auth::id();
        $data = $this->validate([
            'email_digest' => 'nullable|boolean',
            'push'         => 'nullable|boolean',
        ], [], 'onboarding.notifications');
        $emailDigest = (bool) ($data['email_digest'] ?? false);
        $push = (bool) ($data['push'] ?? false);
        Consent::record($userId, 'digest_email', $emailDigest);
        Consent::record($userId, 'push', $push);
        AuditService::log($emailDigest ? 'consent.granted' : 'consent.revoked', 'consent', null, null, ['kind' => 'digest_email']);
        AuditService::log($push ? 'consent.granted' : 'consent.revoked', 'consent', null, null, ['kind' => 'push']);
        \App\Core\Database::execute(
            'INSERT INTO notification_settings (user_id, settings, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE settings = VALUES(settings)',
            [$userId, json_encode(['version' => 1, 'channels' => ['email' => $emailDigest, 'push' => $push], 'types' => []], JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]
        );
        self::setOnboardingStage((int) Auth::householdId(), 'done');
        $this->flash('success', 'Tudo pronto! Você pode ajustar os avisos a qualquer momento em Conta → Notificações.');
        return $this->redirectRoute('dashboard');
    }

    public static function setOnboardingStage(int $householdId, string $stage): void
    {
        $household = Household::findById($householdId);
        if ($household === null) {
            return;
        }
        $settings = $household['settings'];
        $settings['onboarding'] = $stage;
        (new Household())->update($householdId, ['settings' => $settings]);
        Auth::refresh();
    }
}
