<?php
// app/Controllers/LegalController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Services\LegalService;

final class LegalController extends Controller
{
    public function terms(): Response
    {
        $doc = LegalService::document('terms');
        return $this->view('legal/document', ['title' => $doc['title'], 'doc' => $doc]);
    }

    public function privacy(): Response
    {
        $doc = LegalService::document('privacy');
        return $this->view('legal/document', ['title' => $doc['title'], 'doc' => $doc]);
    }
}
