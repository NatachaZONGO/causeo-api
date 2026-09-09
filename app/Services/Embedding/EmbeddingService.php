<?php

namespace App\Services\Embedding;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EmbeddingService
{
    private Client $client;

    private string $model;

    private string $apiKey;

    private const OUTPUT_DIMENSIONALITY = 1536;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://generativelanguage.googleapis.com/v1beta/',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'verify' => 'C:\wamp64\bin\php\php8.2.0\extras\ssl\cacert.pem',
        ]);

        $this->apiKey = (string) config('services.gemini.api_key');
        $this->model = config('services.gemini.embedding_model', 'gemini-embedding-001');
    }

    /**
     * Générer l'embedding d'un texte unique.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        try {
            $response = $this->client->post("models/{$this->model}:embedContent?key={$this->apiKey}", [
                'json' => [
                    'model' => "models/{$this->model}",
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                    'outputDimensionality' => self::OUTPUT_DIMENSIONALITY,
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true);

            return $payload['embedding']['values'];
        } catch (GuzzleException $e) {
            Log::error('EmbeddingService::embed a échoué', [
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Impossible de générer l\'embedding du texte.', 0, $e);
        }
    }

    /**
     * Générer les embeddings d'un lot de textes en une seule requête.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array
    {
        try {
            $requests = array_map(
                fn (string $text): array => [
                    'model' => "models/{$this->model}",
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                    'outputDimensionality' => self::OUTPUT_DIMENSIONALITY,
                ],
                array_values($texts),
            );

            $response = $this->client->post("models/{$this->model}:batchEmbedContents?key={$this->apiKey}", [
                'json' => [
                    'requests' => $requests,
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true);

            return array_map(
                static fn (array $item): array => $item['values'],
                $payload['embeddings'],
            );
        } catch (GuzzleException $e) {
            Log::error('EmbeddingService::embedBatch a échoué', [
                'count' => count($texts),
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Impossible de générer les embeddings du lot de textes.', 0, $e);
        }
    }
}
