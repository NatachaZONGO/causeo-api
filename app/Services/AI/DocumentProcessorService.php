<?php

namespace App\Services\AI;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

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
            $raw = Storage::disk('supabase_documents')->get($document->file_path);

            $text = $this->normalizeText($this->extractText($document->file_type, $raw));

            if (trim($text) === '') {
                throw new RuntimeException($document->file_type === 'pdf'
                    ? "Aucun texte extractible dans ce PDF : il s'agit probablement d'un document scanné (image). Fournissez un PDF contenant du texte."
                    : 'Le document ne contient aucun texte exploitable.');
            }

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
    public function extractText(string $fileType, string $raw): string
    {
        return match ($fileType) {
            'pdf' => (new PdfParser())->parseContent($raw)->getText(),
            'docx' => $this->extractDocxText($raw),
            default => $raw,
        };
    }

    /**
     * Extraire le texte d'un fichier DOCX (contenu de word/document.xml).
     */
    private function extractDocxText(string $raw): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');

        try {
            file_put_contents($path, $raw);

            $zip = new ZipArchive();

            if ($zip->open($path) !== true) {
                throw new RuntimeException('Le fichier DOCX est illisible.');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        if ($xml === false) {
            throw new RuntimeException('Le fichier DOCX ne contient pas de document Word.');
        }

        $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", ' ', "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Garantir un texte UTF-8 valide : conversion depuis Windows-1252 si le texte
     * ne contient aucun caractère UTF-8 multi-octets (sinon on garde l'UTF-8 et on
     * retire seulement les octets invalides), puis suppression des caractères nuls.
     */
    public function normalizeText(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8') && ! $this->containsUtf8Multibyte($text)) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $substitute = mb_substitute_character();
        mb_substitute_character('none');
        $text = mb_scrub($text, 'UTF-8');
        mb_substitute_character($substitute);

        return str_replace("\0", '', $text);
    }

    /**
     * Indiquer si le texte contient au moins une séquence UTF-8 multi-octets valide.
     */
    private function containsUtf8Multibyte(string $text): bool
    {
        return preg_match(
            '/[\xC2-\xDF][\x80-\xBF]'
            .'|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]'
            .'|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}/',
            $text,
        ) === 1;
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
