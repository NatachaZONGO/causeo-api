<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMedia;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BusinessMediaController extends Controller
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * Lister les médias d'une entreprise.
     */
    public function index(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $media = $business->media()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($media);
    }

    /**
     * Téléverser un nouveau média.
     */
    public function store(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:16384', 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'keywords' => ['nullable'],
            'type' => ['nullable', 'in:image,document,catalog'],
        ], [
            'file.required' => 'Le fichier est obligatoire.',
            'file.file' => 'Le fichier téléversé n\'est pas valide.',
            'file.max' => 'Le fichier ne doit pas dépasser 16 Mo.',
            'file.mimes' => 'Le fichier doit être une image (JPG, PNG, WEBP, GIF) ou un document (PDF, DOC, DOCX).',
            'title.required' => 'Le titre est obligatoire.',
            'type.in' => 'Le type doit être « image », « document » ou « catalog ».',
        ]);

        $keywords = $this->normalizeKeywords($request->input('keywords'));

        $file = $request->file('file');
        $mimeType = $file->getClientMimeType();
        $type = $data['type'] ?? (str_starts_with($mimeType, 'image/') ? 'image' : 'document');

        $path = $file->store("media/{$business->id}", 'public');
        $publicUrl = Storage::disk('public')->url($path);

        $media = $business->media()->create([
            'type' => $type,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'file_path' => $path,
            'public_url' => $publicUrl,
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
            'keywords' => $keywords,
            'is_active' => true,
        ]);

        $this->embedKeywords($media);

        return response()->json([
            'message' => 'Le média a été téléversé avec succès.',
            'media' => $media->fresh(),
        ], 201);
    }

    /**
     * Afficher un média.
     */
    public function show(BusinessMedia $media): JsonResponse
    {
        $media->loadMissing('business');

        $this->checkOwnership($media->business);

        return response()->json([
            'media' => $media,
        ]);
    }

    /**
     * Supprimer un média et son fichier.
     */
    public function destroy(BusinessMedia $media): JsonResponse
    {
        $media->loadMissing('business');

        $this->checkOwnership($media->business);

        if ($media->file_path && Storage::disk('public')->exists($media->file_path)) {
            Storage::disk('public')->delete($media->file_path);
        }

        $media->delete();

        return response()->json([
            'message' => 'Le média a été supprimé avec succès.',
        ]);
    }

    /**
     * Générer l'embedding des mots-clés (best effort : n'échoue pas l'upload).
     */
    private function embedKeywords(BusinessMedia $media): void
    {
        $parts = array_filter([
            implode(', ', (array) $media->keywords),
            $media->title,
            $media->description,
        ]);

        $text = trim(implode('. ', $parts));

        if ($text === '') {
            return;
        }

        try {
            $vector = '['.implode(',', $this->embeddingService->embed($text)).']';

            DB::statement(
                'UPDATE business_media SET keywords_embedding = ?::vector WHERE id = ?',
                [$vector, $media->id],
            );
        } catch (Throwable $e) {
            Log::error('BusinessMediaController::embedKeywords a échoué', [
                'media_id' => $media->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Accepter les mots-clés en tableau ou en chaîne JSON / séparée par virgules.
     *
     * @return array<int, string>
     */
    private function normalizeKeywords(mixed $keywords): array
    {
        if (is_string($keywords)) {
            $decoded = json_decode($keywords, true);
            $keywords = is_array($decoded) ? $decoded : explode(',', $keywords);
        }

        if (! is_array($keywords)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($k) => trim((string) $k),
            $keywords,
        )));
    }

    /**
     * Interrompre la requête si l'entreprise n'appartient pas à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
