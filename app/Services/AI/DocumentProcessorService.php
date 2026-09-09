<?php

namespace App\Services\AI;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentProcessorService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * Traiter un document : extraction du texte, découpage, embeddings et stockage.
     */
    public function process(Document $document): void
    {
        $document->update(['status' => 'processing']);

        try {
            $raw = Storage::disk('local')->get($document->file_path);

            $text = $this->extractText($document->file_type, $raw);

            $chunks = $this->splitIntoChunks($text);

            $document->chunks()->delete();

            $index = 0;

            foreach (array_chunk($chunks, 20) as $batch) {
                $vectors = $this->embeddingService->embedBatch($batch);

                foreach ($batch as $position => $content) {
                    $chunk = DocumentChunk::create([
                        'document_id' => $document->id,
                        'business_id' => $document->business_id,
                        'content' => $content,
                        'chunk_index' => $index,
                    ]);

                    DB::statement(
                        'UPDATE document_chunks SET embedding = ? WHERE id = ?',
                        [$this->toVector($vectors[$position]), $chunk->id],
                    );

                    $index++;
                }
            }

            $document->update([
                'status' => 'ready',
                'chunk_count' => $index,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $document->update(['status' => 'failed']);

            Log::error('DocumentProcessorService::process a échoué', [
                'document_id' => $document->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Découper un texte en morceaux d'environ $chunkSize mots avec chevauchement.
     *
     * @return array<int, string>
     */
    public function splitIntoChunks(string $text, int $chunkSize = 500, int $overlap = 50): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return [];
        }

        $step = max(1, $chunkSize - $overlap);
        $chunks = [];

        for ($start = 0; $start < count($words); $start += $step) {
            $slice = array_slice($words, $start, $chunkSize);

            if (count($slice) < 10) {
                continue;
            }

            $chunks[] = implode(' ', $slice);
        }

        return $chunks;
    }

    /**
     * Extraire le texte brut d'un fichier selon son type.
     */
    private function extractText(string $fileType, string $raw): string
    {
        return match ($fileType) {
            'txt', 'csv' => $raw,
            // TODO: brancher un parser dédié (pdf, docx) — contenu brut en attendant.
            'pdf', 'docx' => $raw,
            default => $raw,
        };
    }

    /**
     * Formater un vecteur PHP au format littéral pgvector.
     *
     * @param  array<int, float>  $vector
     */
    private function toVector(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }
}
