<?php
// app/Controllers/NotificationController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\NotificationSettingsService;
use App\Services\PrivacyService;
use App\Services\PushService;

/** Conta → Notificações (/conta/notificacoes): tipos, canais, cores, sons, agrupamento, horário silencioso, aparelhos. */
final class NotificationController extends Controller
{
    public function index(): Response
    {
        $userId = (int) Auth::id();
        return $this->view('account/notifications', [
            'title'      => 'Notificações',
            'settings'   => NotificationSettingsService::load($userId),
            'consents'   => PrivacyService::consentState($userId),
            'types'      => NotificationSettingsService::TYPES,
            'colors'     => NotificationSettingsService::COLORS,
            'sounds'     => NotificationSettingsService::SOUNDS,
            'channels'   => NotificationSettingsService::CHANNELS,
            'groupOpts'  => NotificationSettingsService::GROUP_MINUTES,
            'digestOpts' => NotificationSettingsService::DIGEST_CONTENTS,
            'devices'    => PushService::devices($userId),
            'pushReady'  => PushService::configured(),
            'vapidPublic'=> (string) Config::get('vapid.public', ''),
            'isFamily'   => Auth::isFamily(),
        ]);
    }

    public function update(): Response
    {
        $userId = (int) Auth::id();
        $input = $this->request->all();
        // Consentimentos de canal (base legal: consentimento) — a Privacidade continua sendo o lugar de revogar tudo
        PrivacyService::setConsent($userId, 'digest_email', !empty($input['consent_email']));
        PrivacyService::setConsent($userId, 'push', !empty($input['consent_push']));
        $saved = NotificationSettingsService::save($userId, $input);
        AuditService::log('notifications.updated', 'user', $userId, null, ['mode' => $saved['mode'], 'enabled' => array_keys(array_filter($saved['types'], static fn(array $t): bool => !empty($t['enabled'])))]);
        $this->flash('success', 'Preferências de aviso salvas. Nada é enviado fora do que você ligou aqui.');
        return $this->redirectRoute('notifications.index');
    }

    /** Chave pública VAPID para o navegador assinar o push. */
    public function vapidPublic(): Response
    {
        return $this->json(PushService::configured(), ['key' => (string) Config::get('vapid.public', '')], PushService::configured() ? '' : 'Web Push não configurado neste servidor.');
    }

    /** Registra a assinatura push deste aparelho (JSON do PushManager). */
    public function subscribe(): Response
    {
        $userId = (int) Auth::id();
        if (!PrivacyService::consentState($userId)['push']) {
            return $this->json(false, null, 'Ative o consentimento de push antes de registrar um aparelho.', 422);
        }
        $sub = $this->request->input('subscription');
        if (!is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
            return $this->json(false, null, 'Assinatura inválida.', 422);
        }
        $id = PushService::subscribe($userId, $sub, $this->request->userAgent(), $this->request->input('label'));
        AuditService::log('push.subscribed', 'push_subscription', $id);
        return $this->json(true, ['id' => $id], 'Aparelho registrado para receber avisos push.');
    }

    public function unsubscribe(): Response
    {
        $endpoint = (string) $this->request->input('endpoint', '');
        if ($endpoint !== '') {
            PushService::unsubscribeEndpoint((int) Auth::id(), $endpoint);
        }
        return $this->json(true, null, 'Aparelho removido.');
    }

    public function removeDevice(string $id): Response
    {
        PushService::unsubscribe((int) Auth::id(), (int) $id);
        AuditService::log('push.unsubscribed', 'push_subscription', (int) $id);
        $this->flash('success', 'Aparelho removido. Ele não recebe mais avisos push.');
        return $this->redirectRoute('notifications.index');
    }

    /** Envia um push de teste para um aparelho com a cor/som do tipo escolhido. */
    public function testDevice(string $id): Response
    {
        $userId = (int) Auth::id();
        $settings = NotificationSettingsService::load($userId);
        $type = (string) $this->request->input('type', 'income');
        $t = $settings['types'][$type] ?? $settings['types']['income'];
        if (!PushService::configured()) {
            throw new HttpException(422, 'Web Push não está configurado neste servidor (chaves VAPID).');
        }
        $r = PushService::sendToUser($userId, [
            'title' => 'Teste do Nosso Cofre', 'body' => 'Assim chegam os avisos de "' . (NotificationSettingsService::TYPES[$type]['label'] ?? $type) . '" neste aparelho.',
            'url' => absolute_url('/conta/notificacoes'), 'tag' => 'nc-test', 'renotify' => true, 'type' => $type, 'color' => $t['color'], 'sound' => $t['sound'],
            'vibrate' => !empty($settings['vibrate']) ? [100, 50, 100] : [], 'actions' => [['action' => 'open', 'title' => 'Ver']], 'data' => [],
        ], (int) $id);
        $this->flash($r['sent'] > 0 ? 'success' : 'danger', $r['sent'] > 0 ? 'Push de teste enviado. Se não apareceu, confira as permissões de notificação do aparelho.' : 'Não foi possível enviar: o aparelho recusou ou a assinatura expirou. Registre-o de novo.');
        return $this->redirectRoute('notifications.index');
    }
}
