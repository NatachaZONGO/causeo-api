<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\StatusPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Statuts du jour : le gérant enregistre ce qu'il publie en statut WhatsApp (un ou plusieurs
 * articles du catalogue, ou une image importée avec une légende) pour que l'assistant sache à
 * quoi répond un client. Chaque statut expire 24 h après sa publication.
 */
class StatusPostController extends Controller
{
    /** Image envoyée au client en message « image » WhatsApp : JPEG ou PNG, 5 Mo au plus. */
    public const IMAGE_MAX_KB = BusinessMediaController::IMAGE_MAX_KB;

    /**
     * Statuts actifs, le plus récent d'abord, avec l'article et l'image.
     */
    public function index(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        return response()->json([
            'data' => $business->statusPosts()->active()->with(['product:id,name,base_price,media_ids', 'media:id,public_url'])->get(),
        ]);
    }

    /**
     * Enregistrer un statut par article choisi (product_ids), et un pour l'image importée (image,
     * caption). posted_at : maintenant par défaut, jamais dans le futur ni il y a plus de 24 h.
     */
    public function store(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'product_ids' => ['required_without:image', 'array', 'max:20'],
            'product_ids.*' => ['uuid', Rule::exists('products', 'id')->where('business_id', $business->id)],
            'image' => ['required_without:product_ids', 'file', 'mimes:jpg,jpeg,png', 'max:'.self::IMAGE_MAX_KB],
            'caption' => ['nullable', 'string', 'max:500'],
            'posted_at' => ['nullable', 'date', 'before_or_equal:now', 'after:'.now()->subHours(StatusPost::LIFETIME_HOURS)->toIso8601String()],
        ], [
            'product_ids.required_without' => 'Choisissez au moins un article du catalogue, ou importez une image.',
            'product_ids.max' => 'Au plus 20 articles par envoi.',
            'product_ids.*.uuid' => 'Article introuvable dans votre catalogue.',
            'product_ids.*.exists' => 'Article introuvable dans votre catalogue.',
            'image.required_without' => 'Choisissez au moins un article du catalogue, ou importez une image.',
            'image.mimes' => 'L\'image doit être au format JPG ou PNG.',
            'image.max' => 'L\'image ne doit pas dépasser 5 Mo : WhatsApp refuse d\'envoyer les images plus lourdes.',
            'caption.max' => 'La légende ne doit pas dépasser 500 caractères.',
            'posted_at.before_or_equal' => 'La date de publication ne peut pas être dans le futur.',
            'posted_at.after' => 'Un statut publié il y a plus de 24 heures n\'est plus visible.',
        ]);

        $postedAt = isset($data['posted_at']) ? Carbon::parse($data['posted_at']) : now();
        $dates = ['posted_at' => $postedAt, 'expires_at' => $postedAt->copy()->addHours(StatusPost::LIFETIME_HOURS)];
        $caption = isset($data['caption']) && trim($data['caption']) !== '' ? trim($data['caption']) : null;
        $posts = [];

        foreach (array_unique($data['product_ids'] ?? []) as $productId) {
            $posts[] = $business->statusPosts()->create(['product_id' => $productId, 'caption' => $caption] + $dates);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $path = $file->store("status/{$business->id}", 'supabase_media');

            // Média inactif : envoyable au client, mais hors du catalogue illustré de l'assistant.
            $media = $business->media()->create([
                'type' => 'image',
                'title' => $caption !== null ? mb_substr($caption, 0, 255) : 'Statut du '.$postedAt->format('d/m/Y'),
                'file_path' => $path,
                'public_url' => Storage::disk('supabase_media')->url($path),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'keywords' => [],
                'is_active' => false,
            ]);

            $posts[] = $business->statusPosts()->create(['media_id' => $media->id, 'caption' => $caption] + $dates);
        }

        return response()->json([
            'message' => count($posts) > 1 ? 'Vos statuts ont été enregistrés.' : 'Votre statut a été enregistré.',
            'data' => StatusPost::whereKey(array_map(fn (StatusPost $post) => $post->id, $posts))
                ->with(['product:id,name,base_price,media_ids', 'media:id,public_url'])->orderBy('created_at')->get(),
        ], 201);
    }

    /**
     * Supprimer un statut, et son image importée.
     */
    public function destroy(StatusPost $statusPost): JsonResponse
    {
        $statusPost->loadMissing(['business', 'media']);

        $this->checkOwnership($statusPost->business);

        $media = $statusPost->media;
        $statusPost->delete();

        if ($media !== null && ! $media->is_active) {
            if ($media->file_path && Storage::disk('supabase_media')->exists($media->file_path)) {
                Storage::disk('supabase_media')->delete($media->file_path);
            }
            $media->delete();
        }

        return response()->json(['message' => 'Le statut a été supprimé.']);
    }

    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
