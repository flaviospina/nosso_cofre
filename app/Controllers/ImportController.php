<?php
// app/Controllers/ImportController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Models\Category;
use App\Models\HouseholdMember;
use App\Models\ImportBatch;
use App\Services\AccountService;
use App\Services\ImportService;
use App\Services\TransactionPolicy;

/** Importação de extratos CSV/OFX (/importar). */
final class ImportController extends Controller
{
    public function index(): Response
    {
        return $this->view('import/index', [
            'title'    => 'Importar extrato',
            'batches'  => (new ImportBatch())->all([], 'id', 'DESC', 20),
            'accounts' => array_values(array_filter(AccountService::withBalances((int) Auth::householdId(), false), static fn(array $a): bool => (int) $a['is_active'] === 1)),
            'canWrite' => TransactionPolicy::canCreate(),
        ]);
    }

    public function upload(): Response
    {
        $this->requireWrite();
        $file = $this->request->file('file');
        if ($file === null) {
            Session::flashErrors(['file' => ['Escolha um arquivo CSV ou OFX.']]);
            return $this->redirectRoute('import.index');
        }
        try {
            $staged = ImportService::stage($file, (int) Auth::id());
        } catch (\RuntimeException $e) {
            Session::flashErrors(['file' => [$e->getMessage()]]);
            return $this->redirectRoute('import.index');
        }
        Session::set('import_account_id', (int) $this->request->input('account_id', 0));
        return $this->redirectRoute('import.preview', ['key' => $staged['key']]);
    }

    /** Mapeamento (CSV) e pré-visualização com duplicados e sugestões. */
    public function preview(string $key): Response
    {
        $this->requireWrite();
        $meta = ImportService::load($key, (int) Auth::id());
        if ($meta === null) {
            $this->flash('danger', 'Arquivo de importação não encontrado ou expirado. Envie de novo.');
            return $this->redirectRoute('import.index');
        }
        $householdId = (int) Auth::householdId();
        $inspect = null;
        $mapping = [];
        $parsed = null;
        if ($meta['format'] === 'csv') {
            $inspect = ImportService::inspectCsv($meta['path']);
            $mapping = $this->mappingFromRequest($inspect);
            if ($this->request->query('mapear') !== null || $this->request->isMethod('POST')) {
                $parsed = ImportService::parseCsv($meta['path'], $mapping);
            }
        } else {
            $parsed = ImportService::parseOfx($meta['path']);
        }
        if ($parsed !== null) {
            $parsed['rows'] = ImportService::enrich($parsed['rows'], $householdId);
        }
        return $this->view('import/preview', [
            'title'       => 'Importar: ' . $meta['filename'],
            'key'         => $key,
            'meta'        => $meta,
            'inspect'     => $inspect,
            'mapping'     => $mapping,
            'parsed'      => $parsed,
            'dateFormats' => ImportService::DATE_FORMATS,
            'accounts'    => array_values(array_filter(AccountService::withBalances($householdId, false), static fn(array $a): bool => (int) $a['is_active'] === 1)),
            'categories'  => (new Category())->tree(null, false, true),
            'categoryMap' => (new Category())->map(),
            'members'     => (new HouseholdMember())->activeMembers(),
            'accountId'   => (int) Session::get('import_account_id', 0),
            'me'          => Auth::id(),
        ]);
    }

    public function confirm(string $key): Response
    {
        $this->requireWrite();
        $meta = ImportService::load($key, (int) Auth::id());
        if ($meta === null) {
            throw new HttpException(404, 'Arquivo de importação não encontrado ou expirado.');
        }
        $householdId = (int) Auth::householdId();
        $data = $this->validate([
            'account_id'          => 'required|integer',
            'responsible_user_id' => 'nullable|integer',
            'status'              => 'required|in:paid,pending',
            'rows'                => 'nullable|array',
        ], ['account_id' => 'conta', 'responsible_user_id' => 'responsável', 'status' => 'situação'], 'import.index');
        $accountOk = \App\Core\Database::scalar('SELECT COUNT(*) FROM accounts WHERE id = ? AND household_id = ? AND deleted_at IS NULL', [(int) $data['account_id'], $householdId]);
        if ((int) $accountOk === 0) {
            throw new HttpException(422, 'Conta inválida.');
        }
        if (!empty($data['responsible_user_id']) && (int) \App\Core\Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [$householdId, (int) $data['responsible_user_id']]) === 0) {
            throw new HttpException(422, 'Responsável inválido.');
        }
        $mapping = [];
        if ($meta['format'] === 'csv') {
            $inspect = ImportService::inspectCsv($meta['path']);
            $mapping = $this->mappingFromRequest($inspect);
            $parsed = ImportService::parseCsv($meta['path'], $mapping);
        } else {
            $parsed = ImportService::parseOfx($meta['path']);
        }
        $rows = ImportService::enrich($parsed['rows'], $householdId);
        $selected = array_values(array_map('intval', array_keys((array) ($data['rows'] ?? []))));
        $categories = [];
        $categoryModel = new Category();
        foreach ((array) $this->request->input('category', []) as $i => $cid) {
            $cid = (int) $cid;
            $categories[(int) $i] = $cid > 0 && $categoryModel->validFor($cid) ? $cid : null;
        }
        if ($selected === []) {
            $this->flash('warning', 'Nenhuma linha selecionada.');
            return $this->redirectRoute('import.preview', ['key' => $key], ['mapear' => 1]);
        }
        $result = ImportService::commit($rows, $selected, $categories, (int) $data['account_id'], !empty($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null, (string) $meta['filename'], (string) $meta['format'], $mapping, (string) $data['status']);
        ImportService::discard($key);
        $this->flash('success', $result['imported'] . ' lançamento(s) importado(s)' . ($result['duplicated'] > 0 ? ', ' . $result['duplicated'] . ' ignorado(s)' : '') . '. Dá para desfazer em Importar → Lotes.');
        return $this->redirectRoute('transactions.index', [], $rows !== [] ? ['de' => min(array_column($rows, 'date')), 'ate' => max(array_column($rows, 'date'))] : []);
    }

    public function undo(string $id): Response
    {
        $this->requireWrite();
        $batch = (new ImportBatch())->findOrFail((int) $id);
        if ((int) $batch['user_id'] !== (int) Auth::id() && !Auth::canManage()) {
            throw new HttpException(403, 'Só quem importou (ou o responsável do lar) pode desfazer este lote.');
        }
        if ($batch['status'] === 'undone') {
            throw new HttpException(422, 'Este lote já foi desfeito.');
        }
        $n = ImportService::undo((int) $id);
        $this->flash('success', "{$n} lançamento(s) enviados para a lixeira.");
        return $this->redirectRoute('import.index');
    }

    /** @param array<string,mixed> $inspect
     *  @return array<string,mixed> */
    private function mappingFromRequest(array $inspect): array
    {
        $m = $inspect['mapping'];
        $in = $this->request->isMethod('POST') ? $this->request->all() : $this->request->queryAll();
        $col = static function (string $key) use ($in, $m): ?int {
            if (!array_key_exists($key, $in)) {
                return $m[$key] ?? null;
            }
            return $in[$key] === '' ? null : (int) $in[$key];
        };
        return [
            'delimiter'   => $inspect['delimiter'],
            'has_header'  => array_key_exists('has_header', $in) ? (bool) $in['has_header'] : (bool) $inspect['has_header'],
            'date'        => $col('date'),
            'description' => $col('description'),
            'amount'      => $col('amount'),
            'debit'       => $col('debit'),
            'credit'      => $col('credit'),
            'type'        => $col('type'),
            'date_format' => isset($in['date_format']) && isset(ImportService::DATE_FORMATS[$in['date_format']]) ? (string) $in['date_format'] : (string) $m['date_format'],
            'invert'      => !empty($in['invert']),
        ];
    }

    private function requireWrite(): void
    {
        if (!TransactionPolicy::canCreate()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
