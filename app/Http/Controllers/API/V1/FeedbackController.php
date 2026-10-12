<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\User;
use App\Services\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Retours du gérant connecté : envoi (bug, suggestion, question) et historique.
 */
class FeedbackController extends Controller
{
    /** Envois autorisés par gérant et par heure. */
    public const MAX_PER_HOUR = 10;

    public function index(Request $request): JsonResponse
    {
        $feedback = Feedback::query()
            ->where('user_id', $request->user()->id)
            ->with('business:id,name')
            ->latest()
            ->paginate(20);

        return response()->json($feedback);
    }

    public function store(Request $request, FeedbackService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $key = "feedback:user:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return response()->json([
                'message' => "Vous avez envoyé beaucoup de retours. Réessayez dans {$minutes} minute".($minutes > 1 ? 's' : '').'.',
            ], 429);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(Feedback::TYPES)],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
            'page' => ['nullable', 'string', 'max:2048'],
            'business_id' => ['nullable', 'uuid', Rule::exists('businesses', 'id')->where('user_id', $user->id)],
            'context' => ['nullable', 'array'],
            'context.browser' => ['nullable', 'string', 'max:500'],
            'context.screen' => ['nullable', 'string', 'max:50'],
            'context.app_version' => ['nullable', 'string', 'max:50'],
            'screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'type.required' => 'Choisissez le type de retour.',
            'type.in' => 'Le type doit être : bug, suggestion, question ou autre.',
            'message.required' => 'Décrivez votre retour.',
            'message.min' => 'Votre message est trop court (5 caractères minimum).',
            'message.max' => 'Votre message est trop long (5 000 caractères maximum).',
            'business_id.exists' => 'Ce business ne vous appartient pas.',
            'screenshot.mimes' => 'La capture d\'écran doit être une image JPG, PNG ou WebP.',
            'screenshot.max' => 'La capture d\'écran ne doit pas dépasser 5 Mo.',
        ]);

        // Seules les clés connues sont gardées ; le navigateur vient de l'en-tête s'il manque.
        $context = array_filter([
            'browser' => $data['context']['browser'] ?? $request->userAgent(),
            'screen' => $data['context']['screen'] ?? null,
            'app_version' => $data['context']['app_version'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $feedback = $service->submit($user, [
            ...$data,
            'context' => $context !== [] ? $context : null,
        ], $request->file('screenshot'));

        RateLimiter::hit($key, 3600);

        return response()->json([
            'message' => 'Merci ! Votre retour a bien été envoyé à l\'équipe Causeo.',
            'feedback' => $feedback,
        ], 201);
    }
}
