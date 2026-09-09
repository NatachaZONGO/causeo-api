<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Embedding\EmbeddingService;
use Illuminate\Support\Facades\DB;

$service = app(EmbeddingService::class);

echo "1. Génération de l'embedding pour la question...\n";
try {
    $vector = $service->embed('Quel est le prix du riz sauce arachide?');
    echo '   Embedding généré: '.count($vector)." dimensions\n";
} catch (\Exception $e) {
    echo '   ERREUR: '.$e->getMessage()."\n";
    exit(1);
}

$vectorString = '['.implode(',', $vector).']';

echo "2. Recherche de similarité...\n";
$results = DB::select(
    'SELECT id, LEFT(content, 80) as content_preview, 1 - (embedding <=> ?::vector) as similarity FROM document_chunks ORDER BY similarity DESC LIMIT 5',
    [$vectorString]
);

foreach ($results as $r) {
    echo "   Similarité: {$r->similarity} | {$r->content_preview}...\n";
}

if (empty($results)) {
    echo "   Aucun résultat trouvé\n";
}
