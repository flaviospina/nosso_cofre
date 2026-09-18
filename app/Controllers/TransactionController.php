<?php
// app/Controllers/TransactionController.php
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
use App\Models\Transaction;
use App\Models\TransactionTemplate;
use App\Services\AccountService;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Services\CategoryService;
use App\Services\TransactionPolicy;
use App\Services\TransactionService;

/** Lançamentos (/lancamentos): lista com filtros, lançamento rápido, edição, lixeira, lote, modelos e anexos. */
final class TransactionController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $q = $this->request->queryAll();
        $today = new \DateTimeImmutable('today', user_timezone());
        $filters = [
            'from'      => self::dateOrNull($q['de'] ?? null) ?? $today->modify('first day of this month')->format('Y-m-d'),
            'to'        => self::dateOrNull($q['ate'] ?? null) ?? $today->modify('last day of this month')->format('Y-m-d'),
            'member'    => isset($q['membro']) && $q['membro'] !== '' ? (string) $q['membro'] : '',
            'account'   => (int) ($q['conta'] ?? 0),
            'category'  => (int) ($q['categoria'] ?? 0),
            'type'      => in_array($q['tipo'] ?? '', ['expense', 'income', 'transfer'], true) ? (string) $q['tipo'] : '',
            'status'    => in_array($q['status'] ?? '', ['paid', 'pending', 'scheduled'], true) ? (string) $q['status'] : '',
            'tag'       => trim((string) ($q['tag'] ?? '')),
            'search'    => trim((string) ($q['busca'] ?? '')),
            'page'      => max(1, (int) ($q['pagina'] ?? 1)),
        ];
        [$where, $params] = $this->buildWhere($filters, $householdId);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM transactions t WHERE {$where}", $params);
        $sums = Database::selectOne("SELECT
                COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS expense,
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'paid' THEN t.amount END), 0) AS income_paid,
                COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'paid' THEN t.amount END), 0) AS expense_paid
              FROM transactions t WHERE {$where}", $params) ?? [];
        $offset = ($filters['page'] - 1) * self::PER_PAGE;
        $rows = Database::select(
            "SELECT t.*, a.name AS account_name, a.type AS account_type, ta.name AS transfer_account_name, u.name AS responsible_name, u.color AS responsible_color
               FROM transactions t
               JOIN accounts a ON a.id = t.account_id
               LEFT JOIN accounts ta ON ta.id = t.transfer_account_id
               LEFT JOIN users u ON u.id = t.responsible_user_id
              WHERE {$where} ORDER BY t.date DESC, t.id DESC LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $params
        );
        $model = new Transaction();
        $rows = array_map(static fn(array $r): array => TransactionPolicy::mask($model->castRow($r)), $rows);

        return $this->view('transactions/index', [
            'title'        => 'Lançamentos',
            'transactions' => $rows,
            'filters'      => $filters,
            'total'        => $total,
            'pages'        => (int) max(1, ceil($total / self::PER_PAGE)),
            'sums'         => $sums,
            'accounts'     => AccountService::withBalances($householdId, false),
            'categories'   => (new Category())->map(),
            'members'      => (new HouseholdMember())->activeMembers(),
            'canWrite'     => TransactionPolicy::canCreate(),
            'isFamily'     => Auth::isFamily(),
            'me'           => Auth::id(),
        ]);
    }

    public function create(): Response
    {
        $this->requireWrite();
        $prefill = [];
        if ($this->request->query('repetir') === 'ultimo') {
            $last = TransactionService::lastOfUser();
            if ($last !== null) {
                $prefill = (new Transaction())->castRow($last);
                $prefill['description'] = preg_replace('/ \(\d+\/\d+\)$/', '', (string) $prefill['description']);
            }
        } elseif ($this->request->query('modelo') !== null) {
            $tpl = (new TransactionTemplate())->find((int) $this->request->query('modelo'));
            if ($tpl !== null && (int) $tpl['user_id'] === (int) Auth::id()) {
                $prefill = $tpl;
            }
        }
        $prefill['type'] = $this->request->query('tipo') ?? ($prefill['type'] ?? 'expense');
        return $this->form(null, $prefill);
    }

    public function store(): Response
    {
        $this->requireWrite();
        $data = $this->validateTransaction('transactions.create');
        $data['installments'] = max(1, min(120, (int) $this->request->input('installments', 1)));
        try {
            $ids = TransactionService::create($data, $this->request->file('attachment'));
        } catch (\RuntimeException $e) {
            if ($e instanceof \PDOException || get_class($e) !== \RuntimeException::class) {
                throw $e; // erro de banco/infra: mensagem genérica pelo ErrorHandler, detalhe só no log
            }
            Session::flashErrors(['attachment' => [$e->getMessage()]]);
            Session::flashInput($this->request->all());
            return $this->redirectRoute('transactions.create');
        }
        $this->flash('success', count($ids) > 1 ? count($ids) . ' parcelas registradas.' : 'Lançamento registrado.');
        if ($this->request->input('save_and_new')) {
            return $this->redirectRoute('transactions.create', [], ['tipo' => $data['type']]);
        }
        return $this->redirectRoute('transactions.index', [], $this->periodQuery((string) $data['date']));
    }

    public function edit(string $id): Response
    {
        $tx = (new Transaction())->findOrFail((int) $id);
        if (!TransactionPolicy::canEdit($tx)) {
            throw new HttpException(403, 'Você não pode editar este lançamento.');
        }
        return $this->form($tx, []);
    }

    public function update(string $id): Response
    {
        $data = $this->validateTransaction('transactions.edit');
        try {
            TransactionService::update((int) $id, $data, $this->request->file('attachment'), $this->request->bool('remove_attachment'));
        } catch (\RuntimeException $e) {
            if ($e instanceof \PDOException || get_class($e) !== \RuntimeException::class) {
                throw $e;
            }
            Session::flashErrors(['attachment' => [$e->getMessage()]]);
            return $this->redirectRoute('transactions.edit', ['id' => $id]);
        }
        $this->flash('success', 'Lançamento atualizado.');
        return $this->redirectRoute('transactions.index', [], $this->periodQuery((string) $data['date']));
    }

    public function status(string $id): Response
    {
        $data = $this->validate(['status' => 'required|in:paid,pending,scheduled'], ['status' => 'situação']);
        TransactionService::setStatus((int) $id, (string) $data['status']);
        if ($this->request->wantsJson()) {
            return $this->json(true, ['status' => $data['status']], 'Situação atualizada.');
        }
        $this->flash('success', $data['status'] === 'paid' ? 'Marcado como pago.' : 'Situação atualizada.');
        return $this->back('transactions.index');
    }

    public function trash(string $id): Response
    {
        TransactionService::trash((int) $id);
        if ($this->request->wantsJson()) {
            return $this->json(true, null, 'Enviado para a lixeira.');
        }
        $this->flash('success', 'Lançamento enviado para a lixeira. Você tem 30 dias para restaurar.');
        return $this->back('transactions.index');
    }

    public function trashIndex(): Response
    {
        $rows = Database::select(
            'SELECT t.*, a.name AS account_name, u.name AS responsible_name, u.color AS responsible_color FROM transactions t JOIN accounts a ON a.id = t.account_id LEFT JOIN users u ON u.id = t.responsible_user_id
              WHERE t.household_id = ? AND t.deleted_at IS NOT NULL ORDER BY t.deleted_at DESC LIMIT 200',
            [(int) Auth::householdId()]
        );
        $model = new Transaction();
        return $this->view('transactions/trash', [
            'title'        => 'Lixeira',
            'transactions' => array_map(static fn(array $r): array => TransactionPolicy::mask($model->castRow($r)), $rows),
            'categories'   => (new Category())->map(),
            'days'         => (int) (Database::scalar("SELECT `value` FROM settings WHERE `key` = 'retention.trash_days'") ?? 30),
        ]);
    }

    public function restore(string $id): Response
    {
        TransactionService::restore((int) $id);
        $this->flash('success', 'Lançamento restaurado.');
        return $this->redirectRoute('transactions.trash');
    }

    public function destroy(string $id): Response
    {
        TransactionService::destroy((int) $id);
        $this->flash('success', 'Lançamento excluído definitivamente.');
        return $this->redirectRoute('transactions.trash');
    }

    /** Edição em lote. */
    public function bulk(): Response
    {
        $this->requireWrite();
        $data = $this->validate([
            'action' => 'required|in:category,status,responsible,trash,private,public',
            'ids'    => 'required|array',
            'value'  => 'nullable|max:20',
        ], ['action' => 'ação', 'ids' => 'lançamentos'], 'transactions.index');
        $ids = array_values(array_filter(array_map('intval', (array) $data['ids'])));
        if ($ids === []) {
            $this->flash('warning', 'Selecione pelo menos um lançamento.');
            return $this->back('transactions.index');
        }
        $value = $data['value'] ?? null;
        if ($data['action'] === 'category' && $value !== null && $value !== '' && !(new Category())->validFor((int) $value)) {
            throw new HttpException(422, 'Categoria inválida.');
        }
        if ($data['action'] === 'status' && !in_array($value, ['paid', 'pending', 'scheduled'], true)) {
            throw new HttpException(422, 'Situação inválida.');
        }
        if ($data['action'] === 'responsible' && $value !== null && $value !== '' && (int) Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [(int) Auth::householdId(), (int) $value]) === 0) {
            throw new HttpException(422, 'Responsável inválido.');
        }
        $n = TransactionService::bulk($ids, (string) $data['action'], $value === '' ? null : $value);
        $this->flash('success', "{$n} lançamento(s) alterado(s).");
        return $this->back('transactions.index');
    }

    /** Serve o comprovante (autenticado, escopado pelo lar e respeitando privacidade). */
    public function attachment(string $id): Response
    {
        $tx = (new Transaction())->withTrashed()->findOrFail((int) $id);
        if (TransactionPolicy::isPrivateForMe($tx) || empty($tx['attachment_path'])) {
            throw new HttpException(404);
        }
        $path = AttachmentService::absolutePath((string) $tx['attachment_path']);
        if ($path === null) {
            throw new HttpException(404, 'Comprovante não encontrado.');
        }
        $mime = (string) ($tx['attachment_mime'] ?: 'application/octet-stream');
        // Imagens abrem na tela; PDF (pode trazer JavaScript/formulários) é baixado
        return Response::file($path, (string) ($tx['attachment_name'] ?: 'comprovante'), $mime, str_starts_with($mime, 'image/'));
    }

    // --- Modelos favoritos ---

    public function templates(): Response
    {
        return $this->view('transactions/templates', [
            'title'      => 'Modelos favoritos',
            'templates'  => (new TransactionTemplate())->all(['user_id' => (int) Auth::id()], 'sort_order', 'ASC'),
            'categories' => (new Category())->map(),
            'accounts'   => array_column(AccountService::withBalances((int) Auth::householdId(), false), 'name', 'id'),
        ]);
    }

    public function saveTemplate(string $id): Response
    {
        $this->requireWrite();
        $data = $this->validate(['name' => 'required|min:2|max:80'], ['name' => 'nome do modelo']);
        TransactionService::saveAsTemplate((int) $id, (string) $data['name']);
        $this->flash('success', 'Modelo "' . $data['name'] . '" salvo. Ele aparece no lançamento rápido.');
        return $this->back('transactions.index');
    }

    public function deleteTemplate(string $id): Response
    {
        $model = new TransactionTemplate();
        $tpl = $model->findOrFail((int) $id);
        if ((int) $tpl['user_id'] !== Auth::id() && !Auth::canManage()) {
            throw new HttpException(403);
        }
        $model->delete((int) $id);
        $this->flash('success', 'Modelo excluído.');
        return $this->redirectRoute('transactions.templates');
    }

    /** Sugestão de categoria pela descrição (fetch do formulário). */
    public function suggest(): Response
    {
        $description = (string) $this->request->query('descricao', '');
        $id = $description !== '' ? CategoryService::suggest((int) Auth::householdId(), $description) : null;
        return $this->json(true, ['category_id' => $id]);
    }

    // --- internos ---

    /** @param array<string,mixed>|null $tx
     *  @param array<string,mixed> $prefill */
    private function form(?array $tx, array $prefill): Response
    {
        $householdId = (int) Auth::householdId();
        $categories = new Category();
        $me = (int) Auth::id();
        $values = $tx ?? $prefill;
        $today = (new \DateTimeImmutable('today', user_timezone()))->format('Y-m-d');
        return $this->view('transactions/form', [
            'title'             => $tx !== null ? 'Editar lançamento' : 'Novo lançamento',
            'tx'                => $tx,
            'values'            => $values,
            'today'             => $today,
            'accounts'          => array_values(array_filter(AccountService::withBalances($householdId, false), static fn(array $a): bool => (int) $a['is_active'] === 1 || (int) ($values['account_id'] ?? 0) === (int) $a['id'])),
            'categoriesExpense' => $categories->tree('expense'),
            'categoriesIncome'  => $categories->tree('income'),
            'members'           => (new HouseholdMember())->activeMembers(),
            'templates'         => $tx === null ? (new TransactionTemplate())->all(['user_id' => $me], 'sort_order', 'ASC') : [],
            'defaultResponsible'=> $values['responsible_user_id'] ?? $me,
            'defaultAccount'    => $values['account_id'] ?? Session::get('last_account_id'),
            'isFamily'          => Auth::isFamily(),
        ]);
    }

    /** @return array<string,mixed> */
    private function validateTransaction(string $backRoute): array
    {
        $data = $this->validate([
            'type'                => 'required|in:expense,income,transfer',
            'amount'              => 'required|money',
            'date'                => 'required|date_br',
            'description'         => 'required|max:190',
            'account_id'          => 'required|integer',
            'transfer_account_id' => 'nullable|integer',
            'category_id'         => 'nullable|integer',
            'responsible_user_id' => 'nullable|integer',
            'status'              => 'nullable|in:paid,pending,scheduled',
            'notes'               => 'nullable|max:2000',
            'tags'                => 'nullable|max:200',
            'auto_debit'          => 'nullable|boolean',
            'is_private'          => 'nullable|boolean',
        ], ['type' => 'tipo', 'amount' => 'valor', 'date' => 'data', 'description' => 'descrição', 'account_id' => 'conta', 'transfer_account_id' => 'conta de destino', 'category_id' => 'categoria', 'responsible_user_id' => 'responsável', 'status' => 'situação', 'notes' => 'observações', 'tags' => 'etiquetas'], $backRoute);
        if ((float) $data['amount'] <= 0) {
            Session::flashErrors(['amount' => ['O valor deve ser maior que zero.']]);
            Session::flashInput($this->request->all());
            throw new HttpException(422);
        }
        $householdId = (int) Auth::householdId();
        $accountOk = Database::scalar('SELECT COUNT(*) FROM accounts WHERE id = ? AND household_id = ? AND deleted_at IS NULL', [(int) $data['account_id'], $householdId]);
        if ((int) $accountOk === 0) {
            throw new HttpException(422, 'Conta inválida.');
        }
        if ($data['type'] === 'transfer') {
            $to = (int) ($data['transfer_account_id'] ?? 0);
            $toOk = Database::scalar('SELECT COUNT(*) FROM accounts WHERE id = ? AND household_id = ? AND deleted_at IS NULL', [$to, $householdId]);
            if ($to === 0 || (int) $toOk === 0 || $to === (int) $data['account_id']) {
                Session::flashErrors(['transfer_account_id' => ['Escolha uma conta de destino diferente da origem.']]);
                Session::flashInput($this->request->all());
                throw new HttpException(422);
            }
            $data['category_id'] = null;
        } elseif (!empty($data['category_id']) && !(new Category())->validFor((int) $data['category_id'], (string) $data['type'])) {
            Session::flashErrors(['category_id' => ['Categoria inválida para este tipo.']]);
            Session::flashInput($this->request->all());
            throw new HttpException(422);
        }
        if (!empty($data['responsible_user_id'])) {
            $isMember = Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [$householdId, (int) $data['responsible_user_id']]);
            if ((int) $isMember === 0) {
                throw new HttpException(422, 'Responsável inválido.');
            }
        } else {
            $data['responsible_user_id'] = null;
        }
        $data['category_id'] = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        $data['status'] = $data['status'] ?? 'paid';
        $tags = array_values(array_filter(array_map(static fn(string $t): string => mb_substr(trim($t), 0, 30), explode(',', (string) ($data['tags'] ?? '')))));
        $data['tags'] = $tags === [] ? null : array_values(array_unique($tags));
        Session::set('last_account_id', (int) $data['account_id']);
        return $data;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function buildWhere(array $f, int $householdId): array
    {
        $where = ['t.household_id = ?', 't.deleted_at IS NULL', 't.date BETWEEN ? AND ?'];
        $params = [$householdId, $f['from'], $f['to']];
        if ($f['member'] === 'todos') {
            $where[] = 't.responsible_user_id IS NULL';
        } elseif ($f['member'] !== '' && ctype_digit($f['member'])) {
            $where[] = 't.responsible_user_id = ?';
            $params[] = (int) $f['member'];
        }
        if ($f['account'] > 0) {
            $where[] = '(t.account_id = ? OR t.transfer_account_id = ?)';
            $params[] = $f['account'];
            $params[] = $f['account'];
        }
        [$visibleSql, $visibleParams] = TransactionPolicy::visibleSql('t', (int) Auth::id(), $householdId);
        if ($f['category'] > 0) {
            // Filtrar por categoria/etiqueta/texto revelaria dados de lançamentos privados de outros: eles ficam fora do filtro
            $where[] = '(t.category_id = ? OR t.category_id IN (SELECT id FROM categories WHERE parent_id = ?)) AND ' . $visibleSql;
            $params[] = $f['category'];
            $params[] = $f['category'];
            $params = array_merge($params, $visibleParams);
        }
        if ($f['type'] !== '') {
            $where[] = 't.type = ?';
            $params[] = $f['type'];
        }
        if ($f['status'] !== '') {
            $where[] = 't.status = ?';
            $params[] = $f['status'];
        }
        if ($f['tag'] !== '') {
            $where[] = 'JSON_SEARCH(t.tags, "one", ?) IS NOT NULL AND ' . $visibleSql;
            $params[] = $f['tag'];
            $params = array_merge($params, $visibleParams);
        }
        if ($f['search'] !== '') {
            $where[] = 't.description LIKE ? AND ' . $visibleSql;
            $params[] = '%' . addcslashes($f['search'], '%_\\') . '%';
            $params = array_merge($params, $visibleParams);
        }
        return [implode(' AND ', $where), $params];
    }

    private static function dateOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    /** @return array<string,string> */
    private function periodQuery(string $date): array
    {
        $d = new \DateTimeImmutable($date);
        return ['de' => $d->modify('first day of this month')->format('Y-m-d'), 'ate' => $d->modify('last day of this month')->format('Y-m-d')];
    }

    private function requireWrite(): void
    {
        if (!TransactionPolicy::canCreate()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
