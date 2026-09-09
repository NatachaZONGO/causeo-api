<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Models\Business;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Lister les documents d'une entreprise.
     */
    public function index(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $documents = $business->documents()
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json($documents);
    }

    /**
     * Téléverser un document pour une entreprise.
     */
    public function store(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,txt,docx,csv', 'max:10240'],
            'title' => ['nullable', 'string', 'max:255'],
        ], [
            'file.required' => 'Le fichier est obligatoire.',
            'file.file' => 'Le fichier téléversé n\'est pas valide.',
            'file.mimes' => 'Le fichier doit être au format PDF, TXT, DOCX ou CSV.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
            'title.string' => 'Le titre doit être une chaîne de caractères.',
            'title.max' => 'Le titre ne doit pas dépasser 255 caractères.',
        ]);

        $file = $request->file('file');

        $title = $data['title']
            ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $path = $file->store("documents/{$business->id}", 'local');

        $document = $business->documents()->create([
            'title' => $title,
            'file_path' => $path,
            'file_type' => strtolower($file->getClientOriginalExtension()),
            'file_size' => $file->getSize(),
            'status' => 'pending',
            'chunk_count' => 0,
        ]);

        ProcessDocumentJob::dispatch($document);

        return response()->json([
            'message' => 'Le document a été téléversé avec succès et sera traité prochainement.',
            'document' => $document,
        ], 201);
    }

    /**
     * Afficher un document.
     */
    public function show(Document $document): JsonResponse
    {
        $document->loadMissing('business');

        $this->checkOwnership($document->business);

        $document->loadCount('chunks');

        return response()->json([
            'document' => $document,
        ]);
    }

    /**
     * Supprimer un document et son fichier.
     */
    public function destroy(Document $document): JsonResponse
    {
        $document->loadMissing('business');

        $this->checkOwnership($document->business);

        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        $document->delete();

        return response()->json([
            'message' => 'Le document a été supprimé avec succès.',
        ]);
    }

    /**
     * Interrompre la requête si l'entreprise n'appartient pas à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
