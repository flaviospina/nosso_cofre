<?php
// app/Controllers/PrivacyController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Models\Consent;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\ExportService;
use App\Services\LegalService;
use App\Services\PrivacyService;
use App\Services\TwoFactorService;

/** Área "Privacidade e seus dados" (art. 18 da LGPD). */
final class PrivacyController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user() ?? [];
        $household = Auth::household();
        $consents = Consent::currentFor((int) $user['id']);
        return $this->view('account/privacy', [
            'title'          => 'Privacidade e seus dados',
            'user'           => $user,
            'household'      => $household,
            'state'          => PrivacyService::consentState((int) $user['id']),
            'meta'           => PrivacyService::CONSENTS,
            'termsAccepted'  => $consents['terms'] ?? null,
            'privacyAccepted' => $consents['privacy'] ?? null,
            'termsVersion'   => LegalService::version('terms'),
            'privacyVersion' => LegalService::version('privacy'),
            'pendingAccount' => PrivacyService::pendingDeletion((int) $user['id']),
            'pendingHousehold' => $household !== null ? PrivacyService::pendingHouseholdDeletion((int) $household['id']) : null,
            'graceDays'      => PrivacyService::graceDays(),
            'isOwner'        => Auth::isOwner(),
            'isFamily'       => Auth::isFamily(),
            'hasTwoFactor'   => Auth::hasTwoFactor(),
            'exports'        => \App\Core\Database::select("SELECT id, status, size_bytes, expires_at, created_at FROM data_exports WHERE user_id = ? AND status IN ('ready','downloaded') AND expires_at > ? ORDER BY id DESC LIMIT 3", [(int) $user['id'], gmdate('Y-m-d H:i:s')]),
        ]);
    }

    // --- Consentimentos ---

    public function updateConsents(): Response
    {
        $userId = (int) Auth::id();
        foreach (PrivacyService::CONSENTS as $kind => $meta) {
            if (!$meta['revocable']) {
                continue;
            }
            PrivacyService::setConsent($userId, $kind, $this->request->bool('consent_' . $kind));
        }
        $this->flash('success', 'Consentimentos atualizados. Cada mudança fica registrada com data, hora e IP.');
        return $this->redirectRoute('privacy.index');
    }

    public function revokeAllNotifications(): Response
    {
        PrivacyService::revokeAllNotifications((int) Auth::id());
        $this->flash('success', 'Todos os avisos foram desligados. Você continua recebendo apenas os e-mails necessários ao serviço.');
        return $this->redirectRoute('privacy.index');
    }

    // --- Acesso ---

    public function myData(): Response
    {
        return $this->view('account/my-data', ['title' => 'Meus dados', 'info' => PrivacyService::myData((int) Auth::id())]);
    }

    // --- Portabilidade ---

    public function export(): Response
    {
        $userId = (int) Auth::id();
        $recent = (int) \App\Core\Database::scalar('SELECT COUNT(*) FROM data_exports WHERE user_id = ? AND created_at >= ?', [$userId, gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ($recent >= 3) {
            $this->flash('warning', 'Você já gerou 3 exportações na última hora. Use um dos links abaixo ou aguarde.');
            return $this->redirectRoute('privacy.index');
        }
        $export = ExportService::create($userId);
        Session::set('last_export_token', $export['token']);
        $this->flash('success', 'Exportação pronta (' . number_format($export['size'] / 1024, 1, ',', '.') . ' KB). O link vale por 24 horas e também foi enviado por e-mail.');
        return $this->redirectRoute('privacy.export.download', ['token' => $export['token']], ['ver' => 1]);
    }

    public function download(string $token): Response
    {
        $row = ExportService::findValid($token, (int) Auth::id());
        if ($row === null) {
            throw new HttpException(404, 'Esta exportação não existe ou expirou. Gere uma nova.');
        }
        if ($this->request->query('ver') !== null) {
            return $this->view('account/export', ['title' => 'Exportação pronta', 'row' => $row, 'token' => $token]);
        }
        ExportService::markDownloaded((int) $row['id']);
        return Response::file((string) $row['absolute_path'], 'nosso-cofre-meus-dados-' . gmdate('Ymd') . '.zip', 'application/zip');
    }

    // --- Correção: troca de e-mail e dados opcionais ---

    public function requestEmailChange(): Response
    {
        $user = Auth::user() ?? [];
        $data = $this->validate([
            'email'    => 'required|email|unique:users,email',
            'password' => 'required',
        ], ['email' => 'novo e-mail', 'password' => 'senha'], 'privacy.index');
        if (!Auth::verifyPassword((string) $data['password'], (string) $user['password_hash'])) {
            Session::flashErrors(['password' => ['Senha incorreta.']]);
            return $this->redirectRoute('privacy.index');
        }
        AuthService::requestEmailChange($user, (string) $data['email']);
        $this->flash('info', 'Enviamos um link de confirmação para ' . $data['email'] . '. O e-mail atual continua valendo até a confirmação.');
        return $this->redirectRoute('privacy.index');
    }

    public function updateOptional(): Response
    {
        $user = Auth::user() ?? [];
        $data = $this->validate([
            'document' => 'nullable|cpf',
            'income'   => 'nullable|money',
        ], ['document' => 'CPF', 'income' => 'renda mensal'], 'privacy.index');
        (new User())->update((int) $user['id'], ['document' => $data['document'] !== null ? (string) $data['document'] : null]);
        if (Auth::householdId() !== null) {
            \App\Core\Database::execute('UPDATE household_members SET estimated_income = ? WHERE household_id = ? AND user_id = ?', [$data['income'] !== null ? (string) $data['income'] : null, Auth::householdId(), (int) $user['id']]);
        }
        AuditService::log('user.profile_updated', 'user', (int) $user['id'], null, ['document' => $data['document'] !== null ? 'informado' : 'removido', 'income' => $data['income']]);
        Auth::refresh();
        $this->flash('success', 'Dados opcionais atualizados.');
        return $this->redirectRoute('privacy.index');
    }

    // --- Anonimização ---

    public function anonymize(): Response
    {
        $user = Auth::user() ?? [];
        if (!$this->confirmIdentity($user)) {
            return $this->redirectRoute('privacy.index');
        }
        $error = PrivacyService::requestAccountDeletion((int) $user['id'], $this->request->ip()); // mesma trava de responsável
        if ($error !== null && !str_contains($error, 'em andamento')) {
            $this->flash('danger', $error);
            return $this->redirectRoute('privacy.index');
        }
        // Anonimização é imediata (não tem carência): a identidade some, os lançamentos ficam
        \App\Core\Database::execute("UPDATE deletion_requests SET cancelled_at = ? WHERE user_id = ? AND kind = 'account' AND executed_at IS NULL AND cancelled_at IS NULL", [gmdate('Y-m-d H:i:s'), (int) $user['id']]);
        PrivacyService::anonymizeUser((int) $user['id'], 'anonymize');
        Auth::logout();
        $this->flash('info', 'Sua identidade foi desvinculada. Os lançamentos continuam nos totais do lar como "Membro removido". Obrigado por usar o Nosso Cofre.');
        return $this->redirectRoute('home');
    }

    // --- Exclusão da conta ---

    public function requestDeletion(): Response
    {
        $user = Auth::user() ?? [];
        if (!$this->confirmIdentity($user)) {
            return $this->redirectRoute('privacy.index');
        }
        $error = PrivacyService::requestAccountDeletion((int) $user['id'], $this->request->ip());
        if ($error !== null) {
            $this->flash('danger', $error);
        } else {
            Auth::refresh();
            $this->flash('warning', 'Exclusão agendada para daqui a ' . PrivacyService::graceDays() . ' dias. Até lá você pode cancelar aqui ou pelo link do e-mail.');
        }
        return $this->redirectRoute('privacy.index');
    }

    public function cancelDeletion(): Response
    {
        PrivacyService::cancelAccountDeletion((int) Auth::id());
        Auth::refresh();
        $this->flash('success', 'Exclusão cancelada. Sua conta continua ativa.');
        return $this->redirectRoute('privacy.index');
    }

    // --- Exclusão do lar (responsável) ---

    public function requestHouseholdDeletion(): Response
    {
        if (!Auth::isOwner()) {
            throw new HttpException(403, 'Só o responsável pelo lar pode excluí-lo.');
        }
        $user = Auth::user() ?? [];
        if (!$this->confirmIdentity($user)) {
            return $this->redirectRoute('privacy.index');
        }
        $error = PrivacyService::requestHouseholdDeletion((int) Auth::householdId(), (int) $user['id'], $this->request->ip());
        $this->flash($error === null ? 'warning' : 'danger', $error ?? 'Exclusão do lar agendada. Todos os membros foram avisados por e-mail e você pode cancelar nos próximos ' . PrivacyService::graceDays() . ' dias.');
        Auth::refresh();
        return $this->redirectRoute('privacy.index');
    }

    public function cancelHouseholdDeletion(): Response
    {
        if (!Auth::isOwner()) {
            throw new HttpException(403);
        }
        PrivacyService::cancelHouseholdDeletion((int) Auth::householdId(), (int) Auth::id());
        Auth::refresh();
        $this->flash('success', 'Exclusão do lar cancelada.');
        return $this->redirectRoute('privacy.index');
    }

    // --- Sair do lar levando ou não os dados ---

    public function leaveHousehold(): Response
    {
        if (Auth::isOwner()) {
            throw new HttpException(403, 'O responsável não sai do lar: transfira a responsabilidade ou exclua o lar.');
        }
        $data = $this->validate(['take_data' => 'required|in:keep,take'], ['take_data' => 'o que fazer com os lançamentos'], 'privacy.index');
        $newId = PrivacyService::leaveHousehold((int) Auth::id(), (int) Auth::householdId(), $data['take_data'] === 'take');
        if ($newId === null) {
            $this->flash('danger', 'Não foi possível sair do lar.');
            return $this->redirectRoute('privacy.index');
        }
        Session::set('household_id', $newId > 0 ? $newId : null);
        Auth::refresh();
        $this->flash('info', $newId > 0 ? 'Você saiu do lar e seus dados foram para um lar individual só seu.' : 'Você saiu do lar. Seus lançamentos ficaram lá, sem o seu nome.');
        return $this->redirectRoute($newId > 0 ? 'dashboard' : 'onboarding.start');
    }

    /** Ações irreversíveis exigem senha e, se ativo, código 2FA. */
    /** @param array<string,mixed> $user */
    private function confirmIdentity(array $user): bool
    {
        $password = $this->request->str('password');
        if (!Auth::verifyPassword($password, (string) $user['password_hash'])) {
            Session::flashErrors(['confirm' => ['Senha incorreta.']]);
            return false;
        }
        if (!empty($user['totp_enabled_at']) && !TwoFactorService::verifyCode($user, $this->request->str('code'))) {
            Session::flashErrors(['confirm' => ['Código de verificação inválido ou já utilizado. Aguarde o próximo código do aplicativo e tente de novo.']]);
            return false;
        }
        return true;
    }
}
