<?php
// app/Controllers/AdminIncidentController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\IncidentService;

/** Área do controlador: registro e comunicação de incidentes de segurança (LGPD art. 48). */
final class AdminIncidentController extends Controller
{
    public function index(): Response
    {
        return $this->view('admin/incidents', ['title' => 'Incidentes de segurança', 'incidents' => IncidentService::all()]);
    }

    public function store(): Response
    {
        $data = $this->validate([
            'title'       => 'required|min:5|max:190',
            'description' => 'required|min:10',
            'occurred_at' => 'nullable|datetime',
            'detected_at' => 'required|datetime',
            'notes'       => 'nullable|max:5000',
        ], ['title' => 'título', 'description' => 'descrição', 'occurred_at' => 'ocorrido em', 'detected_at' => 'detectado em', 'notes' => 'anotações'], 'admin.incidents');
        $id = IncidentService::create([
            'title' => (string) $data['title'], 'description' => (string) $data['description'],
            'occurred_at' => $data['occurred_at'] !== null ? str_replace('T', ' ', (string) $data['occurred_at']) . ':00' : null,
            'detected_at' => str_replace('T', ' ', (string) $data['detected_at']) . ':00',
            'notes' => $data['notes'] !== null ? (string) $data['notes'] : null,
        ], (int) Auth::id());
        $this->flash('success', 'Incidente registrado. Avalie o risco e, se necessário, comunique os afetados e a ANPD.');
        return $this->redirectRoute('admin.incidents.show', ['id' => $id]);
    }

    public function show(string $id): Response
    {
        $incident = IncidentService::find((int) $id);
        if ($incident === null) {
            throw new HttpException(404);
        }
        return $this->view('admin/incident', ['title' => 'Incidente #' . $id, 'incident' => $incident]);
    }

    public function notify(string $id): Response
    {
        $data = $this->validate([
            'scope'           => 'required|in:all,list',
            'emails'          => 'nullable|max:5000',
            'measures'        => 'required|min:5|max:2000',
            'recommendations' => 'required|min:5|max:2000',
        ], ['scope' => 'destinatários', 'measures' => 'medidas adotadas', 'recommendations' => 'recomendações'], 'admin.incidents.show');
        $emails = null;
        if ($data['scope'] === 'list') {
            $emails = array_values(array_filter(preg_split('/[\s,;]+/', (string) ($data['emails'] ?? '')) ?: []));
        }
        $count = IncidentService::notify((int) $id, $emails, (string) $data['measures'], (string) $data['recommendations'], (int) Auth::id());
        $this->flash('success', "Comunicado enfileirado para {$count} usuário(s).");
        return $this->redirectRoute('admin.incidents.show', ['id' => $id]);
    }

    public function close(string $id): Response
    {
        IncidentService::close((int) $id, (int) Auth::id());
        $this->flash('success', 'Incidente encerrado.');
        return $this->redirectRoute('admin.incidents.show', ['id' => $id]);
    }

    public function anpd(string $id): Response
    {
        IncidentService::markAnpdNotified((int) $id, (int) Auth::id());
        $this->flash('success', 'Comunicação à ANPD registrada.');
        return $this->redirectRoute('admin.incidents.show', ['id' => $id]);
    }
}
