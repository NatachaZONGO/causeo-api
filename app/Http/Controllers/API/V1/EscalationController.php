<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Escalation;
use Illuminate\Http\JsonResponse;

class EscalationController extends Controller
{
    /**
     * Le gérant marque une escalade en attente comme traitée, sans répondre au client
     * (réglée par téléphone, plus d'actualité…). Rien n'est appris.
     */
    public function resolve(Escalation $escalation): JsonResponse
    {
        $business = $escalation->business ?? $escalation->conversation?->business;

        abort_if($business === null || $business->user_id !== auth()->id(), 403, 'Cette escalade ne vous appartient pas.');

        if ($escalation->status !== Escalation::PENDING) {
            return response()->json([
                'message' => 'Cette escalade n\'est plus en attente.',
                'escalation' => $escalation,
            ], 422);
        }

        $escalation->update(['status' => Escalation::RESOLVED]);

        return response()->json([
            'message' => 'Escalade marquée comme traitée.',
            'escalation' => $escalation,
        ]);
    }
}
