<?php
// app/Controllers/AlertController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Transaction;
use App\Services\AlertService;
use App\Services\NotificationSettingsService;
use App\Services\TransactionService;

/** Central de avisos (/avisos): histórico, filtro por tipo, lido/não lido, silenciar por 7 dias; ação assinada "marcar como pago". */
final class AlertController extends Controller
{
    public function index(): Response
    {
        $userId = (int) Auth::id();
        $type = (string) $this->request->query('tipo', '');
        $type = isset(NotificationSettingsService::TYPES[$type]) ? $type : null;
        $settings = NotificationSettingsService::load($userId);
        return $this->view('alerts/index', [
            'title'    => 'Avisos',
            'alerts'   => AlertService::list($userId, $type),
            'type'     => $type,
            'types'    => NotificationSettingsService::TYPES,
            'muted'    => array_filter((array) ($settings['muted_until'] ?? []), static fn(string $until): bool => strtotime($until . ' UTC') > time()),
            'unread'   => AlertService::unreadCount($userId),
        ]);
    }

    /** Avisos novos desde ?depois=ID (toast em tempo real). */
    public function poll(): Response
    {
        $userId = (int) Auth::id();
        $after = (int) $this->request->query('depois', 0);
        $rows = AlertService::since($userId, $after);
        return $this->json(true, ['alerts' => $rows, 'unread' => AlertService::unreadCount($userId), 'last' => $rows !== [] ? (int) end($rows)['id'] : $after]);
    }

    public function read(string $id): Response
    {
        AlertService::markRead((int) Auth::id(), (int) $id);
        if ($this->request->wantsJson()) {
            return $this->json(true, ['unread' => AlertService::unreadCount((int) Auth::id())]);
        }
        return $this->back('alerts.index');
    }

    public function readAll(): Response
    {
        $n = AlertService::markAllRead((int) Auth::id());
        if ($this->request->wantsJson()) {
            return $this->json(true, ['marked' => $n]);
        }
        $this->flash('success', $n > 0 ? "{$n} aviso(s) marcado(s) como lido(s)." : 'Nenhum aviso pendente.');
        return $this->redirectRoute('alerts.index');
    }

    public function mute(string $type): Response
    {
        if (!isset(NotificationSettingsService::TYPES[$type])) {
            throw new HttpException(404);
        }
        NotificationSettingsService::mute((int) Auth::id(), $type, 7);
        $this->flash('success', 'Avisos de "' . NotificationSettingsService::TYPES[$type]['label'] . '" silenciados por 7 dias.');
        return $this->redirectRoute('alerts.index');
    }

    public function unmute(string $type): Response
    {
        NotificationSettingsService::unmute((int) Auth::id(), $type);
        $this->flash('success', 'Avisos reativados.');
        return $this->redirectRoute('alerts.index');
    }

    /** Ação a partir da notificação (link assinado, sem sessão): marcar a conta como paga. */
    public function action(string $token): Response
    {
        $data = AlertService::parseActionToken($token);
        if ($data === null) {
            throw new HttpException(410, 'Este link expirou. Abra o app e marque a conta como paga por lá.');
        }
        if (Auth::id() !== $data['user_id']) {
            // exige a sessão do próprio usuário para executar a ação; sem sessão, manda para o login e volta
            if (!Auth::check()) {
                \App\Core\Session::set('_intended', '/avisos/acao/' . $token);
                return $this->redirectRoute('auth.login');
            }
            throw new HttpException(403, 'Este link é de outra conta.');
        }
        $tx = (new Transaction())->find($data['transaction_id']);
        if ($tx === null) {
            throw new HttpException(404, 'Lançamento não encontrado.');
        }
        if ($data['action'] === 'pay' && $tx['status'] !== 'paid') {
            TransactionService::setStatus($data['transaction_id'], 'paid');
            $this->flash('success', '"' . $tx['description'] . '" marcado como pago.');
        }
        return $this->redirectRoute('transactions.edit', ['id' => $data['transaction_id']]);
    }
}
