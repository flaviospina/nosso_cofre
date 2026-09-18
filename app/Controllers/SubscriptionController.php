<?php
// app/Controllers/SubscriptionController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\RecurrenceService;
use App\Services\SubscriptionService;

/** Radar de assinaturas (/assinaturas). */
final class SubscriptionController extends Controller
{
    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        $radar = SubscriptionService::radar($householdId, $today);
        return $this->view('subscriptions/index', [
            'title'      => 'Radar de assinaturas',
            'radar'      => $radar,
            'categories' => (new Category())->map(),
            'accounts'   => array_column(AccountService::withBalances($householdId, false), 'name', 'id'),
            'canWrite'   => Auth::canWrite(),
            'today'      => $today->format('Y-m-d'),
        ]);
    }

    /** "Usei este serviço": zera o contador de 60 dias. */
    public function usage(string $id): Response
    {
        $this->requireWrite();
        $model = new RecurringRule();
        $model->findOrFail((int) $id);
        $model->update((int) $id, ['last_usage_confirmed_at' => (new \DateTimeImmutable('today', user_timezone()))->format('Y-m-d')]);
        $this->flash('success', 'Uso confirmado. Avisamos de novo se ficar 60 dias sem uso.');
        return $this->redirectRoute('subscriptions.index');
    }

    /** "Cancelei": encerra a regra e remove ocorrências futuras. */
    public function cancel(string $id): Response
    {
        $this->requireWrite();
        $model = new RecurringRule();
        $rule = $model->findOrFail((int) $id);
        $today = (new \DateTimeImmutable('today', user_timezone()))->format('Y-m-d');
        $model->update((int) $id, ['is_active' => 0, 'end_date' => $today]);
        Database::execute("DELETE FROM transactions WHERE household_id = ? AND recurring_id = ? AND status = 'scheduled' AND deleted_at IS NULL AND date >= ?", [(int) Auth::householdId(), (int) $id, $today]);
        AuditService::log('subscription.cancelled', 'recurring_rule', (int) $id, null, ['description' => $rule['description'], 'monthly' => (float) $rule['expected_amount'] * RecurrenceService::monthlyFactor($rule)]);
        $this->flash('success', 'Assinatura "' . $rule['description'] . '" marcada como cancelada. Economia de ' . money((float) $rule['expected_amount'] * RecurrenceService::monthlyFactor($rule)) . ' por mês.');
        return $this->redirectRoute('subscriptions.index');
    }

    /** Transforma uma cobrança detectada em recorrência (assinatura). */
    public function adopt(): Response
    {
        $this->requireWrite();
        $data = $this->validate([
            'description'  => 'required|max:190',
            'amount'       => 'required|money',
            'category_id'  => 'nullable|integer',
            'account_id'   => 'nullable|integer',
            'day_of_month' => 'required|integer|between:1,31',
        ], ['description' => 'descrição', 'amount' => 'valor', 'day_of_month' => 'dia'], 'subscriptions.index');
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        $accountOk = !empty($data['account_id']) && (int) Database::scalar('SELECT COUNT(*) FROM accounts WHERE id = ? AND household_id = ? AND deleted_at IS NULL', [(int) $data['account_id'], $householdId]) > 0;
        $categoryOk = !empty($data['category_id']) && (new Category())->validFor((int) $data['category_id'], 'expense');
        $rule = [
            'description' => $data['description'], 'kind' => 'expense',
            'category_id' => $categoryOk ? (int) $data['category_id'] : null, 'account_id' => $accountOk ? (int) $data['account_id'] : null,
            'responsible_user_id' => Auth::id(), 'expected_amount' => $data['amount'], 'expected_amount_source' => 'fixed',
            'frequency' => 'monthly', 'interval_count' => 1, 'day_of_month' => (int) $data['day_of_month'],
            'start_date' => $today->format('Y-m-01'), 'generate_days_ahead' => 30, 'auto_debit' => 1, 'is_subscription' => 1, 'is_active' => 1,
            'last_usage_confirmed_at' => $today->format('Y-m-d'),
        ];
        $rule['next_run_date'] = RecurrenceService::nextOccurrence($rule, $today->modify('+1 day'))?->format('Y-m-d');
        $id = (new RecurringRule())->create($rule);
        AuditService::log('recurrence.created', 'recurring_rule', $id, null, ['description' => $data['description'], 'source' => 'radar']);
        $this->flash('success', 'Assinatura "' . $data['description'] . '" cadastrada como recorrência. Ajuste os detalhes se precisar.');
        return $this->redirectRoute('recurrences.edit', ['id' => $id]);
    }

    private function requireWrite(): void
    {
        if (!Auth::canWrite()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
