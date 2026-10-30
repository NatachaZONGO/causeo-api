<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Contacts\ContactExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Export des contacts clients en vCard, pour le gérant propriétaire uniquement (pas d'accès
 * admin), dans toutes les formules.
 */
class ContactExportController extends Controller
{
    /**
     * Nombre de clients, dont ceux arrivés depuis le dernier export « nouveaux », et sa date.
     */
    public function summary(Business $business, ContactExportService $contacts): JsonResponse
    {
        $this->checkOwnership($business);

        return response()->json($contacts->summary($business));
    }

    /**
     * Fichier .vcf (vCard 3.0, un contact par client). scope=new : clients arrivés depuis le
     * dernier export, dont la date avance ensuite ; 204 s'il n'y en a aucun. scope=all : tous,
     * sans toucher à la date.
     */
    public function export(Request $request, Business $business, ContactExportService $contacts): Response
    {
        $this->checkOwnership($business);
        $scope = $request->validate(['scope' => ['required', Rule::in(ContactExportService::SCOPES)]])['scope'];

        $exportedAt = now();
        $clients = $contacts->clients($business, $scope);

        if ($clients->isEmpty()) {
            return response()->noContent();
        }

        $body = $contacts->vcards($business, $clients);

        if ($scope === ContactExportService::SCOPE_NEW) {
            $business->forceFill(['contacts_exported_at' => $exportedAt])->save();
        }

        return response($body, 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$contacts->filename($business).'"',
        ]);
    }

    /**
     * Vérifier que l'entreprise appartient à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
