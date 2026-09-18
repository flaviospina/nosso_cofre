<?php
// app/Controllers/ReportController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Pdf;
use App\Core\Response;
use App\Models\Category;
use App\Models\HouseholdMember;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\ReportService;

/** Relatórios (/relatorios): mensal, anual, por membro, por categoria, por conta/cartão; exportação CSV e PDF. */
final class ReportController extends Controller
{
    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        return $this->view('reports/index', [
            'title'      => 'Relatórios',
            'month'      => $today->format('Y-m'),
            'year'       => (int) $today->format('Y'),
            'members'    => (new HouseholdMember())->activeMembers(),
            'accounts'   => AccountService::withBalances($householdId, false),
            'categories' => (new Category())->tree(null, false, true),
            'isFamily'   => Auth::isFamily(),
        ]);
    }

    public function monthly(): Response
    {
        $householdId = (int) Auth::householdId();
        $memberId = $this->memberId();
        $data = ReportService::monthly($householdId, $this->month(), $memberId);
        return $this->deliver('monthly', 'Relatório mensal', $data['label'] . $this->memberSuffix($memberId), $data, 'reports/monthly', ['memberId' => $memberId]);
    }

    public function annual(): Response
    {
        $householdId = (int) Auth::householdId();
        $memberId = $this->memberId();
        $year = (int) $this->request->query('ano', (new \DateTimeImmutable('today', user_timezone()))->format('Y'));
        $year = max(2000, min(2100, $year));
        $data = ReportService::annual($householdId, $year, $memberId);
        return $this->deliver('annual', 'Relatório anual', (string) $year . $this->memberSuffix($memberId), $data, 'reports/annual', ['memberId' => $memberId]);
    }

    public function members(): Response
    {
        $data = ReportService::members((int) Auth::householdId(), $this->month());
        return $this->deliver('members', 'Relatório por membro', $data['label'], $data, 'reports/members', []);
    }

    public function category(): Response
    {
        $householdId = (int) Auth::householdId();
        $categoryId = (int) $this->request->query('categoria', 0);
        $categories = (new Category())->tree(null, false, true);
        if ($categoryId === 0) {
            $categoryId = (int) ($categories[0]['id'] ?? 0);
        }
        if ($categoryId === 0 || (new Category())->find($categoryId) === null) {
            throw new HttpException(404, 'Categoria não encontrada.');
        }
        $data = ReportService::category($householdId, $categoryId, $this->month());
        return $this->deliver('category', 'Relatório por categoria', ($data['category']['full_name'] ?? '') . ' · 12 meses até ' . $data['label'], $data, 'reports/category', ['categories' => $categories, 'categoryId' => $categoryId]);
    }

    public function account(): Response
    {
        $householdId = (int) Auth::householdId();
        $accounts = AccountService::withBalances($householdId, false);
        $accountId = (int) $this->request->query('conta', (int) ($accounts[0]['id'] ?? 0));
        $data = ReportService::account($householdId, $accountId, $this->month());
        if ($data === null) {
            throw new HttpException(404, 'Conta não encontrada.');
        }
        return $this->deliver('account', $data['is_card'] ? 'Fatura do cartão' : 'Extrato da conta', $data['account']['name'] . ' · ' . $data['label'], $data, 'reports/account', ['accounts' => $accounts, 'accountId' => $accountId]);
    }

    // --- internos ---

    /** Tela, CSV ou PDF conforme ?formato=. @param array<string,mixed> $extra */
    private function deliver(string $type, string $title, string $subtitle, array $data, string $view, array $extra): Response
    {
        $format = (string) $this->request->query('formato', '');
        if ($format === 'csv' || $format === 'pdf') {
            $table = ReportService::toTable($type, $data);
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title . ' ' . $subtitle) ?: $title)) ?? 'relatorio';
            $slug = trim((string) $slug, '-');
            AuditService::log('report.exported', 'report', 0, null, ['type' => $type, 'format' => $format, 'subtitle' => $subtitle]);
            if ($format === 'csv') {
                return Response::download(ReportService::csv($table), "{$slug}.csv", 'text/csv; charset=UTF-8');
            }
            $pdf = new Pdf($title, (Auth::household()['name'] ?? 'Nosso Cofre') . ' · ' . $subtitle);
            $pdf->table($table['headers'], $table['rows'], $table['widths'], $table['aligns'], $table['bold']);
            return Response::download($pdf->output(), "{$slug}.pdf", 'application/pdf');
        }
        return $this->view($view, $extra + [
            'title'    => $title,
            'subtitle' => $subtitle,
            'report'   => $data,
            'month'    => substr($this->month(), 0, 7),
            'members'  => (new HouseholdMember())->activeMembers(),
            'isFamily' => Auth::isFamily(),
            'query'    => array_filter($this->request->queryAll(), static fn($v, $k): bool => $k !== 'formato' && $v !== '', ARRAY_FILTER_USE_BOTH),
        ]);
    }

    private function month(): string
    {
        $value = (string) $this->request->query('mes', '');
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return $m[1] . '-' . $m[2] . '-01';
        }
        return (new \DateTimeImmutable('today', user_timezone()))->format('Y-m-01');
    }

    private function memberId(): ?int
    {
        $wanted = (int) $this->request->query('membro', 0);
        if ($wanted === 0) {
            return null;
        }
        foreach ((new HouseholdMember())->activeMembers() as $m) {
            if ((int) $m['user_id'] === $wanted) {
                return $wanted;
            }
        }
        return null;
    }

    private function memberSuffix(?int $memberId): string
    {
        if ($memberId === null) {
            return '';
        }
        foreach ((new HouseholdMember())->activeMembers() as $m) {
            if ((int) $m['user_id'] === $memberId) {
                return ' · ' . $m['name'];
            }
        }
        return '';
    }
}
