<?php

namespace App\Http\Controllers\API\V1;

use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    /**
     * Lister les entreprises de l'utilisateur connecté.
     */
    public function index(): JsonResponse
    {
        $businesses = auth()->user()
            ->businesses()
            ->latest()
            ->paginate(10);

        return response()->json($businesses);
    }

    /**
     * Créer une entreprise pour l'utilisateur connecté.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), $this->messages());

        $data['country'] = $data['country'] ?? 'BF';

        $business = auth()->user()->businesses()->create($data);

        return response()->json([
            'message' => 'L\'entreprise a été créée avec succès.',
            'business' => $business,
        ], 201);
    }

    /**
     * Afficher une entreprise avec ses statistiques.
     */
    public function show(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $business->loadCount([
            'documents',
            'conversations',
            'escalations as pending_escalations_count' => fn ($query) => $query->where('status', 'pending'),
        ]);

        return response()->json([
            'business' => $business,
        ]);
    }

    /**
     * Mettre à jour une entreprise.
     */
    public function update(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate($this->rules(nullable: true), $this->messages());

        $business->update($data);

        return response()->json([
            'message' => 'L\'entreprise a été mise à jour avec succès.',
            'business' => $business,
        ]);
    }

    /**
     * Supprimer une entreprise.
     */
    public function destroy(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $business->delete();

        return response()->json([
            'message' => 'L\'entreprise a été supprimée avec succès.',
        ]);
    }

    /**
     * Interrompre la requête si l'entreprise n'appartient pas à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }

    /**
     * Règles de validation partagées entre store() et update().
     *
     * @return array<string, mixed>
     */
    private function rules(bool $nullable = false): array
    {
        $required = $nullable ? 'nullable' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(BusinessType::class)],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'custom_greeting' => ['nullable', 'string'],
            'ai_instructions' => ['nullable', 'string'],
        ];
    }

    /**
     * Messages d'erreur en français.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'Le nom de l\'entreprise est obligatoire.',
            'name.string' => 'Le nom doit être une chaîne de caractères.',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
            'type.required' => 'Le type d\'entreprise est obligatoire.',
            'type.enum' => 'Le type d\'entreprise sélectionné n\'est pas valide.',
            'description.string' => 'La description doit être une chaîne de caractères.',
            'phone.max' => 'Le téléphone ne doit pas dépasser 20 caractères.',
            'whatsapp_number.max' => 'Le numéro WhatsApp ne doit pas dépasser 20 caractères.',
            'address.max' => 'L\'adresse ne doit pas dépasser 255 caractères.',
            'city.max' => 'La ville ne doit pas dépasser 100 caractères.',
            'country.size' => 'Le pays doit être un code à 2 lettres.',
            'custom_greeting.string' => 'Le message d\'accueil doit être une chaîne de caractères.',
            'ai_instructions.string' => 'Les instructions IA doivent être une chaîne de caractères.',
        ];
    }
}
