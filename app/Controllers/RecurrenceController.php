<?php
// app/Controllers/RecurrenceController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Models\Category;
use App\Models\HouseholdMember;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\RecurrenceService;
use App\Services\TransactionService;

/** Recorrências (/recorrencias): regras, ocorrências a confirmar, débito automático e eventos previstos. */
final class RecurrenceController extends Controller
{
    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        RecurrenceService::generate($householdId, $today);
        $rules = (new RecurringRule())->all([], 'description', 'ASC');
        $model = new Transaction();
        return $this->view('recurrences/index', [
            'title'      => 'Recorrências',
            'rules'      => $rules,
            'pending'    => array_map(static fn(array $r): array => $model->castRow($r), RecurrenceService::pendingOccurrences($householdId, $today, 7)),
            'autoDebit'  => RecurrenceService::autoDebitStats($householdId),
            'events'     => RecurrenceService::upcomingEvents($householdId, $today),
            'categories' => (new Category())->map(),
            'accounts'   => array_column(AccountService::withBalances($householdId, false), 'name', 'id'),
            'members'    => $this->membersMap(),
            'canWrite'   => Auth::canWrite(),
            'isFamily'   => Auth::isFamily(),
            'today'      => $today->format('Y-m-d'),
        ]);
    }

    public function create(): Response
    {
        $this->requireWrite();
        $prefill = ['kind' => $this->request->query('tipo') === 'income' ? 'income' : 'expense', 'frequency' => 'monthly', 'start_date' => (new \DateTimeImmutable('today', user_timezone()))->format('Y-m-d')];
        return $this->form(null, $prefill);
    }

    public function store(): Response
    {
        $this->requireWrite();
        $data = $this->validated('recurrences.create');
        $today = new \DateTimeImmutable('today', user_timezone());
        $data['next_run_date'] = RecurrenceService::nextOccurrence($data, $today)?->format('Y-m-d');
        $data['is_active'] = 1;
        $id = (new RecurringRule())->create($data);
        AuditService::log('recurrence.created', 'recurring_rule', $id, null, ['description' => $data['description'], 'frequency' => $data['frequency']]);
        RecurrenceService::generate((int) Auth::householdId(), $today);
        $this->flash('success', 'Recorrência "' . $data['description'] . '" criada. As próximas ocorrências já aparecem como agendadas em Lançamentos.');
        return $this->redirectRoute('recurrences.index');
    }

    public function edit(string $id): Response
    {
        $this->requireWrite();
        return $this->form((new RecurringRule())->findOrFail((int) $id), []);
    }

    public function update(string $id): Response
    {
        $this->requireWrite();
        $model = new RecurringRule();
        $before = $model->findOrFail((int) $id);
        $data = $this->validated('recurrences.edit', (int) $id);
        $today = new \DateTimeImmutable('today', user_timezone());
        $data['next_run_date'] = RecurrenceService::nextOccurrence($data, $today)?->format('Y-m-d');
        $model->update((int) $id, $data);
        AuditService::log('recurrence.updated', 'recurring_rule', (int) $id, array_intersect_key($before, $data), $data);
        // Ocorrências futuras ainda não confirmadas são refeitas com os novos parâmetros
        $this->dropFutureScheduled((int) $id, $today);
        RecurrenceService::generate((int) Auth::householdId(), $today);
        $this->flash('success', 'Recorrência atualizada.');
        return $this->redirectRoute('recurrences.index');
    }

    /** Pausa/retoma. */
    public function toggle(string $id): Response
    {
        $this->requireWrite();
        $model = new RecurringRule();
        $rule = $model->findOrFail((int) $id);
        $today = new \DateTimeImmutable('today', user_timezone());
        $active = (int) $rule['is_active'] === 1 ? 0 : 1;
        $model->update((int) $id, ['is_active' => $active, 'next_run_date' => $active ? RecurrenceService::nextOccurrence($rule, $today)?->format('Y-m-d') : $rule['next_run_date']]);
        if ($active === 0) {
            $this->dropFutureScheduled((int) $id, $today);
        } else {
            RecurrenceService::generate((int) Auth::householdId(), $today);
        }
        AuditService::log($active ? 'recurrence.resumed' : 'recurrence.paused', 'recurring_rule', (int) $id);
        $this->flash('success', $active ? 'Recorrência retomada.' : 'Recorrência pausada; as ocorrências futuras ainda não confirmadas foram removidas.');
        return $this->redirectRoute('recurrences.index');
    }

    public function destroy(string $id): Response
    {
        $this->requireWrite();
        $model = new RecurringRule();
        $rule = $model->findOrFail((int) $id);
        $this->dropFutureScheduled((int) $id, new \DateTimeImmutable('today', user_timezone()));
        $model->update((int) $id, ['is_active' => 0]);
        $model->delete((int) $id);
        AuditService::log('recurrence.deleted', 'recurring_rule', (int) $id, ['description' => $rule['description']], null);
        $this->flash('success', 'Recorrência excluída. Os lançamentos já confirmados ficam no histórico.');
        return $this->redirectRoute('recurrences.index');
    }

    public function autoDebit(string $id): Response
    {
        $this->requireWrite();
        $model = new RecurringRule();
        $rule = $model->findOrFail((int) $id);
        $value = (int) $rule['auto_debit'] === 1 ? 0 : 1;
        $model->update((int) $id, ['auto_debit' => $value]);
        Database::execute("UPDATE transactions SET auto_debit = ? WHERE household_id = ? AND recurring_id = ? AND status = 'scheduled'", [$value, (int) Auth::householdId(), (int) $id]);
        AuditService::log('recurrence.updated', 'recurring_rule', (int) $id, ['auto_debit' => $rule['auto_debit']], ['auto_debit' => $value]);
        $this->flash('success', $value ? 'Marcada como débito automático.' : 'Débito automático desmarcado.');
        return $this->redirectRoute('recurrences.index');
    }

    public function generate(): Response
    {
        $this->requireWrite();
        $n = RecurrenceService::generate((int) Auth::householdId(), new \DateTimeImmutable('today', user_timezone()));
        $this->flash('success', $n > 0 ? "{$n} ocorrência(s) agendada(s) gerada(s)." : 'Nada novo para gerar: as próximas ocorrências já estão agendadas.');
        return $this->redirectRoute('recurrences.index');
    }

    /** Confirma uma ocorrência agendada (opcionalmente com o valor real). */
    public function confirm(string $txId): Response
    {
        $this->requireWrite();
        $data = $this->validate(['amount' => 'nullable|money', 'date' => 'nullable|date'], ['amount' => 'valor', 'date' => 'data'], 'recurrences.index');
        $model = new Transaction();
        $tx = $model->findOrFail((int) $txId);
        if ($tx['recurring_id'] === null) {
            throw new HttpException(422, 'Este lançamento não vem de uma recorrência.');
        }
        $fields = [];
        if (!empty($data['amount']) && (float) $data['amount'] > 0) {
            $fields['amount'] = $data['amount'];
        }
        if (!empty($data['date'])) {
            $fields['date'] = $data['date'];
        }
        if ($fields !== []) {
            $model->update((int) $txId, $fields);
        }
        TransactionService::setStatus((int) $txId, 'paid');
        $this->flash('success', 'Ocorrência confirmada como paga/recebida.');
        return $this->back('recurrences.index');
    }

    /** Pula uma ocorrência (manda para a lixeira). */
    public function skip(string $txId): Response
    {
        $this->requireWrite();
        $tx = (new Transaction())->findOrFail((int) $txId);
        if ($tx['recurring_id'] === null) {
            throw new HttpException(422, 'Este lançamento não vem de uma recorrência.');
        }
        TransactionService::trash((int) $txId);
        $this->flash('success', 'Ocorrência pulada.');
        return $this->back('recurrences.index');
    }

    // --- internos ---

    /** @param array<string,mixed>|null $rule
     *  @param array<string,mixed> $prefill */
    private function form(?array $rule, array $prefill): Response
    {
        $householdId = (int) Auth::householdId();
        $categories = new Category();
        return $this->view('recurrences/form', [
            'title'             => $rule !== null ? 'Editar recorrência' : 'Nova recorrência',
            'rule'              => $rule,
            'values'            => $rule ?? $prefill,
            'accounts'          => array_values(array_filter(AccountService::withBalances($householdId, false), static fn(array $a): bool => (int) $a['is_active'] === 1 || (int) ($rule['account_id'] ?? 0) === (int) $a['id'])),
            'categoriesExpense' => $categories->tree('expense'),
            'categoriesIncome'  => $categories->tree('income'),
            'members'           => (new HouseholdMember())->activeMembers(),
            'frequencies'       => RecurringRule::FREQUENCIES,
            'sources'           => RecurringRule::SOURCES,
            'isFamily'          => Auth::isFamily(),
        ]);
    }

    /** @return array<string,mixed> */
    private function validated(string $backRoute, ?int $id = null): array
    {
        $data = $this->validate([
            'description'            => 'required|min:2|max:190',
            'kind'                   => 'required|in:expense,income',
            'category_id'            => 'nullable|integer',
            'account_id'             => 'nullable|integer',
            'responsible_user_id'    => 'nullable|integer',
            'expected_amount'        => 'required|money',
            'expected_amount_source' => 'required|in:fixed,average',
            'frequency'              => 'required|in:weekly,monthly,yearly,custom',
            'interval_count'         => 'nullable|integer|between:1,365',
            'day_of_month'           => 'nullable|integer|between:1,31',
            'day_of_week'            => 'nullable|integer|between:1,7',
            'month_of_year'          => 'nullable|integer|between:1,12',
            'start_date'             => 'required|date',
            'end_date'               => 'nullable|date',
            'generate_days_ahead'    => 'nullable|integer|between:0,366',
            'auto_debit'             => 'nullable|boolean',
            'is_subscription'        => 'nullable|boolean',
            'is_major_event'         => 'nullable|boolean',
            'notify_days_before'     => 'nullable|integer|between:0,120',
        ], ['description' => 'descrição', 'kind' => 'tipo', 'category_id' => 'categoria', 'account_id' => 'conta', 'responsible_user_id' => 'responsável', 'expected_amount' => 'valor esperado', 'expected_amount_source' => 'origem do valor', 'frequency' => 'frequência', 'interval_count' => 'intervalo', 'day_of_month' => 'dia do mês', 'day_of_week' => 'dia da semana', 'month_of_year' => 'mês', 'start_date' => 'início', 'end_date' => 'fim', 'generate_days_ahead' => 'antecedência'], $backRoute);
        $householdId = (int) Auth::householdId();
        if ((float) $data['expected_amount'] < 0) {
            Session::flashErrors(['expected_amount' => ['O valor não pode ser negativo.']]);
            Session::flashInput($this->request->all());
            throw new HttpException(422);
        }
        if (!empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            Session::flashErrors(['end_date' => ['A data final deve ser depois do início.']]);
            Session::flashInput($this->request->all());
            throw new HttpException(422);
        }
        if (!empty($data['account_id']) && (int) Database::scalar('SELECT COUNT(*) FROM accounts WHERE id = ? AND household_id = ? AND deleted_at IS NULL', [(int) $data['account_id'], $householdId]) === 0) {
            throw new HttpException(422, 'Conta inválida.');
        }
        if (!empty($data['category_id']) && !(new Category())->validFor((int) $data['category_id'], (string) $data['kind'])) {
            Session::flashErrors(['category_id' => ['Categoria inválida para este tipo.']]);
            Session::flashInput($this->request->all());
            throw new HttpException(422);
        }
        if (!empty($data['responsible_user_id']) && (int) Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [$householdId, (int) $data['responsible_user_id']]) === 0) {
            throw new HttpException(422, 'Responsável inválido.');
        }
        $start = new \DateTimeImmutable((string) $data['start_date']);
        return [
            'description'            => $data['description'],
            'kind'                   => $data['kind'],
            'category_id'            => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'account_id'             => !empty($data['account_id']) ? (int) $data['account_id'] : null,
            'responsible_user_id'    => !empty($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null,
            'expected_amount'        => $data['expected_amount'],
            'expected_amount_source' => $data['expected_amount_source'],
            'frequency'              => $data['frequency'],
            'interval_count'         => max(1, (int) ($data['interval_count'] ?? 1)),
            'day_of_month'           => in_array($data['frequency'], ['monthly', 'yearly'], true) ? (int) ($data['day_of_month'] ?: $start->format('j')) : null,
            'day_of_week'            => $data['frequency'] === 'weekly' ? (int) ($data['day_of_week'] ?: $start->format('N')) : null,
            'month_of_year'          => $data['frequency'] === 'yearly' ? (int) ($data['month_of_year'] ?: $start->format('n')) : null,
            'start_date'             => $data['start_date'],
            'end_date'               => $data['end_date'] ?: null,
            'generate_days_ahead'    => (int) ($data['generate_days_ahead'] ?? 30),
            'auto_debit'             => (int) ($data['auto_debit'] ?? 0),
            'is_subscription'        => (int) ($data['is_subscription'] ?? 0),
            'is_major_event'         => (int) ($data['is_major_event'] ?? 0),
            'notify_days_before'     => $data['notify_days_before'] !== null && $data['notify_days_before'] !== '' ? (int) $data['notify_days_before'] : null,
        ];
    }

    private function dropFutureScheduled(int $ruleId, \DateTimeImmutable $today): void
    {
        Database::execute("DELETE FROM transactions WHERE household_id = ? AND recurring_id = ? AND status = 'scheduled' AND deleted_at IS NULL AND date >= ?", [(int) Auth::householdId(), $ruleId, $today->format('Y-m-d')]);
    }

    /** @return array<int,string> */
    private function membersMap(): array
    {
        $map = [];
        foreach ((new HouseholdMember())->activeMembers() as $m) {
            $map[(int) $m['user_id']] = (string) $m['name'];
        }
        return $map;
    }

    private function requireWrite(): void
    {
        if (!Auth::canWrite()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
