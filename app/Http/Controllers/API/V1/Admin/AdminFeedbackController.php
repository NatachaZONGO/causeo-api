<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Retours des gérants, côté admin : liste filtrée, statut, réponse écrite.
 */
class AdminFeedbackController extends Controller
{
    /**
     * Les plus récents d'abord. Filtres : ?type=, ?status=, ?search= (message, gérant, business).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(Feedback::TYPES)],
            'status' => ['nullable', Rule::in(Feedback::STATUSES)],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $search = trim((string) $request->input('search'));
        $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';

        $feedback = Feedback::query()
            ->with(['user:id,name,email', 'business:id,name'])
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($like) {
                // % et _ tapés par l'admin sont cherchés tels quels (ESCAPE : SQLite n'a pas d'échappement par défaut).
                $contains = fn (string $column) => ["LOWER({$column}) LIKE ? ESCAPE '\'", [$like]];

                $query->whereRaw(...$contains('message'))
                    ->orWhereHas('user', fn ($user) => $user->whereRaw(...$contains('name'))->orWhereRaw(...$contains('email')))
                    ->orWhereHas('business', fn ($business) => $business->whereRaw(...$contains('name')));
            }))
            ->latest()
            ->paginate(20);

        return response()->json([
            'new_count' => Feedback::query()->where('status', 'nouveau')->count(),
            ...$feedback->toArray(),
        ]);
    }

    /**
     * Changer le statut et/ou répondre. Une nouvelle réponse ou le passage en
     * « résolu » prévient le gérant (cloche et e-mail).
     */
    public function update(Request $request, Feedback $feedback, FeedbackService $service): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Feedback::STATUSES)],
            'admin_reply' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [
            'status.in' => 'Le statut doit être : nouveau, en_cours, resolu ou ferme.',
            'admin_reply.max' => 'La réponse est trop longue (5 000 caractères maximum).',
        ]);

        $feedback = $service->update($feedback, $data);

        return response()->json([
            'message' => 'Retour mis à jour.',
            'feedback' => $feedback->load(['user:id,name,email', 'business:id,name']),
        ]);
    }

    /**
     * Télécharger la capture d'écran jointe (bucket privé).
     */
    public function screenshot(Feedback $feedback): StreamedResponse
    {
        $disk = Storage::disk('supabase_documents');

        abort_if(empty($feedback->screenshot_path) || ! $disk->exists($feedback->screenshot_path), 404, 'Aucune capture pour ce retour.');

        return $disk->download($feedback->screenshot_path);
    }
}
