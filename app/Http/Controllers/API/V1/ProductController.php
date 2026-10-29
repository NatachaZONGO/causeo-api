<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Catalog\CatalogService;
use App\Services\Catalog\CatalogSpreadsheet;
use App\Support\ActivityProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Catalogue et stock (module « catalog ») : articles et variantes, stock, import et
 * export. Réservé au gérant ; en lecture seule si la formule ne comprend plus le module.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    /**
     * Articles avec leurs variantes : recherche (nom, catégorie, description, SKU), filtres
     * catégorie, actif et stock (out : une variante en rupture, low : une variante sous son seuil).
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->checkAccess($business);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'stock' => ['nullable', Rule::in(['out', 'low'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'stock.in' => 'Le filtre de stock doit être « out » (rupture) ou « low » (stock faible).',
        ]);

        $query = $business->products()->with('variants')->orderBy('name');

        if (! empty($filters['q'])) {
            $this->search($query, $filters['q']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if (($filters['stock'] ?? null) === 'out') {
            $query->whereHas('variants', fn (Builder $variants) => $variants->whereNotNull('stock_quantity')->where('stock_quantity', '<=', 0));
        } elseif (($filters['stock'] ?? null) === 'low') {
            $query->whereHas('variants', fn (Builder $variants) => $variants->whereNotNull('stock_quantity')
                ->where('stock_quantity', '>', 0)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold'));
        }

        return response()->json($query->paginate($filters['per_page'] ?? 25)->withQueryString()->toArray() + [
            'categories' => $business->products()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->checkAccess($business, write: true);

        $data = $this->validated($request, $business);
        $product = $this->catalog->saveProduct($business, $data, null, $data['variants'] ?? [], auth()->id());

        return response()->json(['message' => 'L\'article a été ajouté au catalogue.', 'product' => $product], 201);
    }

    /**
     * Un article, ses variantes et ses derniers mouvements de stock.
     */
    public function show(Product $product): JsonResponse
    {
        $this->checkAccess($product->business);

        return response()->json([
            'product' => $product->load('variants'),
            'movements' => StockMovement::query()
                ->whereIn('variant_id', $product->variants->modelKeys())
                ->latest('created_at')->latest('id')
                ->limit(50)
                ->get(),
        ]);
    }

    /**
     * Modifier un article ; avec « variants », la liste des variantes est remplacée (une
     * variante avec id est modifiée, sans id créée, absente supprimée).
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $this->checkAccess($product->business, write: true);

        $data = $this->validated($request, $product->business, $product);
        $product = $this->catalog->saveProduct($product->business, $data, $product, $data['variants'] ?? null, auth()->id());

        return response()->json(['message' => 'L\'article a été mis à jour.', 'product' => $product]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->checkAccess($product->business, write: true);

        $product->delete();

        return response()->json(['message' => 'L\'article a été supprimé du catalogue.']);
    }

    /**
     * Restaurant : plat épuisé aujourd'hui (available: false), ou de nouveau disponible. Sans
     * remise à disponible chaque matin (catalog_daily_reset), il reste épuisé jusqu'à ce rappel.
     */
    public function updateAvailability(Request $request, Product $product): JsonResponse
    {
        $this->checkAccess($product->business, write: true);

        $data = $request->validate(['available' => ['required', 'boolean']], [
            'available.required' => 'Indiquez si le plat est disponible (true) ou épuisé (false).',
            'available.boolean' => 'La disponibilité doit valoir vrai ou faux.',
        ]);

        $product = $this->catalog->setAvailability($product, $request->boolean('available'));
        $item = ActivityProfile::definition($product->business->activityProfile())['item_singular'];

        return response()->json([
            'message' => $data['available'] ? "Le {$item} est de nouveau disponible." : "Le {$item} est marqué épuisé.",
            'product' => $product,
        ]);
    }

    /**
     * Créer les variantes manquantes à partir de listes d'options (tailles × couleurs).
     */
    public function generateVariants(Request $request, Product $product): JsonResponse
    {
        $this->checkAccess($product->business, write: true);

        $data = $request->validate([
            'options' => ['required', 'array', 'min:1', 'max:3'],
            'options.*' => ['array', 'min:1', 'max:30'],
            'options.*.*' => ['string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ], [
            'options.required' => 'Indiquez au moins une liste d\'options, par exemple {"taille": ["S", "M", "L"]}.',
            'options.max' => 'Au plus trois options (par exemple taille, couleur et matière).',
            'options.*.max' => 'Au plus 30 valeurs par option.',
        ]);

        $created = $this->catalog->generateVariants($product, $data['options'], $data, auth()->id());

        return response()->json([
            'message' => $created->isEmpty() ? 'Toutes ces variantes existent déjà.' : "{$created->count()} variante(s) créée(s).",
            'created' => $created->count(),
            'product' => $product->fresh()->load('variants'),
        ]);
    }

    /**
     * Modifier le stock d'une variante : valeur exacte (stock_quantity, null : non suivi)
     * ou ajustement (+/-). Chaque changement est un mouvement « manual ».
     */
    public function updateStock(Request $request, ProductVariant $variant): JsonResponse
    {
        $variant->loadMissing('product.business');
        $this->checkAccess($variant->product->business, write: true);

        $data = $request->validate([
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'required_without:adjustment'],
            'adjustment' => ['nullable', 'integer', 'not_in:0', 'required_without:stock_quantity'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ], [
            'stock_quantity.required_without' => 'Indiquez le nouveau stock (stock_quantity) ou un ajustement (adjustment).',
            'adjustment.required_without' => 'Indiquez le nouveau stock (stock_quantity) ou un ajustement (adjustment).',
            'stock_quantity.min' => 'Le stock ne peut pas être négatif.',
        ]);

        if (isset($data['low_stock_threshold'])) {
            $variant->update(['low_stock_threshold' => $data['low_stock_threshold']]);
        }

        $quantity = array_key_exists('adjustment', $data) && $data['adjustment'] !== null
            ? max(0, ($variant->stock_quantity ?? 0) + (int) $data['adjustment'])
            : ($data['stock_quantity'] ?? null);

        $variant = $this->catalog->setStock($variant, $quantity, StockMovement::MANUAL, auth()->id());

        return response()->json(['message' => 'Le stock a été mis à jour.', 'variant' => $variant, 'product' => $variant->product]);
    }

    /**
     * Importer un fichier CSV ou Excel (une ligne par variante) ; rapport des lignes en erreur.
     */
    public function import(Request $request, Business $business, CatalogSpreadsheet $spreadsheet): JsonResponse
    {
        $this->checkAccess($business, write: true);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120'],
        ], [
            'file.required' => 'Choisissez un fichier CSV ou Excel (.xlsx).',
            'file.mimes' => 'Le fichier doit être un CSV ou un Excel (.xlsx) : partez du modèle à télécharger.',
            'file.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $report = $spreadsheet->import($business, $file->getRealPath(), $extension, auth()->id());

        return response()->json([
            'message' => $report['errors'] === [] ? 'Import terminé.' : 'Import terminé, avec des lignes en erreur.',
            'report' => $report,
        ]);
    }

    public function export(Request $request, Business $business, CatalogSpreadsheet $spreadsheet): BinaryFileResponse
    {
        $this->checkAccess($business);
        $format = $this->format($request);

        return response()->download($spreadsheet->write($business, $format), "catalogue.{$format}")->deleteFileAfterSend();
    }

    public function template(Request $request, Business $business, CatalogSpreadsheet $spreadsheet): BinaryFileResponse
    {
        $this->checkAccess($business);
        $format = $this->format($request);

        return response()->download($spreadsheet->write(null, $format), "modele-catalogue.{$format}")->deleteFileAfterSend();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Business $business, ?Product $product = null): array
    {
        $required = $product === null ? 'required' : 'sometimes';

        $validator = validator($request->all(), [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:100'],
            'base_price' => [$required, 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'media_ids' => ['nullable', 'array', 'max:10'],
            'media_ids.*' => ['uuid', Rule::exists('business_media', 'id')->where('business_id', $business->id)],
            // Prestations : durée ; restaurant : délai de préparation indicatif et suppléments payants.
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'preparation_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'extras' => ['nullable', 'array', 'max:20'],
            'extras.*.name' => ['required', 'string', 'max:60'],
            'extras.*.price' => ['required', 'numeric', 'min:0'],
            'variants' => ['sometimes', 'array', 'max:200'],
            'variants.*.id' => ['nullable', 'uuid'],
            'variants.*.options' => ['nullable', 'array', 'max:5'],
            'variants.*.options.*' => ['nullable', 'string', 'max:50'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
        ], [
            'name.required' => 'Le nom de l\'article est obligatoire.',
            'base_price.required' => 'Le prix de l\'article est obligatoire.',
            'base_price.numeric' => 'Le prix doit être un nombre (en FCFA).',
            'base_price.min' => 'Le prix ne peut pas être négatif.',
            'media_ids.*.exists' => 'Une photo choisie n\'appartient pas à vos médias.',
            'variants.*.stock_quantity.min' => 'Le stock ne peut pas être négatif.',
            'variants.*.price.min' => 'Le prix d\'une variante ne peut pas être négatif.',
            'duration_minutes.min' => 'La durée doit être d\'au moins 1 minute.',
            'duration_minutes.max' => 'La durée ne peut pas dépasser 24 heures (1440 minutes).',
            'preparation_minutes.max' => 'Le délai de préparation ne peut pas dépasser 600 minutes.',
            'extras.max' => 'Au plus 20 suppléments par plat.',
            'extras.*.name.required' => 'Chaque supplément doit avoir un nom.',
            'extras.*.price.required' => 'Chaque supplément doit avoir un prix (0 s\'il est offert).',
            'extras.*.price.min' => 'Le prix d\'un supplément ne peut pas être négatif.',
        ]);

        // Deux variantes avec les mêmes options seraient indiscernables pour le client.
        $validator->after(function (Validator $validator) use ($request): void {
            $seen = [];
            foreach ((array) $request->input('variants', []) as $index => $variant) {
                $key = json_encode(collect(CatalogService::normalizeOptions((array) ($variant['options'] ?? [])))
                    ->map(fn ($value) => CatalogService::normalize($value))->sortKeys()->all());
                if (isset($seen[$key])) {
                    $validator->errors()->add("variants.{$index}.options", 'Deux variantes ont les mêmes options.');
                }
                $seen[$key] = true;
            }
        });

        return $validator->validate();
    }

    /**
     * @param  Builder<Product>|\Illuminate\Database\Eloquent\Relations\HasMany<Product, Business>  $query
     */
    private function search(Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query, string $search): void
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
        $like = DB::getDriverName() === 'pgsql' ? 'LIKE ?' : "LIKE ? ESCAPE '\\'";

        $query->where(function ($query) use ($pattern, $like) {
            $query->whereRaw("LOWER(products.name) {$like}", [$pattern])
                ->orWhereRaw("LOWER(products.category) {$like}", [$pattern])
                ->orWhereRaw("LOWER(products.description) {$like}", [$pattern])
                ->orWhereHas('variants', fn (Builder $variants) => $variants->whereRaw("LOWER(product_variants.sku) {$like}", [$pattern]));
        });
    }

    private function format(Request $request): string
    {
        return $request->query('format') === 'csv' ? 'csv' : 'xlsx';
    }

    /**
     * Gérant propriétaire, module activé ; pour une modification, module compris dans la formule.
     */
    private function checkAccess(Business $business, bool $write = false): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
        abort_unless($business->moduleEnabled('catalog'), 403, "Le module Catalogue n'est pas activé pour cette entreprise.");

        if ($write) {
            abort_unless($business->hasModule('catalog'), 403, "Votre formule {$business->billingState()->plan->name} ne permet pas de gérer le catalogue : passez en Pro pour le modifier.");
        }
    }
}
