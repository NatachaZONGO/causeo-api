<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessTemplate;
use App\Models\DocumentChunk;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * Créer l'entreprise de l'utilisateur à partir d'un template d'onboarding.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'exists:business_templates,id'],
            'business_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
        ], [
            'template_id.required' => 'Le template est obligatoire.',
            'template_id.exists' => 'Le template sélectionné n\'existe pas.',
            'business_name.required' => 'Le nom de l\'entreprise est obligatoire.',
            'business_name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
            'phone.max' => 'Le téléphone ne doit pas dépasser 20 caractères.',
            'address.max' => 'L\'adresse ne doit pas dépasser 255 caractères.',
            'city.max' => 'La ville ne doit pas dépasser 100 caractères.',
        ]);

        $user = $request->user();

        abort_if($user->businesses()->exists(), 422, 'Vous avez déjà une entreprise enregistrée.');

        $template = BusinessTemplate::findOrFail($data['template_id']);

        $business = $user->businesses()->create([
            'name' => $data['business_name'],
            'type' => $template->type,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'custom_greeting' => str_replace('{nom}', $data['business_name'], $template->default_greeting),
            'ai_instructions' => str_replace('{nom}', $data['business_name'], $template->default_ai_instructions),
            'country' => $user->country,
            'plan' => 'free',
            'is_active' => true,
        ]);

        if (! empty($template->sample_faq)) {
            $this->seedFaqDocument($business, $template->sample_faq);
        }

        return response()->json([
            'message' => 'Votre entreprise a été créée avec succès.',
            'business' => $business,
            'user' => $user->load('businesses'),
        ], 201);
    }

    /**
     * Créer un document "FAQ" à partir des questions/réponses du template et générer leurs embeddings.
     *
     * @param  array<int, array{question: string, answer: string}>  $sampleFaq
     */
    private function seedFaqDocument(Business $business, array $sampleFaq): void
    {
        $document = $business->documents()->create([
            'title' => 'FAQ',
            'file_path' => '',
            'file_type' => 'txt',
            'file_size' => 0,
            'status' => 'processing',
            'chunk_count' => 0,
        ]);

        $contents = array_map(
            fn (array $faq): string => "Question : {$faq['question']}\nRéponse : {$faq['answer']}",
            $sampleFaq,
        );

        $vectors = $this->embeddingService->embedBatch($contents);

        DB::transaction(function () use ($document, $business, $contents, $vectors) {
            foreach ($contents as $index => $content) {
                $chunk = DocumentChunk::create([
                    'document_id' => $document->id,
                    'business_id' => $business->id,
                    'content' => $content,
                    'chunk_index' => $index,
                ]);

                DB::statement(
                    'UPDATE document_chunks SET embedding = ? WHERE id = ?',
                    ['['.implode(',', $vectors[$index]).']', $chunk->id],
                );
            }

            $document->update([
                'status' => 'ready',
                'chunk_count' => count($contents),
                'processed_at' => now(),
            ]);
        });
    }
}
